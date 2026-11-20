<?php

declare(strict_types=1);

namespace Apollo\Agc;

use Apollo\Trace\Routine;

/**
 * State of one AGC: the computer, the major mode (0 = no program running), the routine that ran
 * last and mission time. The Executive, DSKY and event log join it in later slices.
 */
final class Agc
{
    public function __construct(
        public readonly Computer $computer,
        public int $majorMode = 0,
        public float $missionTime = 0.0,
        public ?Routine $currentRoutine = null,
    ) {
    }
}
