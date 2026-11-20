<?php

declare(strict_types=1);

namespace Apollo\Tests\Http;

use Apollo\Chapter\ChapterId;
use Apollo\Http\ApiController;
use Apollo\Simulation\SessionStore;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\SimulationStore;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\TestCase;

final class ApiControllerTest extends TestCase
{
    private const TAB = '0123456789abcdef';
    private const OTHER_TAB = 'fedcba9876543210';
    private const START = '{"action":"start","tab":"0123456789abcdef","chapter":"landing","scenario":false}';
    private const TICK = '{"action":"tick","tab":"0123456789abcdef","keys":[],"rod":0,"speed":1}';

    public function testAnErrorHasOnlyAnErrorField(): void
    {
        $session = [];
        $controller = new ApiController(new SessionStore($session));

        $response = $controller->handle('POST', 'application/json', self::TICK);

        self::assertSame(409, $response->status);
        self::assertSame(['error'], array_keys($response->body));
        self::assertIsString($response->body['error']);
    }

    public function testStartThenTickAdvancesTime(): void
    {
        $session = [];
        $controller = new ApiController(new SessionStore($session));

        $start = $controller->handle('POST', 'application/json', self::START);
        $tick = $controller->handle('POST', 'application/json', self::TICK);

        self::assertSame(200, $start->status);
        self::assertSame(200, $tick->status);
        self::assertSame(0.0, $start->body['t']);
        self::assertSame(0.1, $tick->body['t']);
        self::assertArrayHasKey('simulations', $session);
    }

    public function testEveryChapterStartsThroughTheApi(): void
    {
        foreach (ChapterId::cases() as $id) {
            $session = [];
            $controller = new ApiController(new SessionStore($session));

            $response = $controller->handle('POST', 'application/json', sprintf('{"action":"start","tab":"%s","chapter":"%s","scenario":false}', self::TAB, $id->value));

            self::assertSame(200, $response->status, $id->value);
            self::assertSame($id->value, $response->body['chapter']);
        }
    }

    public function testAThrowableInTheSimulationGivesAnInternalErrorWithoutAPath(): void
    {
        $failing = new class () extends Simulation {
            public function tick(TickInput $input): array
            {
                throw new \RuntimeException('secret detail');
            }
        };
        $failing->start(ChapterId::Landing, false);
        $store = new class ($failing) implements SimulationStore {
            public function __construct(private readonly Simulation $simulation)
            {
            }

            public function simulation(string $tab): Simulation
            {
                return $this->simulation;
            }

            public function save(string $tab, Simulation $simulation): void
            {
            }
        };
        $logged = [];
        $controller = new ApiController(
            $store,
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            },
        );

        $response = $controller->handle('POST', 'application/json', self::TICK);

        self::assertSame(500, $response->status);
        self::assertSame(['error' => 'Internal error'], $response->body);
        $json = json_encode($response->body, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(__FILE__, $json);
        self::assertStringNotContainsString('secret detail', $json);
        self::assertCount(1, $logged);
        self::assertStringContainsString('secret detail', $logged[0]);
    }

    public function testAStartInOneTabLeavesTheRunOfAnotherTabAlone(): void
    {
        $session = [];
        $controller = new ApiController(new SessionStore($session));
        $otherTick = str_replace(self::TAB, self::OTHER_TAB, self::TICK);

        $controller->handle('POST', 'application/json', self::START);
        $controller->handle('POST', 'application/json', self::TICK);
        $controller->handle('POST', 'application/json', self::TICK);
        $withoutStart = $controller->handle('POST', 'application/json', $otherTick);
        $controller->handle('POST', 'application/json', str_replace(['landing', self::TAB], ['launch', self::OTHER_TAB], self::START));
        $other = $controller->handle('POST', 'application/json', $otherTick);
        $first = $controller->handle('POST', 'application/json', self::TICK);

        self::assertSame(409, $withoutStart->status, 'the other tab has no simulation of its own yet');
        self::assertSame('launch', $other->body['chapter']);
        self::assertSame(0.1, $other->body['t']);
        self::assertSame('landing', $first->body['chapter']);
        self::assertEqualsWithDelta(0.3, $first->body['t'], 1e-9);
    }

    public function testASimulationThatCanNoLongerBeReadIsDroppedAndTheTabStartsAgain(): void
    {
        $session = ['simulations' => [self::TAB => 'O:31:"Apollo\\Simulation\\NoLongerThere":0:{}', self::OTHER_TAB => 'not serialized']];
        $controller = new ApiController(new SessionStore($session));

        $tick = $controller->handle('POST', 'application/json', self::TICK);
        $otherTick = @$controller->handle('POST', 'application/json', str_replace(self::TAB, self::OTHER_TAB, self::TICK));
        $start = $controller->handle('POST', 'application/json', self::START);

        self::assertSame(409, $tick->status);
        self::assertSame(409, $otherTick->status);
        self::assertSame(200, $start->status);
        self::assertSame([self::TAB], array_keys($session['simulations']));
    }

    public function testASessionKeepsTheSimulationsOfTheTabsUsedLast(): void
    {
        $session = [];
        $store = new SessionStore($session);
        $simulation = new Simulation();
        $simulation->start(ChapterId::Launch, false);

        $tabs = [];
        for ($i = 0; $i <= SessionStore::MAX_TABS; $i++) {
            $tabs[] = sprintf('%016x', $i);
            $store->save($tabs[$i], $simulation);
        }

        self::assertNull($store->simulation($tabs[0]), 'the tab used longest ago is dropped');
        self::assertInstanceOf(Simulation::class, $store->simulation($tabs[1]));
        self::assertCount(SessionStore::MAX_TABS, $session['simulations']);
    }
}
