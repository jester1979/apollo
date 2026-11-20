<?php

declare(strict_types=1);

namespace Apollo\Tests\Guidance;

use Apollo\Guidance\Comanche\ExternalDeltaVBurn;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;
use PHPUnit\Framework\TestCase;

final class ExternalDeltaVBurnTest extends TestCase
{
    private const EXHAUST = 314.0 * Integrator::G0;

    public function testUpdateVgTakesTheMeasuredDeltaVOffVgAndAccumulatesIt(): void
    {
        $burn = new ExternalDeltaVBurn(-600.0, 800.0, self::EXHAUST);
        self::assertEqualsWithDelta(1000.0, $burn->velocityToBeGained(), 1e-9);

        $burn->updateVg(-60.0, 80.0);

        self::assertEqualsWithDelta(900.0, $burn->velocityToBeGained(), 1e-9);
        self::assertEqualsWithDelta(100.0, $burn->accumulated(), 1e-9);
    }

    public function testSteeringPointsTheThrustAlongVg(): void
    {
        $burn = new ExternalDeltaVBurn(-1000.0, 0.0, self::EXHAUST);

        $command = $burn->pass(-100.0, 100.0, 40000.0, 91190.0); // VG is now (-900, -100)

        self::assertEqualsWithDelta(-900.0 / hypot(900.0, 100.0), $command->directionX, 1e-9);
        self::assertEqualsWithDelta(-100.0 / hypot(900.0, 100.0), $command->directionY, 1e-9);
    }

    public function testTheTimeToGoFollowsTheRocketEquation(): void
    {
        $burn = new ExternalDeltaVBurn(-750.0, 0.0, self::EXHAUST);
        $mass = 43900.0;
        $thrust = 91190.0;

        $command = $burn->pass(0.0, 0.0, $mass, $thrust);

        $propellant = $mass * (1.0 - exp(-750.0 / self::EXHAUST));
        self::assertEqualsWithDelta($propellant / ($thrust / self::EXHAUST), $command->timeToGo, 1e-6);
        self::assertFalse($command->shutDown);
    }

    public function testS4081TakesOverUnderFourSecondsAndHoldsTheAttitude(): void
    {
        $burn = new ExternalDeltaVBurn(-750.0, 0.0, self::EXHAUST);
        $burn->pass(0.0, 0.0, 43000.0, 91190.0);

        // 2.1 m/s per second at 43 t: 3 s of TGO is a VG of about 6.4 m/s.
        $command = $burn->pass(-743.6, 0.0, 43000.0, 91190.0);
        self::assertTrue($command->shutDown);
        self::assertLessThan(ExternalDeltaVBurn::SHUTDOWN_TGO, $command->timeToGo);
        self::assertFalse($burn->isSteering());

        $held = $burn->pass(-3.0, 30.0, 43000.0, 91190.0); // a sideways error is no longer steered out
        self::assertSame($command->directionX, $held->directionX);
        self::assertSame($command->directionY, $held->directionY);
    }

    public function testThePitchPointsTheThrustAlongAnInertialDirection(): void
    {
        $state = new StateVector(0.0, 1.8e6, 0.0, 0.0); // local up is +y, the horizontal is +x

        self::assertEqualsWithDelta(0.0, ExternalDeltaVBurn::pitchFor($state, 1.0, 0.0), 1e-12);
        self::assertEqualsWithDelta(M_PI / 2.0, ExternalDeltaVBurn::pitchFor($state, 0.0, 1.0), 1e-12);
        self::assertEqualsWithDelta(M_PI, abs(ExternalDeltaVBurn::pitchFor($state, -1.0, 0.0)), 1e-12);
    }

    public function testASensedDeltaVMatchesWhatTheIntegratorAdds(): void
    {
        $state = new StateVector(0.0, Body::Moon->radius() + 110000.0, 1650.0, 0.0);
        $vehicle = new Vehicle(mass: 30000.0, dryMass: 10000.0, maxThrust: 91190.0, isp: 314.0, throttle: 1.0, pitch: M_PI);
        $burn = new ExternalDeltaVBurn(-100.0, 0.0, self::EXHAUST);

        [$sx, $sy] = $burn->sensedDeltaV($vehicle, $state, 1.0);
        $next = (new Integrator())->step($vehicle, $state, Body::Moon, 1.0);

        // The velocity change is the sensed thrust plus gravity (about 1.6 m/s per second, straight down).
        self::assertEqualsWithDelta($next->vx - $state->vx, $sx, 0.05);
        self::assertEqualsWithDelta(-3.04, $sx, 0.02);
        self::assertEqualsWithDelta(0.0, $sy, 1e-6);
    }

    /**
     * The same class flies a prograde burn with another vehicle: it is not tied to LOI.
     */
    public function testAProgradeBurnOfAnotherVehicleReachesItsTargetToo(): void
    {
        $state = new StateVector(0.0, Body::Moon->radius() + 111000.0, 1626.0, 0.0);
        $vehicle = new Vehicle(mass: 18000.0, dryMass: 6000.0, maxThrust: 91190.0, isp: 314.0, throttle: 1.0);
        $burn = new ExternalDeltaVBurn(900.0, 0.0, 314.0 * Integrator::G0);
        $integrator = new Integrator();
        $sx = 0.0;
        $sy = 0.0;
        $engineOffIn = null;
        for ($i = 0; $i < 6000 && ($engineOffIn === null || $engineOffIn > 0.0); $i++) {
            if ($i % 20 === 0) {
                $command = $burn->pass($sx, $sy, $vehicle->mass, $vehicle->throttle * $vehicle->maxThrust);
                [$sx, $sy] = [0.0, 0.0];
                if ($command->shutDown && $engineOffIn === null) {
                    $engineOffIn = $command->timeToGo;
                }
                $vehicle->pitch = ExternalDeltaVBurn::pitchFor($state, $command->directionX, $command->directionY);
            }
            $dt = $engineOffIn !== null ? min(0.1, $engineOffIn) : 0.1;
            [$dx, $dy] = $burn->sensedDeltaV($vehicle, $state, $dt);
            $sx += $dx;
            $sy += $dy;
            $state = $integrator->step($vehicle, $state, Body::Moon, $dt);
            if ($engineOffIn !== null) {
                $engineOffIn -= $dt;
            }
        }
        $burn->updateVg($sx, $sy);

        self::assertEqualsWithDelta(900.0, $burn->accumulated(), 2.0);
        self::assertLessThan(2.0, $burn->velocityToBeGained());
        self::assertGreaterThan(2200.0, hypot($state->vx, $state->vy), 'prograde: the burn raised the speed');
    }

    public function testABurnNeedsADeltaVAndAnEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalDeltaVBurn(0.0, 0.0, self::EXHAUST);
    }
}
