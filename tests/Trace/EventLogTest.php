<?php

declare(strict_types=1);

namespace Apollo\Tests\Trace;

use Apollo\Trace\EventLog;
use Apollo\Trace\LogKind;
use Apollo\Trace\Routine;
use PHPUnit\Framework\TestCase;

final class EventLogTest extends TestCase
{
    public function testALineCarriesSequenceTimeKindTextAndRoutine(): void
    {
        $log = new EventLog();
        $log->record(LogKind::Input, 'PRO → proceed', Routine::ProcKey, 12.34);

        $lines = $log->takeNew();

        self::assertCount(1, $lines);
        self::assertSame(1, $lines[0]['seq']);
        self::assertSame(12.3, $lines[0]['t']);
        self::assertSame('input', $lines[0]['kind']);
        self::assertSame('PRO → proceed', $lines[0]['text']);
        self::assertSame('PROCKEY', $lines[0]['routine']['label'] ?? null);
    }

    public function testTakeNewHandsOutEachLineOnce(): void
    {
        $log = new EventLog();
        $log->record(LogKind::Agc, 'one', null, 0.0);
        $log->record(LogKind::Agc, 'two', null, 0.0);

        self::assertSame(['one', 'two'], array_column($log->takeNew(), 'text'));
        self::assertSame([], $log->takeNew());

        $log->record(LogKind::Alarm, 'three', null, 1.0);

        $lines = $log->takeNew();
        self::assertSame(['three'], array_column($lines, 'text'));
        self::assertSame(3, $lines[0]['seq']);
        self::assertNull($lines[0]['routine']);
    }

    public function testItKeepsTheLast200Lines(): void
    {
        $log = new EventLog();
        for ($i = 1; $i <= 250; $i++) {
            $log->record(LogKind::Agc, (string) $i, null, 0.0);
        }

        $lines = $log->lines();

        self::assertCount(EventLog::MAX_LINES, $lines);
        self::assertSame(51, $lines[0]['seq']);
        self::assertSame(250, $lines[199]['seq']);
        self::assertCount(200, $log->takeNew());
    }

    public function testItSurvivesTheSessionRoundTrip(): void
    {
        $log = new EventLog();
        $log->record(LogKind::Input, 'VERB → x', Routine::Verb, 1.0);
        $log->takeNew();
        $log->record(LogKind::Input, 'NOUN → y', Routine::Noun, 2.0);

        $copy = unserialize(serialize($log));

        self::assertInstanceOf(EventLog::class, $copy);
        self::assertSame(['NOUN → y'], array_column($copy->takeNew(), 'text'));
    }
}
