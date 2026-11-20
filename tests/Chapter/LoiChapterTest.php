<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Loi\LoiChapter;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Key;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoiChapterTest extends TestCase
{
    private const FLASH_VERBS = ['50', '99'];

    /**
     * Flies the chapter at 1x (0.1 s per tick) until it ends. The crew answers a flashing V50 or V99 with PRO
     * after the given number of seconds of the flash (null: never). Collects what the DSKY and the view showed.
     *
     * @return array{verbs: array<string, bool>, maxTrail: int, boundDuringBurn: bool, openBeforeBurn: bool}
     */
    private function fly(LoiChapter $chapter, ?float $checklistDelay, ?float $enableDelay, int $maxTicks = 30000): array
    {
        $seen = ['verbs' => [], 'maxTrail' => 0, 'boundDuringBurn' => false, 'openBeforeBurn' => false];
        $flashStart = [];
        for ($tick = 0; $tick < $maxTicks && $chapter->outcome() === null; $tick++) {
            $dsky = $chapter->agc()->dsky->toArray();
            $verb = $dsky['verb'];
            $seen['verbs'][$verb] = true;
            if ($dsky['flash'] && in_array($verb, self::FLASH_VERBS, true)) {
                $flashStart[$verb] ??= [$tick, $chapter->agc()->missionTime];
                $delay = $verb === '50' ? $checklistDelay : $enableDelay;
                $waited = $verb === '50' ? ($tick - $flashStart[$verb][0]) * Integrator::STEP : $chapter->agc()->missionTime - $flashStart[$verb][1];
                if ($delay !== null && $waited >= $delay - 1e-9) {
                    $chapter->agc()->dsky->press(Key::Proceed, $chapter->allowedPrograms(), $chapter->agc()->missionTime);
                }
            }
            $chapter->step(Integrator::STEP, new TickInput());
            $view = $chapter->vehicleView();
            $seen['maxTrail'] = max($seen['maxTrail'], count($view['trail']));
            if ($view['phase'] === 'countdown') {
                $seen['openBeforeBurn'] = $seen['openBeforeBurn'] || !$view['bound'];
            }
            if ($view['phase'] === 'burn' && $view['bound']) {
                $seen['boundDuringBurn'] = true;
            }
        }

        return $seen;
    }

    private function proceed(LoiChapter $chapter): void
    {
        $chapter->agc()->dsky->press(Key::Proceed, $chapter->allowedPrograms(), $chapter->agc()->missionTime);
    }

    public function testItStartsInP40WithTheChecklistFlashing(): void
    {
        $chapter = new LoiChapter();

        self::assertNull($chapter->outcome());
        self::assertSame(ChapterId::LunarOrbitInsertion, $chapter->id());
        self::assertSame([40, 0], $chapter->allowedPrograms());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['40', '50', '25'], [$display['prog'], $display['verb'], $display['noun']]);
        self::assertTrue($display['flash']);
        self::assertSame('+00203', $display['r1'], 'R1 = 00203, the checklist code: switch to CMC-AUTO');
        $view = $chapter->vehicleView();
        self::assertSame('orbit', $view['view']);
        self::assertSame('checklist', $view['phase']);
        self::assertFalse($view['bound'], 'the vehicle is on a hyperbola');
        self::assertGreaterThan(100000.0, $view['altitude']);
    }

    public function testTheClockAndTheVehicleWaitForTheChecklistPro(): void
    {
        $chapter = new LoiChapter();
        $before = $chapter->vehicleView();

        $chapter->step(120.0, new TickInput());

        self::assertSame(0.0, $chapter->agc()->missionTime);
        self::assertSame($before['x'], $chapter->vehicleView()['x']);
        self::assertSame('50', $chapter->agc()->dsky->toArray()['verb']);
        self::assertNull($chapter->outcome());
    }

    public function testProOnV50GivesV06N40CountingDownToTig(): void
    {
        $chapter = new LoiChapter();
        $chapter->step(75.0, new TickInput()); // the crew may take its time
        $this->proceed($chapter);

        $chapter->step(1.0, new TickInput());
        $first = $chapter->agc()->dsky->toArray();
        $chapter->step(10.0, new TickInput());
        $later = $chapter->agc()->dsky->toArray();

        self::assertSame(['06', '40', false], [$first['verb'], $first['noun'], $first['flash']]);
        self::assertSame('-00 39', $first['r1'], 'TIG is 40 s after the PRO; 39 s to go');
        self::assertSame('-00 29', $later['r1']);
        self::assertSame('+24606', $later['r2'], 'R2: VG, 750 m/s in tenths of ft/s');
        self::assertSame('+00000', $later['r3'], 'R3: nothing gained yet');
    }

    public function testV99N40FlashesFromFiveSecondsBeforeTig(): void
    {
        $chapter = new LoiChapter();
        $this->proceed($chapter);

        $chapter->step(34.8, new TickInput());
        self::assertSame(['06', false], [$chapter->agc()->dsky->toArray()['verb'], $chapter->agc()->dsky->toArray()['flash']]);

        $chapter->step(0.4, new TickInput());
        $display = $chapter->agc()->dsky->toArray();

        self::assertSame(['99', '40', true], [$display['verb'], $display['noun'], $display['flash']]);
        self::assertSame('-00 05', $display['r1']);
        self::assertNull($chapter->outcome());
        self::assertSame(0.0, $chapter->vehicleView()['throttle'], 'no burn without PRO');
    }

    public function testTheChapterEndsInABoundOrbitAtOneTimesSpeedWithTheProsOnTime(): void
    {
        $chapter = new LoiChapter();

        $seen = $this->fly($chapter, 0.0, 0.0);

        self::assertSame(Outcome::Success, $chapter->outcome());
        $view = $chapter->vehicleView();
        self::assertTrue($view['bound']);
        self::assertGreaterThan(LoiChapter::MIN_PERILUNE, $view['perilune']);
        self::assertGreaterThan(80000.0, $view['perilune']);
        self::assertLessThan(500000.0, $view['apolune']);
        self::assertSame(0.0, $view['throttle']);
        self::assertGreaterThan(0.0, $view['fuel']);
        self::assertTrue($seen['openBeforeBurn'], 'the path was open before the burn');
        self::assertTrue($seen['boundDuringBurn'], 'the burn closed the path');
        self::assertEqualsWithDelta(StartStates::LOI_COUNTDOWN + 320.0, $chapter->agc()->missionTime, 15.0);
        self::assertLessThanOrEqual(240, $seen['maxTrail'] + 1, 'the trail stays small');
        foreach (['50', '06', '99'] as $verb) {
            self::assertArrayHasKey($verb, $seen['verbs'], "V$verb was shown");
        }
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['40', '16', '40'], [$display['prog'], $display['verb'], $display['noun']], 'POSTBURN: V16N40');
        self::assertSame('00 00', $display['r1'], 'R1: time from cutoff');
        self::assertFalse($display['flash']);
    }

    public function testTheBurnGainsTheTargetDeltaVAndTheEngineGoesOffInAFinalTick(): void
    {
        $chapter = new LoiChapter();
        $this->fly($chapter, 0.0, 0.0);

        $display = $chapter->agc()->dsky->toArray();
        $gained = (int) $display['r3'] / 10.0; // ft/s
        $remaining = abs((int) $display['r2']) / 10.0;

        self::assertEqualsWithDelta(StartStates::LOI_DELTA_V / 0.3048, $gained, 6.0, 'R3: the delta-V gained');
        self::assertLessThan(3.0, $remaining, 'R2: VG is as good as gone');
        $lines = $chapter->agc()->log->takeNew();
        $labels = array_column(array_filter(array_column($lines, 'routine')), 'label');
        foreach (['P40CSM', 'TIG-5', 'STEERING', 'S40.81', 'POSTBURN'] as $label) {
            self::assertContains($label, $labels);
        }
    }

    /** @return iterable<string, array{float}> */
    public static function enableDelays(): iterable
    {
        // Seconds after the start of the V99 flash, 5 s before TIG: TIG is at 5 s.
        foreach ([0.0, 1.0, 5.0, 10.0, 20.0, 35.0, 54.0] as $delay) {
            yield sprintf('PRO %d s after the flash starts (TIG%+d s)', $delay, $delay - 5) => [$delay];
        }
    }

    #[DataProvider('enableDelays')]
    public function testAHandsOffInsertionSucceedsWhenTheV99ProComesLate(float $delay): void
    {
        $chapter = new LoiChapter();

        $this->fly($chapter, 3.0, $delay);

        self::assertSame(Outcome::Success, $chapter->outcome(), sprintf('V99 answered %.0f s after it started to flash', $delay));
        self::assertGreaterThan(LoiChapter::MIN_PERILUNE, $chapter->vehicleView()['perilune']);
    }

    public function testAProBeforeTigWaitsForTig(): void
    {
        $chapter = new LoiChapter();
        $this->proceed($chapter);
        $chapter->step(36.0, new TickInput()); // 4 s before TIG, V99 flashes
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
        $chapter = new LoiChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::LOI_COUNTDOWN - 5.0 + LoiChapter::IGNITION_WAIT_SECONDS - 1.0, new TickInput());
        self::assertNull($chapter->outcome(), 'still waiting for PRO');
        self::assertSame(['99', true], [$chapter->agc()->dsky->toArray()['verb'], $chapter->agc()->dsky->toArray()['flash']]);

        $chapter->step(2.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['06', '40', false], [$display['verb'], $display['noun'], $display['flash']], 'no burn: no POSTBURN display');
        $view = $chapter->vehicleView();
        self::assertSame(0.0, $view['throttle']);
        self::assertSame(1.0, $view['fuel'], 'no propellant used');
        self::assertFalse($view['bound'], 'the vehicle flew by on its hyperbola');
        $lines = $chapter->agc()->log->takeNew();
        $texts = array_column($lines, 'text');
        self::assertContains('No PRO on V99: the ignition was not confirmed, there is no burn and the insertion is missed', $texts);
        $kinds = array_column(array_filter($lines, static fn (array $line): bool => str_starts_with($line['text'], 'No PRO on V99')), 'kind');
        self::assertSame(['agc'], $kinds);
    }

    public function testAProAfterTheWaitDoesNothing(): void
    {
        $chapter = new LoiChapter();
        $this->fly($chapter, 0.0, null);
        self::assertSame(Outcome::Missed, $chapter->outcome());

        $this->proceed($chapter);
        $chapter->step(1.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
    }

    public function testWithoutProOnV50TheChapterIsMissedToo(): void
    {
        $chapter = new LoiChapter();

        $chapter->step(LoiChapter::CHECKLIST_WAIT_SECONDS - 1.0, new TickInput());
        self::assertNull($chapter->outcome());
        $chapter->step(2.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertSame(1.0, $chapter->vehicleView()['fuel']);
        $texts = array_column($chapter->agc()->log->takeNew(), 'text');
        self::assertContains('No PRO on V50N25: the checklist was not completed, there is no burn and the insertion is missed', $texts);
    }

    public function testThePredictedOrbitClosesIntoAnEllipseDuringTheBurn(): void
    {
        $chapter = new LoiChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::LOI_COUNTDOWN - 5.0, new TickInput());
        $this->proceed($chapter);
        $chapter->step(5.5, new TickInput()); // ignition, 0.5 s into the burn

        $early = $chapter->vehicleView();
        self::assertSame('burn', $early['phase']);
        self::assertFalse($early['bound']);
        self::assertNull($early['apolune']);
        self::assertNotSame($early['prediction'][0], $early['prediction'][count($early['prediction']) - 1], 'open path');

        $chapter->step(60.0, new TickInput());
        $late = $chapter->vehicleView();
        self::assertTrue($late['bound']);
        self::assertSame($late['prediction'][0], $late['prediction'][count($late['prediction']) - 1], 'closed path');
        self::assertGreaterThan(1.0, $late['throttle'] + 1.0, 'the engine is still burning');
        self::assertNotNull($late['apolune']);
    }

    public function testTheFlownPathIsSolidAndSmall(): void
    {
        $chapter = new LoiChapter();
        $this->fly($chapter, 0.0, 0.0);

        $view = $chapter->vehicleView();

        self::assertGreaterThan(20, count($view['trail']));
        self::assertLessThanOrEqual(241, count($view['trail']));
        $last = $view['trail'][count($view['trail']) - 1];
        self::assertEqualsWithDelta($view['x'] / 1000.0, $last[0], 0.1, 'the path ends at the vehicle');
    }

    public function testV37ToP00BeforeTheBurnEndsTheChapterMissedWithoutBurning(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::LunarOrbitInsertion, false);

        $simulation->tick(new TickInput(keys: [Key::Verb, Key::Three, Key::Seven, Key::Enter]));
        $response = $simulation->tick(new TickInput(keys: [Key::Zero, Key::Zero, Key::Enter]));

        self::assertSame('missed', $response['outcome']);
        self::assertSame('00', $response['dsky']['prog']);
        self::assertSame(0.0, $response['vehicle']['throttle']);
    }

    public function testV37ToP00DuringTheBurnStopsTheEngine(): void
    {
        $chapter = new LoiChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::LOI_COUNTDOWN - 5.0, new TickInput());
        $this->proceed($chapter);
        $chapter->step(8.0, new TickInput());
        self::assertSame(1.0, $chapter->vehicleView()['throttle']);

        $chapter->agc()->dsky->press(Key::Verb, $chapter->allowedPrograms());
        foreach ([Key::Three, Key::Seven, Key::Enter, Key::Zero, Key::Zero, Key::Enter] as $key) {
            $chapter->agc()->dsky->press($key, $chapter->allowedPrograms());
        }
        $chapter->step(0.1, new TickInput());

        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertSame(Outcome::Missed, $chapter->outcome(), 'the orbit was still open');
    }

    public function testAnotherProgramThanP40OrP00IsOprErr(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::LunarOrbitInsertion, false);

        $response = $simulation->tick(new TickInput(keys: [Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Six, Key::Three, Key::Enter]));

        self::assertTrue($response['dsky']['lights']['oprErr']);
        self::assertNull($response['outcome']);
    }

    public function testTheSimulationStartsTheChapterAndTakesProThroughTheKeyPath(): void
    {
        $simulation = new Simulation();

        $start = $simulation->start(ChapterId::LunarOrbitInsertion, false);
        self::assertSame('loi', $start['chapter']);
        self::assertSame('CM', $start['computer']);
        self::assertSame('orbit', $start['vehicle']['view']);
        self::assertSame(['40', '50', '25'], [$start['dsky']['prog'], $start['dsky']['verb'], $start['dsky']['noun']]);
        self::assertSame('P40CSM', $start['agc']['routine']['label']);
        self::assertSame('Comanche055/P40-P47.agc', $start['agc']['routine']['file']);
        self::assertStringStartsWith('P40', $start['agc']['program']);

        $response = $simulation->tick(new TickInput(keys: [Key::Proceed], speed: 10));

        self::assertSame(['06', '40'], [$response['dsky']['verb'], $response['dsky']['noun']]);
        $labels = array_column(array_column($response['log'], 'routine'), 'label');
        self::assertContains('PROCKEY', $labels);
    }

    public function testTheSimulationSurvivesTheSessionSerializationMidBurn(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::LunarOrbitInsertion, false);
        $simulation->tick(new TickInput(keys: [Key::Proceed], speed: 10));
        for ($i = 0; $i < 45; $i++) {
            $simulation->tick(new TickInput(speed: 10));
        }
        $simulation->tick(new TickInput(keys: [Key::Proceed], speed: 10));
        for ($i = 0; $i < 10; $i++) {
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

    public function testTheStartStateIsAnOpenApproachWithPerilunePassingAbout110Km(): void
    {
        $state = StartStates::loiState();
        $conic = new \Apollo\Physics\Conic($state, Body::Moon);

        self::assertFalse($conic->isBound());
        self::assertEqualsWithDelta(110000.0, $conic->periapsisAltitude(), 3000.0);
    }

    public function testNoun40AndNoun25CanBeAskedForByTheUser(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::LunarOrbitInsertion, false);

        $response = $simulation->tick(new TickInput(keys: [Key::Verb, Key::Zero, Key::Six, Key::Noun, Key::Four, Key::Zero, Key::Enter]));

        self::assertSame(['06', '40'], [$response['dsky']['verb'], $response['dsky']['noun']]);
        self::assertSame('+24606', $response['dsky']['r2'], 'VG in tenths of ft/s');
        self::assertFalse($response['dsky']['lights']['oprErr']);
    }
}
