<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Key;
use Apollo\Dsky\Light;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;
use Apollo\Tests\Support\LandingBurn;
use Apollo\Trace\Routine;
use PHPUnit\Framework\TestCase;

final class LandingChapterTest extends TestCase
{
    public function testItStartsAtPdiAltitudeAndSpeedWithTheEngineOffAndNoOutcome(): void
    {
        $chapter = new LandingChapter();

        self::assertNull($chapter->outcome());
        self::assertEqualsWithDelta(StartStates::LANDING_ALTITUDE, $chapter->vehicleView()['altitude'], 0.01);
        self::assertEqualsWithDelta(1690.0, $chapter->vehicleView()['vx'], 0.01);
        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertSame(1.0, $chapter->vehicleView()['fuel']);
        self::assertSame(63, $chapter->agc()->majorMode);
    }

    public function testFuelRunsDownWhileTheEngineBurns(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);

        $chapter->step(10.0, new TickInput());

        self::assertLessThan(1.0, $chapter->vehicleView()['fuel']);
    }

    public function testContactAtTooHighASpeedIsACrash(): void
    {
        // 100 m above the surface, still inside the ignition phase (no guidance yet): a free fall.
        $chapter = new LandingChapter(new StateVector(0.0, Body::Moon->radius() + 100.0, 0.0, 0.0));
        $ticks = 0;

        while ($chapter->outcome() === null) {
            $chapter->step(Integrator::STEP, new TickInput());
            self::assertLessThan(500, ++$ticks, 'the lander must reach the surface');
        }

        self::assertSame(Outcome::Crashed, $chapter->outcome());
        self::assertSame(0.0, $chapter->vehicleView()['altitude']);
    }

    public function testAFinishedChapterDoesNotMoveAnyMore(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        $chapter->step(2000.0, new TickInput());
        $time = $chapter->agc()->missionTime;

        $chapter->step(10.0, new TickInput(rod: 3));

        self::assertSame(Outcome::Success, $chapter->outcome());
        self::assertSame($time, $chapter->agc()->missionTime);
    }

    public function testSpeedMultipliesTheSimulatedTimePerTick(): void
    {
        $chapter = new LandingChapter();

        $chapter->step(Integrator::STEP * 10, new TickInput(speed: 10));

        self::assertEqualsWithDelta(1.0, $chapter->agc()->missionTime, 1e-9);
    }

    public function testTheDskyShowsV06N63WithRegistersInFeetFromTheStart(): void
    {
        $display = (new LandingChapter())->agc()->dsky->toArray();

        self::assertSame('63', $display['prog']);
        self::assertSame('06', $display['verb']);
        self::assertSame('63', $display['noun']);
        // 1690 m/s horizontal speed is 5544.6 ft/s; no altitude rate; 15240 m is 50000 ft.
        self::assertSame('+55446', $display['r1']);
        self::assertSame('+00000', $display['r2']);
        self::assertSame('+50000', $display['r3']);
    }

    public function testTheRegistersFollowTheVehicleEvery2Seconds(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        $chapter->step(1.0, new TickInput()); // ignition at 5 s, a refresh at 6 s, the next at 8 s
        $first = $chapter->agc()->dsky->toArray()['r1'];

        $chapter->step(1.9, new TickInput());
        self::assertSame($first, $chapter->agc()->dsky->toArray()['r1'], 'no refresh before 2 s');

        $chapter->step(0.1, new TickInput());
        self::assertNotSame($first, $chapter->agc()->dsky->toArray()['r1']);
    }

    public function testCompActyIsOffUntilTheGuidanceRunsAndLightsWhileItComputes(): void
    {
        $chapter = new LandingChapter();
        $dsky = $chapter->agc()->dsky;
        LandingBurn::proceed($chapter);

        $chapter->step(26.0 - 0.1, new TickInput());
        self::assertFalse($dsky->isLit(Light::CompActy), 'the engine still burns at minimum, no guidance yet');

        $chapter->step(0.2, new TickInput()); // crosses the first guidance pass at 26 s
        self::assertTrue($dsky->isLit(Light::CompActy));

        $chapter->step(0.5, new TickInput());
        self::assertFalse($dsky->isLit(Light::CompActy), 'dark again between passes');

        $chapter->step(1.5, new TickInput()); // the next pass at 28 s
        self::assertTrue($dsky->isLit(Light::CompActy));
    }

    public function testTheDskyShowsV06N64InP64AndV06N60InP65AndP66(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        while ($chapter->agc()->majorMode === 63) {
            $chapter->step(1.0, new TickInput(speed: 10));
        }
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['64', '06', '64', false], [$display['prog'], $display['verb'], $display['noun'], $display['flash']]);

        while ($chapter->agc()->majorMode === 64) {
            $chapter->step(1.0, new TickInput(speed: 10));
        }
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['65', '06', '60'], [$display['prog'], $display['verb'], $display['noun']]);

        $chapter->step(1.0, new TickInput(rod: 1));
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['66', '06', '60'], [$display['prog'], $display['verb'], $display['noun']]);
    }

    public function testInN60R2ShowsTheAltitudeRateInTenthsOfFeetPerSecond(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        while ($chapter->agc()->majorMode < 65) {
            $chapter->step(1.0, new TickInput(speed: 10));
        }
        $chapter->step(60.0, new TickInput());

        // P65 has settled at 3 ft/s down: xxxx.x ft/s, so R2 is -30.
        self::assertEqualsWithDelta(-30, (int) $chapter->agc()->dsky->toArray()['r2'], 1);
    }

    /** @param list<Key> $keys */
    private function press(LandingChapter $chapter, array $keys): void
    {
        foreach ($keys as $key) {
            $chapter->agc()->dsky->press($key, $chapter->allowedPrograms());
        }
    }

    public function testV99N63FlashesFromTigMinus5SecondsAndNotBefore(): void
    {
        $chapter = new LandingChapter();

        $chapter->step(4.9, new TickInput());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['06', '63', false], [$display['verb'], $display['noun'], $display['flash']]);

        $chapter->step(0.1, new TickInput());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['99', '63', true], [$display['verb'], $display['noun'], $display['flash']]);

        $chapter->step(30.0, new TickInput());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['99', '63', true], [$display['verb'], $display['noun'], $display['flash']], 'it keeps flashing after TIG');
    }

    public function testWithoutProThereIsNoIgnition(): void
    {
        $chapter = new LandingChapter();

        $chapter->step(120.0, new TickInput());

        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertSame(1.0, $chapter->vehicleView()['fuel']);
        self::assertNull($chapter->outcome());
    }

    public function testProBeforeV99AsksForItDoesNothing(): void
    {
        $chapter = new LandingChapter();
        $chapter->step(2.0, new TickInput());

        $this->press($chapter, [Key::Proceed]);
        $chapter->step(8.0, new TickInput());

        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertSame('99', $chapter->agc()->dsky->toArray()['verb'], 'V99 still waits for its answer');
    }

    public function testProIgnitesTheEngineAndTheDisplayReturnsToV06N63(): void
    {
        $chapter = new LandingChapter();
        $chapter->step(7.0, new TickInput());

        $this->press($chapter, [Key::Proceed]);
        $chapter->step(0.1, new TickInput());

        self::assertSame(0.1, $chapter->vehicleView()['throttle']);
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['06', '63', false], [$display['verb'], $display['noun'], $display['flash']]);
        self::assertSame(Routine::BurnBaby, $chapter->agc()->currentRoutine);
    }

    public function testV16N68RefreshesEverySecondAndKeyRelBringsV06N63Back(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        $chapter->step(1.0, new TickInput());

        $this->press($chapter, [Key::Verb, Key::One, Key::Six, Key::Noun, Key::Six, Key::Eight, Key::Enter]);
        $chapter->step(0.1, new TickInput());
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['16', '68'], [$display['verb'], $display['noun']]);
        self::assertTrue($chapter->agc()->dsky->isLit(Light::KeyRel));
        self::assertMatchesRegularExpression('/^\+\d{5}$/', $display['r1']);
        self::assertMatchesRegularExpression('/^\d\d \d\d$/', $display['r2']);

        // Time to go (R2) counts down in whole seconds: it must differ from one second to the next
        // and must not move in between.
        $previous = $display['r2'];
        for ($second = 0; $second < 4; $second++) {
            $chapter->step(0.5, new TickInput());
            self::assertSame($previous, $chapter->agc()->dsky->toArray()['r2'], 'no refresh half way a second');
            $chapter->step(0.5, new TickInput());
            $now = $chapter->agc()->dsky->toArray()['r2'];
            self::assertNotSame($previous, $now, 'a new value every second');
            $previous = $now;
        }

        $this->press($chapter, [Key::KeyRelease]);
        $display = $chapter->agc()->dsky->toArray();
        self::assertSame(['06', '63'], [$display['verb'], $display['noun']]);
        self::assertFalse($chapter->agc()->dsky->isLit(Light::KeyRel));
    }

    public function testTheSlantRangeToTheLandingSiteIsAboutThe260NauticalMilesOfTheStartAndFalls(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        $this->press($chapter, [Key::Verb, Key::One, Key::Six, Key::Noun, Key::Six, Key::Eight, Key::Enter]);
        $chapter->step(0.1, new TickInput());
        $first = (int) $chapter->agc()->dsky->toArray()['r1'];

        $chapter->step(30.0, new TickInput());

        // xxxx.x nautical miles: 2600 is 260.0.
        self::assertEqualsWithDelta(2600, $first, 100);
        self::assertLessThan($first, (int) $chapter->agc()->dsky->toArray()['r1']);
    }

    public function testTheUserDisplayShowsTheNounsOfTheLandingTable(): void
    {
        $chapter = new LandingChapter();

        foreach ([9 => Key::Five, 60 => Key::Six, 63 => Key::Six, 64 => Key::Six, 68 => Key::Six] as $noun => $verbDigit) {
            $this->press($chapter, [
                Key::Verb, Key::Zero, $verbDigit,
                Key::Noun, Key::from((string) intdiv($noun, 10)), Key::from((string) ($noun % 10)),
                Key::Enter,
            ]);
            $chapter->step(0.1, new TickInput());

            $display = $chapter->agc()->dsky->toArray();
            self::assertNotSame('', $display['r1'], sprintf('N%02d shows its registers', $noun));
            self::assertFalse($chapter->agc()->dsky->isLit(Light::OprErr), sprintf('N%02d is known', $noun));
        }
    }

    public function testV37ToP00StopsTheGuidanceAndTheLandingIsMissed(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        $chapter->step(30.0, new TickInput());

        $this->press($chapter, [Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Zero, Key::Zero, Key::Enter]);
        $chapter->step(0.1, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertSame(0, $chapter->agc()->majorMode);
        self::assertSame('00', $chapter->agc()->dsky->toArray()['prog']);
    }

    public function testV37ToAProgramOutsideTheLandingGivesOprErrAndTheLandingGoesOn(): void
    {
        $chapter = new LandingChapter();

        $this->press($chapter, [Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Four, Key::One, Key::Enter]);
        $chapter->step(0.1, new TickInput());

        self::assertTrue($chapter->agc()->dsky->isLit(Light::OprErr));
        self::assertNull($chapter->outcome());
        self::assertSame(63, $chapter->agc()->majorMode);

        $this->press($chapter, [Key::Reset]);
        self::assertFalse($chapter->agc()->dsky->isLit(Light::OprErr));
    }

    public function testV37ToP63KeepsTheLandingRunning(): void
    {
        $chapter = new LandingChapter();

        $this->press($chapter, [Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Six, Key::Three, Key::Enter]);
        $chapter->step(0.1, new TickInput());

        self::assertFalse($chapter->agc()->dsky->isLit(Light::OprErr));
        self::assertNull($chapter->outcome());
        self::assertSame(63, $chapter->agc()->majorMode);
    }

    public function testTheLogFollowsTheLandingFromP63ThroughIgnitionToP65(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        $chapter->step(2000.0, new TickInput());

        $lines = $chapter->agc()->log->takeNew();

        self::assertSame(
            [
                'P63 starts: the lander coasts towards TIG',
                'TIG-5 s: V99N63 flashes, the crew is asked to enable the engine',
                'PRO → answer to the flashing V99: proceed',
                'Ignition: the DPS burns at minimum throttle',
                '26 s after ignition: the DPS throttles up, the braking guidance takes over',
                'P63 → P64: high gate reached, the approach phase starts',
                'P64 → P65: low gate reached, the automatic landing starts (3 ft/s down)',
            ],
            array_column($lines, 'text'),
        );
        self::assertSame(
            ['P63LM', 'V99RECYC', 'PROCKEY', 'BURNBABY', 'P63ZOOM', 'STARTP64', 'P65START'],
            array_map(static fn (array $line): ?string => $line['routine']['label'] ?? null, $lines),
        );
        self::assertSame(['agc', 'agc', 'input', 'agc', 'agc', 'agc', 'agc'], array_column($lines, 'kind'));
    }

    public function testARodClickIsLoggedAsStartP66AndRodComp(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);
        while ($chapter->agc()->majorMode === 63) {
            $chapter->step(1.0, new TickInput(speed: 10));
        }
        $chapter->agc()->log->takeNew();

        $chapter->step(0.1, new TickInput(rod: 2));
        $lines = $chapter->agc()->log->takeNew();

        self::assertSame(['STARTP66', 'RODCOMP'], array_map(static fn (array $line): ?string => $line['routine']['label'] ?? null, $lines));
        self::assertSame('ROD switch clicked: P64 → P66, the desired altitude rate starts at the present rate', $lines[0]['text']);
        self::assertStringStartsWith('ROD +2: the desired altitude rate is now ', $lines[1]['text']);
        self::assertSame('agc', $lines[0]['kind']);
    }

    public function testRodClicksBeforeP64AreDropped(): void
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);

        $chapter->step(20.0, new TickInput(rod: 5));

        self::assertSame(63, $chapter->agc()->majorMode);
        self::assertNull($chapter->vehicleView()['desiredRate']);
    }

    public function testV37ToP00IsLoggedAsAProgramChange(): void
    {
        $chapter = new LandingChapter();
        $chapter->agc()->log->takeNew();

        $this->press($chapter, [Key::Verb, Key::Three, Key::Seven, Key::Enter, Key::Zero, Key::Zero, Key::Enter]);
        $chapter->step(0.1, new TickInput());
        $lines = $chapter->agc()->log->takeNew();

        $last = array_pop($lines);
        self::assertNotNull($last);
        self::assertSame('agc', $last['kind']);
        self::assertSame('P63 → P00: the engine goes off, the guidance stops', $last['text']);
        self::assertContains('V37 N00 ENTR → program 00 requested', array_column($lines, 'text'));
    }

    public function testEveryKeyThatCannotDoAnythingIsAnswered(): void
    {
        $chapter = new LandingChapter();
        $chapter->agc()->log->takeNew();

        $this->press($chapter, [Key::Clear, Key::Reset, Key::KeyRelease, Key::Enter, Key::Plus, Key::Five, Key::Proceed]);
        $lines = $chapter->agc()->log->takeNew();

        self::assertSame(
            ['CLEAR', 'ERROR', 'VBRELDSP', 'ENTER', 'CHARALRM', 'CHARALRM', 'PROCKEY'],
            array_map(static fn (array $line): ?string => $line['routine']['label'] ?? null, $lines),
        );
        self::assertSame(['input'], array_values(array_unique(array_column($lines, 'kind'))));
    }
}
