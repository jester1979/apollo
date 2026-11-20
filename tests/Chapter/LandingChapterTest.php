<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Light;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\TestCase;

final class LandingChapterTest extends TestCase
{
    public function testItStartsAtPdiAltitudeAndSpeedWithTheEngineIgnitedAndNoOutcome(): void
    {
        $chapter = new LandingChapter();

        self::assertNull($chapter->outcome());
        self::assertEqualsWithDelta(StartStates::LANDING_ALTITUDE, $chapter->vehicleView()['altitude'], 0.01);
        self::assertEqualsWithDelta(1690.0, $chapter->vehicleView()['vx'], 0.01);
        self::assertSame(0.1, $chapter->vehicleView()['throttle']);
        self::assertSame(1.0, $chapter->vehicleView()['fuel']);
        self::assertSame(63, $chapter->agc()->majorMode);
    }

    public function testFuelRunsDownWhileTheEngineBurns(): void
    {
        $chapter = new LandingChapter();

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
        $chapter->step(1000.0, new TickInput());
        $time = $chapter->agc()->missionTime;

        $chapter->step(10.0, new TickInput());

        self::assertSame(Outcome::Missed, $chapter->outcome());
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

        $chapter->step(26.0 - 0.1, new TickInput());
        self::assertFalse($dsky->isLit(Light::CompActy), 'the engine still burns at minimum, no guidance yet');

        $chapter->step(0.2, new TickInput()); // crosses the first guidance pass at 26 s
        self::assertTrue($dsky->isLit(Light::CompActy));

        $chapter->step(0.5, new TickInput());
        self::assertFalse($dsky->isLit(Light::CompActy), 'dark again between passes');

        $chapter->step(1.5, new TickInput()); // the next pass at 28 s
        self::assertTrue($dsky->isLit(Light::CompActy));
    }

    public function testTheDskyShowsP64WhenTheGuidanceHandsOver(): void
    {
        $chapter = new LandingChapter();
        $chapter->step(1000.0, new TickInput());

        self::assertSame('64', $chapter->agc()->dsky->toArray()['prog']);
    }
}
