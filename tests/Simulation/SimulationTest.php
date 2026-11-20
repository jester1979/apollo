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
        self::assertNull($response['agc']['routine']);
        self::assertCount(7, $response['agc']['executive']['coreSets']);
        self::assertCount(5, $response['agc']['executive']['vacAreas']);
        self::assertCount(13, $response['dsky']['lights']);
        self::assertNotContains(true, $response['dsky']['lights']);
        self::assertSame(
            ['view', 'x', 'altitude', 'vx', 'vz', 'pitch', 'throttle', 'fuel', 'target'],
            array_keys($response['vehicle']),
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

    public function testTheOutcomeIsReportedAtContact(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Landing, false);

        $response = [];
        for ($i = 0; $i < 400 && ($response['outcome'] ?? null) === null; $i++) {
            $response = $simulation->tick(new TickInput(speed: 10));
        }

        self::assertSame('crashed', $response['outcome']);
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
