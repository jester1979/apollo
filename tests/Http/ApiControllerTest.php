<?php

declare(strict_types=1);

namespace Apollo\Tests\Http;

use Apollo\Chapter\ChapterId;
use Apollo\Http\ApiController;
use Apollo\Simulation\SessionStore;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\TestCase;

final class ApiControllerTest extends TestCase
{
    private const START = '{"action":"start","chapter":"landing","scenario":false}';
    private const TICK = '{"action":"tick","keys":[],"rod":0,"speed":1}';

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
        self::assertArrayHasKey('simulation', $session);
    }

    public function testAChapterThatIsNotBuiltYetIsReportedWithoutDetails(): void
    {
        $session = [];
        $controller = new ApiController(new SessionStore($session));

        $response = $controller->handle('POST', 'application/json', '{"action":"start","chapter":"tei","scenario":false}');

        self::assertSame(501, $response->status);
        self::assertSame(['error'], array_keys($response->body));
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
        $session = ['simulation' => $failing];
        $logged = [];
        $controller = new ApiController(
            new SessionStore($session),
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
}
