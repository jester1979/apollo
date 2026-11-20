<?php

declare(strict_types=1);

namespace Apollo\Agc;

/**
 * The Executive: seven core sets and five VAC areas for the jobs of the computer, which run highest
 * priority first. NOVAC schedules a job that needs a core set, FINDVAC one that needs a core set and a
 * VAC area. When there is none left the request fails with an alarm: 1201 for no VAC area, 1202 for no
 * core set. The request is then dropped, the alarm waits for whoever owns the computer (takeAlarm())
 * and BAILOUT + GOPROG restart it: all jobs are dropped, the restart counter goes up, and the alarm
 * code goes in the alarm registers. The owner then schedules its jobs again, as the restart tables do.
 *
 * Time is modelled: a job costs AGC seconds, and one second of AGC time offers 1.0 minus the cycle
 * steal. A job that has used up its cost frees its core set and VAC area. The costs and the cycle
 * steal are chosen by the chapter, not measured.
 *
 * Alarm codes are octal numbers like in the original (0o1201, 0o1202).
 *
 * @see Luminary099/EXECUTIVE.agc NOVAC
 * @see Luminary099/EXECUTIVE.agc FINDVAC
 * @see Luminary099/ALARM_AND_ABORT.agc BAILOUT
 * @see Luminary099/FRESH_START_AND_RESTART.agc GOPROG
 */
final class Executive
{
    public const CORE_SETS = 7; // EXECUTIVE.agc:162  NO.CORES DEC 7
    public const VAC_AREAS = 5; // EXECUTIVE.agc:134-142  VAC1USE..VAC5USE

    public const ALARM_NO_VAC_AREAS = 0o1201; // EXECUTIVE.agc:147
    public const ALARM_NO_CORE_SETS = 0o1202; // EXECUTIVE.agc:208

    /** @var array<int, ?Job> */
    private array $coreSets;

    /** @var array<int, ?Job> */
    private array $vacAreas;

    /** @var list<Job> the jobs waiting or running, in the order they were scheduled */
    private array $queue = [];

    private float $cycleSteal = 0.0;
    private int $restarts = 0;
    private ?int $pendingAlarm = null;
    private readonly ProgramAlarm $alarms;

    public function __construct()
    {
        $this->coreSets = array_fill(0, self::CORE_SETS, null);
        $this->vacAreas = array_fill(0, self::VAC_AREAS, null);
        $this->alarms = new ProgramAlarm();
    }

    /** Schedule a job that needs only a core set. Raises Alarm 1202 when all 7 are taken. */
    public function novac(Job $job): void
    {
        $core = self::freeIndex($this->coreSets);
        if ($core === null) {
            $this->raise(self::ALARM_NO_CORE_SETS);

            return;
        }

        $this->coreSets[$core] = $job;
        $this->queue[] = $job;
    }

    /** Schedule a job that needs a core set and a VAC area. Raises 1201 when all 5 VAC areas are taken, 1202 when all core sets are. */
    public function findvac(Job $job): void
    {
        $vac = self::freeIndex($this->vacAreas);
        if ($vac === null) {
            $this->raise(self::ALARM_NO_VAC_AREAS);

            return;
        }
        $core = self::freeIndex($this->coreSets);
        if ($core === null) {
            $this->raise(self::ALARM_NO_CORE_SETS);

            return;
        }

        $this->vacAreas[$vac] = $job;
        $this->coreSets[$core] = $job;
        $this->queue[] = $job;
    }

    /**
     * Run jobs for $seconds of AGC time, highest priority first. Each Job has a cpuCost in seconds;
     * a job frees its core set (and VAC area) when its cost is used up. The capacity per second
     * is 1.0 minus the cycle steal.
     */
    public function run(float $seconds): void
    {
        $capacity = max(0.0, $seconds) * (1.0 - $this->cycleSteal);

        $byPriority = $this->queue;
        usort($byPriority, static fn (Job $a, Job $b): int => $b->priority <=> $a->priority); // stable: oldest first
        foreach ($byPriority as $job) {
            if ($capacity <= 0.0) {
                break;
            }
            $capacity -= $job->work($capacity);
            if ($job->isDone()) {
                $this->finish($job);
            }
        }
    }

    /** Fraction of AGC time lost to counter interrupts (the rendezvous-radar load in the Apollo 11 scenario). */
    public function setCycleSteal(float $fraction): void
    {
        if ($fraction < 0.0 || $fraction >= 1.0) {
            throw new \InvalidArgumentException('The cycle steal is a fraction from 0 up to, not including, 1');
        }
        $this->cycleSteal = $fraction;
    }

    /** BAILOUT + GOPROG: drop all jobs, count a restart, keep the alarm code for N09. The chapter then re-registers its jobs. */
    public function bailout(int $alarmCode): void
    {
        $this->coreSets = array_fill(0, self::CORE_SETS, null);
        $this->vacAreas = array_fill(0, self::VAC_AREAS, null);
        $this->queue = [];
        $this->pendingAlarm = null;
        $this->restarts++;
        $this->alarms->record($alarmCode);
    }

    /** The alarm a failed NOVAC or FINDVAC raised since the last call, or null. */
    public function takeAlarm(): ?int
    {
        $alarm = $this->pendingAlarm;
        $this->pendingAlarm = null;

        return $alarm;
    }

    /**
     * The three alarm registers that noun 09 shows, in the order of FAILREG.
     *
     * @return array{int, int, int}
     */
    public function alarmCodes(): array
    {
        return $this->alarms->registers();
    }

    /** RSET: the alarm registers are zeroed along with the PROG light (ERROR). */
    public function clearAlarmCodes(): void
    {
        $this->alarms->clear();
    }

    /** @return array{coreSets: list<?string>, vacAreas: list<?string>, cycleSteal: float, restarts: int} */
    public function snapshot(): array
    {
        return [
            'coreSets' => array_values(array_map(static fn (?Job $job): ?string => $job?->name, $this->coreSets)),
            'vacAreas' => array_values(array_map(static fn (?Job $job): ?string => $job?->name, $this->vacAreas)),
            'cycleSteal' => $this->cycleSteal,
            'restarts' => $this->restarts,
        ];
    }

    private function raise(int $code): void
    {
        $this->pendingAlarm ??= $code;
    }

    private function finish(Job $job): void
    {
        foreach ($this->coreSets as $index => $held) {
            if ($held === $job) {
                $this->coreSets[$index] = null;
            }
        }
        foreach ($this->vacAreas as $index => $held) {
            if ($held === $job) {
                $this->vacAreas[$index] = null;
            }
        }
        $this->queue = array_values(array_filter($this->queue, static fn (Job $queued): bool => $queued !== $job));
    }

    /**
     * @param array<int, ?Job> $slots
     */
    private static function freeIndex(array $slots): ?int
    {
        foreach ($slots as $index => $held) {
            if ($held === null) {
                return $index;
            }
        }

        return null;
    }
}
