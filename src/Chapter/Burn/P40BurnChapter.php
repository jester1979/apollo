<?php

declare(strict_types=1);

namespace Apollo\Chapter\Burn;

use Apollo\Agc\Agc;
use Apollo\Chapter\Chapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Light;
use Apollo\Guidance\Comanche\BurnCommand;
use Apollo\Guidance\Comanche\ExternalDeltaVBurn;
use Apollo\Guidance\Comanche\LaunchMonitor;
use Apollo\Physics\Body;
use Apollo\Physics\Conic;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Trail;
use Apollo\Physics\Vehicle;
use Apollo\Simulation\TickInput;
use Apollo\Trace\LogKind;
use Apollo\Trace\Routine;

/**
 * The P40 burn chapter that lunar orbit insertion and trans-Earth injection share: P40 with an external
 * delta-V. The chapter differs from the next one only in its start state, its vehicle, the delta-V vector
 * (BurnSpec), the texts and the judgement of the orbit after the burn (judge()).
 *
 * P40CSM starts with the checklist: V50N25 flashes with R1 = 00203 (switch to CMC-AUTO). The clock of the
 * chapter and the vehicle wait for that PRO, the crew can press it at any time. Then V06N40 counts down to
 * TIG, BurnSpec::countdown seconds away, and from TIG-5 s V99N40 flashes: PRO enables the engine. A PRO
 * before TIG waits for TIG (V99P/TIG), a late PRO ignites at once and TIG slips to that moment (the target
 * is a velocity to be gained, not a point of the orbit, so a late start only moves the burn along the
 * orbit). Without PRO the SPS never fires: after IGNITION_WAIT_SECONDS the chapter is missed.
 *
 * The burn gains the delta-V along or against the velocity at ignition (see ExternalDeltaVBurn: UPDATEVG,
 * STEERING, S40.8 every 2 s, and S40.81 for the engine-off in a final tick). While the engine burns, the
 * orbit it predicts changes: it bends from an open hyperbola into a closed ellipse, or the other way round.
 * After the burn the DSKY shows V16N40 (POSTBURN) and the chapter ends with the judgement of the orbit. V37
 * to P00 stops the guidance and the engine; the orbit as it is then decides the outcome. The Executive is
 * not loaded in this chapter.
 *
 * The keys of the user are handled by the Dsky; this chapter reads what they asked for (PRO, RSET, V37 and
 * the nouns of a user display).
 *
 * @see Comanche055/P40-P47.agc P40CSM
 * @see Comanche055/P40-P47.agc TIG-5
 * @see Comanche055/P40-P47.agc V99P
 * @see Comanche055/P40-P47.agc V99P/TIG
 * @see Comanche055/P40-P47.agc POSTBURN
 */
abstract class P40BurnChapter implements Chapter
{
    /**
     * Without PRO on V99 the ignition is not confirmed and the burn is missed. Counted from the moment
     * V99 starts to flash, in seconds. A PRO inside this wait still starts the burn.
     */
    public const IGNITION_WAIT_SECONDS = 60.0;

    /** Without PRO on the checklist V50 the burn cannot start either: the chapter is missed after this wait. */
    public const CHECKLIST_WAIT_SECONDS = 300.0;

    /** The checklist code of V50N25: switch to CMC-AUTO. */
    public const CHECKLIST_CODE = 203.0;

    /** The programs V37 may select: P40 itself and P00, which stops the guidance. */
    private const ALLOWED_PROGRAMS = [40, 0];

    /** Guidance runs every 2 s, like the SERVICER that calls STEERING, in physics steps of 0.1 s. */
    private const GUIDANCE_STEPS = 20;

    /** The displays refresh every second (CLOKTASK). */
    private const DISPLAY_STEPS = 10;

    /** A summary of the burn goes in the log once a minute. */
    private const REPORT_STEPS = 600;

    /** Points of the predicted path. */
    private const PREDICTION_POINTS = 120;

    /** COMP ACTY stays lit for 0.5 s after a guidance pass, so that it is visible between ticks. */
    private const COMP_ACTY_STEPS = 5;

    private StateVector $state;
    private readonly Vehicle $vehicle;
    /** The propellant at the start, in kg; the view shows what is left of it. */
    private readonly float $propellant;
    /** TIG, counted in steps from the PRO of the checklist, and TIG-5 s for V99 (TIG-5). */
    private readonly int $tigSteps;
    private readonly int $enableEngineSteps;
    private readonly Integrator $integrator;
    private readonly Agc $agc;
    private readonly Trail $trail;
    private BurnPhase $phase = BurnPhase::Checklist;
    private ?ExternalDeltaVBurn $burn = null;
    /** Steps of coast since the PRO of the checklist. */
    private int $steps = 0;
    private int $checklistSteps = 0;
    private int $burnSteps = 0;
    private bool $engineEnabled = false;
    private ?float $ignitionTime = null;
    private ?float $cutoffTime = null;
    /** Mission time at which S40.81 switches the engine off, once TGO is under 4 s. */
    private ?float $engineOffAt = null;
    /** Inertial direction of the thrust, held between guidance passes. */
    private float $directionX = 0.0;
    private float $directionY = 0.0;
    private float $sensedX = 0.0;
    private float $sensedY = 0.0;
    private ?int $lastGuidanceStep = null;
    /** TGO of the last guidance pass and the mission time of that pass. */
    private float $timeToGo = 0.0;
    private float $timeToGoAt = 0.0;
    private bool $guidedThisStep = false;
    private ?Outcome $outcome = null;

    /**
     * @param StateVector $state the start state, a coast before the burn
     * @param Vehicle $vehicle the vehicle with its engine off; the chapter sets its pitch
     */
    protected function __construct(
        private readonly ChapterId $id,
        StateVector $state,
        Vehicle $vehicle,
        private readonly BurnSpec $spec,
    ) {
        $this->state = $state;
        $this->vehicle = $vehicle;
        $this->propellant = $vehicle->fuelMass();
        $this->tigSteps = $spec->countdown * 10;
        $this->enableEngineSteps = $this->tigSteps - 50;
        $this->integrator = new Integrator();
        $this->trail = new Trail();
        $this->agc = new Agc($id->computer(), 40, 0.0, Routine::P40Csm);
        $this->agc->dsky->showMajorMode($this->agc->majorMode);
        $this->aimAtBurnAttitude();
        $this->trail->record(0.0, $this->state);
        $this->logAgc($spec->startNote, Routine::P40Csm);
        $this->displayProgram();
    }

    /**
     * The judgement of the orbit once the engine is off: whether the burn reached its goal, and the log line
     * that tells why. Called after the burn and after a V37 to P00.
     *
     * @return array{bool, string} success and the text for the log
     */
    abstract protected function judge(Conic $conic): array;

    public function id(): ChapterId
    {
        return $this->id;
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
                BurnPhase::Checklist => $this->waitForChecklist(),
                BurnPhase::Countdown => $this->coast(),
                BurnPhase::Burn => $this->burnStep(),
                BurnPhase::Done => null,
            };
        }
        $this->agc->dsky->setLight(Light::CompActy, $this->guidanceIsComputing());
    }

    public function vehicleView(): array
    {
        $conic = new Conic($this->state, Body::Moon);
        $trail = $this->trail->points();
        $trail[] = [$this->state->x, $this->state->y];
        $apolune = $conic->apoapsisAltitude();

        return [
            'view' => 'orbit',
            'x' => round($this->state->x, 1),
            'y' => round($this->state->y, 1),
            'altitude' => round($this->state->altitude(Body::Moon), 1),
            'speed' => round(hypot($this->state->vx, $this->state->vy), 1),
            'bodyRadius' => Body::Moon->radius(),
            'extent' => $this->spec->viewExtent,
            'thrustAngle' => round($this->thrustAngle(), 4),
            'throttle' => round($this->vehicle->throttle, 2),
            'fuel' => round($this->vehicle->fuelMass() / $this->propellant, 4),
            'trail' => self::inKilometres($trail),
            'prediction' => self::inKilometres($conic->path(self::PREDICTION_POINTS, $this->spec->predictionRadius)),
            'bound' => $conic->isBound(),
            'eccentricity' => round($conic->eccentricity, 4),
            'perilune' => round($conic->periapsisAltitude(), 0),
            'apolune' => $apolune === null ? null : round($apolune, 0),
            'vg' => round($this->burn?->velocityToBeGained() ?? $this->spec->deltaV, 1),
            'phase' => strtolower($this->phase->name),
            'program' => $this->agc->majorMode,
        ];
    }

    public function outcome(): ?Outcome
    {
        return $this->outcome;
    }

    /**
     * What the Dsky took from the keyboard: PRO on the flashing V50 or V99, RSET, and V37. They reach the
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
        if ($program === 0) {
            $this->stopGuidance();
        } elseif ($program !== null) {
            $this->logAgc(sprintf('P%02d is already running, nothing changes', $program), Routine::V37);
        }
    }

    /** PRO: the answer to the V50 of the checklist, or to the V99 that asks to enable the engine. */
    private function proceed(): void
    {
        if ($this->outcome !== null) {
            return;
        }

        if ($this->phase === BurnPhase::Checklist) {
            $this->phase = BurnPhase::Countdown;
            $this->agc->currentRoutine = Routine::P40Csm;
            $this->logAgc(
                sprintf('PRO: CMC-AUTO is set, V06N40 counts down to TIG, %.0f s from now', $this->spec->countdown),
                Routine::P40Csm,
            );
            $this->displayProgram();
        } elseif ($this->awaitingEngineEnable()) {
            $this->enableEngine();
        }
    }

    /** V99 is displayed from TIG-5 s until PRO is pressed (TIG-5). */
    private function awaitingEngineEnable(): bool
    {
        return $this->phase === BurnPhase::Countdown && !$this->engineEnabled && $this->steps >= $this->enableEngineSteps;
    }

    /**
     * V99P: PRO before TIG waits for TIG, PRO after TIG ignites at once and TIG slips to this moment.
     */
    private function enableEngine(): void
    {
        $this->engineEnabled = true;
        if ($this->steps < $this->tigSteps) {
            $this->agc->currentRoutine = Routine::V99PTig;
            $this->logAgc('PRO on V99: the V99 flash is cleared, the engine ignites at TIG', Routine::V99PTig);
            $this->displayProgram();

            return;
        }

        $this->agc->currentRoutine = Routine::V99P;
        $this->logAgc(
            sprintf('PRO on V99 at TIG+%.1f s: the ignition follows at once, TIG slips', $this->agc->missionTime - $this->spec->countdown),
            Routine::V99P,
        );
        $this->ignite();
    }

    /** V37 to P00: the engine goes off and the guidance stops; the orbit as it is decides the outcome. */
    private function stopGuidance(): void
    {
        $this->burn?->updateVg($this->sensedX, $this->sensedY);
        $this->sensedX = 0.0;
        $this->sensedY = 0.0;
        $this->vehicle->throttle = 0.0;
        $this->phase = BurnPhase::Done;
        $this->cutoffTime = $this->agc->missionTime;
        $this->agc->majorMode = 0;
        $this->agc->dsky->showMajorMode(0);
        $this->logAgc('P40 → P00: the engine goes off, the guidance stops', Routine::V37);
        $this->judgeOrbit();
        $this->displayProgram();
    }

    /** The checklist waits for PRO, without the clock or the vehicle moving; after a long wait it is missed. */
    private function waitForChecklist(): void
    {
        $this->checklistSteps++;
        if ($this->checklistSteps < (int) round(self::CHECKLIST_WAIT_SECONDS / Integrator::STEP)) {
            return;
        }

        $this->phase = BurnPhase::Done;
        $this->outcome = Outcome::Missed;
        $this->logAgc(sprintf('No PRO on V50N25: the checklist was not completed, there is no burn and the %s is missed', $this->spec->burnName), Routine::P40Csm);
        $this->displayProgram();
    }

    /** One 0.1 s of coast towards TIG: V06N40 counts down, V99N40 flashes from TIG-5 s. */
    private function coast(): void
    {
        $this->aimAtBurnAttitude();
        $this->integrate(Integrator::STEP);
        $this->steps++;
        if ($this->checkCrash()) {
            return;
        }

        if ($this->steps === $this->enableEngineSteps) {
            $this->agc->currentRoutine = Routine::Tig5;
            $this->logAgc('TIG-5 s: V99N40 flashes, the crew is asked to enable the engine', Routine::Tig5);
            $this->displayProgram();
        }
        if ($this->engineEnabled && $this->steps >= $this->tigSteps) {
            $this->ignite();

            return;
        }
        if (!$this->engineEnabled && $this->steps - $this->enableEngineSteps >= (int) round(self::IGNITION_WAIT_SECONDS / Integrator::STEP)) {
            $this->phase = BurnPhase::Done;
            $this->outcome = Outcome::Missed;
            $this->logAgc(sprintf('No PRO on V99: the ignition was not confirmed, there is no burn and the %s is missed', $this->spec->burnName), Routine::V99PTig);
            $this->displayProgram();

            return;
        }
        if ($this->steps % self::DISPLAY_STEPS === 0) {
            $this->displayProgram();
        }
        $this->supplyUserDisplay($this->steps % self::DISPLAY_STEPS === 0);
    }

    /**
     * IGNITION: the SPS fires. The velocity to be gained is retrograde with respect to the velocity at this
     * moment: a late ignition changes the burn point, not the size of the burn.
     */
    private function ignite(): void
    {
        $speed = hypot($this->state->vx, $this->state->vy);
        $this->burn = new ExternalDeltaVBurn(
            $this->spec->sign() * $this->state->vx / $speed * $this->spec->deltaV,
            $this->spec->sign() * $this->state->vy / $speed * $this->spec->deltaV,
            $this->vehicle->isp * Integrator::G0,
        );
        $this->phase = BurnPhase::Burn;
        $this->ignitionTime = $this->agc->missionTime;
        $this->burnSteps = 0;
        $this->vehicle->throttle = 1.0;
        $this->agc->currentRoutine = Routine::Steering;
        $this->logAgc(
            sprintf('Ignition: the SPS burns %s, VG %.0f ft/s', $this->spec->prograde ? 'prograde' : 'retrograde', $this->spec->deltaV / 0.3048),
            Routine::P40Csm,
        );
        $this->logAgc(
            'STEERING runs every 2 s: UPDATEVG takes the measured delta-V off VG, S40.8 points the thrust along VG and computes TGO',
            Routine::Steering,
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

        $this->vehicle->pitch = ExternalDeltaVBurn::pitchFor($this->state, $this->directionX, $this->directionY);
        if ($dt > 0.0 && $this->burn !== null) {
            [$senseX, $senseY] = $this->burn->sensedDeltaV($this->vehicle, $this->state, $dt);
            $this->sensedX += $senseX;
            $this->sensedY += $senseY;
            $this->integrate($dt);
        }
        $this->burnSteps++;
        $this->steps++;

        if ($this->checkCrash()) {
            return;
        }
        if ($final) {
            $this->cutOff();

            return;
        }
        if ($this->vehicle->fuelMass() <= 0.0) {
            $this->vehicle->throttle = 0.0;
            $this->phase = BurnPhase::Done;
            $this->outcome = Outcome::OutOfFuel;
            $this->logAgc('Out of fuel: the SPS quits before the velocity to be gained is gone', Routine::S408);
            $this->displayProgram();

            return;
        }

        if ($this->burnSteps % self::REPORT_STEPS === 0) {
            $this->logAgc(
                sprintf(
                    'Burn %.0f s: VG %.0f ft/s, TGO %.0f s',
                    $this->agc->missionTime - ($this->ignitionTime ?? 0.0),
                    ($this->burn?->velocityToBeGained() ?? 0.0) / 0.3048,
                    max(0.0, $this->timeToGo - ($this->agc->missionTime - $this->timeToGoAt)),
                ),
                Routine::S408,
            );
        }
        if ($this->burnSteps % self::DISPLAY_STEPS === 0) {
            $this->displayProgram();
        }
        $this->supplyUserDisplay($this->burnSteps % self::DISPLAY_STEPS === 0);
    }

    /**
     * STEERING: UPDATEVG with the delta-V measured since the last pass, then S40.8. Under 4 s of TGO
     * S40.81 holds the attitude and the engine-off is set for the end of TGO.
     */
    private function guide(): void
    {
        if ($this->burn === null) {
            return;
        }

        $this->agc->currentRoutine = Routine::Steering;
        $command = $this->burn->pass(
            $this->sensedX,
            $this->sensedY,
            $this->vehicle->mass,
            $this->vehicle->throttle * $this->vehicle->maxThrust,
        );
        $this->sensedX = 0.0;
        $this->sensedY = 0.0;
        $this->lastGuidanceStep = $this->steps;
        $this->guidedThisStep = true;
        $this->timeToGo = $command->timeToGo;
        $this->timeToGoAt = $this->agc->missionTime;
        $this->directionX = $command->directionX;
        $this->directionY = $command->directionY;
        if ($command->shutDown) {
            $this->scheduleEngineOff($command);
        }
    }

    /** S40.81: TGO is under 4 s. The engine is switched off at TGO, in a final tick of the remaining time. */
    private function scheduleEngineOff(BurnCommand $command): void
    {
        $this->engineOffAt = $this->agc->missionTime + $command->timeToGo;
        $this->agc->currentRoutine = Routine::S4081;
        $this->logAgc(
            sprintf('TGO %.1f s is under 4 s: the steering stops, the engine goes off at TGO', $command->timeToGo),
            Routine::S4081,
        );
    }

    /** The engine is off: POSTBURN shows V16N40 and the orbit decides the outcome. */
    private function cutOff(): void
    {
        // The last measurement goes into VG too, so that R2 and R3 show the whole burn.
        $this->burn?->updateVg($this->sensedX, $this->sensedY);
        $this->sensedX = 0.0;
        $this->sensedY = 0.0;
        $this->vehicle->throttle = 0.0;
        $this->phase = BurnPhase::Done;
        $this->cutoffTime = $this->agc->missionTime;
        $this->agc->currentRoutine = Routine::PostBurn;
        $this->logAgc(
            sprintf(
                'Engine off after %.1f s: %.1f ft/s gained, %.1f ft/s of VG left',
                $this->agc->missionTime - ($this->ignitionTime ?? 0.0),
                ($this->burn?->accumulated() ?? 0.0) / 0.3048,
                ($this->burn?->velocityToBeGained() ?? 0.0) / 0.3048,
            ),
            Routine::S4081,
        );
        $this->judgeOrbit();
        $this->displayProgram();
    }

    /** The orbit as it is now decides the outcome: success or missed, as the chapter judges it. */
    private function judgeOrbit(): void
    {
        [$success, $text] = $this->judge(new Conic($this->state, Body::Moon));
        $this->outcome = $success ? Outcome::Success : Outcome::Missed;
        $this->logAgc($text, Routine::PostBurn);
    }

    /** The vehicle hits the surface. */
    private function checkCrash(): bool
    {
        if ($this->state->altitude(Body::Moon) > 0.0) {
            return false;
        }

        $this->vehicle->throttle = 0.0;
        $this->phase = BurnPhase::Done;
        $this->outcome = Outcome::Crashed;
        $this->logAgc('The vehicle hit the lunar surface', Routine::P40Csm);

        return true;
    }

    private function integrate(float $dt): void
    {
        $this->state = $this->integrator->step($this->vehicle, $this->state, Body::Moon, $dt);
        $this->agc->missionTime += $dt;
        $this->trail->record($this->agc->missionTime, $this->state);
    }

    /** Until the burn the vehicle points along the burn direction: along or against its velocity. */
    private function aimAtBurnAttitude(): void
    {
        $speed = hypot($this->state->vx, $this->state->vy);
        $this->directionX = $this->spec->sign() * $this->state->vx / $speed;
        $this->directionY = $this->spec->sign() * $this->state->vy / $speed;
        $this->vehicle->pitch = ExternalDeltaVBurn::pitchFor($this->state, $this->directionX, $this->directionY);
    }

    /** The direction of the thrust in the inertial frame, as an angle from the +x axis (y up). */
    private function thrustAngle(): float
    {
        $r = $this->state->radius();
        $upX = $this->state->x / $r;
        $upY = $this->state->y / $r;
        $pitch = $this->vehicle->pitch;

        return atan2(
            -cos($pitch) * $upX + sin($pitch) * $upY,
            cos($pitch) * $upY + sin($pitch) * $upX,
        );
    }

    /**
     * V50N25 while the checklist waits; V06N40 for the countdown and the burn, flashed as V99N40 while the
     * engine waits to be enabled; V16N40 after the burn (POSTBURN).
     */
    private function displayProgram(): void
    {
        $dsky = $this->agc->dsky;
        if ($this->phase === BurnPhase::Checklist) {
            $dsky->requestDisplay(50, 25, true);
            $dsky->supplyData([self::CHECKLIST_CODE, 0.0, 0.0]);

            return;
        }

        $enable = $this->awaitingEngineEnable();
        // POSTBURN's V16N40 follows a burn; a chapter that ends without one keeps V06N40.
        $postBurn = $this->phase === BurnPhase::Done && $this->burn !== null;
        $dsky->requestDisplay($enable ? 99 : ($postBurn ? 16 : 6), 40, $enable);
        $dsky->supplyData($this->nounValues(40));
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
     * The values of a noun of the CM table, in SI units, as the display programs fetch them.
     *
     * @return list<float>
     */
    private function nounValues(int $noun): array
    {
        return match ($noun) {
            // 09: the three alarm registers (octal).
            9 => array_map(floatval(...), $this->agc->executive->alarmCodes()),
            // 25: the checklist code in R1.
            25 => [self::CHECKLIST_CODE, 0.0, 0.0],
            // 60 to 69 and 74: the nouns of the entry programs; a burn has no entry data, so they hold zeros.
            60, 63, 64, 66, 67, 69, 74 => [0.0, 0.0, 0.0],
            // 65: sampled AGC time in hours, minutes and seconds: the mission clock of this chapter.
            65 => [$this->agc->missionTime, $this->agc->missionTime, $this->agc->missionTime],
            // 40: time from ignition (cutoff), VG, accumulated delta-V.
            40 => $this->burnValues(),
            // 62: inertial velocity magnitude, altitude rate change, altitude.
            62 => (new LaunchMonitor())->displayValues($this->state, Body::Moon),
            default => throw new \LogicException(sprintf('Noun %d has no values in the P40 burn', $noun)),
        };
    }

    /**
     * N40: the time from TIG (negative while it counts down), from ignition during the burn and from cutoff
     * after it, then VG and the delta-V gained so far.
     *
     * @return list<float>
     */
    private function burnValues(): array
    {
        $time = $this->agc->missionTime;
        $from = match ($this->phase) {
            BurnPhase::Burn => $this->ignitionTime ?? $time,
            BurnPhase::Done => $this->cutoffTime ?? $this->spec->countdown,
            default => $this->spec->countdown,
        };

        return [
            $time - $from,
            $this->burn?->velocityToBeGained() ?? $this->spec->deltaV,
            $this->burn?->accumulated() ?? 0.0,
        ];
    }

    private function guidanceIsComputing(): bool
    {
        return $this->guidedThisStep
            || ($this->lastGuidanceStep !== null && $this->steps - $this->lastGuidanceStep < self::COMP_ACTY_STEPS);
    }

    /**
     * @param list<array{float, float}> $points positions in metres
     * @return list<array{float, float}> the same in kilometres, one decimal
     */
    private static function inKilometres(array $points): array
    {
        return array_map(
            static fn (array $point): array => [round($point[0] / 1000.0, 1), round($point[1] / 1000.0, 1)],
            $points,
        );
    }
}
