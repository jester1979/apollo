<?php

declare(strict_types=1);

namespace Apollo\Guidance\Comanche;

/**
 * The result of one S40.8 pass: the direction the thrust has to point (a unit vector in the inertial
 * frame of the state vector), the time to go of the burn, and whether the engine-off call is due.
 *
 * @see Comanche055/P40-P47.agc S40.8
 */
final readonly class BurnCommand
{
    public function __construct(
        public float $directionX,
        public float $directionY,
        /** TGO: seconds the engine still has to burn. */
        public float $timeToGo,
        /** TGO is under 4 s: S40.81 takes the steering off and the engine is switched off at TGO. */
        public bool $shutDown,
    ) {
    }
}
