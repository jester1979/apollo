<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Agc\Executive;
use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Key;
use Apollo\Dsky\Light;
use Apollo\Simulation\TickInput;
use Apollo\Tests\Support\LandingBurn;
use PHPUnit\Framework\TestCase;

final class LandingScenarioTest extends TestCase
{
    public function testWithoutTheScenarioAFullAutomaticLandingGivesNoAlarm(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        $seen = $this->fly($chapter);

        self::assertSame(Outcome::Success, $chapter->outcome());
        self::assertSame(0, $chapter->agc()->executive->snapshot()['restarts']);
        self::assertSame([0, 0, 0], $chapter->agc()->executive->alarmCodes());
        self::assertFalse($seen['prog']);
        self::assertSame([], $this->alarmLines($chapter));
        self::assertSame(0.0, $chapter->agc()->executive->snapshot()['cycleSteal']);
    }

    public function testWithTheScenarioTheExecutiveOverloadsAndAlarmsAndTheLandingStillSucceeds(): void
    {
        $chapter = new LandingChapter(null, true);
        LandingBurn::proceed($chapter);
        $seen = $this->fly($chapter);

        $executive = $chapter->agc()->executive;
        self::assertSame(Outcome::Success, $chapter->outcome());
        self::assertSame(0.15, $executive->snapshot()['cycleSteal']);
        self::assertGreaterThanOrEqual(1, $executive->snapshot()['restarts']);
        self::assertTrue($seen['prog'], 'PROG lit during the landing');
        self::assertTrue($seen['duringBraking'], 'the first alarm came in P63 or P64');
        self::assertContains($executive->alarmCodes()[0], [Executive::ALARM_NO_VAC_AREAS, Executive::ALARM_NO_CORE_SETS]);
        self::assertNotSame([], $this->alarmLines($chapter));
        self::assertSame($executive->snapshot()['restarts'], $seen['maxRestarts']);
    }

    public function testTheBoxesFillWithJobNamesJustBeforeTheAlarm(): void
    {
        $chapter = new LandingChapter(null, true);
        $before = $chapter->agc()->executive->snapshot();
        $mostCoreSets = 0;

        while (true) {
            $chapter->step(0.1, new TickInput());
            $now = $chapter->agc()->executive->snapshot();
            if ($now['restarts'] > 0) {
                break;
            }
            $before = $now;
            $mostCoreSets = max($mostCoreSets, count(array_filter($now['coreSets'])));
            self::assertLessThan(3000, $chapter->agc()->missionTime);
        }

        self::assertSame(array_fill(0, 5, 'SERVICER'), $before['vacAreas'], 'all five VAC areas hold a SERVICER');
        self::assertGreaterThanOrEqual(6, $mostCoreSets, 'the radar job takes a core set next to the five SERVICERs');
        self::assertSame(['SERVICER', 'RADAR', null, null, null, null, null], $now['coreSets'], 'the restart left only the jobs of the restart tables');
        self::assertSame(['SERVICER', null, null, null, null], $now['vacAreas']);
    }

    public function testV05N09ShowsTheCodeInOctalAndRsetPutsOutPromAndClearsTheCodes(): void
    {
        $chapter = new LandingChapter(null, true);
        $dsky = $chapter->agc()->dsky;
        while ($chapter->agc()->executive->snapshot()['restarts'] === 0) {
            $chapter->step(0.1, new TickInput());
            self::assertLessThan(3000, $chapter->agc()->missionTime);
        }
        self::assertTrue($dsky->isLit(Light::Prog));

        foreach ([Key::Verb, Key::Zero, Key::Five, Key::Noun, Key::Zero, Key::Nine, Key::Enter] as $key) {
            $dsky->press($key, $chapter->allowedPrograms());
        }
        $chapter->step(0.1, new TickInput());
        $display = $dsky->toArray();

        self::assertSame('05', $display['verb']);
        self::assertSame('09', $display['noun']);
        self::assertContains($display['r1'], ['01201', '01202']);

        $dsky->press(Key::Reset);
        $chapter->step(0.1, new TickInput());

        self::assertFalse($dsky->isLit(Light::Prog));
        self::assertSame([0, 0, 0], $chapter->agc()->executive->alarmCodes());
        self::assertGreaterThanOrEqual(1, $chapter->agc()->executive->snapshot()['restarts']);
    }

    public function testAnActiveMonitorAddsADisplayJobEverySecond(): void
    {
        $chapter = new LandingChapter();
        $dsky = $chapter->agc()->dsky;
        foreach ([Key::Verb, Key::One, Key::Six, Key::Noun, Key::Six, Key::Eight, Key::Enter] as $key) {
            $dsky->press($key, $chapter->allowedPrograms());
        }

        $chapter->step(1.0, new TickInput());

        self::assertTrue($dsky->isMonitoring());
        self::assertContains('DISPLAY', $chapter->agc()->executive->snapshot()['coreSets']);
        $chapter->step(60.0, new TickInput());
        self::assertSame(0, $chapter->agc()->executive->snapshot()['restarts'], 'a monitor alone is no overload');
    }

    public function testTheChapterSerializesIntoTheSessionWithItsExecutive(): void
    {
        $chapter = new LandingChapter(null, true);
        $chapter->step(30.0, new TickInput());

        $copy = unserialize(serialize($chapter));
        $chapter->step(60.0, new TickInput());
        $copy->step(60.0, new TickInput());

        self::assertEquals($chapter->agc()->executive->snapshot(), $copy->agc()->executive->snapshot());
        self::assertGreaterThanOrEqual(1, $copy->agc()->executive->snapshot()['restarts']);
    }

    /**
     * Flies to the end of the chapter.
     *
     * @return array{prog: bool, duringBraking: bool, maxRestarts: int}
     */
    private function fly(LandingChapter $chapter): array
    {
        $seen = ['prog' => false, 'duringBraking' => false, 'maxRestarts' => 0];
        $ticks = 0;
        while ($chapter->outcome() === null) {
            $chapter->step(0.1, new TickInput());
            self::assertLessThan(20000, ++$ticks, 'the landing must end');
            $restarts = $chapter->agc()->executive->snapshot()['restarts'];
            if ($restarts > 0 && !$seen['prog']) {
                $seen['duringBraking'] = in_array($chapter->agc()->majorMode, [63, 64], true);
            }
            $seen['prog'] = $seen['prog'] || $chapter->agc()->dsky->isLit(Light::Prog);
            $seen['maxRestarts'] = max($seen['maxRestarts'], $restarts);
        }

        return $seen;
    }

    /** @return list<array<string, mixed>> */
    private function alarmLines(LandingChapter $chapter): array
    {
        return array_values(array_filter(
            $chapter->agc()->log->lines(),
            static fn (array $line): bool => $line['kind'] === 'alarm',
        ));
    }
}
