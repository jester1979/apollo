<?php

declare(strict_types=1);

namespace Apollo\Guidance\Luminary;

use Apollo\Physics\StateVector;
use Apollo\Scenario\LandingTargets;

/**
 * P66, the rate-of-descent mode. The crew clicks the ROD switch; every click changes the desired
 * altitude rate (VDGVERT) by RODSCALE, 1 ft/s. The guidance holds that rate with the velocity loop of
 * the vertical phases, which also steers the horizontal speed to zero (the crew would do that with the
 * hand controller on the real flight).
 *
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc STARTP66
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc P66VERT
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc RODCOMP
 */
final class RateOfDescent
{
    /** VDGVERT: the desired altitude rate in m/s, negative while descending. */
    private float $desiredRate = 0.0;

    public function __construct(private readonly LandingGuidance $guidance)
    {
    }

    /** STARTP66: the desired altitude rate starts out as the present altitude rate (HDOTDISP). */
    public function start(StateVector $state): void
    {
        $this->desiredRate = $state->radialVelocity();
    }

    /**
     * RODCOMP: the clicks since the last pass change the desired altitude rate by RODSCALE each. A positive
     * click is the ROD switch up: less descent rate.
     *
     * @return float the new desired altitude rate in m/s
     */
    public function click(int $clicks): float
    {
        $this->desiredRate += $clicks * LandingTargets::ROD_SCALE;

        return $this->desiredRate;
    }

    public function desiredRate(): float
    {
        return $this->desiredRate;
    }

    /** One pass of P66VERT: the thrust that holds the desired altitude rate and nulls the horizontal speed. */
    public function pass(StateVector $state, float $mass, float $maxThrust): GuidanceCommand
    {
        return $this->guidance->velocityPass($state, $mass, $maxThrust, $this->desiredRate, LandingTargets::TAU_ROD);
    }
}
