<?php

declare(strict_types=1);

namespace Apollo\Agc;

use Apollo\Dsky\Dsky;
use Apollo\Trace\EventLog;
use Apollo\Trace\Routine;

/**
 * State of one AGC: the computer, its DSKY, the event log, the major mode (0 = no program running),
 * the routine that ran last and mission time. The Executive joins it in a later slice.
 *
 * This is a model of the state, not a port of one routine: the major mode is MODREG and mission time
 * is TIME2/TIME1 in the original.
 *
 * @see Luminary099/ERASABLE_ASSIGNMENTS.agc MODREG
 * @see Luminary099/ERASABLE_ASSIGNMENTS.agc TIME2
 */
final class Agc
{
    public readonly Dsky $dsky;
    public readonly EventLog $log;

    public function __construct(
        public readonly Computer $computer,
        public int $majorMode = 0,
        public float $missionTime = 0.0,
        public ?Routine $currentRoutine = null,
    ) {
        $this->log = new EventLog();
        $this->dsky = new Dsky($computer, $this->log);
    }
}
