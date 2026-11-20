<?php

declare(strict_types=1);

namespace Apollo\Guidance\Comanche;

use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;
use Apollo\Trace\Routine;

/**
 * A simplified port of the entry guidance of the command module (REENTRY_CONTROL.agc). It commands the
 * bank angle, the roll about the velocity relative to the air, every 2 s, as the SERVICER passes of the
 * original do. The phases follow the sequence of GOTOADDR in the original:
 *
 * - P63 (STARTENT) waits for the first drag, 0.05 g (.05G), then switches to P64.
 * - P64 (UPCONTRL) holds the lift up (a bank angle with an upward lift, LIFT_UP_BANK) until the speed
 *   relative to the air drops under 5 500 m/s, then switches to P67. If the drag has built up and falls
 *   away again below Q7 (6 ft/s^2) the capsule is climbing back out and it switches to P66.
 * - P66 (KEP2) is the ballistic phase: the last bank angle is kept until the drag is above Q7 + 0.5 ft/s^2,
 *   then P67 follows.
 * - P67 (PREDICT3) picks the bank angle with a predictor: it integrates the rest of the trajectory with
 *   the bank angle in question down to the parachute altitude, and bisects on cos(bank) until the
 *   predicted range is the range of the target. The original steers with reference tables (VREFER) that
 *   are not reproduced here. Steering stops under VQUIT (1000 ft/s); the last bank angle is kept.
 *
 * P65 (the up-control skip phase) is not included. The predictor runs inside a tick, so it takes a
 * coarse, adaptive step and a bounded number of steps and bisections.
 *
 * @see Comanche055/REENTRY_CONTROL.agc STARTENT
 * @see Comanche055/REENTRY_CONTROL.agc UPCONTRL
 * @see Comanche055/REENTRY_CONTROL.agc KEP2
 * @see Comanche055/REENTRY_CONTROL.agc PREDICT3
 */
final class EntryGuidance
{
    /** The guidance runs every 2 s, in physics steps of 0.1 s. */
    public const PERIOD_STEPS = 20;

    /** Standard gravity in m/s^2, the "g" of the drag thresholds. */
    public const G0 = 9.80665;

    /** The drag at which the entry starts: .05G, 0.05 g. */
    public const START_DRAG = 0.05 * self::G0;

    /** Q7F: 6 ft/s^2. P64 gives way to P66 when the drag falls under it. */
    public const Q7 = 6.0 * 0.3048;

    /** Q7F + KDMIN: 6.5 ft/s^2. P66 gives way to P67 when the drag is above it. */
    public const Q7_RETURN = 6.5 * 0.3048;

    /** P64 holds the lift up until the speed relative to the air is under this, in m/s. */
    public const UP_CONTROL_END_SPEED = 5500.0;

    /** VQUIT: 1000 ft/s. PREDICT3 stops steering under this speed, in m/s. */
    public const QUIT_SPEED = 1000.0 * 0.3048;

    /**
     * The lift-up bank angle of P64, in radians (60 degrees: cos = 0.5, the lift points up). The original
     * holds a roll attitude keyed to the .05 g point (INITROLL); at this lift-to-drag ratio a bank angle of
     * 0 would skip out of the atmosphere on the entry angle of this chapter.
     */
    public const LIFT_UP_BANK = M_PI / 3.0;

    /** The altitude the predictor flies to and the range is measured at, in m: the parachutes open here. */
    public const TARGET_ALTITUDE = 7000.0;

    /** The altitude of the entry interface in m: above it, flying up, the capsule has skipped out. */
    public const SKIP_ALTITUDE = 122000.0;

    private const PREDICTOR_BISECTIONS = 10;
    private const PREDICTOR_MAX_STEPS = 400;
    private const PREDICTOR_MAX_STEP = 5.0;
    private const PREDICTOR_MIN_STEP = 0.25;

    /** The predictor steps no more than this fraction of the time the drag needs to change the speed. */
    private const PREDICTOR_STEP_FRACTION = 0.5;

    private EntryProgram $program = EntryProgram::P63;
    private float $bank = self::LIFT_UP_BANK;
    private float $predictedMiss = 0.0;
    private bool $dragBuilt = false;
    private bool $steering = true;
    private bool $stopped = false;
    private Routine $routine = Routine::StartEnt;

    /**
     * @param float $targetAngle ground-track angle of the target in radians (see StateVector::angle())
     */
    public function __construct(private readonly float $targetAngle)
    {
    }

    public function program(): EntryProgram
    {
        return $this->program;
    }

    /** The commanded bank angle in radians: 0 lift up, pi lift down. */
    public function bank(): float
    {
        return $this->bank;
    }

    /** How far the capsule is predicted to land past the target, in m (negative: short). Zero before P67. */
    public function predictedMiss(): float
    {
        return $this->predictedMiss;
    }

    /** False once PREDICT3 has stopped steering (under VQUIT), or the guidance was stopped. */
    public function isSteering(): bool
    {
        return $this->steering && !$this->stopped;
    }

    /** The routine of the original that the last pass went through. */
    public function routine(): Routine
    {
        return $this->routine;
    }

    /** P00: the guidance stops, the bank angle stays where it is. */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * One pass of the guidance.
     *
     * @param float $drag the measured drag acceleration in m/s^2 (the PIPAs sense it in the original)
     */
    public function guide(StateVector $state, Vehicle $vehicle, float $drag): void
    {
        if ($this->stopped) {
            return;
        }

        $speed = $state->airSpeed(Body::Earth);
        match ($this->program) {
            EntryProgram::P63 => $this->waitForDrag($drag),
            EntryProgram::P64 => $this->upControl($state, $vehicle, $drag, $speed),
            EntryProgram::P66 => $this->kepler($state, $vehicle, $drag, $speed),
            EntryProgram::P67 => $this->finalPhase($state, $vehicle, $speed),
        };
    }

    /** STARTENT: the initial roll attitude is held until the drag reaches .05G. */
    private function waitForDrag(float $drag): void
    {
        $this->routine = Routine::StartEnt;
        if ($drag >= self::START_DRAG) {
            $this->program = EntryProgram::P64;
        }
    }

    /** UPCONTRL: the lift stays up until the speed is under the end of the up-control phase. */
    private function upControl(StateVector $state, Vehicle $vehicle, float $drag, float $speed): void
    {
        $this->routine = Routine::UpControl;
        $this->bank = self::LIFT_UP_BANK;
        if ($drag > self::Q7_RETURN) {
            $this->dragBuilt = true;
        }

        if ($speed < self::UP_CONTROL_END_SPEED) {
            $this->program = EntryProgram::P67;
            $this->finalPhase($state, $vehicle, $speed);
        } elseif ($this->dragBuilt && $drag < self::Q7) {
            $this->program = EntryProgram::P66;
        }
    }

    /** KEP2: ballistic; the last bank angle is kept and the drag is watched. */
    private function kepler(StateVector $state, Vehicle $vehicle, float $drag, float $speed): void
    {
        $this->routine = Routine::Kep2;
        if ($drag > self::Q7_RETURN) {
            $this->program = EntryProgram::P67;
            $this->finalPhase($state, $vehicle, $speed);
        }
    }

    /** PREDICT3: the bank angle that brings the predicted range to the target; steering stops under VQUIT. */
    private function finalPhase(StateVector $state, Vehicle $vehicle, float $speed): void
    {
        $this->routine = Routine::Predict3;
        if ($speed < self::QUIT_SPEED) {
            $this->steering = false;

            return;
        }

        $capsule = new Vehicle(
            mass: $vehicle->mass,
            dryMass: $vehicle->dryMass,
            maxThrust: 0.0,
            isp: $vehicle->isp,
            dragArea: $vehicle->dragArea,
            liftToDrag: $vehicle->liftToDrag,
        );
        $integrator = new Integrator();

        $miss = $this->miss($integrator, $capsule, $state, 1.0);
        if ($miss <= 0.0) {
            // short even with the lift fully up
            $this->bank = 0.0;
            $this->predictedMiss = $miss;

            return;
        }
        $miss = $this->miss($integrator, $capsule, $state, -1.0);
        if ($miss >= 0.0) {
            // long even with the lift fully down
            $this->bank = M_PI;
            $this->predictedMiss = $miss;

            return;
        }

        // The range grows with cos(bank): bisect on it.
        $low = -1.0;
        $high = 1.0;
        $cosine = 0.0;
        for ($i = 0; $i < self::PREDICTOR_BISECTIONS; $i++) {
            $cosine = ($low + $high) / 2.0;
            $miss = $this->miss($integrator, $capsule, $state, $cosine);
            if ($miss > 0.0) {
                $high = $cosine;
            } else {
                $low = $cosine;
            }
        }

        $this->bank = acos($cosine);
        $this->predictedMiss = $miss;
    }

    /** Predicted ground range past the target in metres for a bank angle with the given cosine. */
    private function miss(Integrator $integrator, Vehicle $capsule, StateVector $state, float $cosine): float
    {
        $capsule->bank = acos($cosine);

        return ($this->predictedAngle($integrator, $capsule, $state) - $this->targetAngle) * Body::Earth->radius();
    }

    /**
     * Integrates the rest of the trajectory with the bank angle of $capsule and returns the ground-track
     * angle where it crosses TARGET_ALTITUDE. A trajectory that skips out or is not down within the step
     * limit counts as far too long.
     */
    private function predictedAngle(Integrator $integrator, Vehicle $capsule, StateVector $state): float
    {
        $atmosphere = Body::Earth->atmosphere();
        $dragPerKilogram = $capsule->dragArea / $capsule->mass;
        $altitude = $state->altitude(Body::Earth);

        for ($i = 0; $i < self::PREDICTOR_MAX_STEPS && $altitude > self::TARGET_ALTITUDE; $i++) {
            $rate = $atmosphere === null ? 0.0 : $atmosphere->density($altitude) * $state->airSpeed(Body::Earth) * $dragPerKilogram;
            $step = $rate > 0.0 ? min(self::PREDICTOR_MAX_STEP, max(self::PREDICTOR_MIN_STEP, self::PREDICTOR_STEP_FRACTION / $rate)) : self::PREDICTOR_MAX_STEP;

            $next = $integrator->step($capsule, $state, Body::Earth, $step);
            $nextAltitude = $next->altitude(Body::Earth);
            if ($nextAltitude <= self::TARGET_ALTITUDE) {
                $fraction = ($altitude - self::TARGET_ALTITUDE) / ($altitude - $nextAltitude);

                return $state->angle() + $fraction * ($next->angle() - $state->angle());
            }
            if ($nextAltitude > self::SKIP_ALTITUDE && $next->radialVelocity() > 0.0) {
                return $next->angle() + 1.0;
            }
            $state = $next;
            $altitude = $nextAltitude;
        }

        return $altitude > self::TARGET_ALTITUDE ? $state->angle() + 1.0 : $state->angle();
    }
}
