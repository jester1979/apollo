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

    public function testDragSlowsAVehicleInTheAtmosphereOfTheEarthButNotInVacuum(): void
    {
        // 10 km up at 300 m/s straight along the horizontal, relative to the air that turns with the Earth.
        $start = new StateVector(0.0, Body::Earth->radius() + 10000.0, 300.0 + Body::Earth->rotationRate() * (Body::Earth->radius() + 10000.0), 0.0);
        $draggy = new Vehicle(mass: 1000.0, dryMass: 500.0, maxThrust: 0.0, isp: 300.0, dragArea: 1.0);
        $clean = new Vehicle(mass: 1000.0, dryMass: 500.0, maxThrust: 0.0, isp: 300.0);
        $integrator = new Integrator();

        $withDrag = $integrator->step($draggy, $start, Body::Earth, 0.1);
        $withoutDrag = $integrator->step($clean, $start, Body::Earth, 0.1);

        // rho(10 km) = 1.225 * exp(-10000 / 7200) = 0.305; a = 0.5 * 0.305 * 300^2 * 1 / 1000 = 13.7 m/s^2, so 1.37 m/s in 0.1 s.
        self::assertEqualsWithDelta(1.37, $withoutDrag->vx - $withDrag->vx, 0.05);
        // No atmosphere on the Moon: the drag area changes nothing.
        $moonStart = new StateVector(0.0, Body::Moon->radius() + 1000.0, 300.0, 0.0);
        self::assertEquals(
            $integrator->step($clean, $moonStart, Body::Moon, 1.0),
            $integrator->step($draggy, $moonStart, Body::Moon, 1.0),
        );
    }

    public function testTheAtmosphereOfTheEarthIsExponential(): void
    {
        $atmosphere = Body::Earth->atmosphere();

        self::assertNotNull($atmosphere);
        self::assertEqualsWithDelta(1.225, $atmosphere->density(0.0), 1e-9);
        self::assertEqualsWithDelta(1.225 / M_E, $atmosphere->density(7200.0), 1e-9);
        self::assertNull(Body::Moon->atmosphere());
    }

    /** One short step of the command module at 60 km, 7 km/s, level and clockwise, with the given bank angle. */
    private function glide(float $bank, float $liftToDrag = 0.3): StateVector
    {
        $vehicle = new Vehicle(mass: 5560.0, dryMass: 5560.0, maxThrust: 0.0, isp: 300.0, dragArea: 15.5, liftToDrag: $liftToDrag, bank: $bank);

        return (new Integrator())->step($vehicle, new StateVector(0.0, Body::Earth->radius() + 60000.0, 7000.0, 0.0), Body::Earth, 0.01);
    }

    public function testTheLiftIsTheDragTimesLiftToDragAndPointsUpAtBankZero(): void
    {
        $start = new StateVector(0.0, Body::Earth->radius() + 60000.0, 7000.0, 0.0);
        $airSpeed = $start->airSpeed(Body::Earth);
        $drag = 0.5 * (Body::Earth->atmosphere()?->density(60000.0) ?? 0.0) * $airSpeed ** 2 * 15.5 / 5560.0;
        $gravity = Body::Earth->mu() / $start->radius() ** 2;
        $noLift = $this->glide(M_PI / 2.0, 0.0);

        $lift = ($this->glide(0.0)->vy - $noLift->vy) / 0.01;

        self::assertGreaterThan(0.0, $drag);
        self::assertEqualsWithDelta(0.3 * $drag, $lift, 0.3 * $drag * 0.01);
        self::assertEqualsWithDelta(-$gravity, ($noLift->vy) / 0.01, 0.05 * $gravity, 'only gravity pulls down when there is no lift');
    }

    public function testTheLiftScalesWithTheCosineOfTheBankAngle(): void
    {
        $up = $this->glide(0.0)->vy;
        $sideways = $this->glide(M_PI / 2.0)->vy;
        $sixty = $this->glide(M_PI / 3.0)->vy;
        $down = $this->glide(M_PI)->vy;

        self::assertEqualsWithDelta(($up - $sideways) * 0.5, $sixty - $sideways, ($up - $sideways) * 0.01);
        self::assertEqualsWithDelta($up - $sideways, $sideways - $down, ($up - $sideways) * 0.01, 'down is the mirror image of up');
        self::assertEqualsWithDelta($sideways, $this->glide(0.0, 0.0)->vy, 1e-9, 'bank 90 degrees is the same as no lift');
    }

    public function testTheLiftDoesNotChangeTheSpeedThroughTheAir(): void
    {
        $start = new StateVector(0.0, Body::Earth->radius() + 60000.0, 7000.0, 0.0);

        // Lift is perpendicular to the relative velocity, so a short step changes the air speed by drag
        // and gravity alone, the same with the lift up and with it down.
        self::assertEqualsWithDelta(
            $this->glide(0.0)->airSpeed(Body::Earth) - $start->airSpeed(Body::Earth),
            $this->glide(M_PI)->airSpeed(Body::Earth) - $start->airSpeed(Body::Earth),
            1e-3,
        );
    }
}
