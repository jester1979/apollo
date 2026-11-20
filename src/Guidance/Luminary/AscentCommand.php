<?php

declare(strict_types=1);

namespace Apollo\Guidance\Luminary;

/**
 * The result of one ASCENT guidance pass: the direction the thrust has to point, the time to go of the
 * burn, and whether the engine-off call (ENGOFF) is due.
 *
 * @see Luminary099/ASCENT_GUIDANCE.agc ASCENT
 */
final readonly class AscentCommand
{
    public function __construct(
        /** Thrust direction in radians above the local horizontal (pi / 2 = straight up). */
        public float $pitch,
        /** TGO: seconds the engine still has to burn. */
        public float $timeToGo,
        /** The size of the velocity to be gained, gravity compensated, in m/s. */
        public float $velocityToGo,
        /** The vertical rise: the thrust points straight up until the radial velocity reaches 40 ft/s. */
        public bool $verticalRise,
        /** TGO is under 4 s: the engine is switched off at TGO (ENGOFF). */
        public bool $shutDown,
    ) {
    }
}
