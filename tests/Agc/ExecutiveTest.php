<?php

declare(strict_types=1);

namespace Apollo\Tests\Agc;

use Apollo\Agc\Executive;
use Apollo\Agc\Job;
use PHPUnit\Framework\TestCase;

final class ExecutiveTest extends TestCase
{
    public function testTheSizesAreThoseOfTheOriginal(): void
    {
        $snapshot = (new Executive())->snapshot();

        self::assertSame(7, Executive::CORE_SETS);
        self::assertSame(5, Executive::VAC_AREAS);
        self::assertSame(array_fill(0, 7, null), $snapshot['coreSets']);
        self::assertSame(array_fill(0, 5, null), $snapshot['vacAreas']);
        self::assertSame(0.0, $snapshot['cycleSteal']);
        self::assertSame(0, $snapshot['restarts']);
    }

    public function testTheEighthNovacJobRaisesAlarm1202(): void
    {
        $executive = new Executive();

        for ($i = 1; $i <= 7; $i++) {
            $executive->novac(new Job("J$i", 10, 1.0));
            self::assertNull($executive->takeAlarm(), "job $i fits");
        }
        $executive->novac(new Job('J8', 10, 1.0));

        self::assertSame(0o1202, $executive->takeAlarm());
        self::assertNull($executive->takeAlarm(), 'an alarm is handed out once');
        self::assertNotContains('J8', $executive->snapshot()['coreSets'], 'the refused job is not scheduled');
        self::assertSame(['J1', 'J2', 'J3', 'J4', 'J5', 'J6', 'J7'], $executive->snapshot()['coreSets']);
    }

    public function testTheSixthFindvacJobRaisesAlarm1201(): void
    {
        $executive = new Executive();

        for ($i = 1; $i <= 5; $i++) {
            $executive->findvac(new Job("V$i", 10, 1.0));
            self::assertNull($executive->takeAlarm(), "job $i fits");
        }
        $executive->findvac(new Job('V6', 10, 1.0));

        self::assertSame(0o1201, $executive->takeAlarm());
        self::assertSame(['V1', 'V2', 'V3', 'V4', 'V5'], $executive->snapshot()['vacAreas']);
        self::assertSame(['V1', 'V2', 'V3', 'V4', 'V5', null, null], $executive->snapshot()['coreSets']);
    }

    public function testFindvacRaises1202WhenOnlyTheCoreSetsAreGone(): void
    {
        $executive = new Executive();
        for ($i = 1; $i <= 7; $i++) {
            $executive->novac(new Job("N$i", 10, 1.0));
        }

        $executive->findvac(new Job('V', 10, 1.0));

        self::assertSame(0o1202, $executive->takeAlarm());
        self::assertSame(array_fill(0, 5, null), $executive->snapshot()['vacAreas'], 'no VAC area is kept for the refused job');
    }

    public function testBailoutDropsAllJobsCountsARestartAndKeepsTheCodeForN09(): void
    {
        $executive = new Executive();
        for ($i = 1; $i <= 5; $i++) {
            $executive->findvac(new Job("V$i", 10, 1.0));
        }
        $executive->novac(new Job('N', 10, 1.0));

        $executive->bailout(0o1202);

        $snapshot = $executive->snapshot();
        self::assertSame(array_fill(0, 7, null), $snapshot['coreSets']);
        self::assertSame(array_fill(0, 5, null), $snapshot['vacAreas']);
        self::assertSame(1, $snapshot['restarts']);
        self::assertSame([0o1202, 0, 0], $executive->alarmCodes());
        self::assertSame('01202', sprintf('%05o', $executive->alarmCodes()[0]));
        $executive->novac(new Job('again', 10, 1.0));
        self::assertNull($executive->takeAlarm(), 'the core sets are free again');
    }

    public function testTheAlarmRegistersFillInTheOrderOfFailregAndRsetClearsThem(): void
    {
        $executive = new Executive();

        $executive->bailout(0o1201);
        $executive->bailout(0o1202);
        $executive->bailout(0o1201);
        self::assertSame([0o1201, 0o1202, 0o1201], $executive->alarmCodes());

        $executive->bailout(0o1202);
        self::assertSame([0o1201, 0o1202, 0o1202 + 0o40000], $executive->alarmCodes(), 'bit 15 shows more alarms came');
        self::assertSame(4, $executive->snapshot()['restarts']);

        $executive->clearAlarmCodes();
        self::assertSame([0, 0, 0], $executive->alarmCodes());
        self::assertSame(4, $executive->snapshot()['restarts'], 'the restart counter is not an alarm register');
    }

    public function testJobsRunHighestPriorityFirstAndFreeTheirSlotsWhenDone(): void
    {
        $executive = new Executive();
        $executive->findvac(new Job('LOW', 20, 0.5));
        $executive->novac(new Job('HIGH', 25, 0.5));

        $executive->run(0.5);

        self::assertSame(['LOW', null, null, null, null, null, null], $executive->snapshot()['coreSets'], 'HIGH used the time and left');
        self::assertSame(['LOW', null, null, null, null], $executive->snapshot()['vacAreas']);

        $executive->run(0.5);

        self::assertSame(array_fill(0, 7, null), $executive->snapshot()['coreSets']);
        self::assertSame(array_fill(0, 5, null), $executive->snapshot()['vacAreas']);
    }

    public function testEqualPrioritiesRunInTheOrderOfScheduling(): void
    {
        $executive = new Executive();
        $executive->novac(new Job('FIRST', 10, 0.5));
        $executive->novac(new Job('SECOND', 10, 0.5));

        $executive->run(0.5);

        self::assertSame([null, 'SECOND', null, null, null, null, null], $executive->snapshot()['coreSets']);
    }

    public function testACycleStealLeavesLessTimeForTheJobs(): void
    {
        $executive = new Executive();
        $executive->setCycleSteal(0.5);
        $executive->novac(new Job('J', 10, 1.0));

        $executive->run(1.0); // half a second of work

        self::assertSame('J', $executive->snapshot()['coreSets'][0]);
        self::assertSame(0.5, $executive->snapshot()['cycleSteal']);

        $executive->run(1.0);

        self::assertNull($executive->snapshot()['coreSets'][0]);
    }

    public function testAJobKeepsItsCoreSetUntilItsCostIsUsedUp(): void
    {
        $executive = new Executive();
        $executive->novac(new Job('J', 10, 0.3));

        $executive->run(0.1);
        $executive->run(0.1);
        self::assertSame('J', $executive->snapshot()['coreSets'][0]);

        $executive->run(0.1);
        self::assertNull($executive->snapshot()['coreSets'][0]);
    }

    public function testACycleStealOfOneOrMoreIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Executive())->setCycleSteal(1.0);
    }

    public function testTheExecutiveSerializesIntoTheSessionWithItsJobs(): void
    {
        $executive = new Executive();
        $executive->findvac(new Job('SERVICER', 20, 1.0));
        $executive->bailout(0o1202);
        $executive->findvac(new Job('SERVICER', 20, 1.0));

        $copy = unserialize(serialize($executive));

        self::assertEquals($executive->snapshot(), $copy->snapshot());
        self::assertSame($executive->alarmCodes(), $copy->alarmCodes());
    }
}
