<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Entry\EntryChapter;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Key;
use Apollo\Guidance\Comanche\EntryGuidance;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\TestCase;

final class EntryChapterTest extends TestCase
{
    /**
     * Flies the chapter at 1x (0.1 s per tick) until it ends, collecting what the view and the DSKY showed.
     *
     * @return array{programs: list<int>, displays: array<string, true>, routines: array<string, true>, maxLoad: float, minDragAfterStart: float}
     */
    private function fly(EntryChapter $chapter, int $maxTicks = 20000): array
    {
        $seen = ['programs' => [], 'displays' => [], 'routines' => [], 'maxLoad' => 0.0, 'minDragAfterStart' => INF];
        for ($tick = 0; $tick < $maxTicks && $chapter->outcome() === null; $tick++) {
            $chapter->step(Integrator::STEP, new TickInput());
            $view = $chapter->vehicleView();
            if ($seen['programs'] === [] || end($seen['programs']) !== $view['program']) {
                $seen['programs'][] = $view['program'];
            }
            $dsky = $chapter->agc()->dsky->toArray();
            $seen['displays'][sprintf('P%s V%sN%s', $dsky['prog'], $dsky['verb'], $dsky['noun'])] = true;
            $routine = $chapter->agc()->currentRoutine;
            if ($routine !== null) {
                $seen['routines'][$routine->label()] = true;
            }
            $seen['maxLoad'] = max($seen['maxLoad'], $view['load']);
        }

        return $seen;
    }

    public function testItStartsAtTheEntryInterfaceInP63WithV06N64(): void
    {
        $chapter = new EntryChapter();

        self::assertNull($chapter->outcome());
        self::assertSame(ChapterId::Entry, $chapter->id());
        self::assertSame([63, 0], $chapter->allowedPrograms());
        self::assertSame(63, $chapter->agc()->majorMode);
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['63', '06', '64'], [$display['prog'], $display['verb'], $display['noun']]);
        $view = $chapter->vehicleView();
        self::assertSame('entry', $view['view']);
        self::assertEqualsWithDelta(122000.0, $view['altitude'], 1.0);
        self::assertEqualsWithDelta(11030.0, $view['speed'], 1.0);
        self::assertLessThan(0.01, $view['drag'], 'no air yet');
        self::assertFalse($view['chutes']);
    }

    public function testTheChapterEndsWithParachutesInSuccessAtOneTimesSpeed(): void
    {
        $chapter = new EntryChapter();

        $seen = $this->fly($chapter);

        self::assertSame(Outcome::Success, $chapter->outcome());
        $view = $chapter->vehicleView();
        self::assertTrue($view['chutes']);
        self::assertLessThanOrEqual(7000.0, $view['altitude']);
        self::assertLessThan(200.0, $view['airSpeed']);
        self::assertGreaterThan(5.0, $view['maxLoad'], 'a real entry, not a glide');
        self::assertLessThan(12.0, $view['maxLoad']);
        self::assertLessThan(12.0, $seen['maxLoad']);
        self::assertEqualsWithDelta(0.0, $view['range'], 5 * 1852.0, 'the predictor steers to within 5 nautical miles of the target');
        self::assertEqualsWithDelta(490.0, $chapter->agc()->missionTime, 60.0, 'about 8 minutes');
    }

    public function testTheProgramsFollowEachOtherByThemselvesWithTheirDisplays(): void
    {
        $chapter = new EntryChapter();

        $seen = $this->fly($chapter);

        self::assertSame([63, 64, 67], $seen['programs']);
        self::assertArrayHasKey('P63 V06N64', $seen['displays']);
        self::assertArrayHasKey('P64 V06N74', $seen['displays']);
        self::assertArrayHasKey('P67 V06N66', $seen['displays']);
        self::assertArrayHasKey('P67 V16N67', $seen['displays'], 'steering stops under 1000 ft/s');
        foreach (['STARTENT', 'UPCONTRL', 'PREDICT3'] as $label) {
            self::assertArrayHasKey($label, $seen['routines']);
        }
        $log = array_column($chapter->agc()->log->lines(), 'text');
        self::assertStringContainsString('P63 → P64', implode("\n", $log));
        self::assertStringContainsString('→ P67', implode("\n", $log));
    }

    public function testTheGlowFollowsTheDragAndTheBankAngleComesFromThePredictorInP67(): void
    {
        $chapter = new EntryChapter();
        $chapter->step(20.0, new TickInput());
        $early = $chapter->vehicleView();
        self::assertSame(63, $early['program']);
        self::assertLessThan(0.05, $early['drag'], 'weak before P64');

        $peak = 0.0;
        $bankInP64 = null;
        while ($chapter->outcome() === null && $chapter->vehicleView()['program'] !== 67) {
            $chapter->step(1.0, new TickInput());
            $view = $chapter->vehicleView();
            $peak = max($peak, $view['drag']);
            if ($view['program'] === 64) {
                $bankInP64 = $view['bank'];
            }
        }

        self::assertGreaterThan(5.0, $peak);
        self::assertEqualsWithDelta(EntryGuidance::LIFT_UP_BANK, $bankInP64, 1e-3);
        $chapter->step(4.0, new TickInput());
        self::assertNotEqualsWithDelta(EntryGuidance::LIFT_UP_BANK, $chapter->vehicleView()['bank'], 1e-3, 'P67 picks its own bank angle');
    }

    public function testTooShallowAnEntrySkipsOutOfTheAtmosphereAndIsAMiss(): void
    {
        $chapter = new EntryChapter(StartStates::entryState(-5.5));

        $seen = $this->fly($chapter);

        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertGreaterThan(EntryGuidance::SKIP_ALTITUDE, $chapter->vehicleView()['altitude']);
        self::assertFalse($chapter->vehicleView()['chutes']);
        self::assertContains(66, $seen['programs'], 'the drag fell away: KEP2');
        $texts = array_column($chapter->agc()->log->lines(), 'text');
        self::assertStringContainsString('skipped out', implode("\n", $texts));
    }

    public function testTooSteepAnEntryOverloadsTheCrewAndIsAMiss(): void
    {
        $chapter = new EntryChapter(StartStates::entryState(-9.0));

        $this->fly($chapter);

        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertGreaterThan(EntryChapter::LOAD_LIMIT, $chapter->vehicleView()['load']);
        self::assertFalse($chapter->vehicleView()['chutes']);
    }

    public function testV37ToP00StopsTheGuidanceAndTheCapsuleFliesOnWithTheLastBankAngle(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Entry, false);
        $response = $simulation->tick(new TickInput(speed: 10));
        $bank = $response['vehicle']['bank'];

        $simulation->tick(new TickInput(keys: [Key::Verb, Key::Three, Key::Seven, Key::Enter]));
        $response = $simulation->tick(new TickInput(keys: [Key::Zero, Key::Zero, Key::Enter]));

        self::assertSame('00', $response['dsky']['prog']);
        self::assertSame('P00 – No guidance (the bank angle is kept)', $response['agc']['program']);
        for ($i = 0; $i < 1500 && $response['outcome'] === null; $i++) {
            $response = $simulation->tick(new TickInput(speed: 10));
            self::assertSame($bank, $response['vehicle']['bank']);
        }
        // Held at 60 degrees the capsule still comes down (the outcome decides), but nothing steered it to the target.
        self::assertNotNull($response['outcome']);
        self::assertSame(0, $response['vehicle']['program']);
        self::assertSame('00', $response['dsky']['prog']);
    }

    public function testAnotherProgramThanP63OrP00IsOprErr(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Entry, false);

        $response = $simulation->tick(new TickInput(keys: [Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Four, Key::Zero, Key::Enter]));

        self::assertTrue($response['dsky']['lights']['oprErr']);
        self::assertNull($response['outcome']);
    }

    public function testTheSimulationStartsTheEntryAndAnswersWithTheEntryView(): void
    {
        $simulation = new Simulation();

        $response = $simulation->start(ChapterId::Entry, false);

        self::assertSame('entry', $response['chapter']);
        self::assertSame('CM', $response['computer']);
        self::assertSame('entry', $response['vehicle']['view']);
        self::assertSame('P63', $response['agc']['routine']['label']);
        self::assertSame('Comanche055/P61-P67.agc', $response['agc']['routine']['file']);
        self::assertStringContainsString('P63', $response['agc']['program']);
        self::assertSame(['63', '06', '64'], [$response['dsky']['prog'], $response['dsky']['verb'], $response['dsky']['noun']]);
    }

    public function testTheEntryReachesParachutesAtTenTimesSpeedThroughTheSimulationAndEveryTickIsFast(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Entry, false);
        $slowest = 0.0;
        $labels = [];
        $response = ['outcome' => null];
        for ($i = 0; $i < 1000 && $response['outcome'] === null; $i++) {
            $started = microtime(true);
            $response = $simulation->tick(new TickInput(speed: 10));
            $slowest = max($slowest, microtime(true) - $started);
            $labels[$response['agc']['routine']['label']] = true;
        }

        self::assertSame('success', $response['outcome']);
        self::assertTrue($response['vehicle']['chutes']);
        self::assertLessThan(12.0, $response['vehicle']['maxLoad']);
        self::assertArrayHasKey('PREDICT3', $labels);
        self::assertLessThan(0.1, $slowest, 'a tick at speed 10 stays under 100 ms');
    }

    public function testTheEntrySurvivesTheSessionSerialization(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Entry, false);
        for ($i = 0; $i < 20; $i++) {
            $simulation->tick(new TickInput(speed: 10));
        }

        $copy = unserialize(serialize($simulation));

        self::assertSame(
            $simulation->tick(new TickInput(speed: 10))['vehicle'],
            $copy->tick(new TickInput(speed: 10))['vehicle'],
        );
    }

    public function testTheEntryStateIsTheEntryInterface(): void
    {
        $state = StartStates::entryState();

        self::assertEqualsWithDelta(122000.0, $state->altitude(Body::Earth), 1e-6);
        self::assertEqualsWithDelta(11030.0, hypot($state->vx, $state->vy), 1e-6);
        self::assertEqualsWithDelta(-6.5, rad2deg(asin($state->radialVelocity() / 11030.0)), 1e-9);
    }
}
