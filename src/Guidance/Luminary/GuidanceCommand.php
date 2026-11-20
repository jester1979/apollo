<?php

declare(strict_types=1);

namespace Apollo\Guidance\Luminary;

/**
 * Result of one landing guidance pass.
 *
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc QUADGUID
 */
final readonly class GuidanceCommand
{
    public function __construct(
        /** Time to go to the target in seconds, negative as in the AGC. */
        public float $ttf,
        /** Newton iterations ROOTPSRS used to solve TTF. */
        public int $iterations,
        /** False when ROOTPSRS gave up and the previous TTF was kept. */
        public bool $converged,
        /** True when the time to go has fallen below the switch to the approach phase (P64). */
        public bool $brakingComplete,
        /** Thrust direction in radians above the local horizontal. */
        public float $pitch,
        /** Thrust the guidance wants, as a fraction of full thrust (may exceed 1). */
        public float $thrustFraction,
    ) {
    }

    /** The command of a velocity loop pass (P65, P66): there is no time to go to solve. */
    public static function velocity(float $pitch, float $thrustFraction): self
    {
        return new self(ttf: 0.0, iterations: 0, converged: true, brakingComplete: false, pitch: $pitch, thrustFraction: $thrustFraction);
    }
}
