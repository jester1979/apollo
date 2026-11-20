<?php

declare(strict_types=1);

namespace Apollo\Tests\Simulation;

use Apollo\Chapter\ChapterId;
use Apollo\Dsky\Key;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\TestCase;

final class SimulationTest extends TestCase
{
    public function testTheResponseKeepsTheTickApiShape(): void
    {
        $simulation = new Simulation();

        $response = $simulation->start(ChapterId::Landing, false);

        self::assertSame(
            ['chapter', 'computer', 't', 'speed', 'outcome', 'dsky', 'vehicle', 'agc', 'log'],
            array_keys($response),
        );
        self::assertSame('landing', $response['chapter']);
        self::assertSame('LM', $response['computer']);
        self::assertNull($response['outcome']);
        self::assertCount(1, $response['log']);
        self::assertSame('agc', $response['log'][0]['kind']);
        self::assertSame('P63LM', $response['log'][0]['routine']['label']);
        self::assertIsArray($response['dsky']);
        self::assertIsArray($response['agc']);
        self::assertSame('P63LM', $response['agc']['routine']['label']);
        self::assertSame('P63 – Braking phase', $response['agc']['program']);
        self::assertCount(7, $response['agc']['executive']['coreSets']);
        self::assertCount(5, $response['agc']['executive']['vacAreas']);
        self::assertCount(13, $response['dsky']['lights']);
        self::assertNotContains(true, $response['dsky']['lights']);
        self::assertSame(
            ['view', 'x', 'altitude', 'vx', 'vz', 'pitch', 'throttle', 'fuel', 'target', 'program', 'desiredRate'],
            array_keys($response['vehicle']),
        );
    }

    public function testTheResponseCarriesTheRealDsky(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $response = $simulation->tick(new TickInput(speed: 10));

        self::assertSame(
            ['prog', 'verb', 'noun', 'r1', 'r2', 'r3', 'flash', 'lights'],
            array_keys($response['dsky']),
        );
        self::assertSame('63', $response['dsky']['prog']);
        self::assertSame('06', $response['dsky']['verb']);
        self::assertSame('63', $response['dsky']['noun']);
        self::assertMatchesRegularExpression('/^[+-]\d{5}$/', $response['dsky']['r1']);
        self::assertMatchesRegularExpression('/^[+-]\d{5}$/', $response['dsky']['r2']);
        self::assertMatchesRegularExpression('/^[+-]\d{5}$/', $response['dsky']['r3']);
        self::assertSame(
            ['compActy', 'prog', 'oprErr', 'keyRel', 'restart', 'uplinkActy', 'noAtt', 'stby', 'temp', 'gimbalLock', 'tracker', 'alt', 'vel'],
            array_keys($response['dsky']['lights']),
        );
    }

    public function testATickAdvancesByTenthsOfASecondTimesSpeed(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $response = $simulation->tick(new TickInput(speed: 5));

        self::assertSame(0.5, $response['t']);
        self::assertSame(5, $response['speed']);
    }

    /** Starts the landing and answers V99N63 with PRO; the engine ignites on the next tick. */
    private function ignited(): Simulation
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);
        for ($i = 0; $i < 10; $i++) {
            $simulation->tick(new TickInput(speed: 5)); // up to TIG-5 s
        }
        $simulation->tick(new TickInput(keys: [Key::Proceed], speed: 10));

        return $simulation;
    }

    public function testTheLandingRunsThroughP64AndP65ByItselfAndEndsInSuccess(): void
    {
        $simulation = $this->ignited();

        $programs = [];
        $response = [];
        for ($i = 0; $i < 2000 && ($response['outcome'] ?? null) === null; $i++) {
            $response = $simulation->tick(new TickInput(speed: 10));
            $programs[$response['agc']['program']] = true;
        }

        self::assertSame('success', $response['outcome']);
        self::assertSame(
            ['P63 – Braking phase', 'P64 – Approach phase', 'P65 – Automatic landing (3 ft/s)'],
            array_keys($programs),
        );
        self::assertSame(0.0, $response['vehicle']['altitude']);
        self::assertNull($response['vehicle']['desiredRate']);
    }

    public function testARodClickInTheTickSwitchesToP66AndMovesTheDesiredRate(): void
    {
        $simulation = $this->ignited();
        do {
            $response = $simulation->tick(new TickInput(speed: 10));
        } while ($response['vehicle']['program'] === 63);

        $response = $simulation->tick(new TickInput(rod: -1));

        self::assertSame('P66 – Rate of descent (ROD)', $response['agc']['program']);
        self::assertSame('66', $response['dsky']['prog']);
        self::assertSame('60', $response['dsky']['noun']);
        self::assertContains($response['agc']['routine']['label'], ['RODCOMP', 'P66VERT']);
        self::assertEqualsWithDelta($response['vehicle']['vz'] - 0.3048, $response['vehicle']['desiredRate'], 0.5);
    }

    public function testEveryChapterCanBeStarted(): void
    {
        foreach (ChapterId::cases() as $id) {
            $response = (new Simulation())->start($id, false);

            self::assertSame($id->value, $response['chapter']);
            self::assertNull($response['outcome']);
        }
    }

    public function testTickBeforeStartIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        (new Simulation())->tick(new TickInput());
    }

    /**
     * @param list<Key> $keys
     * @return array<string, mixed>
     */
    private function tick(Simulation $simulation, array $keys = [], int $speed = 1): array
    {
        return $simulation->tick(new TickInput(keys: $keys, speed: $speed));
    }

    public function testKeysPressedInATickShowInThatTicksDsky(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $response = $this->tick($simulation, [Key::Verb, Key::One, Key::Six, Key::Noun, Key::Six, Key::Eight, Key::Enter]);

        self::assertSame('16', $response['dsky']['verb']);
        self::assertSame('68', $response['dsky']['noun']);
        self::assertTrue($response['dsky']['lights']['keyRel']);
        self::assertMatchesRegularExpression('/^\+\d{5}$/', $response['dsky']['r1']);

        $response = $this->tick($simulation, [Key::KeyRelease]);
        self::assertSame('06', $response['dsky']['verb']);
        self::assertSame('63', $response['dsky']['noun']);
        self::assertFalse($response['dsky']['lights']['keyRel']);
    }

    public function testAnUnknownVerbLightsOprErrAndRsetPutsItOut(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $response = $this->tick($simulation, [Key::Verb, Key::Nine, Key::Seven, Key::Enter]);
        self::assertTrue($response['dsky']['lights']['oprErr']);

        $response = $this->tick($simulation, [Key::Reset]);
        self::assertFalse($response['dsky']['lights']['oprErr']);
    }

    public function testTheLampTestLastsFiveSimulatedSeconds(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $response = $this->tick($simulation, [Key::Verb, Key::Three, Key::Five, Key::Enter], 5);
        self::assertSame('+88888', $response['dsky']['r1']);
        self::assertNotContains(false, $response['dsky']['lights']);

        for ($i = 0; $i < 8; $i++) {
            $response = $this->tick($simulation, [], 5); // 0.5 s each
        }
        self::assertSame('+88888', $response['dsky']['r1'], 'still on at 4.5 s');

        $response = $this->tick($simulation, [], 5);
        self::assertSame('99', $response['dsky']['verb'], 'the program display is back, and it is V99 by now (t = 5 s)');
        self::assertFalse($response['dsky']['lights']['keyRel']);
        self::assertFalse($response['dsky']['lights']['oprErr']);
    }

    public function testV99FlashesAtTigMinus5AndProStartsTheBurn(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $response = $this->tick($simulation, [], 10);
        self::assertSame(['06', false], [$response['dsky']['verb'], $response['dsky']['flash']]);
        self::assertSame(0.0, $response['vehicle']['throttle']);

        for ($i = 0; $i < 4; $i++) {
            $response = $this->tick($simulation, [], 10);
        }
        self::assertSame(['99', '63', true], [$response['dsky']['verb'], $response['dsky']['noun'], $response['dsky']['flash']]);
        self::assertSame(0.0, $response['vehicle']['throttle'], 'no ignition without PRO');

        $response = $this->tick($simulation, [Key::Proceed]);
        self::assertSame(0.1, $response['vehicle']['throttle']);
        self::assertSame(['06', false], [$response['dsky']['verb'], $response['dsky']['flash']]);
    }

    public function testTheSimulationSurvivesTheSessionSerializationAfterKeys(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);
        $this->tick($simulation, [Key::Verb, Key::One, Key::Six, Key::Noun, Key::Six]);

        $copy = unserialize(serialize($simulation));

        $before = $this->tick($simulation, [Key::Eight, Key::Enter]);
        $after = $this->tick($copy, [Key::Eight, Key::Enter]);
        self::assertSame($before['dsky'], $after['dsky']);
        self::assertSame('68', $after['dsky']['noun']);
    }

    public function testALogLineIsSentOnlyOnce(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $first = $simulation->tick(new TickInput(keys: [Key::Verb, Key::One, Key::Six, Key::Noun, Key::Six, Key::Eight, Key::Enter]));
        $second = $simulation->tick(new TickInput());

        self::assertSame(
            [
                'VERB → verb register blanked, two digits expected',
                '1 → digit put in the verb register',
                '6 → digit put in the verb register',
                'NOUN → noun register blanked, two digits expected',
                '6 → digit put in the noun register',
                '8 → digit put in the noun register',
                'V16N68 ENTR → monitor noun 68, refreshed every second',
            ],
            array_column($first['log'], 'text'),
        );
        self::assertSame([], $second['log']);
    }

    public function testAnInputLineNamesThePinballRoutineAndKeepsTheMissionTime(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $response = $simulation->tick(new TickInput(keys: [Key::Verb, Key::Seven, Key::Seven, Key::Enter]));

        $last = $response['log'][array_key_last($response['log'])];
        self::assertSame('input', $last['kind']);
        self::assertSame('V77N63 ENTR → unknown verb, OPR ERR', $last['text']);
        self::assertSame('CHARALRM', $last['routine']['label']);
        self::assertSame(2744, $last['routine']['line']);
        self::assertSame(0.0, $last['t']);
    }
}
