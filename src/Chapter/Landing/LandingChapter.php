<?php

declare(strict_types=1);

namespace Apollo\Chapter\Landing;

use Apollo\Agc\Agc;
use Apollo\Agc\Executive;
use Apollo\Chapter\Chapter;
use Apollo\Chapter\ChapterId;
use Apollo\Chapter\Outcome;
use Apollo\Dsky\Light;
use Apollo\Guidance\Luminary\GuidanceCommand;
use Apollo\Guidance\Luminary\LandingGuidance;
use Apollo\Guidance\Luminary\RateOfDescent;
use Apollo\Guidance\Luminary\Throttle;
use Apollo\Physics\Body;
use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;
use Apollo\Scenario\LandingTargets;
use Apollo\Scenario\StartStates;
use Apollo\Simulation\TickInput;
use Apollo\Trace\LogKind;
use Apollo\Trace\Routine;

/**
 * The landing chapter. P63 starts with the lander coasting towards TIG. From TIG-5 s the DSKY flashes
 * V99N63 (please enable engine); PRO ignites the descent engine at minimum throttle, it goes flat
 * out after 26 s and from then on the braking guidance (P63, QUADGUID) steers it every 2 s. Without
 * PRO there is no ignition and V99N63 keeps flashing. P63 hands over to P64 at high gate, which
 * runs the approach guidance (QUADGUID with the approach targets) and below 200 m P65 lands the
 * vehicle at 3 ft/s by itself. A ROD click during P64 or P65 switches to P66 (STARTP66), where every
 * click changes the desired descent rate by 1 ft/s. The chapter ends at contact: success below
 * 3 m/s vertical and 1.2 m/s horizontal, crashed above, out of fuel when the tank runs dry first.
 * The DSKY shows V06N63 in P63, V06N64 in P64 and V06N60 in P65 and P66, COMP ACTY lights while the
 * guidance computes. The keys of the user are handled by the Dsky; this chapter reads what they
 * asked for (PRO, RSET, V37, the nouns of a user display) and the ROD clicks of the tick.
 *
 * The Executive carries the load: the SERVICER (the guidance pass and the physics measurement) every
 * 2 s through FINDVAC, a radar job every second and a display job per monitor through NOVAC (see
 * LandingLoad). Each job holds a core set (the SERVICER also a VAC area) until its cost is used up. In
 * the Apollo 11 scenario the cycle steal leaves too little time, the jobs pile up and FINDVAC or NOVAC
 * raises 1201 or 1202: PROG lights, N09 keeps the code, BAILOUT and GOPROG drop all jobs and the
 * chapter schedules them again like the restart tables. The guidance and the physics go on; the
 * load only models the time they take, it does not delay them.
 *
 * @see Luminary099/THE_LUNAR_LANDING.agc P63LM
 * @see Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc BURNBABY
 * @see Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc V99RECYC
 * @see Luminary099/SERVICER.agc SERVICER
 * @see Luminary099/EXECUTIVE.agc FINDVAC2
 * @see Luminary099/ALARM_AND_ABORT.agc BAILOUT
 * @see Luminary099/FRESH_START_AND_RESTART.agc GOPROG
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc P63DISPS
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc STARTP64
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc P65START
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc STARTP66
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

    /** In P66 the ROD task runs every second (P66VERTA: RODTASK is twiddled in 1 SEC). */
    private const ROD_STEPS = 10;

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
    private readonly LandingGuidance $approachGuidance;
    private readonly RateOfDescent $rod;
    private readonly Throttle $throttle;
    private int $steps = 0;
    private ?int $ignitionStep = null;
    private ?int $lastGuidanceStep = null;
    private bool $guidedThisStep = false;
    private ?Outcome $outcome = null;

    /**
     * @param StateVector|null $state start state; the PDI state of the chapter when omitted
     * @param bool $scenario the Apollo 11 scenario: the rendezvous radar steals cycles from the Executive
     */
    public function __construct(?StateVector $state = null, bool $scenario = false)
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
        $this->approachGuidance = new LandingGuidance(
            LandingTargets::approach(),
            StartStates::LANDING_SITE_DOWNRANGE / Body::Moon->radius(),
        );
        $this->rod = new RateOfDescent($this->approachGuidance);
        if ($scenario) {
            $this->agc->executive->setCycleSteal(LandingLoad::SCENARIO_CYCLE_STEAL);
        }
        $this->agc->dsky->showMajorMode($this->agc->majorMode);
        $this->displayProgram();
        $this->logAgc('P63 starts: the lander coasts towards TIG', Routine::P63Lm);
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
        $this->clickRod($input->rod);
        $this->supplyUserDisplay(false);
        for ($i = 0; $i < $steps && $this->outcome === null; $i++) {
            $this->runExecutive();
            $burnStep = $this->ignitionStep === null ? null : $this->steps - $this->ignitionStep;
            if ($burnStep !== null && $burnStep >= self::IGNITION_STEPS && $burnStep % $this->guidanceSteps() === 0) {
                $this->guide($burnStep);
                if ($this->outcome !== null) {
                    break;
                }
            }
            $this->state = $this->integrator->step($this->vehicle, $this->state, Body::Moon, Integrator::STEP);
            $this->agc->missionTime += Integrator::STEP;
            $this->steps++;
            $this->checkContact();
            $this->checkFuel();
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
            'program' => $this->agc->majorMode,
            'desiredRate' => $this->agc->majorMode === 66 ? round($this->rod->desiredRate(), 3) : null,
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
        if ($dsky->takeReset()) {
            $this->agc->executive->clearAlarmCodes(); // ERROR zeroes FAILREG along with the PROG light
        }

        $program = $dsky->takeProgramChange();
        if ($program === 0) {
            $this->stopGuidance();
        } elseif ($program !== null) {
            $this->logAgc(
                $program === $this->agc->majorMode
                    ? sprintf('P%02d is already running, nothing changes', $program)
                    : sprintf('P%02d cannot be selected from P%02d, nothing changes', $program, $this->agc->majorMode),
                Routine::V37,
            );
        }
        // Only P00 changes the program: V37 to the program that runs, or to an earlier one, changes nothing.
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
        $this->displayProgram();
        $this->logAgc('Ignition: the DPS burns at minimum throttle', Routine::BurnBaby);
    }

    /** V37 to P00: the engine goes off and the guidance stops; the landing is lost. */
    private function stopGuidance(): void
    {
        $this->vehicle->throttle = 0.0;
        $this->agc->majorMode = 0;
        $this->agc->dsky->showMajorMode(0);
        $this->outcome = Outcome::Missed;
        $this->logAgc('P63 → P00: the engine goes off, the guidance stops', Routine::V37);
    }

    /**
     * One 0.1 s of Executive time. On the second the radar job (and a display job per monitor) is
     * scheduled with NOVAC, and every 2 s the SERVICER with FINDVAC; then the jobs run. A request that
     * finds no core set or VAC area raises an alarm, which restarts the computer.
     */
    private function runExecutive(): void
    {
        $executive = $this->agc->executive;
        $this->scheduleDueJobs();
        $executive->run(Integrator::STEP);
    }

    /** Stops at the first alarm: the restart has scheduled the jobs of this moment already. */
    private function scheduleDueJobs(): void
    {
        $executive = $this->agc->executive;
        $requests = [];
        if ($this->steps % LandingLoad::SECOND_STEPS === 0) {
            $requests[] = [LandingLoad::radar(), false];
            if ($this->agc->dsky->isMonitoring()) {
                $requests[] = [LandingLoad::display(), false];
            }
        }
        if ($this->steps % LandingLoad::SERVICER_PERIOD_STEPS === 0) {
            $requests[] = [LandingLoad::servicer(), true];
        }

        foreach ($requests as [$job, $needsVacArea]) {
            if ($needsVacArea) {
                $executive->findvac($job);
            } else {
                $executive->novac($job);
            }
            if ($this->handleAlarm()) {
                return;
            }
        }
    }

    /** True when the Executive raised an alarm, which was then handled by a restart. */
    private function handleAlarm(): bool
    {
        $code = $this->agc->executive->takeAlarm();
        if ($code === null) {
            return false;
        }

        $this->agc->dsky->setLight(Light::Prog, true);
        $this->agc->log->record(
            LogKind::Alarm,
            sprintf(
                'Program alarm %04o: no %s free, PROG light on, code kept in N09',
                $code,
                $code === Executive::ALARM_NO_VAC_AREAS ? 'VAC area' : 'core set',
            ),
            $code === Executive::ALARM_NO_VAC_AREAS ? Routine::FindVac : Routine::NoVac,
            $this->agc->missionTime,
        );

        $this->agc->executive->bailout($code);
        $this->agc->currentRoutine = Routine::GoProg;
        $this->logAgc(
            sprintf('BAILOUT, GOPROG: all jobs dropped, restart %d, the restart tables schedule SERVICER, radar and display again', $this->agc->executive->snapshot()['restarts']),
            Routine::GoProg,
        );
        $this->scheduleAfterRestart();

        return true;
    }

    /** The restart tables: the jobs of the landing start again, with nothing in the way. */
    private function scheduleAfterRestart(): void
    {
        $executive = $this->agc->executive;
        $executive->findvac(LandingLoad::servicer());
        $executive->novac(LandingLoad::radar());
        if ($this->agc->dsky->isMonitoring()) {
            $executive->novac(LandingLoad::display());
        }
    }

    /** What happens after each 0.1 s step: the V99 display and the refreshes of the displays. */
    private function afterStep(): void
    {
        if ($this->steps === self::ENABLE_ENGINE_STEPS || $this->steps % $this->guidanceSteps() === 0) {
            $this->displayProgram();
        }
        $this->supplyUserDisplay($this->steps % self::MONITOR_STEPS === 0);
    }

    /** Steps between two guidance passes: 2 s like the SERVICER, in P66 1 s like the ROD task. */
    private function guidanceSteps(): int
    {
        return $this->agc->majorMode === 66 ? self::ROD_STEPS : self::GUIDANCE_STEPS;
    }

    /** One guidance pass: sets the thrust direction and the throttle, and hands over to the next program when due. */
    private function guide(int $burnStep): void
    {
        if ($burnStep === self::IGNITION_STEPS) {
            // P63ZOOM: 26 s after ignition the DPS throttles up and the guidance takes over.
            $this->vehicle->throttle = $this->throttle->flatOut();
            $this->logAgc('26 s after ignition: the DPS throttles up, the braking guidance takes over', Routine::P63Zoom);
        }

        $this->lastGuidanceStep = $this->steps;
        $this->guidedThisStep = true;
        $elapsed = self::GUIDANCE_STEPS * Integrator::STEP;
        $mass = $this->vehicle->mass;
        $maxThrust = $this->vehicle->maxThrust;

        if ($this->agc->majorMode === 63) {
            $this->agc->currentRoutine = Routine::RootPsrs;
            $command = $this->guidance->pass($this->state, $mass, $maxThrust, $elapsed);
            $this->agc->currentRoutine = Routine::QuadGuid;
            if (!$command->brakingComplete) {
                $this->apply($command);

                return;
            }
            $this->startP64();
        }

        if ($this->agc->majorMode === 64 && $this->state->altitude(Body::Moon) < LandingTargets::SWITCH_TO_P65_ALTITUDE) {
            $this->startP65();
        }

        $command = match ($this->agc->majorMode) {
            64 => $this->quadGuidApproach($mass, $maxThrust, $elapsed),
            65 => $this->p65Vert($mass, $maxThrust),
            default => $this->p66Vert($mass, $maxThrust),
        };
        $this->apply($command);
    }

    private function apply(GuidanceCommand $command): void
    {
        $this->vehicle->pitch = $command->pitch;
        $this->vehicle->throttle = $this->throttle->command($command->thrustFraction);
    }

    private function quadGuidApproach(float $mass, float $maxThrust, float $elapsed): GuidanceCommand
    {
        $this->agc->currentRoutine = Routine::RootPsrs;
        $command = $this->approachGuidance->pass($this->state, $mass, $maxThrust, $elapsed);
        $this->agc->currentRoutine = Routine::QuadGuid;

        return $command;
    }

    /** P65VERT: the vehicle settles at 3 ft/s down and its horizontal speed goes to zero, all by itself. */
    private function p65Vert(float $mass, float $maxThrust): GuidanceCommand
    {
        $this->agc->currentRoutine = Routine::P65Vert;

        return $this->approachGuidance->velocityPass(
            $this->state,
            $mass,
            $maxThrust,
            LandingTargets::P65_DESCENT_RATE,
            LandingTargets::TAU_VERTICAL,
        );
    }

    private function p66Vert(float $mass, float $maxThrust): GuidanceCommand
    {
        $this->agc->currentRoutine = Routine::P66Vert;

        return $this->rod->pass($this->state, $mass, $maxThrust);
    }

    /** STARTP64: high gate. The approach guidance takes over from the braking guidance. */
    private function startP64(): void
    {
        $this->changeProgram(64);
        $this->agc->currentRoutine = Routine::StartP64;
        $this->logAgc('P63 → P64: high gate reached, the approach phase starts', Routine::StartP64);
        $this->displayProgram();
    }

    /** P65START: below 200 m the vertical phase lands the vehicle, as long as nobody clicks the ROD switch. */
    private function startP65(): void
    {
        $this->changeProgram(65);
        $this->agc->currentRoutine = Routine::P65Start;
        $this->logAgc('P64 → P65: low gate reached, the automatic landing starts (3 ft/s down)', Routine::P65Start);
        $this->displayProgram();
    }

    /** STARTP66: the ROD switch was clicked in P64 or P65. The desired altitude rate starts as the present one. */
    private function startP66(): void
    {
        $from = $this->agc->majorMode;
        $this->changeProgram(66);
        $this->rod->start($this->state);
        $this->agc->currentRoutine = Routine::StartP66;
        $this->logAgc(
            sprintf('ROD switch clicked: P%02d → P66, the desired altitude rate starts at the present rate', $from),
            Routine::StartP66,
        );
        $this->displayProgram();
    }

    private function changeProgram(int $program): void
    {
        $this->agc->majorMode = $program;
        $this->agc->dsky->showMajorMode($program);
    }

    /**
     * The ROD switch (the arrow keys): positive clicks are up, less descent rate. In P64 and P65 a click
     * switches to P66 and counts as the first click, as RODCOUNT does in the original; before P64 the
     * clicks are dropped (GUILDRET clears RODCOUNT on every pass).
     */
    private function clickRod(int $clicks): void
    {
        if ($clicks === 0 || $this->outcome !== null) {
            return;
        }
        if ($this->agc->majorMode === 64 || $this->agc->majorMode === 65) {
            $this->startP66();
        }
        if ($this->agc->majorMode !== 66) {
            return;
        }

        $rate = $this->rod->click($clicks);
        $this->agc->currentRoutine = Routine::RodComp;
        $this->logAgc(
            sprintf('ROD %+d: the desired altitude rate is now %+.1f ft/s', $clicks, $rate / LandingTargets::ROD_SCALE),
            Routine::RodComp,
        );
    }

    /** The tank is empty before the lander touches down: nothing can save the landing any more. */
    private function checkFuel(): void
    {
        if ($this->outcome !== null || $this->ignitionStep === null || $this->vehicle->fuelMass() > 0.0) {
            return;
        }

        $this->vehicle->throttle = 0.0;
        $this->outcome = Outcome::OutOfFuel;
        $this->logAgc(sprintf('Out of fuel in P%02d: the descent engine quits', $this->agc->majorMode), Routine::Throttle);
    }

    /**
     * P63DISPS, P64DISPS, VERTDISP: V06N63 in P63, V06N64 in P64 and V06N60 in P65 and P66, with the
     * values in ft/s and ft. While the engine waits to be enabled the P63 noun is flashed as V99N63.
     */
    private function displayProgram(): void
    {
        $dsky = $this->agc->dsky;
        $enable = $this->awaitingEngineEnable();
        if ($enable && $this->steps === self::ENABLE_ENGINE_STEPS) {
            $this->logAgc('TIG-5 s: V99N63 flashes, the crew is asked to enable the engine', Routine::V99Recyc);
        }
        $noun = match ($this->agc->majorMode) {
            64 => 64,
            65, 66 => 60,
            default => 63,
        };
        $dsky->requestDisplay($enable ? 99 : 6, $noun, $enable);
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
        $speedUp = $this->state->radialVelocity();

        return match ($noun) {
            // 09: the three alarm registers (octal).
            9 => array_map(floatval(...), $this->agc->executive->alarmCodes()),
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
     * Time to go of the phase that runs: the TTF of the last guidance pass, counted down since. Before the
     * first pass it is the TTF that ROOTPSRS finds for the present state. P65 and P66 have no time to go.
     */
    private function timeToGo(): float
    {
        if ($this->agc->majorMode > 64) {
            return 0.0;
        }

        $guidance = $this->agc->majorMode === 64 ? $this->approachGuidance : $this->guidance;
        $ttf = $guidance->ttf();
        if ($ttf === null || $this->lastGuidanceStep === null) {
            return max(0.0, $guidance->timeToGoFrom($this->state));
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
