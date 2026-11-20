<?php

declare(strict_types=1);

namespace Apollo\Agc;

/**
 * State of one AGC. Slice 1 only tracks the computer, the major mode (0 = no program running)
 * and mission time; the Executive, DSKY and event log join it in later slices.
 */
final class Agc
{
    public function __construct(
        public readonly Computer $computer,
        public int $majorMode = 0,
        public float $missionTime = 0.0,
    ) {
    }
}
