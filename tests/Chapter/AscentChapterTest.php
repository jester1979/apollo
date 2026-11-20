<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Agc\Computer;
use Apollo\Chapter\Ascent\AscentChapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Key;
use Apollo\Dsky\NounTable;
use Apollo\Physics\Integrator;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AscentChapterTest extends TestCase
{
    /**
     * Flies the chapter at 1x (0.1 s per tick) until it ends. The crew answers the flashing V06N76 with PRO
     * after the given number of seconds (null: never) and the flashing V99 after the given number of seconds of
     * the flash (null: never). Collects what the DSKY and the view showed.
     *
     * @return array{verbs: array<string, bool>, maxAltitudeBeforeIgnition: float, descentStageMoved: bool}
     */
    private function fly(AscentChapter $chapter, ?float $targetDelay, ?float $enableDelay, int $maxTicks = 20000): array
    {
        $seen = ['verbs' => [], 'maxAltitudeBeforeIgnition' => 0.0, 'descentStageMoved' => false];
        $targetWaited = 0.0;
        $flashStart = null;
        for ($tick = 0; $tick < $maxTicks && $chapter->outcome() === null; $tick++) {
            $dsky = $chapter->agc()->dsky->toArray();
            $verb = $dsky['verb'];
            $seen['verbs'][$verb . 'N' . $dsky['noun']] = true;
            if ($dsky['flash'] && $dsky['noun'] === '76') {
                if ($targetDelay !== null && $targetWaited >= $targetDelay - 1e-9) {
                    $this->proceed($chapter);
                }
                $targetWaited += Integrator::STEP;
            }
            if ($dsky['flash'] && $verb === '99') {
                $flashStart ??= $chapter->agc()->missionTime;
                if ($enableDelay !== null && $chapter->agc()->missionTime - $flashStart >= $enableDelay - 1e-9) {
                    $this->proceed($chapter);
                }
            }
            $chapter->step(Integrator::STEP, new TickInput());
            $view = $chapter->vehicleView();
            if ($view['phase'] !== 'burn' && $view['phase'] !== 'done') {
                $seen['maxAltitudeBeforeIgnition'] = max($seen['maxAltitudeBeforeIgnition'], $view['altitude']);
            }
        }

        return $seen;
    }

    private function proceed(AscentChapter $chapter): void
    {
        $chapter->agc()->dsky->press(Key::Proceed, $chapter->allowedPrograms(), $chapter->agc()->missionTime);
    }

    public function testItStartsInP12WithTheTargetFlashing(): void
    {
        $chapter = new AscentChapter();

        self::assertNull($chapter->outcome());
        self::assertSame(ChapterId::Ascent, $chapter->id());
        self::assertSame([12, 0], $chapter->allowedPrograms());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['12', '06', '76'], [$display['prog'], $display['verb'], $display['noun']]);
        self::assertTrue($display['flash']);
        self::assertSame('+55093', $display['r1'], 'R1: VINJNOM, 5509.3 ft/s');
        self::assertSame('+00195', $display['r2'], 'R2: RDOTDNOM, 19.5 ft/s');
        self::assertSame('+00000', $display['r3'], 'R3: no cross-range');
        $view = $chapter->vehicleView();
        self::assertSame('ascent', $view['view']);
        self::assertSame('targets', $view['phase']);
        self::assertFalse($view['liftedOff']);
        self::assertSame(0.0, $view['altitude']);
        self::assertSame(1.0, $view['fuel']);
        self::assertSame('P12LM', $chapter->agc()->currentRoutine?->label());
    }

    public function testTheClockAndTheVehicleWaitForTheFirstPro(): void
    {
        $chapter = new AscentChapter();

        $chapter->step(120.0, new TickInput());

        self::assertSame(0.0, $chapter->agc()->missionTime);
        self::assertSame(['06', '76', true], [
            $chapter->agc()->dsky->toArray()['verb'],
            $chapter->agc()->dsky->toArray()['noun'],
            $chapter->agc()->dsky->toArray()['flash'],
        ]);
        self::assertNull($chapter->outcome());
    }

    public function testProOnV06N76GivesV06N74CountingDownToTig(): void
    {
        $chapter = new AscentChapter();
        $chapter->step(75.0, new TickInput()); // the crew may take its time
        $this->proceed($chapter);

        $chapter->step(1.0, new TickInput());
        $first = $chapter->agc()->dsky->toArray();
        $chapter->step(10.0, new TickInput());
        $later = $chapter->agc()->dsky->toArray();

        self::assertSame(['06', '74', false], [$first['verb'], $first['noun'], $first['flash']]);
        self::assertSame('-00 19', $first['r1'], 'TIG is 20 s after the PRO; 19 s to go');
        self::assertSame('-00 09', $later['r1']);
        self::assertSame('+00000', $first['r2'], 'R2: no yaw');
        self::assertMatchesRegularExpression('/^-\d{5}$/', $first['r3'], 'R3: the pitch after the rise, from the vertical');
        self::assertEqualsWithDelta(-50.0, (int) $first['r3'] / 100.0, 15.0);
    }

    public function testV99N74FlashesFromFiveSecondsBeforeTig(): void
    {
        $chapter = new AscentChapter();
        $this->proceed($chapter);

        $chapter->step(14.8, new TickInput());
        self::assertSame(['06', false], [$chapter->agc()->dsky->toArray()['verb'], $chapter->agc()->dsky->toArray()['flash']]);

        $chapter->step(0.4, new TickInput());
        $display = $chapter->agc()->dsky->toArray();

        self::assertSame(['99', '74', true], [$display['verb'], $display['noun'], $display['flash']]);
        self::assertSame('-00 05', $display['r1']);
        self::assertNull($chapter->outcome());
        self::assertSame(0.0, $chapter->vehicleView()['throttle'], 'no burn without PRO');
    }

    public function testTheChapterEndsInALunarOrbitAtOneTimesSpeedWithTheProsOnTime(): void
    {
        $chapter = new AscentChapter();

        $seen = $this->fly($chapter, 0.0, 0.0);

        self::assertSame(Outcome::Success, $chapter->outcome());
        $view = $chapter->vehicleView();
        self::assertTrue($view['bound']);
        self::assertGreaterThan(AscentChapter::MIN_PERILUNE, $view['perilune']);
        self::assertEqualsWithDelta(17000.0, $view['perilune'], 2000.0);
        self::assertEqualsWithDelta(53000.0, $view['apolune'], 6000.0);
        self::assertSame(0.0, $view['throttle']);
        self::assertGreaterThan(0.0, $view['fuel']);
        self::assertTrue($view['liftedOff']);
        self::assertEqualsWithDelta(StartStates::ASCENT_TARGET_ALTITUDE, $view['altitude'], 400.0);
        self::assertEqualsWithDelta(StartStates::ASCENT_COUNTDOWN + 400.0, $chapter->agc()->missionTime, 60.0);
        self::assertSame(0.0, $seen['maxAltitudeBeforeIgnition'], 'the stage stays on the surface until the APS fires');
        foreach (['06N76', '06N74', '99N74', '06N63'] as $display) {
            self::assertArrayHasKey($display, $seen['verbs'], "V{$display} was shown");
        }
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['12', '16', '63'], [$display['prog'], $display['verb'], $display['noun']], 'CUTOFF: V16N63');
        self::assertFalse($display['flash']);
        self::assertEqualsWithDelta(StartStates::ASCENT_TARGET_ALTITUDE / 0.3048, (int) $display['r3'], 1300.0, 'R3: the altitude in feet');
    }

    public function testTheBurnLeavesTheDescentStageOnTheSurfaceAndTheLogNamesTheRoutines(): void
    {
        $chapter = new AscentChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::ASCENT_COUNTDOWN - 5.0, new TickInput());
        $this->proceed($chapter);
        $chapter->step(5.0, new TickInput());
        self::assertSame('burn', $chapter->vehicleView()['phase']);

        $chapter->step(30.0, new TickInput());
        $view = $chapter->vehicleView();
        self::assertTrue($view['liftedOff']);
        self::assertGreaterThan(100.0, $view['altitude']);
        self::assertSame(1.0, $view['throttle']);
        self::assertGreaterThan(0.0, $view['x'], 'the stage moves downrange; the descent stage stays at x = 0');

        $chapter->step(500.0, new TickInput());
        self::assertSame(Outcome::Success, $chapter->outcome());
        $labels = array_column(array_filter(array_column($chapter->agc()->log->takeNew(), 'routine')), 'label');
        foreach (['P12LM', 'P12TABLE', 'V99RECYC', 'P12IGN', 'ATMAG', 'ASCENT', 'ENGOFF'] as $label) {
            self::assertContains($label, $labels);
        }
    }

    public function testGroundContactBeforeLiftOffIsNotACrash(): void
    {
        $chapter = new AscentChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::ASCENT_COUNTDOWN - 5.0, new TickInput());
        $this->proceed($chapter);
        $chapter->step(5.2, new TickInput()); // ignition, the stage is still on the surface

        $view = $chapter->vehicleView();
        self::assertSame('burn', $view['phase']);
        self::assertNull($chapter->outcome(), 'the surface is where the stage starts');
        self::assertLessThan(1.0, $view['altitude']);
    }

    /** @return iterable<string, array{float}> */
    public static function enableDelays(): iterable
    {
        // Seconds after the start of the V99 flash, 5 s before TIG: TIG is at 5 s.
        foreach ([0.0, 1.0, 5.0, 10.0, 20.0, 30.0, 35.0, 55.0] as $delay) {
            yield sprintf('PRO %d s after the flash starts (TIG%+d s)', $delay, $delay - 5) => [$delay];
        }
    }

    #[DataProvider('enableDelays')]
    public function testAHandsOffAscentSucceedsWhenTheV99ProComesLate(float $delay): void
    {
        $chapter = new AscentChapter();

        $this->fly($chapter, 3.0, $delay);

        self::assertSame(Outcome::Success, $chapter->outcome(), sprintf('V99 answered %.0f s after it started to flash', $delay));
        self::assertGreaterThan(AscentChapter::MIN_PERILUNE, $chapter->vehicleView()['perilune']);
    }

    /** @return iterable<string, array{float}> */
    public static function targetDelays(): iterable
    {
        foreach ([0.0, 12.0, 100.0, 250.0] as $delay) {
            yield sprintf('first PRO after %d s', $delay) => [$delay];
        }
    }

    #[DataProvider('targetDelays')]
    public function testTheFirstProMayComeAtAnyTime(float $delay): void
    {
        $chapter = new AscentChapter();

        $this->fly($chapter, $delay, 0.0, 30000);

        self::assertSame(Outcome::Success, $chapter->outcome());
    }

    public function testAProBeforeTigWaitsForTig(): void
    {
        $chapter = new AscentChapter();
        $this->proceed($chapter);
        $chapter->step(16.0, new TickInput()); // 4 s before TIG, V99 flashes
        $this->proceed($chapter);

        $chapter->step(3.0, new TickInput());
        self::assertSame('06', $chapter->agc()->dsky->toArray()['verb'], 'the V99 flash is cleared');
        self::assertSame(0.0, $chapter->vehicleView()['throttle'], 'the engine waits for TIG');

        $chapter->step(1.5, new TickInput());
        self::assertSame(1.0, $chapter->vehicleView()['throttle']);
        self::assertSame('burn', $chapter->vehicleView()['phase']);
    }

    public function testWithoutProOnV99ThereIsNoBurnAndTheChapterIsMissedAfterABoundedWait(): void
    {
        $chapter = new AscentChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::ASCENT_COUNTDOWN - 5.0 + AscentChapter::IGNITION_WAIT_SECONDS - 1.0, new TickInput());
        self::assertNull($chapter->outcome(), 'still waiting for PRO');
        self::assertSame(['99', true], [$chapter->agc()->dsky->toArray()['verb'], $chapter->agc()->dsky->toArray()['flash']]);

        $chapter->step(2.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['06', '74', false], [$display['verb'], $display['noun'], $display['flash']]);
        $view = $chapter->vehicleView();
        self::assertSame(0.0, $view['throttle']);
        self::assertSame(1.0, $view['fuel'], 'no propellant used');
        self::assertFalse($view['liftedOff']);
        $lines = $chapter->agc()->log->takeNew();
        $noPro = array_values(array_filter($lines, static fn (array $line): bool => str_starts_with($line['text'], 'No PRO on V99')));
        self::assertCount(1, $noPro);
        self::assertSame('agc', $noPro[0]['kind']);
    }

    public function testWithoutTheFirstProTheChapterIsMissedToo(): void
    {
        $chapter = new AscentChapter();

        $chapter->step(AscentChapter::TARGETS_WAIT_SECONDS - 1.0, new TickInput());
        self::assertNull($chapter->outcome());
        $chapter->step(2.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertSame(1.0, $chapter->vehicleView()['fuel']);
        $lines = $chapter->agc()->log->takeNew();
        $noPro = array_values(array_filter($lines, static fn (array $line): bool => str_starts_with($line['text'], 'No PRO on V06N76')));
        self::assertCount(1, $noPro);
        self::assertSame('agc', $noPro[0]['kind']);
    }

    public function testAProAfterTheWaitDoesNothing(): void
    {
        $chapter = new AscentChapter();
        $this->fly($chapter, 0.0, null, 5000);
        self::assertSame(Outcome::Missed, $chapter->outcome());

        $this->proceed($chapter);
        $chapter->step(1.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
    }

    public function testV37ToP00BeforeTheBurnEndsTheChapterMissedWithoutBurning(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Ascent, false);

        $simulation->tick(new TickInput(keys: [Key::Verb, Key::Three, Key::Seven, Key::Enter]));
        $response = $simulation->tick(new TickInput(keys: [Key::Zero, Key::Zero, Key::Enter]));

        self::assertSame('missed', $response['outcome']);
        self::assertSame('00', $response['dsky']['prog']);
        self::assertSame(0.0, $response['vehicle']['throttle']);
    }

    public function testV37ToP00DuringTheBurnStopsTheEngineAndTheOrbitDecides(): void
    {
        $chapter = new AscentChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::ASCENT_COUNTDOWN - 5.0, new TickInput());
        $this->proceed($chapter);
        $chapter->step(30.0, new TickInput());
        self::assertSame(1.0, $chapter->vehicleView()['throttle']);

        $chapter->agc()->dsky->press(Key::Verb, $chapter->allowedPrograms());
        foreach ([Key::Three, Key::Seven, Key::Enter, Key::Zero, Key::Zero, Key::Enter] as $key) {
            $chapter->agc()->dsky->press($key, $chapter->allowedPrograms());
        }
        $chapter->step(0.1, new TickInput());

        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertSame(Outcome::Missed, $chapter->outcome(), 'not in orbit');
    }

    public function testAnotherProgramThanP12OrP00IsOprErr(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Ascent, false);

        $response = $simulation->tick(new TickInput(keys: [Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Six, Key::Three, Key::Enter]));

        self::assertTrue($response['dsky']['lights']['oprErr']);
        self::assertNull($response['outcome']);
    }

    public function testTheSimulationStartsTheChapterAndTakesProThroughTheKeyPath(): void
    {
        $simulation = new Simulation();

        $start = $simulation->start(ChapterId::Ascent, false);
        self::assertSame('ascent', $start['chapter']);
        self::assertSame('LM', $start['computer']);
        self::assertSame('ascent', $start['vehicle']['view']);
        self::assertSame(['12', '06', '76'], [$start['dsky']['prog'], $start['dsky']['verb'], $start['dsky']['noun']]);
        self::assertSame('P12LM', $start['agc']['routine']['label']);
        self::assertSame('Luminary099/P12.agc', $start['agc']['routine']['file']);
        self::assertSame(35, $start['agc']['routine']['line']);
        self::assertStringStartsWith('P12', $start['agc']['program']);

        $response = $simulation->tick(new TickInput(keys: [Key::Proceed], speed: 10));

        self::assertSame(['06', '74'], [$response['dsky']['verb'], $response['dsky']['noun']]);
        $labels = array_column(array_column($response['log'], 'routine'), 'label');
        self::assertContains('PROCKEY', $labels);
    }

    public function testTheWholeAscentFliesThroughTheSimulationAtTenTimesSpeed(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Ascent, false);
        $simulation->tick(new TickInput(keys: [Key::Proceed], speed: 10));

        $pressed = false;
        $response = [];
        for ($tick = 0; $tick < 2000; $tick++) {
            $keys = [];
            if (!$pressed && $simulation->tick(new TickInput(speed: 1))['dsky']['verb'] === '99') {
                $keys = [Key::Proceed];
                $pressed = true;
            }
            $response = $simulation->tick(new TickInput(keys: $keys, speed: 10));
            if ($response['outcome'] !== null) {
                break;
            }
        }

        self::assertTrue($pressed);
        self::assertSame('success', $response['outcome']);
        self::assertSame(['16', '63'], [$response['dsky']['verb'], $response['dsky']['noun']]);
    }

    public function testTheSimulationSurvivesTheSessionSerializationMidBurn(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::Ascent, false);
        $simulation->tick(new TickInput(keys: [Key::Proceed], speed: 10));
        for ($i = 0; $i < 15; $i++) {
            $simulation->tick(new TickInput(speed: 10));
        }
        $simulation->tick(new TickInput(keys: [Key::Proceed], speed: 10));
        for ($i = 0; $i < 20; $i++) {
            $simulation->tick(new TickInput(speed: 10));
        }

        $copy = unserialize(serialize($simulation));
        self::assertGreaterThan(0.0, $simulation->tick(new TickInput(speed: 10))['vehicle']['throttle']);

        $copy->tick(new TickInput(speed: 10)); // the copy is one tick behind: bring it level
        self::assertSame(
            $simulation->tick(new TickInput(speed: 10))['vehicle'],
            $copy->tick(new TickInput(speed: 10))['vehicle'],
        );
    }

    public function testEveryNounOfTheLmCanBeKeyedInDuringTheAscent(): void
    {
        foreach (NounTable::for(Computer::Lm)->numbers() as $noun) {
            $simulation = new Simulation();
            $simulation->start(ChapterId::Ascent, false);
            $keys = [Key::Verb, Key::Zero, Key::Six, Key::Noun, ...self::digits($noun), Key::Enter];

            $response = $simulation->tick(new TickInput(keys: $keys));

            self::assertFalse($response['dsky']['lights']['oprErr'], "N$noun is known");
            self::assertSame(sprintf('%02d', $noun), $response['dsky']['noun']);
            self::assertNotSame('', $response['dsky']['r1'], "N$noun shows a value in R1");
        }
    }

    public function testNoun76And74CanBeKeyedInTheLanding(): void
    {
        foreach ([74, 76] as $noun) {
            $simulation = new Simulation();
            $simulation->start(ChapterId::Landing, false);
            $keys = [Key::Verb, Key::Zero, Key::Six, Key::Noun, ...self::digits($noun), Key::Enter];

            $response = $simulation->tick(new TickInput(keys: $keys));

            self::assertFalse($response['dsky']['lights']['oprErr']);
            self::assertSame(sprintf('%02d', $noun), $response['dsky']['noun']);
            self::assertNotSame('', $response['dsky']['r1']);
        }
    }

    public function testTheTargetOrbitIsAbout17By53Kilometres(): void
    {
        $state = new \Apollo\Physics\StateVector(
            0.0,
            \Apollo\Physics\Body::Moon->radius() + StartStates::ASCENT_TARGET_ALTITUDE,
            StartStates::ASCENT_TARGET_HORIZONTAL_SPEED,
            StartStates::ASCENT_TARGET_RADIAL_SPEED,
        );
        $conic = new \Apollo\Physics\Conic($state, \Apollo\Physics\Body::Moon);

        self::assertGreaterThan(AscentChapter::MIN_PERILUNE, $conic->periapsisAltitude());
        self::assertEqualsWithDelta(17100.0, $conic->periapsisAltitude(), 300.0);
        self::assertEqualsWithDelta(52600.0, $conic->apoapsisAltitude() ?? 0.0, 600.0);
    }

    /** @return list<Key> */
    private static function digits(int $number): array
    {
        return array_map(
            static fn (string $digit): Key => Key::from($digit),
            str_split(sprintf('%02d', $number)),
        );
    }
}
