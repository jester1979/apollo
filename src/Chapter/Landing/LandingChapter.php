<?php

declare(strict_types=1);

namespace Apollo\Chapter\Landing;

use Apollo\Agc\Agc;
use Apollo\Chapter\Chapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Light;
use Apollo\Guidance\Luminary\LandingGuidance;
use Apollo\Guidance\Luminary\Throttle;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;
use Apollo\Scenario\LandingTargets;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;
use Apollo\Trace\Routine;

/**
 * The landing chapter. The descent engine ignites at once at minimum throttle, goes flat out after
 * 26 s and from then on the braking guidance (P63, QUADGUID) steers it every 2 s. P63 hands over to
 * P64 at high gate; until P64 exists the chapter ends there as missed. During P63 the DSKY shows
 * V06N63, refreshed every 2 s, and COMP ACTY lights while the guidance computes.
 *
 * @see Luminary099/THE_LUNAR_LANDING.agc P63LM
 * @see Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc BURNBABY
 * @see Luminary099/SERVICER.agc SERVICER
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc P63DISPS
 */
final class LandingChapter implements Chapter
{
    /** Contact speeds below which the landing counts as a success, in m/s. */
    private const MAX_LANDING_VERTICAL_SPEED = 3.0;
    private const MAX_LANDING_HORIZONTAL_SPEED = 1.2;

    /** Guidance runs every 2 s, like the SERVICER (SERVICER.agc:73), in physics steps of 0.1 s. */
    private const GUIDANCE_STEPS = 20;

    /** The DPS burns at minimum throttle for 26 s after ignition, then throttles up (BURNBABY). */
    private const IGNITION_STEPS = 260;

    /**
     * COMP ACTY stays lit for 0.5 s after a guidance pass, so that it is visible between ticks. The
     * length is a display choice, not measured AGC timing.
     */
    private const COMP_ACTY_STEPS = 5;

    private StateVector $state;
    private Vehicle $vehicle;
    private readonly float $startFuelMass;
    private readonly Integrator $integrator;
    private readonly Agc $agc;
    private readonly LandingGuidance $guidance;
    private readonly Throttle $throttle;
    private int $steps = 0;
    private ?int $lastGuidanceStep = null;
    private bool $guidedThisStep = false;
    private ?Outcome $outcome = null;

    /** @param StateVector|null $state start state; the PDI state of the chapter when omitted */
    public function __construct(?StateVector $state = null)
    {
        $this->state = $state ?? StartStates::landingState();
        $this->vehicle = StartStates::landingVehicle();
        $this->startFuelMass = $this->vehicle->fuelMass();
        $this->integrator = new Integrator();
        $this->agc = new Agc(ChapterId::Landing->computer(), 63, 0.0, Routine::P63Lm);
        $this->throttle = new Throttle();
        $this->guidance = new LandingGuidance(
            LandingTargets::braking(),
            StartStates::LANDING_SITE_DOWNRANGE / Body::Moon->radius(),
        );
        $this->ignite();
        $this->agc->dsky->showMajorMode($this->agc->majorMode);
        $this->displayP63();
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
        if ($steps > 0 && $this->steps === 0) {
            $this->agc->currentRoutine = Routine::BurnBaby;
        }
        $this->guidedThisStep = false;
        for ($i = 0; $i < $steps && $this->outcome === null; $i++) {
            if ($this->steps >= self::IGNITION_STEPS && $this->steps % self::GUIDANCE_STEPS === 0) {
                $this->guide();
                if ($this->outcome !== null) {
                    break;
                }
            }
            $this->state = $this->integrator->step($this->vehicle, $this->state, Body::Moon, Integrator::STEP);
            $this->agc->missionTime += Integrator::STEP;
            $this->steps++;
            $this->checkContact();
            if ($this->outcome === null && $this->steps % self::GUIDANCE_STEPS === 0) {
                $this->displayP63();
            }
        }
        $this->agc->dsky->setLight(Light::CompActy, $this->guidanceIsComputing());
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

    /** BURNBABY: the DPS ignites at minimum throttle, thrusting against the direction of flight. */
    private function ignite(): void
    {
        $this->vehicle->throttle = Throttle::MINIMUM;
    }

    /** One 2 s guidance pass: sets the thrust direction and the throttle, or hands over to P64. */
    private function guide(): void
    {
        if ($this->steps === self::IGNITION_STEPS) {
            // P63ZOOM: 26 s after ignition the DPS throttles up and the guidance takes over.
            $this->vehicle->throttle = $this->throttle->flatOut();
        }

        $this->lastGuidanceStep = $this->steps;
        $this->guidedThisStep = true;
        $this->agc->currentRoutine = Routine::RootPsrs;
        $command = $this->guidance->pass(
            $this->state,
            $this->vehicle->mass,
            $this->vehicle->maxThrust,
            self::GUIDANCE_STEPS * Integrator::STEP,
        );
        $this->agc->currentRoutine = Routine::QuadGuid;

        if ($command->brakingComplete) {
            // STARTP64. The approach phase does not exist yet, so the chapter stops here.
            $this->agc->majorMode = 64;
            $this->agc->dsky->showMajorMode(64);
            $this->agc->currentRoutine = Routine::StartP64;
            $this->outcome = Outcome::Missed;

            return;
        }

        $this->vehicle->pitch = $command->pitch;
        $this->vehicle->throttle = $this->throttle->command($command->thrustFraction);
    }

    /** P63DISPS: V06N63 shows the speed, the altitude rate and the altitude, in ft/s and ft. */
    private function displayP63(): void
    {
        $dsky = $this->agc->dsky;
        $dsky->requestDisplay(6, 63, false);
        $dsky->supplyData([
            hypot($this->state->horizontalVelocity(), $this->state->radialVelocity()),
            $this->state->radialVelocity(),
            max(0.0, $this->state->altitude(Body::Moon)),
        ]);
    }

    private function guidanceIsComputing(): bool
    {
        return $this->guidedThisStep
            || ($this->lastGuidanceStep !== null && $this->steps - $this->lastGuidanceStep < self::COMP_ACTY_STEPS);
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
