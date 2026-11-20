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
 * The landing chapter. P63 starts with the lander coasting towards TIG. From TIG-5 s the DSKY flashes
 * V99N63 (please enable engine); PRO ignites the descent engine at minimum throttle, it goes flat
 * out after 26 s and from then on the braking guidance (P63, QUADGUID) steers it every 2 s. Without
 * PRO there is no ignition and V99N63 keeps flashing. P63 hands over to P64 at high gate; until P64
 * exists the chapter ends there as missed. During P63 the DSKY shows V06N63, refreshed every 2 s, and
 * COMP ACTY lights while the guidance computes. The keys of the user are handled by the Dsky; this
 * chapter reads what they asked for (PRO, V37, the nouns of a user display).
 *
 * @see Luminary099/THE_LUNAR_LANDING.agc P63LM
 * @see Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc BURNBABY
 * @see Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc V99RECYC
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

    /** TIG, the time of ignition, counted from the start of the chapter: 10 s. */
    private const TIG_STEPS = 100;

    /** V99 is displayed from TIG-5 s (V99RECYC: "it is initiated at TIG-5 sec"). */
    private const ENABLE_ENGINE_STEPS = self::TIG_STEPS - 50;

    /** The monitor verb V16 refreshes every second. */
    private const MONITOR_STEPS = 10;

    /** The programs V37 may select: P63 itself and P00, which stops the guidance. */
    private const ALLOWED_PROGRAMS = [63, 0];

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
    private ?int $ignitionStep = null;
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

    public function allowedPrograms(): array
    {
        return self::ALLOWED_PROGRAMS;
    }

    public function step(float $dt, TickInput $input): void
    {
        $steps = max(0, (int) round($dt / Integrator::STEP));
        $this->guidedThisStep = false;
        $this->readKeys();
        $this->supplyUserDisplay(false);
        for ($i = 0; $i < $steps && $this->outcome === null; $i++) {
            $burnStep = $this->ignitionStep === null ? null : $this->steps - $this->ignitionStep;
            if ($burnStep !== null && $burnStep >= self::IGNITION_STEPS && $burnStep % self::GUIDANCE_STEPS === 0) {
                $this->guide($burnStep);
                if ($this->outcome !== null) {
                    break;
                }
            }
            $this->state = $this->integrator->step($this->vehicle, $this->state, Body::Moon, Integrator::STEP);
            $this->agc->missionTime += Integrator::STEP;
            $this->steps++;
            $this->checkContact();
            if ($this->outcome === null) {
                $this->afterStep();
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

    /**
     * Reads what the Dsky took from the keyboard: PRO on the flashing V99 and a program change by V37.
     * Both reach the chapter only as flags of the Dsky, which keeps the chapter free of key handling.
     */
    private function readKeys(): void
    {
        $dsky = $this->agc->dsky;
        if ($dsky->takeProceed() && $this->awaitingEngineEnable()) {
            $this->ignite();
        }

        $program = $dsky->takeProgramChange();
        if ($program === 0) {
            $this->stopGuidance();
        }
        // P63 is the program that runs: selecting it again changes nothing.
    }

    /** V99RECYC: from TIG-5 s the DSKY asks for the engine to be enabled, until PRO is pressed. */
    private function awaitingEngineEnable(): bool
    {
        return $this->ignitionStep === null && $this->outcome === null && $this->steps >= self::ENABLE_ENGINE_STEPS;
    }

    /** PRO on V99: BURNBABY ignites the DPS at minimum throttle, thrusting against the direction of flight. */
    private function ignite(): void
    {
        $this->ignitionStep = $this->steps;
        $this->vehicle->throttle = Throttle::MINIMUM;
        $this->agc->currentRoutine = Routine::BurnBaby;
        $this->displayP63();
    }

    /** V37 to P00: the engine goes off and the guidance stops; the landing is lost. */
    private function stopGuidance(): void
    {
        $this->vehicle->throttle = 0.0;
        $this->agc->majorMode = 0;
        $this->agc->dsky->showMajorMode(0);
        $this->outcome = Outcome::Missed;
    }

    /** What happens after each 0.1 s step: the V99 display and the refreshes of the displays. */
    private function afterStep(): void
    {
        if ($this->steps === self::ENABLE_ENGINE_STEPS || $this->steps % self::GUIDANCE_STEPS === 0) {
            $this->displayP63();
        }
        $this->supplyUserDisplay($this->steps % self::MONITOR_STEPS === 0);
    }

    /** One 2 s guidance pass: sets the thrust direction and the throttle, or hands over to P64. */
    private function guide(int $burnStep): void
    {
        if ($burnStep === self::IGNITION_STEPS) {
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

    /**
     * P63DISPS: V06N63 shows the speed, the altitude rate and the altitude, in ft/s and ft. While the
     * engine waits to be enabled the same noun is flashed as V99N63.
     */
    private function displayP63(): void
    {
        $dsky = $this->agc->dsky;
        $enable = $this->awaitingEngineEnable();
        $dsky->requestDisplay($enable ? 99 : 6, 63, $enable);
        $dsky->supplyData($this->nounValues(63));
    }

    /** Gives the user's own display (V06, V16, V05) the values of the noun it shows, when it asks. */
    private function supplyUserDisplay(bool $monitorDue): void
    {
        $dsky = $this->agc->dsky;
        $noun = $dsky->requestedUserNoun($monitorDue);
        if ($noun !== null) {
            $dsky->supplyUserData($this->nounValues($noun));
        }
    }

    /**
     * The values of a noun of the LM table, in SI units, as the display programs fetch them.
     *
     * @return list<float>
     */
    private function nounValues(int $noun): array
    {
        $altitude = max(0.0, $this->state->altitude(Body::Moon));
        $speedUp = $this->state->radialVelocity();

        return match ($noun) {
            // 09: alarm codes; no program alarm exists yet.
            9 => [0.0, 0.0, 0.0],
            // 60: horizontal velocity, altitude rate, computed altitude.
            60 => [$this->state->horizontalVelocity(), $speedUp, $altitude],
            // 63: absolute value of velocity, altitude rate, computed altitude.
            63 => [hypot($this->state->horizontalVelocity(), $speedUp), $speedUp, $altitude],
            // 64: time left for redesignation (no redesignation in this port), altitude rate, computed altitude.
            64 => [0.0, $speedUp, $altitude],
            // 68: slant range to the landing site, time to go in braking phase, LR altitude minus computed
            // altitude (there is no landing radar model, so no difference).
            68 => [$this->slantRange(), $this->timeToGo(), 0.0],
            default => throw new \LogicException(sprintf('Noun %d has no values in the landing', $noun)),
        };
    }

    /** Distance to the landing site: the ground distance still to cover and the altitude, in metres. */
    private function slantRange(): float
    {
        $groundRange = StartStates::LANDING_SITE_DOWNRANGE - Body::Moon->radius() * $this->state->angle();

        return hypot($groundRange, max(0.0, $this->state->altitude(Body::Moon)));
    }

    /**
     * Time to go in the braking phase: the TTF of the last guidance pass, counted down since. Before
     * the first pass it is the TTF that ROOTPSRS finds for the present state.
     */
    private function timeToGo(): float
    {
        $ttf = $this->guidance->ttf();
        if ($ttf === null || $this->lastGuidanceStep === null) {
            return max(0.0, $this->guidance->timeToGoFrom($this->state));
        }

        return max(0.0, -$ttf - ($this->steps - $this->lastGuidanceStep) * Integrator::STEP);
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
