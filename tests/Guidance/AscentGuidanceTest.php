<?php

declare(strict_types=1);

namespace Apollo\Tests\Guidance;

use Apollo\Guidance\Luminary\AscentGuidance;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;
use Apollo\Scenario\StartStates;
use PHPUnit\Framework\TestCase;

final class AscentGuidanceTest extends TestCase
{
    private function guidance(Vehicle $vehicle): AscentGuidance
    {
        return new AscentGuidance(
            StartStates::ASCENT_TARGET_HORIZONTAL_SPEED,
            StartStates::ASCENT_TARGET_RADIAL_SPEED,
            Body::Moon->radius() + StartStates::ASCENT_TARGET_ALTITUDE,
            $vehicle->isp * Integrator::G0,
        );
    }

    public function testTheFirstPassOnTheSurfacePointsStraightUpAndTheBurnTakesAboutSixMinutes(): void
    {
        $vehicle = StartStates::ascentVehicle();

        $command = $this->guidance($vehicle)->pass(StartStates::ascentState(), $vehicle->mass, $vehicle->maxThrust);

        self::assertTrue($command->verticalRise);
        self::assertEqualsWithDelta(M_PI / 2.0, $command->pitch, 1e-9);
        self::assertGreaterThan(330.0, $command->timeToGo);
        self::assertLessThan(420.0, $command->timeToGo);
        self::assertFalse($command->shutDown);
        // The gravity-compensated velocity to be gained is more than the horizontal velocity alone.
        self::assertGreaterThan(StartStates::ASCENT_TARGET_HORIZONTAL_SPEED, $command->velocityToGo);
    }

    public function testTheVerticalRiseEndsAtFortyFeetPerSecondAndThePitchThenTipsOver(): void
    {
        $vehicle = StartStates::ascentVehicle();
        $guidance = $this->guidance($vehicle);
        $radius = Body::Moon->radius();

        $before = $guidance->pass(new StateVector(0.0, $radius + 40.0, 0.0, 12.0), $vehicle->mass, $vehicle->maxThrust);
        $after = $guidance->pass(new StateVector(0.0, $radius + 45.0, 0.0, 12.2), $vehicle->mass, $vehicle->maxThrust);

        self::assertTrue($before->verticalRise);
        self::assertFalse($after->verticalRise);
        self::assertFalse($guidance->isVerticalRise());
        self::assertLessThan(M_PI / 2.0 - 0.3, $after->pitch, 'the thrust leans over');
        self::assertGreaterThan(0.2, $after->pitch);
    }

    public function testAFlownBurnReachesTheTargetOrbitAndShutsTheEngineOffUnderFourSecondsOfTgo(): void
    {
        $vehicle = StartStates::ascentVehicle();
        $guidance = $this->guidance($vehicle);
        $integrator = new Integrator();
        $state = StartStates::ascentState();
        $vehicle->throttle = 1.0;

        $time = 0.0;
        $engineOffAt = null;
        for ($step = 0; $step < 6000; $step++) {
            if ($engineOffAt === null && $step % 20 === 0) {
                $command = $guidance->pass($state, $vehicle->mass, $vehicle->maxThrust);
                $vehicle->pitch = $command->pitch;
                if ($command->shutDown) {
                    $engineOffAt = $time + $command->timeToGo;
                }
            }
            $dt = Integrator::STEP;
            if ($engineOffAt !== null && $engineOffAt - $time <= $dt + 1e-9) {
                $state = $integrator->step($vehicle, $state, Body::Moon, max(0.0, $engineOffAt - $time));
                break;
            }
            $state = $integrator->step($vehicle, $state, Body::Moon, $dt);
            $time += $dt;
        }

        self::assertNotNull($engineOffAt, 'ENGOFF came');
        self::assertEqualsWithDelta(StartStates::ASCENT_TARGET_HORIZONTAL_SPEED, $state->horizontalVelocity(), 3.0);
        self::assertEqualsWithDelta(StartStates::ASCENT_TARGET_RADIAL_SPEED, $state->radialVelocity(), 3.0);
        self::assertEqualsWithDelta(StartStates::ASCENT_TARGET_ALTITUDE, $state->altitude(Body::Moon), 400.0);
        self::assertGreaterThan(0.0, $vehicle->fuelMass(), 'propellant is left');
    }
}
