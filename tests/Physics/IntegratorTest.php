<?php

declare(strict_types=1);

namespace Apollo\Tests\Physics;

use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;
use PHPUnit\Framework\TestCase;

final class IntegratorTest extends TestCase
{
    private function coaster(): Vehicle
    {
        return new Vehicle(mass: 1000.0, dryMass: 500.0, maxThrust: 1000.0, isp: 300.0);
    }

    public function testACircularOrbitKeepsItsAltitudeAfterOneRevolution(): void
    {
        $radius = Body::Moon->radius() + 110000.0;
        $speed = sqrt(Body::Moon->mu() / $radius);
        $period = 2.0 * M_PI * sqrt($radius ** 3 / Body::Moon->mu());
        $state = new StateVector(0.0, $radius, $speed, 0.0);
        $vehicle = $this->coaster();
        $integrator = new Integrator();

        $steps = (int) round($period / Integrator::STEP);
        for ($i = 0; $i < $steps; $i++) {
            $state = $integrator->step($vehicle, $state, Body::Moon, Integrator::STEP);
        }

        self::assertEqualsWithDelta(110000.0, $state->altitude(Body::Moon), 100.0);
    }

    public function testFreeFallFrom100MetresLandsAfterSqrtTwoHOverG(): void
    {
        $dt = 0.01;
        $state = new StateVector(0.0, Body::Moon->radius() + 100.0, 0.0, 0.0);
        $vehicle = $this->coaster();
        $integrator = new Integrator();

        $time = 0.0;
        while ($state->altitude(Body::Moon) > 0.0) {
            $state = $integrator->step($vehicle, $state, Body::Moon, $dt);
            $time += $dt;
        }

        $g = Body::Moon->mu() / Body::Moon->radius() ** 2;
        self::assertEqualsWithDelta(sqrt(2.0 * 100.0 / $g), $time, 0.1);
    }

    public function testFuelBurnMatchesTheRocketEquation(): void
    {
        $state = new StateVector(0.0, Body::Moon->radius() + 100000.0, 1600.0, 0.0);
        $vehicle = new Vehicle(mass: 10000.0, dryMass: 5000.0, maxThrust: 40000.0, isp: 311.0, throttle: 1.0, pitch: M_PI);
        $integrator = new Integrator();

        $steps = 100; // 10 s at the fixed step
        for ($i = 0; $i < $steps; $i++) {
            $state = $integrator->step($vehicle, $state, Body::Moon, Integrator::STEP);
        }

        $expected = 40000.0 / (311.0 * Integrator::G0) * 10.0;
        self::assertEqualsWithDelta($expected, 10000.0 - $vehicle->mass, $expected * 0.001);
        // Thrust opposes the horizontal motion, so the lander slows down.
        self::assertLessThan(1600.0, $state->horizontalVelocity());
    }

    public function testTheEngineCutsOutWhenTheFuelIsUsedUp(): void
    {
        $state = new StateVector(0.0, Body::Moon->radius() + 100000.0, 1600.0, 0.0);
        $vehicle = new Vehicle(mass: 1000.0, dryMass: 999.9, maxThrust: 40000.0, isp: 311.0, throttle: 1.0);
        $integrator = new Integrator();

        for ($i = 0; $i < 50; $i++) {
            $state = $integrator->step($vehicle, $state, Body::Moon, Integrator::STEP);
        }

        self::assertEqualsWithDelta(999.9, $vehicle->mass, 1e-9);
        self::assertSame(0.0, $vehicle->fuelMass());
    }
}
