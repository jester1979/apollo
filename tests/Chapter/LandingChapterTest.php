<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Chapter\Outcome;
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
}
