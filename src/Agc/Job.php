<?php

declare(strict_types=1);

namespace Apollo\Agc;

/**
 * A job the Executive can schedule: a name, a priority and the AGC time it needs. It is plain data
 * (no callback), so the Executive and its jobs serialize into the session with the rest of the
 * simulation. What the work of a job does is up to the chapter that schedules it.
 *
 * In the original a job is a 2CADR with a priority word; the cpuCost has no counterpart there.
 *
 * @see Luminary099/EXECUTIVE.agc NOVAC
 */
final class Job
{
    /** Time still needed, in seconds of AGC time. */
    private float $remaining;

    /**
     * @param string $name shown in the code panel
     * @param int $priority higher runs first (PRIO25 above PRIO20)
     * @param float $cpuCost seconds of AGC time the job needs to finish
     */
    public function __construct(
        public readonly string $name,
        public readonly int $priority,
        public readonly float $cpuCost,
    ) {
        if ($name === '' || $priority < 0 || $cpuCost <= 0.0) {
            throw new \InvalidArgumentException('A job needs a name, a priority of 0 or more and a positive cpuCost');
        }
        $this->remaining = $cpuCost;
    }

    /** Spends up to $seconds of AGC time on the job and returns what it used. */
    public function work(float $seconds): float
    {
        $used = min($this->remaining, max(0.0, $seconds));
        $this->remaining -= $used;

        return $used;
    }

    public function isDone(): bool
    {
        return $this->remaining <= 1e-9;
    }
}
