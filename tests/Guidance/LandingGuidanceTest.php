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
use Apollo\Tests\Support\LandingBurn;
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

    public function testTheTimeToGoBeforeTheFirstPassIsTheTtfThePassWillFind(): void
    {
        $vehicle = StartStates::landingVehicle();
        $guidance = $this->guidance();

        $estimate = $guidance->timeToGoFrom(StartStates::landingState());
        self::assertNull($guidance->ttf(), 'asking changes nothing');
        $command = $guidance->pass(StartStates::landingState(), $vehicle->mass, $vehicle->maxThrust, 2.0);

        self::assertEqualsWithDelta(-$command->ttf, $estimate, 1e-9);
        self::assertGreaterThan(300.0, $estimate);
        self::assertLessThan(900.0, $estimate, 'a braking burn of ten minutes or so');
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

    /** A landing chapter that has been ignited with PRO at TIG, from the PDI start state. */
    private function ignited(): LandingChapter
    {
        $chapter = new LandingChapter();
        LandingBurn::proceed($chapter);

        return $chapter;
    }

    /** Steps in seconds of simulated time until $done, a limit of two hours. */
    private function runUntil(LandingChapter $chapter, callable $done, float $seconds = 1.0): void
    {
        $ticks = 0;
        while (!$done($chapter)) {
            $chapter->step($seconds, new TickInput(speed: 10));
            self::assertLessThan(8000, ++$ticks, 'the condition must be met');
        }
    }

    public function testBrakingBringsTheLanderToHighGateAndSwitchesToP64(): void
    {
        $chapter = $this->ignited();

        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->agc()->majorMode !== 63);

        $view = $chapter->vehicleView();
        self::assertNull($chapter->outcome(), 'the landing goes on in P64');
        self::assertSame(64, $chapter->agc()->majorMode);
        self::assertSame(Routine::QuadGuid, $chapter->agc()->currentRoutine, 'the first approach pass ran right after STARTP64');
        self::assertGreaterThan(1800.0, $view['altitude']);
        self::assertLessThan(2800.0, $view['altitude']);
        self::assertLessThan(100.0, $view['vx'], 'the braking burn took the horizontal speed down from 1690 m/s');
        self::assertGreaterThan(0.0, $view['vx']);
        self::assertGreaterThan(0.0, $view['fuel'], 'fuel is left for the approach');
        self::assertLessThan(0.5, $view['fuel'], 'the burn used fuel');
    }

    public function testP64SwitchesToP65BelowTwoHundredMetres(): void
    {
        $chapter = $this->ignited();
        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->agc()->majorMode === 64);

        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->agc()->majorMode !== 64, 0.1);

        $view = $chapter->vehicleView();
        self::assertSame(65, $chapter->agc()->majorMode);
        self::assertLessThan(200.0, $view['altitude']);
        self::assertGreaterThan(100.0, $view['altitude'], 'P64 flew down to low gate before it handed over');
        self::assertSame(Routine::P65Vert, $chapter->agc()->currentRoutine, 'the first P65 pass ran right after P65START');
    }

    public function testWithoutARodClickP65LandsGentlyAndTheChapterEndsInSuccess(): void
    {
        $chapter = $this->ignited();

        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->outcome() !== null);

        $view = $chapter->vehicleView();
        self::assertSame(Outcome::Success, $chapter->outcome());
        self::assertSame(65, $chapter->agc()->majorMode, 'nobody clicked, so P66 never started');
        self::assertLessThan(3.0, abs($view['vz']));
        self::assertLessThan(1.2, abs($view['vx']));
        self::assertEqualsWithDelta(0.91, abs($view['vz']), 0.1, 'P65 lands at 3 ft/s');
        self::assertSame(0.0, $view['altitude']);
        self::assertGreaterThan(0.02, $view['fuel'], 'a hands-off landing keeps a margin of fuel');
    }

    public function testOneRodClickInP64SwitchesToP66(): void
    {
        $chapter = $this->ignited();
        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->agc()->majorMode === 64);
        self::assertNull($chapter->vehicleView()['desiredRate']);

        $chapter->step(0.1, new TickInput(rod: -1));

        self::assertSame(66, $chapter->agc()->majorMode);
        self::assertNull($chapter->outcome());
        self::assertSame('66', $chapter->agc()->dsky->toArray()['prog']);
        self::assertNotNull($chapter->vehicleView()['desiredRate']);
    }

    public function testInP66EveryClickChangesTheDesiredDescentRateBy0Point3048MetresPerSecond(): void
    {
        $chapter = $this->ignited();
        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->agc()->majorMode === 64);
        $chapter->step(0.1, new TickInput(rod: 1));
        $rate = $chapter->vehicleView()['desiredRate'];
        self::assertNotNull($rate);

        foreach ([1, 1, -1, 3, -4, 0] as $clicks) {
            $chapter->step(1.0, new TickInput(rod: $clicks));
            $now = $chapter->vehicleView()['desiredRate'];
            self::assertEqualsWithDelta($rate + $clicks * 0.3048, $now, 0.001, sprintf('%d clicks', $clicks));
            $rate = $now;
        }
    }

    public function testInP66TheVehicleFollowsTheDesiredRateAndNullsTheHorizontalSpeed(): void
    {
        $chapter = $this->ignited();
        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->agc()->majorMode === 65);
        $chapter->step(0.1, new TickInput(rod: 4));
        $before = $chapter->vehicleView()['vz'];

        $chapter->step(15.0, new TickInput());

        $view = $chapter->vehicleView();
        self::assertSame(66, $chapter->agc()->majorMode);
        self::assertEqualsWithDelta($view['desiredRate'], $view['vz'], 0.1);
        self::assertGreaterThan($before, $view['vz'], 'four clicks up: less descent rate');
        self::assertLessThan(0.3, abs($view['vx']));
    }

    public function testALargeDownRequestOnTheRodSwitchNeverPointsTheEngineAtTheGround(): void
    {
        $vehicle = StartStates::landingVehicle();
        $state = StartStates::landingState();

        // 60 m/s below the present vertical speed with a 2 s loop asks for far more than free fall.
        $command = $this->guidance()->velocityPass(
            $state,
            $vehicle->mass,
            $vehicle->maxThrust,
            $state->radialVelocity() - 60.0,
            LandingTargets::TAU_ROD,
        );

        self::assertGreaterThanOrEqual(0.0, $command->pitch, 'the thrust has no part towards the ground');
        self::assertLessThanOrEqual(M_PI, $command->pitch);
    }

    public function testTooHardAContactIsACrash(): void
    {
        $chapter = $this->ignited();
        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->agc()->majorMode === 64);

        // ROD down in P64: P66 holds the descent rate of high gate, about 15 m/s.
        $chapter->step(0.1, new TickInput(rod: -1));
        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->outcome() !== null);

        self::assertSame(Outcome::Crashed, $chapter->outcome());
        self::assertGreaterThan(3.0, abs($chapter->vehicleView()['vz']));
    }

    public function testAnEmptyTankBeforeContactIsOutOfFuel(): void
    {
        $chapter = $this->ignited();
        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->agc()->majorMode === 64);

        // ROD up all the way: the vehicle climbs on its engine until the tank is empty.
        for ($i = 0; $i < 6; $i++) {
            $chapter->step(1.0, new TickInput(rod: 10));
        }
        self::assertGreaterThan(0.0, $chapter->vehicleView()['desiredRate']);
        $this->runUntil($chapter, static fn (LandingChapter $c): bool => $c->outcome() !== null);

        self::assertSame(Outcome::OutOfFuel, $chapter->outcome());
        self::assertSame(0.0, $chapter->vehicleView()['fuel']);
        self::assertSame(0.0, $chapter->vehicleView()['throttle']);
        self::assertGreaterThan(0.0, $chapter->vehicleView()['altitude']);
    }

    public function testTheDpsIgnitesAtMinimumThrottleAndGoesFlatOutAfter26Seconds(): void
    {
        $chapter = new LandingChapter();
        self::assertSame(0.0, $chapter->vehicleView()['throttle'], 'no ignition before PRO');
        self::assertSame(63, $chapter->agc()->majorMode);
        LandingBurn::proceed($chapter);

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
        LandingBurn::proceed($chapter);
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
