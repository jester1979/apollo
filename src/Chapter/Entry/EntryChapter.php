<?php

declare(strict_types=1);

namespace Apollo\Chapter\Entry;

use Apollo\Agc\Agc;
use Apollo\Chapter\Chapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Light;
use Apollo\Guidance\Comanche\EntryGuidance;
use Apollo\Guidance\Comanche\EntryProgram;
use Apollo\Guidance\Comanche\LaunchMonitor;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;
use Apollo\Trace\LogKind;
use Apollo\Trace\Routine;

/**
 * The entry chapter. The command module starts at the entry interface, 122 km up at 11 030 m/s on a
 * flight path of -6.5 degrees, in P63. The programs follow each other by themselves, nobody has to press
 * PRO: P63 (STARTENT) waits for 0.05 g, P64 (UPCONTRL) holds the lift up, P67 (PREDICT3) steers the bank
 * angle to the target with a range predictor, and P66 (KEP2) is the ballistic phase if the drag falls
 * away again; see EntryGuidance. The DSKY shows what the programs of P61-P67.agc display: V06N64 in P63,
 * V06N74 in P64 (the original shows ROLLC, VI and D there), V06N66 in P66 and P67, and V16N67 when PREDICT3
 * has stopped steering under 1000 ft/s.
 *
 * The chapter ends in success when the parachutes open: under 7 km and slower than 200 m/s relative to
 * the air. It is a miss when the capsule skips out of the atmosphere, or when the load is over 12 g. V37
 * to P00 stops the guidance; the bank angle then stays where it was and the capsule flies on, so whatever
 * happens next decides the outcome.
 *
 * @see Comanche055/P61-P67.agc P63
 * @see Comanche055/P61-P67.agc P64
 * @see Comanche055/P61-P67.agc P66
 * @see Comanche055/P61-P67.agc P67
 */
final class EntryChapter implements Chapter
{
    /** The load the crew can take, in g: over it the chapter is a miss. */
    public const LOAD_LIMIT = 12.0;

    /** The parachutes open under this altitude (EntryGuidance::TARGET_ALTITUDE) and under this speed, in m/s. */
    public const CHUTE_SPEED = 200.0;

    /** The programs V37 may select: P63 itself and P00, which stops the guidance. */
    private const ALLOWED_PROGRAMS = [63, 0];

    /** The monitor verb V16 refreshes every second. */
    private const MONITOR_STEPS = 10;

    /** COMP ACTY stays lit for 0.5 s after a guidance pass, so that it is visible between ticks (a display choice). */
    private const COMP_ACTY_STEPS = 5;

    private StateVector $state;
    private readonly Vehicle $capsule;
    private readonly Integrator $integrator;
    private readonly EntryGuidance $guidance;
    private readonly Agc $agc;
    private readonly float $targetAngle;
    private readonly float $entryAngle;
    private readonly float $entrySpeed;
    private int $steps = 0;
    private ?int $lastGuidanceStep = null;
    private float $drag = 0.0;
    private float $load = 0.0;
    private float $maxLoad = 0.0;
    private bool $finalDisplay = false;
    private bool $chutes = false;
    private ?Outcome $outcome = null;

    /**
     * @param StateVector|null $state the start state, the entry interface of StartStates unless a test gives another
     */
    public function __construct(?StateVector $state = null)
    {
        $this->state = $state ?? StartStates::entryState();
        $this->capsule = StartStates::entryVehicle();
        $this->integrator = new Integrator();
        $this->targetAngle = StartStates::ENTRY_TARGET_RANGE / Body::Earth->radius();
        $this->guidance = new EntryGuidance($this->targetAngle);
        $this->entrySpeed = hypot($this->state->vx, $this->state->vy);
        $this->entryAngle = asin($this->state->radialVelocity() / $this->entrySpeed);
        $this->agc = new Agc(ChapterId::Entry->computer(), EntryProgram::P63->value, 0.0, Routine::P63Cm);
        $this->agc->dsky->showMajorMode($this->agc->majorMode);
        $this->measure();
        $this->logAgc(
            'Entry interface: P63 starts and waits for the first drag, 0.05 g; the programs follow each other by themselves',
            Routine::P63Cm,
        );
        $this->showProgramDisplay();
    }

    public function id(): ChapterId
    {
        return ChapterId::Entry;
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
        $this->readKeys();
        $this->supplyUserDisplay(false);
        for ($i = 0; $i < $steps && $this->outcome === null; $i++) {
            $this->capsule->bank = $this->guidance->bank();
            $this->state = $this->integrator->step($this->capsule, $this->state, Body::Earth, Integrator::STEP);
            $this->agc->missionTime += Integrator::STEP;
            $this->steps++;
            $this->measure();

            if ($this->steps % EntryGuidance::PERIOD_STEPS === 0) {
                $this->runGuidance();
            }
            $this->checkEnd();
            if ($this->outcome === null) {
                $this->supplyUserDisplay($this->steps % self::MONITOR_STEPS === 0);
            }
        }
        $this->agc->dsky->setLight(Light::CompActy, $this->isComputing());
    }

    public function vehicleView(): array
    {
        return [
            'view' => 'entry',
            'x' => round(Body::Earth->radius() * $this->state->angle(), 1),
            'altitude' => round(max(0.0, $this->state->altitude(Body::Earth)), 1),
            'speed' => round(hypot($this->state->vx, $this->state->vy), 1),
            'airSpeed' => round($this->state->airSpeed(Body::Earth), 1),
            'drag' => round($this->drag / EntryGuidance::G0, 3),
            'load' => round($this->load, 3),
            'maxLoad' => round($this->maxLoad, 3),
            'pathAngle' => round(atan2($this->state->radialVelocity(), max(0.0, $this->state->horizontalVelocity())), 4),
            'bank' => round($this->guidance->bank(), 4),
            'range' => round($this->rangeToGo(), 0),
            'miss' => round($this->guidance->predictedMiss(), 0),
            'program' => $this->agc->majorMode,
            'chutes' => $this->chutes,
        ];
    }

    public function outcome(): ?Outcome
    {
        return $this->outcome;
    }

    /** The drag acceleration the capsule senses, in m/s^2: 1/2 rho v^2 (Cd A / m) with v relative to the air. */
    private function dragAcceleration(): float
    {
        $atmosphere = Body::Earth->atmosphere();
        if ($atmosphere === null) {
            return 0.0;
        }
        $speed = $this->state->airSpeed(Body::Earth);

        return 0.5 * $atmosphere->density($this->state->altitude(Body::Earth)) * $speed * $speed
            * $this->capsule->dragArea / $this->capsule->mass;
    }

    /** The drag, the load (drag and lift together: the lift is the drag times L/D) and the highest load so far. */
    private function measure(): void
    {
        $this->drag = $this->dragAcceleration();
        $this->load = $this->drag / EntryGuidance::G0 * sqrt(1.0 + $this->capsule->liftToDrag ** 2);
        $this->maxLoad = max($this->maxLoad, $this->load);
    }

    /** Ground distance still to fly to the target in metres. */
    private function rangeToGo(): float
    {
        return ($this->targetAngle - $this->state->angle()) * Body::Earth->radius();
    }

    /** What the Dsky took from the keyboard: RSET also clears the alarm registers, V37 to P00 stops the guidance. */
    private function readKeys(): void
    {
        $dsky = $this->agc->dsky;
        $dsky->takeProceed(); // the entry asks no question: PRO has nothing to answer
        if ($dsky->takeReset()) {
            $this->agc->executive->clearAlarmCodes();
        }

        $program = $dsky->takeProgramChange();
        if ($program === 0) {
            $this->stopGuidance();
        } elseif ($program !== null) {
            $this->logAgc(sprintf('P%02d is the start program of the entry and it is running, nothing changes', $program), Routine::V37);
        }
    }

    /** V37 to P00: the roll commands stop and the bank angle stays where it was. */
    private function stopGuidance(): void
    {
        if ($this->agc->majorMode === 0) {
            return;
        }
        $this->guidance->stop();
        $this->agc->majorMode = 0;
        $this->agc->dsky->showMajorMode(0);
        $this->logAgc('P00: the roll commands stop, the capsule keeps the bank angle it has', Routine::V37);
    }

    /** One 2 s pass: the guidance commands the bank angle, a change of program is logged, the display is refreshed. */
    private function runGuidance(): void
    {
        $before = $this->guidance->program();
        $this->guidance->guide($this->state, $this->capsule, $this->drag);
        $this->lastGuidanceStep = $this->steps;
        $this->agc->currentRoutine = $this->guidance->routine();

        $after = $this->guidance->program();
        if ($after !== $before && $this->agc->majorMode !== 0) {
            $this->changeProgram($after);
        }
        if (!$this->guidance->isSteering() && $this->agc->majorMode === EntryProgram::P67->value && !$this->finalDisplay) {
            $this->finalDisplay = true;
            $this->logAgc(
                'The speed is under 1000 ft/s: PREDICT3 stops steering, the last bank angle is kept; P67 shows V16N67',
                Routine::Predict3,
            );
        }
        if ($this->agc->majorMode !== 0) {
            $this->showProgramDisplay();
        }
    }

    private function changeProgram(EntryProgram $program): void
    {
        $this->agc->majorMode = $program->value;
        $this->agc->dsky->showMajorMode($program->value);
        [$routine, $text] = match ($program) {
            EntryProgram::P63 => [Routine::P63Cm, 'P63: waiting for the first drag'],
            EntryProgram::P64 => [
                Routine::P64Cm,
                sprintf('P63 → P64: the drag is %.2f g, the entry starts; the lift stays up (bank %.0f degrees)', $this->drag / EntryGuidance::G0, rad2deg($this->guidance->bank())),
            ],
            EntryProgram::P66 => [
                Routine::P66Cm,
                sprintf('P64 → P66: the drag fell under Q7, %.2f g; KEP2 flies ballistic and keeps the last bank angle', $this->drag / EntryGuidance::G0),
            ],
            EntryProgram::P67 => [
                Routine::P67Cm,
                sprintf('→ P67: the final phase; PREDICT3 picks the bank angle that brings the predicted range to the target (bank %.0f degrees)', rad2deg($this->guidance->bank())),
            ],
        };
        $this->logAgc($text, $routine);
    }

    /** The display of the program: P63 V06N64, P64 V06N74, P66 and P67 V06N66, then V16N67 when steering has stopped. */
    private function showProgramDisplay(): void
    {
        if ($this->agc->majorMode === 0) {
            return;
        }
        $dsky = $this->agc->dsky;
        [$verb, $noun] = match ($this->agc->majorMode) {
            63 => [6, 64],
            64 => [6, 74],
            66 => [6, 66],
            default => $this->finalDisplay ? [16, 67] : [6, 66],
        };
        $dsky->requestDisplay($verb, $noun, false);
        $dsky->supplyData($this->nounValues($noun));
    }

    /** The end conditions: the load limit, the skip out of the atmosphere, the parachutes, the ground. */
    private function checkEnd(): void
    {
        if ($this->outcome !== null) {
            return;
        }

        $altitude = $this->state->altitude(Body::Earth);
        if ($this->load > self::LOAD_LIMIT) {
            $this->outcome = Outcome::Missed;
            $this->logAgc(sprintf('The load is %.1f g, over the limit of 12 g', $this->load), Routine::UpControl);
        } elseif ($altitude > EntryGuidance::SKIP_ALTITUDE && $this->state->radialVelocity() > 0.0) {
            $this->outcome = Outcome::Missed;
            $this->logAgc('The capsule skipped out of the atmosphere', Routine::UpControl);
        } elseif ($altitude <= EntryGuidance::TARGET_ALTITUDE && $this->state->airSpeed(Body::Earth) < self::CHUTE_SPEED) {
            $this->outcome = Outcome::Success;
            $this->chutes = true;
            $this->logAgc(
                sprintf(
                    'Parachutes: %.1f km up at %.0f m/s; the highest load was %.1f g, the capsule is %.0f nautical miles from the target',
                    $altitude / 1000.0,
                    $this->state->airSpeed(Body::Earth),
                    $this->maxLoad,
                    abs($this->rangeToGo()) / 1852.0,
                ),
                Routine::Predict3,
            );
        } elseif ($altitude <= 0.0) {
            $this->outcome = Outcome::Crashed;
            $this->logAgc('The capsule hit the ground without parachutes', Routine::Predict3);
        }
    }

    private function isComputing(): bool
    {
        return $this->lastGuidanceStep !== null && $this->steps - $this->lastGuidanceStep < self::COMP_ACTY_STEPS;
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
        $speed = hypot($this->state->vx, $this->state->vy);
        $time = $this->agc->missionTime;
        $bank = $this->guidance->bank();
        // The 2D model has no latitude; the longitude is the ground-track angle since the entry interface.
        $longitude = $this->state->angle();

        return match ($noun) {
            // 09: the three alarm registers (octal).
            9 => array_map(floatval(...), $this->agc->executive->alarmCodes()),
            // 25 and 40: the entry loads no checklist code and plans no burn, so the registers hold zeros.
            25, 40 => [0.0, 0.0, 0.0],
            // 60: GMAX (the highest load so far), VPRED (the speed at the entry interface), GAMMA EI.
            60 => [$this->maxLoad * EntryGuidance::G0, $this->entrySpeed, $this->entryAngle],
            // 62: inertial velocity magnitude, altitude rate change, altitude above the pad radius.
            62 => (new LaunchMonitor())->displayValues($this->state, Body::Earth),
            // 63: range to the target, inertial velocity, time since the entry interface.
            63 => [$this->rangeToGo(), $speed, $time],
            // 64: drag acceleration, inertial velocity VI, range to the target.
            64 => [$this->drag, $speed, $this->rangeToGo()],
            // 65: sampled time in hours, minutes and seconds.
            65 => [$time, $time, $time],
            // 66: commanded bank angle, cross-range error (none in 2D), down-range error (the predicted miss).
            66 => [$bank, 0.0, $this->guidance->predictedMiss()],
            // 67: range to the target, latitude (none in 2D), longitude.
            67 => [$this->rangeToGo(), 0.0, $longitude],
            // 69: BETA, DL, VL: the last two belong to P65, which this port does not fly.
            69 => [$bank, 0.0, 0.0],
            // 74: commanded bank angle, inertial velocity, drag acceleration.
            74 => [$bank, $speed, $this->drag],
            default => throw new \LogicException(sprintf('Noun %d has no values in the entry', $noun)),
        };
    }
}
