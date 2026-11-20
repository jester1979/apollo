<?php

declare(strict_types=1);

namespace Apollo\Agc;

use Apollo\Dsky\Dsky;
use Apollo\Trace\Routine;

/**
 * State of one AGC: the computer, its DSKY, the major mode (0 = no program running), the routine
 * that ran last and mission time. The Executive and event log join it in later slices.
 */
final class Agc
{
    public readonly Dsky $dsky;

    public function __construct(
        public readonly Computer $computer,
        public int $majorMode = 0,
        public float $missionTime = 0.0,
        public ?Routine $currentRoutine = null,
    ) {
        $this->dsky = new Dsky($computer);
    }
}
