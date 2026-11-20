<?php

declare(strict_types=1);

namespace Apollo\Tests\Chapter;

use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Chapter\Outcome;
use Apollo\Physics\Integrator;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;
use PHPUnit\Framework\TestCase;

final class LandingChapterTest extends TestCase
{
    public function testItStartsAtPdiAltitudeWithoutAnOutcome(): void
    {
        $chapter = new LandingChapter();

        self::assertNull($chapter->outcome());
        self::assertEqualsWithDelta(StartStates::LANDING_ALTITUDE, $chapter->vehicleView()['altitude'], 0.01);
        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertSame(1.0, $chapter->vehicleView()['fuel']);
    }

    public function testWithoutThrustTheAltitudeDropsEveryTickAndTheLanderCrashes(): void
    {
        $chapter = new LandingChapter();
        $previous = $chapter->vehicleView()['altitude'];
        $ticks = 0;

        while ($chapter->outcome() === null) {
            $chapter->step(Integrator::STEP, new TickInput());
            $altitude = $chapter->vehicleView()['altitude'];
            self::assertLessThan($previous, $altitude, 'altitude must drop on tick ' . $ticks);
            $previous = $altitude;
            self::assertLessThan(5000, ++$ticks, 'the lander must reach the surface');
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

        self::assertSame(Outcome::Crashed, $chapter->outcome());
        self::assertSame($time, $chapter->agc()->missionTime);
    }

    public function testSpeedMultipliesTheSimulatedTimePerTick(): void
    {
        $chapter = new LandingChapter();

        $chapter->step(Integrator::STEP * 10, new TickInput(speed: 10));

        self::assertEqualsWithDelta(1.0, $chapter->agc()->missionTime, 1e-9);
    }
}
