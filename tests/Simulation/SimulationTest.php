<?php

declare(strict_types=1);

namespace Apollo\Tests\Simulation;

use Apollo\Chapter\ChapterId;
use Apollo\Simulation\ChapterNotAvailable;
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
        self::assertSame([], $response['log']);
        self::assertIsArray($response['dsky']);
        self::assertIsArray($response['agc']);
        self::assertSame('P63LM', $response['agc']['routine']['label']);
        self::assertSame('P63 – Braking phase', $response['agc']['program']);
        self::assertCount(7, $response['agc']['executive']['coreSets']);
        self::assertCount(5, $response['agc']['executive']['vacAreas']);
        self::assertCount(13, $response['dsky']['lights']);
        self::assertNotContains(true, $response['dsky']['lights']);
        self::assertSame(
            ['view', 'x', 'altitude', 'vx', 'vz', 'pitch', 'throttle', 'fuel', 'target'],
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

    public function testTheOutcomeIsReportedWhenP63HandsOverToP64(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $response = [];
        for ($i = 0; $i < 1000 && ($response['outcome'] ?? null) === null; $i++) {
            $response = $simulation->tick(new TickInput(speed: 10));
        }

        self::assertSame('missed', $response['outcome']);
        self::assertSame('P64 – Approach phase', $response['agc']['program']);
        self::assertSame('STARTP64', $response['agc']['routine']['label']);
        self::assertGreaterThan(1800.0, $response['vehicle']['altitude']);
        self::assertLessThan(2800.0, $response['vehicle']['altitude']);
    }

    public function testAnUnbuiltChapterIsRefused(): void
    {
        $this->expectException(ChapterNotAvailable::class);

        (new Simulation())->start(ChapterId::Launch, false);
    }

    public function testTickBeforeStartIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        (new Simulation())->tick(new TickInput());
    }
}
