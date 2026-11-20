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

    /** Horizontal speed at PDI, the perilune speed of the descent orbit, in m/s. */
    public const LANDING_HORIZONTAL_SPEED = 1690.0;

    /** Ground distance from the PDI point to the landing site, in metres (about 260 nautical miles). */
    public const LANDING_SITE_DOWNRANGE = 481000.0;

    /**
     * The Saturn V of Apollo 11, rounded public values (kg, N, s). Each stage has its propellant, the
     * structure that is jettisoned with it, the thrust (the five F-1 engines on average over the
     * flight, the five J-2 engines, the one J-2) and the specific impulse. The payload is the CSM, the
     * LM, the adapter and the instrument unit. Together they weigh 2.94 million kg.
     */
    public const SIC_PROPELLANT = 2160000.0;
    public const SIC_STRUCTURE = 130000.0;
    public const SIC_THRUST = 35000000.0;
    public const SIC_ISP = 270.0;

    public const SII_PROPELLANT = 440000.0;
    public const SII_STRUCTURE = 40000.0;
    public const SII_THRUST = 5000000.0;
    public const SII_ISP = 421.0;

    public const SIVB_PROPELLANT = 107000.0;
    public const SIVB_STRUCTURE = 13000.0;
    public const SIVB_THRUST = 1000000.0;
    public const SIVB_ISP = 421.0;

    public const SATURN_PAYLOAD = 50000.0;

    /** Drag coefficient times the frontal area of the 10.1 m stack (80 m^2 at about 0.5), in m^2. */
    public const SATURN_DRAG_AREA = 40.0;

    /** The orbit of the S-IVB cut-off: 185 km (100 nautical miles), the parking orbit of Apollo 11. */
    public const PARKING_ORBIT_ALTITUDE = 185000.0;

    public static function landingState(): StateVector
    {
        return new StateVector(0.0, Body::Moon->radius() + self::LANDING_ALTITUDE, self::LANDING_HORIZONTAL_SPEED, 0.0);
    }

    /** The lunar module with its descent stage: DPS thrust 45.04 kN, isp 311 s. */
    public static function landingVehicle(): Vehicle
    {
        return new Vehicle(
            mass: 15200.0,
            dryMass: 6500.0,
            maxThrust: 45040.0,
            isp: 311.0,
            throttle: 0.0,
            pitch: M_PI,
        );
    }

    /** The Saturn V on the pad at lift-off: the pad moves with the Earth (about 408 m/s at the Cape), nothing else. */
    public static function launchState(): StateVector
    {
        return new StateVector(0.0, Body::Earth->radius(), Body::Earth->rotationRate() * Body::Earth->radius(), 0.0);
    }
}
