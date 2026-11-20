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

    /**
     * The CSM with the LM docked on the approach hyperbola to the Moon, a little before perilune (about 110 km
     * above the surface, 2.4 km/s). Perilune comes about 180 s after the start of the chapter; the clock of
     * the chapter starts with the first PRO, and TIG is LOI_COUNTDOWN seconds later, 140 s before perilune.
     */
    public const LOI_START_X = -430200.0;
    public const LOI_START_Y = 1824300.0;
    public const LOI_START_VX = 2370.5;
    public const LOI_START_VY = 253.9;

    /** Seconds from the first PRO to TIG: V06N40 counts these down. */
    public const LOI_COUNTDOWN = 40;

    /**
     * The velocity to be gained by the LOI burn, retrograde, in m/s (about 2460 ft/s). It leaves an orbit of
     * about 110 by 250 km, also when TIG slips by a minute.
     */
    public const LOI_DELTA_V = 750.0;

    /** The SPS: 20 500 lbf (91.2 kN) and an isp of 314 s. */
    public const SPS_THRUST = 91190.0;
    public const SPS_ISP = 314.0;

    /** CSM and LM together weigh 43.9 t, of which 17 t is SPS propellant. */
    public const LOI_MASS = 43900.0;
    public const LOI_PROPELLANT = 17000.0;

    public static function loiState(): StateVector
    {
        return new StateVector(self::LOI_START_X, self::LOI_START_Y, self::LOI_START_VX, self::LOI_START_VY);
    }

    /** The CSM with the LM, SPS off. The pitch is set by the chapter. */
    public static function loiVehicle(): Vehicle
    {
        return new Vehicle(
            mass: self::LOI_MASS,
            dryMass: self::LOI_MASS - self::LOI_PROPELLANT,
            maxThrust: self::SPS_THRUST,
            isp: self::SPS_ISP,
        );
    }

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
