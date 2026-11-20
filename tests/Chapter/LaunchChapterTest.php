<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Launch\LaunchChapter;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Key;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\TickInput;
use Apollo\Trace\Routine;
use PHPUnit\Framework\TestCase;

final class LaunchChapterTest extends TestCase
{
    /**
     * Flies the chapter at 1x (0.1 s per tick) until it ends, collecting what the view shows on the way.
     *
     * @return array{stages: array<int, bool>, sicSeparated: bool, siiSeparated: bool, maxAltitude: float, verbs: array<string, bool>, nouns: array<string, bool>}
     */
    private function fly(LaunchChapter $chapter): array
    {
        $seen = ['stages' => [], 'sicSeparated' => false, 'siiSeparated' => false, 'maxAltitude' => 0.0, 'verbs' => [], 'nouns' => []];
        for ($i = 0; $i < 20000 && $chapter->outcome() === null; $i++) {
            $chapter->step(Integrator::STEP, new TickInput());
            $view = $chapter->vehicleView();
            $seen['stages'][$view['stage']] = true;
            $seen['sicSeparated'] = $seen['sicSeparated'] || $view['sicSeparated'] !== null;
            $seen['siiSeparated'] = $seen['siiSeparated'] || $view['siiSeparated'] !== null;
            $seen['maxAltitude'] = max($seen['maxAltitude'], $view['altitude']);
            $dsky = $chapter->agc()->dsky->toArray();
            $seen['verbs'][$dsky['verb']] = true;
            $seen['nouns'][$dsky['noun']] = true;
        }

        return $seen;
    }

    public function testItStartsOnThePadInP11WithV06N62(): void
    {
        $chapter = new LaunchChapter();

        self::assertNull($chapter->outcome());
        self::assertSame(ChapterId::Launch, $chapter->id());
        self::assertSame([11, 0], $chapter->allowedPrograms());
        self::assertSame(11, $chapter->agc()->majorMode);
        self::assertSame(Routine::Vhhdot, $chapter->agc()->currentRoutine);
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['11', '06', '62'], [$display['prog'], $display['verb'], $display['noun']]);
        // VI at the pad is the speed of the pad with the Earth: 409 m/s is 1341 ft/s; no climb; no altitude.
        self::assertSame(['+01341', '+00000', '+00000'], [$display['r1'], $display['r2'], $display['r3']]);
        $view = $chapter->vehicleView();
        self::assertSame('launch', $view['view']);
        self::assertSame(0.0, $view['altitude']);
        self::assertSame(1, $view['stage']);
    }

    public function testTheLogSaysThatTheRocketIsFlownByTheLvdcWhichIsNotInTheSource(): void
    {
        $lines = (new LaunchChapter())->agc()->log->takeNew();

        self::assertCount(1, $lines);
        self::assertStringContainsString('LVDC', $lines[0]['text']);
        self::assertStringContainsString('not in this source', $lines[0]['text']);
        self::assertSame('P11', $lines[0]['routine']['label'] ?? null);
    }

    public function testTheChapterEndsInAnOrbitWithPerigeeAbove150KmAtOneTimesSpeed(): void
    {
        $chapter = new LaunchChapter();

        $seen = $this->fly($chapter);

        self::assertSame(Outcome::Success, $chapter->outcome());
        $view = $chapter->vehicleView();
        self::assertGreaterThan(150000.0, $view['perigee']);
        self::assertSame(3, $view['stage']);
        self::assertSame(0.0, $view['throttle'], 'the S-IVB cut off');
        self::assertGreaterThan(7500.0, $view['speed'], 'orbital speed');
        self::assertGreaterThan(150000.0, $view['altitude']);
        self::assertSame([1 => true, 2 => true, 3 => true], $seen['stages']);
        self::assertTrue($seen['sicSeparated'], 'the S-IC dropped away');
        self::assertTrue($seen['siiSeparated'], 'the S-II dropped away');
        self::assertEqualsWithDelta(675.0, $chapter->agc()->missionTime, 60.0, 'about 11 minutes');
    }

    public function testTheDskyShowsV06N62ThroughoutAndTheRegistersFollowTheRocket(): void
    {
        $chapter = new LaunchChapter();
        $first = $chapter->agc()->dsky->toArray();

        $seen = $this->fly($chapter);
        $last = $chapter->agc()->dsky->toArray();

        self::assertSame(['06' => true], $seen['verbs']);
        self::assertSame(['62' => true], $seen['nouns']);
        self::assertSame('11', $last['prog']);
        self::assertGreaterThan((int) $first['r1'], (int) $last['r1']);
        self::assertGreaterThan(20000, (int) $last['r1'], 'VI in ft/s at orbit (more than 7 km/s)');
        self::assertGreaterThan(1000, (int) $last['r3'], 'H in tenths of nautical miles at about 100 nmi');
    }

    public function testVhhdotRefreshesTheDisplayEveryTwoSeconds(): void
    {
        $chapter = new LaunchChapter();
        $chapter->step(2.0, new TickInput()); // VHHDOT ran at 2.0 s
        $first = $chapter->agc()->dsky->toArray()['r2'];

        $chapter->step(1.9, new TickInput());
        self::assertSame($first, $chapter->agc()->dsky->toArray()['r2'], 'no refresh before 4 s');

        $chapter->step(0.1, new TickInput());
        self::assertNotSame($first, $chapter->agc()->dsky->toArray()['r2'], 'HDOT has grown since 2 s');
    }

    public function testTheStackRisesAndStagesInOrderWithTheSeparationInTheView(): void
    {
        $chapter = new LaunchChapter();

        $chapter->step(20.0, new TickInput());
        $view = $chapter->vehicleView();
        self::assertGreaterThan(100.0, $view['altitude']);
        self::assertLessThan(90.0 * M_PI / 180.0, $view['pitch'], 'pitching over after the tower');
        self::assertNull($view['sicSeparated']);

        $chapter->step(150.0, new TickInput()); // S-IC burns out at about 163 s
        $view = $chapter->vehicleView();
        self::assertSame(2, $view['stage']);
        self::assertGreaterThan(0.0, $view['sicSeparated']);
        self::assertLessThan(10.0, $view['sicSeparated']);
        self::assertNull($view['siiSeparated']);
    }

    public function testASeparatedStageStaysInTheViewForAWhileOnly(): void
    {
        $chapter = new LaunchChapter();
        $chapter->step(190.0, new TickInput());
        self::assertNotNull($chapter->vehicleView()['sicSeparated']);

        $chapter->step(40.0, new TickInput());

        self::assertNull($chapter->vehicleView()['sicSeparated']);
    }

    public function testFallingBackToTheGroundIsAMiss(): void
    {
        // 3 km up and falling at 400 m/s: the thrust of the stack cannot turn that around.
        $chapter = new LaunchChapter(new StateVector(0.0, Body::Earth->radius() + 3000.0, 0.0, -400.0));

        $chapter->step(6.0, new TickInput());
        self::assertNull($chapter->outcome(), 'still in the air');

        for ($i = 0; $i < 600 && $chapter->outcome() === null; $i++) {
            $chapter->step(Integrator::STEP, new TickInput());
        }

        self::assertSame(Outcome::Missed, $chapter->outcome());
    }

    public function testV37ToP00EndsTheChapterWithAMiss(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Launch, false);

        $response = $simulation->tick(new TickInput(keys: [Key::Verb, Key::Three, Key::Seven, Key::Enter]));
        self::assertNull($response['outcome']);
        $response = $simulation->tick(new TickInput(keys: [Key::Zero, Key::Zero, Key::Enter]));

        self::assertSame('missed', $response['outcome']);
        self::assertSame('00', $response['dsky']['prog']);
    }

    public function testAnotherProgramThanP11OrP00IsOprErr(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Launch, false);

        $response = $simulation->tick(new TickInput(keys: [Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Four, Key::Zero, Key::Enter]));

        self::assertTrue($response['dsky']['lights']['oprErr']);
        self::assertNull($response['outcome']);
    }

    public function testTheSimulationStartsTheLaunchAndAnswersWithTheLaunchView(): void
    {
        $simulation = new Simulation();

        $response = $simulation->start(ChapterId::Launch, false);

        self::assertSame('launch', $response['chapter']);
        self::assertSame('CM', $response['computer']);
        self::assertSame('launch', $response['vehicle']['view']);
        self::assertSame('VHHDOT', $response['agc']['routine']['label']);
        self::assertSame('Comanche055/P11.agc', $response['agc']['routine']['file']);
        self::assertStringContainsString('LVDC', $response['agc']['program']);
        self::assertStringContainsString('not in the source', $response['agc']['program']);
        self::assertSame(['11', '06', '62'], [$response['dsky']['prog'], $response['dsky']['verb'], $response['dsky']['noun']]);
    }

    public function testTheLaunchSurvivesTheSessionSerialization(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Launch, false);
        $simulation->tick(new TickInput(speed: 10));

        $copy = unserialize(serialize($simulation));

        self::assertSame(
            $simulation->tick(new TickInput(speed: 10))['vehicle'],
            $copy->tick(new TickInput(speed: 10))['vehicle'],
        );
    }

    public function testTheLaunchStateStartsWithTheSpeedOfThePad(): void
    {
        $state = StartStates::launchState();

        self::assertEqualsWithDelta(408.0, $state->horizontalVelocity(), 1.0);
        self::assertEqualsWithDelta(0.0, $state->altitude(Body::Earth), 1e-6);
    }
}
