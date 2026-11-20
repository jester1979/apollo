<?php

declare(strict_types=1);

namespace Apollo\Chapter\Burn;

/**
 * What distinguishes one P40 burn chapter from another, apart from its start state, its vehicle and the
 * judgement of its orbit: the size and the direction of the external delta-V, the length of the countdown,
 * the size of the view and the words of the log.
 */
final readonly class BurnSpec
{
    /**
     * @param float $deltaV velocity to be gained, in m/s
     * @param bool $prograde true to gain it along the velocity at ignition, false against it
     * @param int $countdown seconds from the first PRO to TIG
     * @param float $viewExtent radius at the edge of the top view, in metres
     * @param float $predictionRadius the most distant point of the predicted path of an open orbit, in metres
     * @param string $burnName what the burn is called in the log, as in "the insertion is missed"
     * @param string $startNote the first line of the log
     */
    public function __construct(
        public float $deltaV,
        public bool $prograde,
        public int $countdown,
        public float $viewExtent,
        public float $predictionRadius,
        public string $burnName,
        public string $startNote,
    ) {
    }

    /** +1 for a prograde burn, -1 for a retrograde one. */
    public function sign(): float
    {
        return $this->prograde ? 1.0 : -1.0;
    }
}
