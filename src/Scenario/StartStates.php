<?php

declare(strict_types=1);

namespace Apollo\Scenario;

use Apollo\Physics\Body;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;

/**
 * Fixed start state of each chapter. Rounded public values, not verified against the
 * Apollo 11 Mission Report.
 */
final class StartStates
{
    /** PDI altitude above the lunar surface, in metres (about 50 000 ft). */
    public const LANDING_ALTITUDE = 15240.0;

    /**
     * Horizontal speed at the start of the landing chapter, in m/s.
     *
     * The real PDI speed is about 1690 m/s, but that is the perilune speed of the descent orbit:
     * without thrust the lander stays in orbit and never falls. Until the braking guidance exists
     * (slice 2) the chapter starts slower, so the unpowered lander visibly falls to the surface.
     */
    public const LANDING_HORIZONTAL_SPEED = 1000.0;

    /** Ground distance from the PDI point to the landing site, in metres (about 260 nautical miles). */
    public const LANDING_SITE_DOWNRANGE = 481000.0;

    public static function landingState(): StateVector
    {
        return new StateVector(0.0, Body::Moon->radius() + self::LANDING_ALTITUDE, self::LANDING_HORIZONTAL_SPEED, 0.0);
    }

    /** The lunar module with its descent stage: DPS thrust 45.04 kN, isp 311 s. */
    public static function landingVehicle(): Vehicle
    {
        return new Vehicle(
            mass: 15200.0,
            dryMass: 7000.0,
            maxThrust: 45040.0,
            isp: 311.0,
            throttle: 0.0,
            pitch: M_PI,
        );
    }
}
