<?php

declare(strict_types=1);

namespace Apollo\Tests\Guidance;

use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Chapter\Outcome;
use Apollo\Guidance\Luminary\LandingGuidance;
use Apollo\Physics\Body;
use Apollo\Physics\StateVector;
use Apollo\Scenario\LandingTargets;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;
use Apollo\Trace\Routine;
use PHPUnit\Framework\TestCase;

final class LandingGuidanceTest extends TestCase
{
    private function guidance(): LandingGuidance
    {
        return new LandingGuidance(
            LandingTargets::braking(),
            StartStates::LANDING_SITE_DOWNRANGE / Body::Moon->radius(),
        );
    }

    public function testTheNewtonSolutionForTtfConvergesWithinEightIterationsOnTheStartState(): void
    {
        $vehicle = StartStates::landingVehicle();

        $command = $this->guidance()->pass(StartStates::landingState(), $vehicle->mass, $vehicle->maxThrust, 2.0);

        self::assertTrue($command->converged);
        self::assertLessThanOrEqual(LandingGuidance::MAX_ITERATIONS, $command->iterations);
        self::assertLessThan(0.0, $command->ttf, 'TTF is negative, as in the AGC');
    }

    public function testTheSolvedTtfIsARootOfTheJerkCondition(): void
    {
        $targets = LandingTargets::braking();
        $guidance = $this->guidance();
        $rg = -481000.0;
        $vg = 1690.0;

        [$ttf, , $converged] = $guidance->solveTtf(-500.0, $rg, $vg);

        self::assertTrue($converged);
        $residual = $targets->jerkHorizontal * $ttf ** 3
            + 6.0 * $targets->accelerationHorizontal * $ttf ** 2
            + (18.0 * $targets->velocityHorizontal + 6.0 * $vg) * $ttf
            + 24.0 * ($targets->positionHorizontal - $rg);
        self::assertEqualsWithDelta(0.0, $residual / abs(24.0 * ($targets->positionHorizontal - $rg)), 1e-3);
    }

    public function testWhenTheRootFinderDoesNotConvergeTheIncrementedTtfIsKept(): void
    {
        $guidance = $this->guidance();
        $vehicle = StartStates::landingVehicle();
        $first = $guidance->pass(StartStates::landingState(), $vehicle->mass, $vehicle->maxThrust, 2.0);

        // 50 km past the landing site: the cubic has no real root, so ROOTPSRS cannot converge.
        $siteAngle = StartStates::LANDING_SITE_DOWNRANGE / Body::Moon->radius();
        $radius = Body::Moon->radius();
        $past = new StateVector(
            $radius * sin($siteAngle) + 50000.0 * cos($siteAngle),
            $radius * cos($siteAngle) - 50000.0 * sin($siteAngle),
            0.0,
            0.0,
        );
        $second = $guidance->pass($past, $vehicle->mass, $vehicle->maxThrust, 2.0);

        self::assertFalse($second->converged);
        self::assertSame(LandingGuidance::MAX_ITERATIONS, $second->iterations);
        self::assertEqualsWithDelta($first->ttf + 2.0, $second->ttf, 1e-9);
    }

    public function testBrakingBringsTheLanderToHighGateAndSwitchesToP64(): void
    {
        $chapter = new LandingChapter();
        $ticks = 0;

        while ($chapter->outcome() === null) {
            $chapter->step(1.0, new TickInput(speed: 10));
            self::assertLessThan(2000, ++$ticks, 'P63 must end');
        }

        $view = $chapter->vehicleView();
        self::assertSame(Outcome::Missed, $chapter->outcome());
        self::assertSame(64, $chapter->agc()->majorMode);
        self::assertSame(Routine::StartP64, $chapter->agc()->currentRoutine);
        self::assertGreaterThan(1800.0, $view['altitude']);
        self::assertLessThan(2800.0, $view['altitude']);
        self::assertLessThan(100.0, $view['vx'], 'the braking burn took the horizontal speed down from 1690 m/s');
        self::assertGreaterThan(0.0, $view['vx']);
        self::assertGreaterThan(0.0, $view['fuel'], 'fuel is left for the approach');
        self::assertLessThan(0.5, $view['fuel'], 'the burn used fuel');
    }

    public function testTheDpsIgnitesAtMinimumThrottleAndGoesFlatOutAfter26Seconds(): void
    {
        $chapter = new LandingChapter();
        self::assertSame(0.1, $chapter->vehicleView()['throttle']);
        self::assertSame(63, $chapter->agc()->majorMode);

        $chapter->step(25.0, new TickInput());
        self::assertSame(0.1, $chapter->vehicleView()['throttle']);
        self::assertSame(Routine::BurnBaby, $chapter->agc()->currentRoutine);

        $chapter->step(2.0, new TickInput());
        self::assertSame(1.0, $chapter->vehicleView()['throttle']);
        self::assertSame(Routine::QuadGuid, $chapter->agc()->currentRoutine);
    }

    public function testTheThrottleStaysInTheDpsRangeAndTheFuelRunsDown(): void
    {
        $chapter = new LandingChapter();
        $previousFuel = 1.0;

        for ($i = 0; $i < 2000 && $chapter->outcome() === null; $i++) {
            $chapter->step(1.0, new TickInput(speed: 10));
            $view = $chapter->vehicleView();
            $throttle = $view['throttle'];
            self::assertTrue(
                $throttle === 1.0 || ($throttle >= 0.1 && $throttle <= 0.6),
                sprintf('throttle %.3f is outside 10-60%% and not 100%%', $throttle),
            );
            self::assertLessThanOrEqual($previousFuel, $view['fuel']);
            $previousFuel = $view['fuel'];
        }

        self::assertNotNull($chapter->outcome());
    }
}
