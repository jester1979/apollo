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
 * the braking phase ends at high gate, about 2 300 m above the surface, and the approach phase at
 * low gate, about 150 m above it. The same holds for the vertical-phase constants below (V2FG,
 * TAUVERT, TAUROD, RODSCALE): pad-loaded, so chosen here.
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

    /** RAPFG, VAPFG, AAPFG: the approach phase (P64) aims at low gate, about 150 m above the site. */
    public static function approach(): self
    {
        return new self(
            positionVertical: 150.0,
            positionHorizontal: -120.0,
            velocityVertical: -10.0,
            velocityHorizontal: 20.0,
            accelerationVertical: 0.0,
            accelerationHorizontal: -0.3,
            jerkHorizontal: 0.0,
        );
    }

    /** P64 hands over to P65 when the altitude falls below this, in metres. */
    public const SWITCH_TO_P65_ALTITUDE = 200.0;

    /** V2FG: the vertical speed P65 lands with, 3 ft/s, in m/s (negative: down). The horizontal part is zero. */
    public const P65_DESCENT_RATE = -0.9144;

    /** TAUVERT: time constant of the P65 velocity loop, in seconds. */
    public const TAU_VERTICAL = 10.0;

    /** TAUROD: time constant of the P66 rate-of-descent loop, in seconds. */
    public const TAU_ROD = 2.0;

    /** RODSCALE: the change of the desired descent rate per ROD click, 1 ft/s, in m/s. */
    public const ROD_SCALE = 0.3048;

    /**
     * The fastest descent the ROD switch can ask for, 20 ft/s, in m/s. A chosen value: RODCOMP in Luminary
     * has no such limit. Without it enough clicks down ask for more than free fall, the engine gives no
     * vertical thrust at all and the lander drops.
     */
    public const ROD_MAX_DESCENT_RATE = -6.096;
}
