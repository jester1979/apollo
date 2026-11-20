<?php

declare(strict_types=1);

namespace Apollo\Chapter\Landing;

use Apollo\Agc\Agc;
use Apollo\Chapter\Chapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Outcome;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;

/**
 * The landing chapter. Slice 1: physics only, the lander falls without thrust until contact.
 */
final class LandingChapter implements Chapter
{
    /** Contact speeds below which the landing counts as a success, in m/s. */
    private const MAX_LANDING_VERTICAL_SPEED = 3.0;
    private const MAX_LANDING_HORIZONTAL_SPEED = 1.2;

    private StateVector $state;
    private Vehicle $vehicle;
    private readonly float $startFuelMass;
    private readonly Integrator $integrator;
    private readonly Agc $agc;
    private ?Outcome $outcome = null;

    public function __construct()
    {
        $this->state = StartStates::landingState();
        $this->vehicle = StartStates::landingVehicle();
        $this->startFuelMass = $this->vehicle->fuelMass();
        $this->integrator = new Integrator();
        $this->agc = new Agc(ChapterId::Landing->computer());
    }

    public function id(): ChapterId
    {
        return ChapterId::Landing;
    }

    public function agc(): Agc
    {
        return $this->agc;
    }

    public function step(float $dt, TickInput $input): void
    {
        $steps = max(0, (int) round($dt / Integrator::STEP));
        for ($i = 0; $i < $steps && $this->outcome === null; $i++) {
            $this->state = $this->integrator->step($this->vehicle, $this->state, Body::Moon, Integrator::STEP);
            $this->agc->missionTime += Integrator::STEP;
            $this->checkContact();
        }
    }

    public function vehicleView(): array
    {
        $downrange = Body::Moon->radius() * $this->state->angle();

        return [
            'view' => 'landing',
            'x' => round($downrange, 1),
            'altitude' => round(max(0.0, $this->state->altitude(Body::Moon)), 2),
            'vx' => round($this->state->horizontalVelocity(), 2),
            'vz' => round($this->state->radialVelocity(), 2),
            'pitch' => round($this->vehicle->pitch, 4),
            'throttle' => round($this->vehicle->throttle, 4),
            'fuel' => round($this->vehicle->fuelMass() / $this->startFuelMass, 4),
            'target' => round(StartStates::LANDING_SITE_DOWNRANGE - $downrange, 1),
        ];
    }

    public function outcome(): ?Outcome
    {
        return $this->outcome;
    }

    private function checkContact(): void
    {
        if ($this->state->altitude(Body::Moon) > 0.0) {
            return;
        }

        $this->outcome = abs($this->state->radialVelocity()) < self::MAX_LANDING_VERTICAL_SPEED
            && abs($this->state->horizontalVelocity()) < self::MAX_LANDING_HORIZONTAL_SPEED
            ? Outcome::Success
            : Outcome::Crashed;

        // Put the lander on the surface; the final velocities stay as they were at contact.
        $scale = Body::Moon->radius() / $this->state->radius();
        $this->state = new StateVector(
            $this->state->x * $scale,
            $this->state->y * $scale,
            $this->state->vx,
            $this->state->vy,
        );
    }
}
