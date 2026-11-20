<?php

declare(strict_types=1);

namespace Apollo\Chapter\Ascent;

use Apollo\Agc\Agc;
use Apollo\Chapter\Chapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Light;
use Apollo\Guidance\Luminary\AscentCommand;
use Apollo\Guidance\Luminary\AscentGuidance;
use Apollo\Physics\Body;
use Apollo\Physics\Conic;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;
use Apollo\Trace\LogKind;
use Apollo\Trace\Routine;

/**
 * The ascent chapter: P12 with the ascent stage of the lunar module on the surface. The descent stage
 * stays behind. P12LM starts by flashing V06N76, the injection target (desired horizontal and radial
 * velocity, cross-range): the clock of the chapter waits for that PRO, the crew can press it at any time.
 * Then V06N74 counts down to TIG, ASCENT_COUNTDOWN seconds away (P12TABLE shows N74 until TIG-5 s), and
 * from TIG-5 s V99N74 flashes: PRO enables the engine. A PRO before TIG waits for TIG, a late PRO ignites
 * at once and TIG slips to that moment (the vehicle sits on the surface, so nothing else changes). Without
 * PRO the APS never fires: after IGNITION_WAIT_SECONDS the chapter is missed, and without the first PRO
 * after TARGETS_WAIT_SECONDS.
 *
 * At ignition P12IGN connects ATMAG and ASCENT to the SERVICER: every 2 s ASCENT runs (see
 * AscentGuidance), first a vertical rise until the radial velocity is 40 ft/s, then the pitch steering
 * to the target, and ENGOFF shuts the engine in a final tick when TGO is under 4 s. The DSKY shows V06N63
 * during the burn (ASCTERM) and V16N63 after it (CUTOFF); the original flashes that last display with
 * GOFLASH, here it is steady because the chapter ends. The chapter ends at cutoff: success for a bound
 * lunar orbit with a perilune above MIN_PERILUNE, missed for any other orbit. V37 to P00 stops the
 * guidance and the engine; the orbit as it is then decides the outcome. The ground does not count as a
 * crash until the vehicle has lifted off. The Executive is not loaded in this chapter.
 *
 * P12LM also flashes V06N33 (TIG) before V06N76; this port starts with N76 and puts TIG in the countdown.
 *
 * @see Luminary099/P12.agc P12LM
 * @see Luminary099/P12.agc NEWLOAD
 * @see Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc P12TABLE
 * @see Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc V99RECYC
 * @see Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc P12IGN
 * @see Luminary099/ASCENT_GUIDANCE.agc ATMAG
 * @see Luminary099/ASCENT_GUIDANCE.agc ASCENT
 * @see Luminary099/ASCENT_GUIDANCE.agc ENGOFF
 * @see Luminary099/ASCENT_GUIDANCE.agc CUTOFF
 */
final class AscentChapter implements Chapter
{
    /** A perilune above this altitude counts as an orbit, in metres. */
    public const MIN_PERILUNE = 15000.0;

    /**
     * Without PRO on V99 the ignition is not confirmed and the ascent is missed. Counted from the moment
     * V99 starts to flash, in seconds. A PRO inside this wait still starts the burn.
     */
    public const IGNITION_WAIT_SECONDS = 60.0;

    /** Without the first PRO (on the target display) the ascent cannot start either: missed after this wait. */
    public const TARGETS_WAIT_SECONDS = 300.0;

    /** The programs V37 may select: P12 itself and P00, which stops the guidance. */
    private const ALLOWED_PROGRAMS = [12, 0];

    /** Guidance runs every 2 s, like the SERVICER that calls ATMAG, in physics steps of 0.1 s. */
    private const GUIDANCE_STEPS = 20;

    /** The displays refresh every second (CLOKTASK). */
    private const DISPLAY_STEPS = 10;

    /** TIG, counted in steps from the PRO on the target display, and TIG-5 s for V99 (V99RECYC). */
    private const TIG_STEPS = StartStates::ASCENT_COUNTDOWN * 10;
    private const ENABLE_ENGINE_STEPS = self::TIG_STEPS - 50;

    /** A summary of the burn goes in the log once a minute. */
    private const REPORT_STEPS = 600;

    /** Above this altitude the vehicle has lifted off, in metres; before that the surface is not a crash. */
    private const LIFT_OFF_ALTITUDE = 1.0;

    /** A little above the surface that the vehicle has when the radial velocity of the rise is reached, in metres. */
    private const PREVIEW_ALTITUDE = 50.0;

    /** COMP ACTY stays lit for 0.5 s after a guidance pass, so that it is visible between ticks. */
    private const COMP_ACTY_STEPS = 5;

    private StateVector $state;
    private readonly Vehicle $vehicle;
    private readonly Integrator $integrator;
    private readonly Agc $agc;
    private readonly AscentGuidance $guidance;
    /** The pitch of N74 R3, the thrust direction after the vertical rise from the vertical, in radians (negative). */
    private readonly float $pitchAfterRise;
    private AscentPhase $phase = AscentPhase::Targets;
    /** Steps of the countdown since the PRO on the target display. */
    private int $steps = 0;
    private int $targetSteps = 0;
    private int $burnSteps = 0;
    private bool $engineEnabled = false;
    private bool $burnStarted = false;
    private bool $liftedOff = false;
    private bool $riseEnded = false;
    private ?float $ignitionTime = null;
    private ?float $cutoffTime = null;
    /** Mission time at which ENGOFF switches the engine off, once TGO is under 4 s. */
    private ?float $engineOffAt = null;
    private ?int $lastGuidanceStep = null;
    private float $timeToGo = 0.0;
    private float $velocityToGo = 0.0;
    private bool $guidedThisStep = false;
    private ?Outcome $outcome = null;

    public function __construct()
    {
        $this->state = StartStates::ascentState();
        $this->vehicle = StartStates::ascentVehicle();
        $this->integrator = new Integrator();
        $this->guidance = $this->newGuidance();
        $this->pitchAfterRise = $this->previewPitchAfterRise();
        $this->agc = new Agc(ChapterId::Ascent->computer(), 12, 0.0, Routine::P12Lm);
        $this->agc->dsky->showMajorMode($this->agc->majorMode);
        $this->logAgc(
            'P12 starts: the ascent stage waits on the surface, the descent stage stays behind. V06N76 flashes the injection target; the clock waits for PRO',
            Routine::P12Lm,
        );
        $this->displayProgram();
    }

    public function id(): ChapterId
    {
        return ChapterId::Ascent;
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
            match ($this->phase) {
                AscentPhase::Targets => $this->waitForTargets(),
                AscentPhase::Countdown => $this->coast(),
                AscentPhase::Burn => $this->burnStep(),
                AscentPhase::Done => null,
            };
        }
        $this->agc->dsky->setLight(Light::CompActy, $this->guidanceIsComputing());
    }

    public function vehicleView(): array
    {
        $conic = $this->liftedOff ? new Conic($this->state, Body::Moon) : null;
        $apolune = $conic?->apoapsisAltitude();

        return [
            'view' => 'ascent',
            'x' => round(Body::Moon->radius() * $this->state->angle(), 1),
            'altitude' => round(max(0.0, $this->state->altitude(Body::Moon)), 1),
            'vx' => round($this->state->horizontalVelocity(), 2),
            'vz' => round($this->state->radialVelocity(), 2),
            'pitch' => round($this->vehicle->pitch, 4),
            'throttle' => round($this->vehicle->throttle, 2),
            'fuel' => round($this->vehicle->fuelMass() / StartStates::ASCENT_PROPELLANT, 4),
            'liftedOff' => $this->liftedOff,
            'targetAltitude' => StartStates::ASCENT_TARGET_ALTITUDE,
            'bound' => $conic?->isBound() ?? false,
            'perilune' => $conic === null ? null : round($conic->periapsisAltitude(), 0),
            'apolune' => $apolune === null ? null : round($apolune, 0),
            'vg' => round($this->velocityToGo, 1),
            'phase' => strtolower($this->phase->name),
            'program' => $this->agc->majorMode,
        ];
    }

    public function outcome(): ?Outcome
    {
        return $this->outcome;
    }

    /**
     * What the Dsky took from the keyboard: PRO on the flashing display, RSET, and V37. They reach the
     * chapter only as flags of the Dsky, which keeps the chapter free of key handling.
     */
    private function readKeys(): void
    {
        $dsky = $this->agc->dsky;
        if ($dsky->takeProceed()) {
            $this->proceed();
        }
        if ($dsky->takeReset()) {
            $this->agc->executive->clearAlarmCodes();
        }

        $program = $dsky->takeProgramChange();
        if ($this->outcome !== null) {
            return; // The chapter has ended: a V37 no longer changes how it ended.
        }
        if ($program === 0) {
            $this->stopGuidance();
        } elseif ($program !== null) {
            $this->logAgc(sprintf('P%02d is already running, nothing changes', $program), Routine::V37);
        }
    }

    /** PRO: the answer to the V06N76 of the target display, or to the V99 that asks to enable the engine. */
    private function proceed(): void
    {
        if ($this->outcome !== null) {
            return;
        }

        if ($this->phase === AscentPhase::Targets) {
            $this->phase = AscentPhase::Countdown;
            $this->agc->currentRoutine = Routine::P12Table;
            $this->logAgc(
                sprintf('PRO: the target is accepted, V06N74 counts down to TIG, %d s from now', StartStates::ASCENT_COUNTDOWN),
                Routine::P12Table,
            );
            $this->displayProgram();
        } elseif ($this->awaitingEngineEnable()) {
            $this->enableEngine();
        }
    }

    /** V99 is displayed from TIG-5 s until PRO is pressed (V99RECYC). */
    private function awaitingEngineEnable(): bool
    {
        return $this->phase === AscentPhase::Countdown && !$this->engineEnabled && $this->steps >= self::ENABLE_ENGINE_STEPS;
    }

    /** V99RECYC: PRO before TIG waits for TIG, PRO after TIG ignites at once and TIG slips to this moment. */
    private function enableEngine(): void
    {
        $this->engineEnabled = true;
        if ($this->steps < self::TIG_STEPS) {
            $this->agc->currentRoutine = Routine::V99Recyc;
            $this->logAgc('PRO on V99: the V99 flash is cleared, the engine ignites at TIG', Routine::V99Recyc);
            $this->displayProgram();

            return;
        }

        $this->agc->currentRoutine = Routine::V99Recyc;
        $this->logAgc(
            sprintf('PRO on V99 at TIG+%.1f s: the ignition follows at once, TIG slips', $this->agc->missionTime - StartStates::ASCENT_COUNTDOWN),
            Routine::V99Recyc,
        );
        $this->ignite();
    }

    /** V37 to P00: the engine goes off and the guidance stops; the orbit as it is decides the outcome. */
    private function stopGuidance(): void
    {
        $this->vehicle->throttle = 0.0;
        $this->phase = AscentPhase::Done;
        $this->cutoffTime = $this->agc->missionTime;
        $this->agc->majorMode = 0;
        $this->agc->dsky->showMajorMode(0);
        $this->logAgc('P12 → P00: the engine goes off, the guidance stops', Routine::V37);
        $this->judgeOrbit();
        $this->displayProgram();
    }

    /** The target display waits for PRO, without the clock moving; after a long wait it is missed. */
    private function waitForTargets(): void
    {
        $this->targetSteps++;
        if ($this->targetSteps < (int) round(self::TARGETS_WAIT_SECONDS / Integrator::STEP)) {
            return;
        }

        $this->phase = AscentPhase::Done;
        $this->outcome = Outcome::Missed;
        $this->logAgc('No PRO on V06N76: the target was not accepted, the ascent never starts and is missed', Routine::NewLoad);
        $this->displayProgram();
    }

    /** One 0.1 s of countdown on the surface towards TIG: V06N74 counts down, V99N74 flashes from TIG-5 s. */
    private function coast(): void
    {
        $this->agc->missionTime += Integrator::STEP;
        $this->steps++;

        if ($this->steps === self::ENABLE_ENGINE_STEPS) {
            $this->agc->currentRoutine = Routine::V99Recyc;
            $this->logAgc('TIG-5 s: V99N74 flashes, the crew is asked to enable the engine', Routine::V99Recyc);
            $this->displayProgram();
        }
        if ($this->engineEnabled && $this->steps >= self::TIG_STEPS) {
            $this->ignite();

            return;
        }
        if (!$this->engineEnabled && $this->steps - self::ENABLE_ENGINE_STEPS >= (int) round(self::IGNITION_WAIT_SECONDS / Integrator::STEP)) {
            $this->phase = AscentPhase::Done;
            $this->outcome = Outcome::Missed;
            $this->logAgc('No PRO on V99: the ignition was not confirmed, the APS never fires and the ascent is missed', Routine::V99Recyc);
            $this->displayProgram();

            return;
        }
        if ($this->steps % self::DISPLAY_STEPS === 0) {
            $this->displayProgram();
        }
        $this->supplyUserDisplay($this->steps % self::DISPLAY_STEPS === 0);
    }

    /** P12IGN: the APS fires and ATMAG and ASCENT are connected to the SERVICER. */
    private function ignite(): void
    {
        $this->phase = AscentPhase::Burn;
        $this->burnStarted = true;
        $this->ignitionTime = $this->agc->missionTime;
        $this->burnSteps = 0;
        $this->vehicle->throttle = 1.0;
        $this->vehicle->pitch = M_PI / 2.0;
        $this->agc->currentRoutine = Routine::P12Ign;
        $this->logAgc('Ignition: the APS fires, the ascent stage lifts off the descent stage', Routine::P12Ign);
        $this->logAgc(
            'ATMAG and ASCENT run every 2 s: the velocity to be gained, TGO, and the thrust direction that reaches the injection target',
            Routine::AtMag,
        );
        $this->guide();
        $this->displayProgram();
    }

    /** One 0.1 s of burn: the guidance pass when due, the engine, and the engine-off in a final tick. */
    private function burnStep(): void
    {
        if ($this->burnSteps > 0 && $this->burnSteps % self::GUIDANCE_STEPS === 0 && $this->engineOffAt === null) {
            $this->guide();
        }

        $dt = Integrator::STEP;
        $final = false;
        if ($this->engineOffAt !== null) {
            $remaining = $this->engineOffAt - $this->agc->missionTime;
            if ($remaining <= Integrator::STEP + 1e-9) {
                $dt = max(0.0, $remaining);
                $final = true;
            }
        }

        if ($dt > 0.0) {
            $this->state = $this->integrator->step($this->vehicle, $this->state, Body::Moon, $dt);
            $this->agc->missionTime += $dt;
        }
        $this->burnSteps++;
        $this->steps++;

        if ($this->checkGround()) {
            return;
        }
        if ($final) {
            $this->cutOff();

            return;
        }
        if ($this->vehicle->fuelMass() <= 0.0) {
            $this->vehicle->throttle = 0.0;
            $this->phase = AscentPhase::Done;
            $this->outcome = Outcome::OutOfFuel;
            $this->logAgc('Out of fuel: the APS quits before the injection target is reached', Routine::Ascent);
            $this->displayProgram();

            return;
        }

        if ($this->burnSteps % self::REPORT_STEPS === 0) {
            $this->logAgc(
                sprintf(
                    'Burn %.0f s: altitude %.1f km, VG %.0f ft/s, TGO %.0f s',
                    $this->agc->missionTime - ($this->ignitionTime ?? 0.0),
                    $this->state->altitude(Body::Moon) / 1000.0,
                    $this->velocityToGo / 0.3048,
                    max(0.0, $this->timeToGo - ($this->burnSteps - ($this->lastGuidanceStep ?? 0)) * Integrator::STEP),
                ),
                Routine::Ascent,
            );
        }
        if ($this->burnSteps % self::DISPLAY_STEPS === 0) {
            $this->displayProgram();
        }
        $this->supplyUserDisplay($this->burnSteps % self::DISPLAY_STEPS === 0);
    }

    /** ATMAG, then ASCENT: the thrust direction and TGO; under 4 s of TGO ENGOFF sets the engine-off at TGO. */
    private function guide(): void
    {
        $this->agc->currentRoutine = Routine::AtMag;
        $command = $this->guidance->pass($this->state, $this->vehicle->mass, $this->vehicle->throttle * $this->vehicle->maxThrust);
        $this->agc->currentRoutine = Routine::Ascent;
        $this->lastGuidanceStep = $this->burnSteps;
        $this->guidedThisStep = true;
        $this->timeToGo = $command->timeToGo;
        $this->velocityToGo = $command->velocityToGo;
        $this->vehicle->pitch = $command->pitch;

        if (!$command->verticalRise && !$this->riseEnded) {
            $this->riseEnded = true;
            $this->logAgc(
                sprintf(
                    'RDOT is 40 ft/s: the vertical rise ends, the thrust points %.0f degrees above the horizon and steers to the target',
                    rad2deg($command->pitch),
                ),
                Routine::Ascent,
            );
        }
        if ($command->shutDown) {
            $this->scheduleEngineOff($command);
        }
    }

    /** ENGOFF: TGO is under 4 s. The engine is switched off at TGO, in a final tick of the remaining time. */
    private function scheduleEngineOff(AscentCommand $command): void
    {
        $this->engineOffAt = $this->agc->missionTime + $command->timeToGo;
        $this->agc->currentRoutine = Routine::EngOff;
        $this->logAgc(
            sprintf('TGO %.1f s is under 4 s: the steering stops, the engine goes off at TGO', $command->timeToGo),
            Routine::EngOff,
        );
    }

    /** The engine is off: CUTOFF shows V16N63 and the orbit decides the outcome. */
    private function cutOff(): void
    {
        $this->vehicle->throttle = 0.0;
        $this->phase = AscentPhase::Done;
        $this->cutoffTime = $this->agc->missionTime;
        $this->agc->currentRoutine = Routine::CutOff;
        $this->logAgc(
            sprintf(
                'Engine off after %.1f s at %.1f km: horizontal velocity %.1f ft/s, radial velocity %.1f ft/s',
                $this->agc->missionTime - ($this->ignitionTime ?? 0.0),
                $this->state->altitude(Body::Moon) / 1000.0,
                $this->state->horizontalVelocity() / 0.3048,
                $this->state->radialVelocity() / 0.3048,
            ),
            Routine::EngOff,
        );
        $this->judgeOrbit();
        $this->displayProgram();
    }

    /** Success for a bound orbit with a perilune above MIN_PERILUNE, missed for any other orbit. */
    private function judgeOrbit(): void
    {
        if (!$this->liftedOff) {
            $this->outcome = Outcome::Missed;
            $this->logAgc('The ascent stage never left the surface: the ascent is missed', Routine::CutOff);

            return;
        }

        $conic = new Conic($this->state, Body::Moon);
        $apolune = $conic->apoapsisAltitude();
        $success = $conic->isBound() && $conic->periapsisAltitude() > self::MIN_PERILUNE;
        $this->outcome = $success ? Outcome::Success : Outcome::Missed;
        $this->logAgc(
            $conic->isBound()
                ? sprintf(
                    'Orbit: perilune %.1f km, apolune %.1f km: %s',
                    $conic->periapsisAltitude() / 1000.0,
                    ($apolune ?? 0.0) / 1000.0,
                    $success ? 'a lunar orbit' : 'the perilune is too low, the ascent is missed',
                )
                : 'Orbit: still open, the stage is not in orbit, the ascent is missed',
            Routine::CutOff,
        );
    }

    /**
     * The vehicle on the ground. Before lift-off the surface is where it belongs; after it, touching the
     * surface again is a crash.
     */
    private function checkGround(): bool
    {
        $altitude = $this->state->altitude(Body::Moon);
        if (!$this->liftedOff) {
            $this->liftedOff = $altitude > self::LIFT_OFF_ALTITUDE;

            return false;
        }
        if ($altitude > 0.0) {
            return false;
        }

        $this->vehicle->throttle = 0.0;
        $this->phase = AscentPhase::Done;
        $this->outcome = Outcome::Crashed;
        $this->logAgc('The ascent stage hit the lunar surface', Routine::Ascent);

        return true;
    }

    /**
     * V06N76 flashing while the target waits for PRO; V06N74 for the countdown, flashed as V99N74 while the
     * engine waits to be enabled; V06N63 during the burn; V16N63 after it (CUTOFF).
     */
    private function displayProgram(): void
    {
        $dsky = $this->agc->dsky;
        if ($this->phase === AscentPhase::Targets) {
            $dsky->requestDisplay(6, 76, true);
            $dsky->supplyData($this->nounValues(76));

            return;
        }

        $noun = $this->phase === AscentPhase::Burn || ($this->phase === AscentPhase::Done && $this->burnStarted) ? 63 : 74;
        $enable = $this->awaitingEngineEnable();
        $verb = $this->phase === AscentPhase::Done && $this->burnStarted ? 16 : ($enable ? 99 : 6);
        $dsky->requestDisplay($verb, $noun, $enable);
        $dsky->supplyData($this->nounValues($noun));
    }

    private function logAgc(string $text, Routine $routine): void
    {
        $this->agc->log->record(LogKind::Agc, $text, $routine, $this->agc->missionTime);
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
        $horizontal = $this->state->horizontalVelocity();
        $radial = $this->state->radialVelocity();

        return match ($noun) {
            // 09: the three alarm registers (octal).
            9 => array_map(floatval(...), $this->agc->executive->alarmCodes()),
            // 60: horizontal velocity, altitude rate, computed altitude.
            60 => [$horizontal, $radial, $altitude],
            // 63: absolute value of velocity, altitude rate, computed altitude.
            63 => [hypot($horizontal, $radial), $radial, $altitude],
            // 64: time left for redesignation (none in ascent), altitude rate, computed altitude.
            64 => [0.0, $radial, $altitude],
            // 65: sampled AGC time in hours, minutes and seconds: the mission clock of this chapter.
            65 => [$this->agc->missionTime, $this->agc->missionTime, $this->agc->missionTime],
            // 68: slant range to the landing site, time to go in braking phase (none), LR altitude difference (no radar).
            68 => [hypot(Body::Moon->radius() * $this->state->angle(), $altitude), 0.0, 0.0],
            // 74: time from ignition, yaw after vehicle rise (no cross-range), pitch after vehicle rise.
            74 => [$this->timeFromIgnition(), 0.0, $this->pitchAfterRise],
            // 76: desired horizontal velocity, desired radial velocity, cross-range distance (none).
            76 => [StartStates::ASCENT_TARGET_HORIZONTAL_SPEED, StartStates::ASCENT_TARGET_RADIAL_SPEED, 0.0],
            default => throw new \LogicException(sprintf('Noun %d has no values in the ascent', $noun)),
        };
    }

    /** N74: negative while TIG counts down, from ignition during the burn and from cutoff after it. */
    private function timeFromIgnition(): float
    {
        $time = $this->agc->missionTime;
        $from = match ($this->phase) {
            AscentPhase::Burn => $this->ignitionTime ?? $time,
            AscentPhase::Done => $this->cutoffTime ?? StartStates::ASCENT_COUNTDOWN,
            default => StartStates::ASCENT_COUNTDOWN,
        };

        return $time - $from;
    }

    private function newGuidance(): AscentGuidance
    {
        return new AscentGuidance(
            StartStates::ASCENT_TARGET_HORIZONTAL_SPEED,
            StartStates::ASCENT_TARGET_RADIAL_SPEED,
            Body::Moon->radius() + StartStates::ASCENT_TARGET_ALTITUDE,
            $this->vehicle->isp * Integrator::G0,
        );
    }

    /**
     * P12RET: the pitch after the vehicle rise as N74 shows it, the angle of the thrust from the vertical
     * (negative) at the end of the vertical rise. A pass of a guidance of its own gives it, from a state
     * just above the surface with the radial velocity at which the rise ends.
     */
    private function previewPitchAfterRise(): float
    {
        $state = new StateVector(
            0.0,
            Body::Moon->radius() + self::PREVIEW_ALTITUDE,
            0.0,
            AscentGuidance::VERTICAL_RISE_EXIT_SPEED + 0.1,
        );
        $command = $this->newGuidance()->pass($state, $this->vehicle->mass, $this->vehicle->maxThrust);

        return $command->pitch - M_PI / 2.0;
    }

    private function guidanceIsComputing(): bool
    {
        return $this->guidedThisStep
            || ($this->lastGuidanceStep !== null && $this->phase === AscentPhase::Burn && $this->burnSteps - $this->lastGuidanceStep < self::COMP_ACTY_STEPS);
    }
}
