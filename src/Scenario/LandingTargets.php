<?php

declare(strict_types=1);

namespace Apollo\Scenario;

/**
 * Guidance targets of one landing phase, in the landing-site frame (SI units).
 *
 * The frame has its origin at the landing site. "Vertical" points up along the local vertical at
 * the site, "horizontal" along the ground track in the direction of flight, so a lander that
 * still has to reach the site has a negative horizontal position.
 *
 * The original targets RBRFG, VBRFG, ABRFG and JBRFG are pad-loaded erasables
 * (ERASABLE_ASSIGNMENTS.agc), so their values are not in the source. These are chosen so that
 * the braking phase ends at high gate, about 2 300 m above the surface.
 *
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc RDG
 */
final readonly class LandingTargets
{
    public function __construct(
        /** RDG vertical, m */
        public float $positionVertical,
        /** RDG horizontal, m (negative: short of the landing site) */
        public float $positionHorizontal,
        /** VDG vertical, m/s */
        public float $velocityVertical,
        /** VDG horizontal, m/s */
        public float $velocityHorizontal,
        /** ADG vertical, m/s^2 (total acceleration, gravity included) */
        public float $accelerationVertical,
        /** ADG horizontal, m/s^2 */
        public float $accelerationHorizontal,
        /** JDG horizontal, m/s^3 */
        public float $jerkHorizontal,
    ) {
    }

    /** RBRFG, VBRFG, ABRFG, JBRFG: the braking phase (P63) aims at high gate. */
    public static function braking(): self
    {
        return new self(
            positionVertical: 2250.0,
            positionHorizontal: -7000.0,
            velocityVertical: -15.0,
            velocityHorizontal: 45.0,
            accelerationVertical: 0.0,
            accelerationHorizontal: -0.8,
            jerkHorizontal: 0.0,
        );
    }

    /**
     * P63 hands over to P64 when the time to go to the braking target falls below this, in seconds.
     * Stands in for the pad-loaded TCGFAPPR and DELTTFAP of the original.
     */
    public const SWITCH_TO_APPROACH_TTF = 4.0;
}
