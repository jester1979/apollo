<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Agc\Computer;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Outcome;
use Apollo\Chapter\Tei\TeiChapter;
use Apollo\Dsky\Key;
use Apollo\Dsky\NounTable;
use Apollo\Physics\Body;
use Apollo\Physics\Conic;
use Apollo\Physics\Integrator;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\Simulation;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TeiChapterTest extends TestCase
{
    private const FLASH_VERBS = ['50', '99'];

    /**
     * Flies the chapter at 1x (0.1 s per tick) until it ends. The crew answers a flashing V50 or V99 with PRO
     * after the given number of seconds of the flash (null: never). Collects what the DSKY and the view showed.
     *
     * @return array{verbs: array<string, bool>, maxTrail: int, closedBeforeBurn: bool, openAfterBurn: bool, closedEarlyInBurn: bool}
     */
    private function fly(TeiChapter $chapter, ?float $checklistDelay, ?float $enableDelay, int $maxTicks = 30000): array
    {
        $seen = ['verbs' => [], 'maxTrail' => 0, 'closedBeforeBurn' => false, 'openAfterBurn' => false, 'closedEarlyInBurn' => false];
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
                $seen['closedBeforeBurn'] = $seen['closedBeforeBurn'] || ($view['bound'] && self::isClosed($view['prediction']));
            }
            if ($view['phase'] === 'burn' && $view['bound'] && self::isClosed($view['prediction'])) {
                $seen['closedEarlyInBurn'] = true;
            }
            if ($view['phase'] === 'burn' && !$view['bound'] && !self::isClosed($view['prediction'])) {
                $seen['openAfterBurn'] = true;
            }
        }

        return $seen;
    }

    /** @param list<array{float, float}> $path */
    private static function isClosed(array $path): bool
    {
        return $path !== [] && $path[0] === $path[count($path) - 1];
    }

    private function proceed(TeiChapter $chapter): void
    {
        $chapter->agc()->dsky->press(Key::Proceed, $chapter->allowedPrograms(), $chapter->agc()->missionTime);
    }

    public function testItStartsInP40WithTheChecklistFlashingAndTheCsmAloneInACircularOrbit(): void
    {
        $chapter = new TeiChapter();

        self::assertNull($chapter->outcome());
        self::assertSame(ChapterId::TransEarthInjection, $chapter->id());
        self::assertSame(Computer::Cm, $chapter->agc()->computer);
        self::assertSame([40, 0], $chapter->allowedPrograms());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['40', '50', '25'], [$display['prog'], $display['verb'], $display['noun']]);
        self::assertTrue($display['flash']);
        self::assertSame('+00203', $display['r1']);
        $view = $chapter->vehicleView();
        self::assertSame('orbit', $view['view']);
        self::assertSame('checklist', $view['phase']);
        self::assertTrue($view['bound']);
        self::assertEqualsWithDelta(111000.0, $view['perilune'], 1.0);
        self::assertEqualsWithDelta(111000.0, $view['apolune'], 1.0);
        self::assertSame($view['prediction'][0], $view['prediction'][count($view['prediction']) - 1], 'closed path');
        self::assertSame(1.0, $view['fuel']);
    }

    public function testThePropellantAndTheDeltaVOfTheStartStateGiveAnEscapeWithRoomToSpare(): void
    {
        $vehicle = StartStates::teiVehicle();
        $capacity = $vehicle->isp * Integrator::G0 * log($vehicle->mass / $vehicle->dryMass);
        $radius = (new Conic(StartStates::teiState(), Body::Moon))->periapsisRadius();
        $escapeSpeed = sqrt(2.0 * Body::Moon->mu() / $radius);
        $orbitSpeed = sqrt(Body::Moon->mu() / $radius);

        self::assertGreaterThan(StartStates::TEI_DELTA_V * 1.1, $capacity, 'the propellant is more than the burn needs');
        self::assertGreaterThan($escapeSpeed, $orbitSpeed + StartStates::TEI_DELTA_V);
    }

    public function testTheClockAndTheVehicleWaitForTheChecklistPro(): void
    {
        $chapter = new TeiChapter();
        $before = $chapter->vehicleView();

        $chapter->step(120.0, new TickInput());

        self::assertSame(0.0, $chapter->agc()->missionTime);
        self::assertSame($before['x'], $chapter->vehicleView()['x']);
        self::assertSame('50', $chapter->agc()->dsky->toArray()['verb']);
        self::assertNull($chapter->outcome());
    }

    public function testProOnV50GivesV06N40CountingDownToTigWithTheTeiDeltaV(): void
    {
        $chapter = new TeiChapter();
        $this->proceed($chapter);

        $chapter->step(1.0, new TickInput());
        $first = $chapter->agc()->dsky->toArray();
        $chapter->step(10.0, new TickInput());
        $later = $chapter->agc()->dsky->toArray();

        self::assertSame(['06', '40', false], [$first['verb'], $first['noun'], $first['flash']]);
        self::assertSame('-00 39', $first['r1']);
        self::assertSame('-00 29', $later['r1']);
        self::assertSame(sprintf('+%05d', (int) round(StartStates::TEI_DELTA_V / 0.3048 * 10.0)), $later['r2'], 'R2: VG in tenths of ft/s');
        self::assertSame('+00000', $later['r3']);
    }

    public function testV99N40FlashesFromFiveSecondsBeforeTigAndNothingBurnsWithoutPro(): void
    {
        $chapter = new TeiChapter();
        $this->proceed($chapter);

        $chapter->step(34.8, new TickInput());
        self::assertSame(['06', false], [$chapter->agc()->dsky->toArray()['verb'], $chapter->agc()->dsky->toArray()['flash']]);

        $chapter->step(0.4, new TickInput());
        $display = $chapter->agc()->dsky->toArray();

        self::assertSame(['99', '40', true], [$display['verb'], $display['noun'], $display['flash']]);
        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
    }

    public function testTheChapterEndsOnAnEscapeTrajectoryAtOneTimesSpeedWithTheProsOnTime(): void
    {
        $chapter = new TeiChapter();

        $seen = $this->fly($chapter, 0.0, 0.0);

        self::assertSame(Outcome::Success, $chapter->outcome());
        $view = $chapter->vehicleView();
        self::assertFalse($view['bound']);
        self::assertNull($view['apolune']);
        self::assertGreaterThan(StartStates::TEI_ORBIT_ALTITUDE - 2000.0, $view['perilune']);
        self::assertSame(0.0, $view['throttle']);
        self::assertGreaterThan(0.0, $view['fuel']);
        self::assertLessThan(0.9, $view['fuel']);
        self::assertTrue($seen['closedBeforeBurn'], 'the path was a closed ellipse before the burn');
        self::assertTrue($seen['closedEarlyInBurn'], 'it was still closed at the start of the burn');
        self::assertTrue($seen['openAfterBurn'], 'the burn broke the path open');
        self::assertEqualsWithDelta(StartStates::TEI_COUNTDOWN + 150.0, $chapter->agc()->missionTime, 25.0);
        self::assertLessThanOrEqual(240, $seen['maxTrail'] + 1, 'the trail stays small');
        foreach (['50', '06', '99'] as $verb) {
            self::assertArrayHasKey($verb, $seen['verbs'], "V$verb was shown");
        }
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['40', '16', '40'], [$display['prog'], $display['verb'], $display['noun']], 'POSTBURN: V16N40');
        self::assertFalse($display['flash']);
    }

    public function testTheBurnGainsTheTargetDeltaVAndTheEnergyIsPositive(): void
    {
        $chapter = new TeiChapter();
        $this->fly($chapter, 0.0, 0.0);

        $display = $chapter->agc()->dsky->toArray();
        $gained = (int) $display['r3'] / 10.0;

        self::assertEqualsWithDelta(StartStates::TEI_DELTA_V / 0.3048, $gained, 6.0, 'R3: the delta-V gained');
        $lines = $chapter->agc()->log->takeNew();
        $labels = array_column(array_filter(array_column($lines, 'routine')), 'label');
        foreach (['P40CSM', 'TIG-5', 'STEERING', 'S40.81', 'POSTBURN'] as $label) {
            self::assertContains($label, $labels);
        }
        $texts = implode("\n", array_column($lines, 'text'));
        self::assertStringContainsString('the SPS burns prograde', $texts);
        self::assertStringContainsString('an escape trajectory', $texts);
    }

    /** @return iterable<string, array{float}> */
    public static function enableDelays(): iterable
    {
        // Seconds after the start of the V99 flash, 5 s before TIG: TIG is at 5 s, 30 s late is 35 s.
        foreach ([0.0, 1.0, 5.0, 10.0, 20.0, 35.0, 54.0] as $delay) {
            yield sprintf('PRO %d s after the flash starts (TIG%+d s)', $delay, $delay - 5) => [$delay];
        }
    }

    #[DataProvider('enableDelays')]
    public function testAV99ProUpToThirtySecondsLateStillReachesSuccess(float $delay): void
    {
        $chapter = new TeiChapter();

        $this->fly($chapter, 3.0, $delay);

        self::assertSame(Outcome::Success, $chapter->outcome(), sprintf('V99 answered %.0f s after it started to flash', $delay));
        self::assertFalse($chapter->vehicleView()['bound']);
    }

    public function testWithoutProOnV99ThereIsNoBurnAndTheChapterIsMissedAfterABoundedWait(): void
    {
        $chapter = new TeiChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::TEI_COUNTDOWN - 5.0 + TeiChapter::IGNITION_WAIT_SECONDS - 1.0, new TickInput());
        self::assertNull($chapter->outcome(), 'still waiting for PRO');
        self::assertSame(['99', true], [$chapter->agc()->dsky->toArray()['verb'], $chapter->agc()->dsky->toArray()['flash']]);

        $chapter->step(2.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['06', '40', false], [$display['verb'], $display['noun'], $display['flash']]);
        $view = $chapter->vehicleView();
        self::assertSame(0.0, $view['throttle']);
        self::assertSame(1.0, $view['fuel'], 'no propellant used');
        self::assertTrue($view['bound'], 'the vehicle is still in its lunar orbit');
        $lines = $chapter->agc()->log->takeNew();
        $texts = array_column($lines, 'text');
        self::assertContains('No PRO on V99: the ignition was not confirmed, there is no burn and the injection is missed', $texts);
        $kinds = array_column(array_filter($lines, static fn (array $line): bool => str_starts_with($line['text'], 'No PRO on V99')), 'kind');
        self::assertSame(['agc'], $kinds);
    }

    public function testWithoutProOnV50TheChapterIsMissedToo(): void
    {
        $chapter = new TeiChapter();

        $chapter->step(TeiChapter::CHECKLIST_WAIT_SECONDS - 1.0, new TickInput());
        self::assertNull($chapter->outcome());
        $chapter->step(2.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        $texts = array_column($chapter->agc()->log->takeNew(), 'text');
        self::assertContains('No PRO on V50N25: the checklist was not completed, there is no burn and the injection is missed', $texts);
    }

    public function testThePredictedOrbitIsClosedAtIgnitionAndOpensDuringTheBurn(): void
    {
        $chapter = new TeiChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::TEI_COUNTDOWN - 5.0, new TickInput());
        $this->proceed($chapter);
        $chapter->step(5.5, new TickInput()); // ignition, 0.5 s into the burn

        $early = $chapter->vehicleView();
        self::assertSame('burn', $early['phase']);
        self::assertTrue($early['bound']);
        self::assertNotNull($early['apolune']);
        self::assertSame($early['prediction'][0], $early['prediction'][count($early['prediction']) - 1], 'closed path');

        $chapter->step(100.0, new TickInput());
        $late = $chapter->vehicleView();
        self::assertSame('burn', $late['phase'], 'the engine is still burning');
        self::assertFalse($late['bound']);
        self::assertNull($late['apolune']);
        self::assertNotSame($late['prediction'][0], $late['prediction'][count($late['prediction']) - 1], 'open path');
    }

    public function testTheFlownPathIsSolidAndSmall(): void
    {
        $chapter = new TeiChapter();
        $this->fly($chapter, 0.0, 0.0);

        $view = $chapter->vehicleView();

        self::assertGreaterThan(20, count($view['trail']));
        self::assertLessThanOrEqual(241, count($view['trail']));
        $last = $view['trail'][count($view['trail']) - 1];
        self::assertEqualsWithDelta($view['x'] / 1000.0, $last[0], 0.1);
    }

    public function testV37ToP00DuringTheBurnStopsTheEngineAndTheBoundOrbitIsMissed(): void
    {
        $chapter = new TeiChapter();
        $this->proceed($chapter);
        $chapter->step(StartStates::TEI_COUNTDOWN - 5.0, new TickInput());
        $this->proceed($chapter);
        $chapter->step(8.0, new TickInput());
        self::assertSame(1.0, $chapter->vehicleView()['throttle']);

        foreach ([Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Zero, Key::Zero, Key::Enter] as $key) {
            $chapter->agc()->dsky->press($key, $chapter->allowedPrograms());
        }
        $chapter->step(0.1, new TickInput());

        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertSame(Outcome::Missed, $chapter->outcome(), 'the orbit is still bound');
    }

    public function testAnotherProgramThanP40OrP00IsOprErr(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::TransEarthInjection, false);

        $response = $simulation->tick(new TickInput(keys: [Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Six, Key::Three, Key::Enter]));

        self::assertTrue($response['dsky']['lights']['oprErr']);
        self::assertNull($response['outcome']);
    }

    public function testTheSimulationStartsTheChapterAndTakesProThroughTheKeyPath(): void
    {
        $simulation = new Simulation();

        $start = $simulation->start(ChapterId::TransEarthInjection, false);
        self::assertSame('tei', $start['chapter']);
        self::assertSame('CM', $start['computer']);
        self::assertSame('orbit', $start['vehicle']['view']);
        self::assertSame('P40CSM', $start['agc']['routine']['label']);
        self::assertStringStartsWith('P40', $start['agc']['program']);

        $response = $simulation->tick(new TickInput(keys: [Key::Proceed], speed: 10));

        self::assertSame(['06', '40'], [$response['dsky']['verb'], $response['dsky']['noun']]);
        self::assertContains('PROCKEY', array_column(array_column($response['log'], 'routine'), 'label'));
    }

    public function testTheSimulationSurvivesTheSessionSerializationMidBurn(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::TransEarthInjection, false);
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

        $copy->tick(new TickInput(speed: 10));
        self::assertSame(
            $simulation->tick(new TickInput(speed: 10))['vehicle'],
            $copy->tick(new TickInput(speed: 10))['vehicle'],
        );
    }

    public function testEveryNounOfTheCmTableHasValuesAndCanBeAskedForByTheUser(): void
    {
        $numbers = NounTable::for(Computer::Cm)->numbers();
        self::assertContains(40, $numbers);

        foreach ($numbers as $noun) {
            $simulation = new Simulation();
            $simulation->start(ChapterId::TransEarthInjection, false);
            $keys = [Key::Verb, Key::Zero, Key::Six, Key::Noun];
            foreach (str_split(sprintf('%02d', $noun)) as $digit) {
                $keys[] = Key::from($digit);
            }
            $keys[] = Key::Enter;

            $response = $simulation->tick(new TickInput(keys: $keys));

            self::assertSame(sprintf('%02d', $noun), $response['dsky']['noun'], "noun $noun is shown");
            self::assertFalse($response['dsky']['lights']['oprErr'], "noun $noun is accepted");
        }
    }

    public function testNoun40ShowsTheTeiDeltaVInR2(): void
    {
        $simulation = new Simulation();
        $simulation->start(ChapterId::TransEarthInjection, false);

        $response = $simulation->tick(new TickInput(keys: [Key::Verb, Key::Zero, Key::Six, Key::Noun, Key::Four, Key::Zero, Key::Enter]));

        self::assertSame(['06', '40'], [$response['dsky']['verb'], $response['dsky']['noun']]);
        self::assertSame(sprintf('+%05d', (int) round(StartStates::TEI_DELTA_V / 0.3048 * 10.0)), $response['dsky']['r2']);
    }
}
