<?php

declare(strict_types=1);

namespace Apollo\Chapter\Launch;

use Apollo\Agc\Agc;
use Apollo\Chapter\Chapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Light;
use Apollo\Guidance\Comanche\LaunchMonitor;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\SaturnStage;
use Apollo\Physics\SaturnV;
use Apollo\Physics\StateVector;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;
use Apollo\Trace\LogKind;
use Apollo\Trace\Routine;

/**
 * The launch chapter. The Saturn V stands on the pad and lifts off at once; P11 runs from lift-off and
 * only monitors: VHHDOT shows V06N62 (VI, HDOT, H) every 2 s. The rocket is not steered by this
 * computer. It flies the fixed pitch programme of SaturnV, drops the S-IC and the S-II when they have
 * burned out and the S-IVB cuts off when its orbit reaches 185 km (the LVDC did all this in the real
 * rocket, and its code is not in the source; the code panel says so). The chapter ends in success
 * when the orbit at cut-off has a perigee above 150 km, and in a miss when the vehicle falls back
 * to the ground, runs out of propellant without an orbit, or when V37 selects P00.
 *
 * @see Comanche055/P11.agc P11
 * @see Comanche055/P11.agc VHHDOT
 */
final class LaunchChapter implements Chapter
{
    /** A perigee above this altitude counts as an orbit, in metres. */
    public const MIN_PERIGEE = 150000.0;

    /** The programs V37 may select: P11 itself and P00, which ends the monitoring. */
    private const ALLOWED_PROGRAMS = [11, 0];

    /** The vehicle may sink back to the pad only after the first seconds, which it spends lifting off the pad. */
    private const FALL_BACK_AFTER = 5.0;

    /** The monitor verb V16 refreshes every second. */
    private const MONITOR_STEPS = 10;

    /** COMP ACTY stays lit for 0.5 s after VHHDOT, so that it is visible between ticks (a display choice). */
    private const COMP_ACTY_STEPS = 5;

    /** Seconds the dropped stages stay in the view. */
    private const SEPARATION_VISIBLE = 40.0;

    private StateVector $state;
    private readonly SaturnV $rocket;
    private readonly Integrator $integrator;
    private readonly LaunchMonitor $monitor;
    private readonly Agc $agc;
    private int $steps = 0;
    private ?float $sicDroppedAt = null;
    private ?float $siiDroppedAt = null;
    private ?int $lastMonitorStep = null;
    private ?Outcome $outcome = null;

    public function __construct(?StateVector $state = null)
    {
        $this->state = $state ?? StartStates::launchState();
        $this->rocket = new SaturnV();
        $this->integrator = new Integrator();
        $this->monitor = new LaunchMonitor();
        $this->agc = new Agc(ChapterId::Launch->computer(), 11, 0.0, Routine::P11);
        $this->agc->dsky->showMajorMode($this->agc->majorMode);
        $this->logAgc(
            'Lift-off: P11 starts and only monitors. The Saturn V is flown by the LVDC in its instrument unit, whose code is not in this source',
            Routine::P11,
        );
        $this->monitorLaunch();
    }

    public function id(): ChapterId
    {
        return ChapterId::Launch;
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
            $this->rocket->flyProgramme($this->agc->missionTime);
            $this->state = $this->integrator->step($this->rocket->vehicle(), $this->state, Body::Earth, Integrator::STEP);
            $this->agc->missionTime += Integrator::STEP;
            $this->steps++;

            $this->checkStaging();
            $this->checkOrbit();
            $this->checkFallBack();
            if ($this->outcome === null) {
                if ($this->steps % LaunchMonitor::PERIOD_STEPS === 0) {
                    $this->monitorLaunch();
                }
                $this->supplyUserDisplay($this->steps % self::MONITOR_STEPS === 0);
            }
        }
        $this->agc->dsky->setLight(Light::CompActy, $this->isComputing());
    }

    public function vehicleView(): array
    {
        $time = $this->agc->missionTime;
        $vehicle = $this->rocket->vehicle();

        return [
            'view' => 'launch',
            'x' => round(Body::Earth->radius() * $this->state->angle(), 1),
            'altitude' => round(max(0.0, $this->state->altitude(Body::Earth)), 1),
            'vx' => round($this->state->horizontalVelocity(), 1),
            'vz' => round($this->state->radialVelocity(), 1),
            'speed' => round(hypot($this->state->vx, $this->state->vy), 1),
            'pitch' => round($vehicle->pitch, 4),
            'throttle' => round($vehicle->throttle, 2),
            'stage' => $this->rocket->stage()->value,
            'propellant' => round($this->rocket->propellantLeft(), 4),
            'sicSeparated' => $this->sinceDropped($this->sicDroppedAt, $time),
            'siiSeparated' => $this->sinceDropped($this->siiDroppedAt, $time),
            'perigee' => round($this->state->perigeeAltitude(Body::Earth), 0),
            'program' => $this->agc->majorMode,
        ];
    }

    public function outcome(): ?Outcome
    {
        return $this->outcome;
    }

    /** Seconds since a stage was dropped while it is still shown, otherwise null. */
    private function sinceDropped(?float $droppedAt, float $time): ?float
    {
        if ($droppedAt === null || $time - $droppedAt > self::SEPARATION_VISIBLE) {
            return null;
        }

        return round($time - $droppedAt, 1);
    }

    /** What the Dsky took from the keyboard: RSET also clears the alarm registers, V37 to P00 ends the monitoring. */
    private function readKeys(): void
    {
        $dsky = $this->agc->dsky;
        $dsky->takeProceed(); // P11 asks no question: PRO has nothing to answer
        if ($dsky->takeReset()) {
            $this->agc->executive->clearAlarmCodes();
        }

        $program = $dsky->takeProgramChange();
        if ($this->outcome !== null) {
            return; // The chapter has ended: a V37 no longer changes how it ended.
        }
        if ($program === 0) {
            $this->stopMonitoring();
        } elseif ($program !== null) {
            $this->logAgc(sprintf('P%02d is already running, nothing changes', $program), Routine::V37);
        }
    }

    /** V37 to P00: the monitor stops and the engines of the rocket go off; the orbit is not reached. */
    private function stopMonitoring(): void
    {
        $this->rocket->cutOff();
        $this->agc->majorMode = 0;
        $this->agc->dsky->showMajorMode(0);
        $this->outcome = Outcome::Missed;
        $this->logAgc('P11 → P00: the monitoring stops and the engines go off', Routine::V37);
    }

    /** The burning stage is spent: it is dropped and the next one lights. */
    private function checkStaging(): void
    {
        $dropped = $this->rocket->separateIfBurnedOut();
        if ($dropped === null) {
            return;
        }

        if ($dropped === SaturnStage::SIC) {
            $this->sicDroppedAt = $this->agc->missionTime;
        } else {
            $this->siiDroppedAt = $this->agc->missionTime;
        }
        $this->logAgc(
            sprintf('The %s is spent and drops away, the %s lights (the LVDC does this, not P11)', $dropped->label(), $this->rocket->stage()->label()),
            Routine::P11,
        );
    }

    /**
     * The S-IVB cuts off when its orbit reaches the parking orbit of 185 km, or when its propellant is
     * spent. Either way a perigee above 150 km is an orbit and the chapter is a success.
     */
    private function checkOrbit(): void
    {
        if ($this->outcome !== null || $this->rocket->stage() !== SaturnStage::SIVB) {
            return;
        }

        $perigee = $this->state->perigeeAltitude(Body::Earth);
        $parkingOrbit = $perigee >= StartStates::PARKING_ORBIT_ALTITUDE;
        if (!$parkingOrbit && !$this->rocket->isSpent()) {
            return;
        }

        $this->rocket->cutOff();
        $this->outcome = $perigee > self::MIN_PERIGEE ? Outcome::Success : Outcome::Missed;
        $this->logAgc(
            sprintf(
                $parkingOrbit ? 'S-IVB cut-off: the orbit has a perigee of %.0f km' : 'The S-IVB ran out of propellant: the orbit has a perigee of %.0f km',
                $perigee / 1000.0,
            ),
            Routine::Vhhdot,
        );
    }

    /** The vehicle sinks back to the ground before it is in an orbit. */
    private function checkFallBack(): void
    {
        if ($this->outcome !== null || $this->agc->missionTime < self::FALL_BACK_AFTER) {
            return;
        }
        if ($this->state->altitude(Body::Earth) > 0.0) {
            return;
        }

        $this->rocket->cutOff();
        $this->outcome = Outcome::Missed;
        $this->logAgc('The vehicle fell back to the ground', Routine::Vhhdot);
    }

    /** VHHDOT: computes the values of N62 and displays V06N62, every 2 s. */
    private function monitorLaunch(): void
    {
        $this->agc->currentRoutine = Routine::Vhhdot;
        $this->lastMonitorStep = $this->steps;
        $dsky = $this->agc->dsky;
        $dsky->requestDisplay(6, 62, false);
        $dsky->supplyData($this->monitor->displayValues($this->state, Body::Earth));
    }

    private function isComputing(): bool
    {
        return $this->lastMonitorStep !== null && $this->steps - $this->lastMonitorStep < self::COMP_ACTY_STEPS;
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
            // 25 and 40: P11 loads no checklist code and plans no burn, so the registers hold nothing but zeros.
            25, 40 => [0.0, 0.0, 0.0],
            // 60 to 69 and 74: the nouns of the entry programs; the launch has no entry data, so they hold zeros.
            60, 63, 64, 66, 67, 69, 74 => [0.0, 0.0, 0.0],
            // 65: sampled AGC time in hours, minutes and seconds: the mission clock of this chapter.
            65 => [$this->agc->missionTime, $this->agc->missionTime, $this->agc->missionTime],
            // 62: inertial velocity magnitude, altitude rate change, altitude above pad radius.
            62 => $this->monitor->displayValues($this->state, Body::Earth),
            default => throw new \LogicException(sprintf('Noun %d has no values in the launch', $noun)),
        };
    }
}
