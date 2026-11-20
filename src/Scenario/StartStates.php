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

    /**
     * The CSM alone, after the LM is left behind, in a circular orbit of 111 km (60 nautical miles) above
     * the Moon. It flies clockwise in the top view, like the approach of the LOI. The clock of the chapter
     * starts with the first PRO, and TIG is TEI_COUNTDOWN seconds later.
     */
    public const TEI_ORBIT_ALTITUDE = 111000.0;

    /** Seconds from the first PRO to TIG: V06N40 counts these down. */
    public const TEI_COUNTDOWN = 40;

    /**
     * The velocity to be gained by the TEI burn, prograde, in m/s (about 3280 ft/s). The escape speed at
     * 111 km is 2304 m/s and the orbit speed 1629 m/s, so this leaves about 1260 m/s of excess speed.
     */
    public const TEI_DELTA_V = 1000.0;

    /** The CSM alone weighs 14.7 t at TEI, of which 5 t is SPS propellant (the burn needs about 4.1 t). */
    public const TEI_MASS = 14700.0;
    public const TEI_PROPELLANT = 5000.0;

    public static function teiState(): StateVector
    {
        $radius = Body::Moon->radius() + self::TEI_ORBIT_ALTITUDE;

        return new StateVector(0.0, $radius, sqrt(Body::Moon->mu() / $radius), 0.0);
    }

    /** The CSM alone, SPS off. The pitch is set by the chapter. */
    public static function teiVehicle(): Vehicle
    {
        return new Vehicle(
            mass: self::TEI_MASS,
            dryMass: self::TEI_MASS - self::TEI_PROPELLANT,
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

    /**
     * The ascent stage on the surface, rounded public values: about 4.5 t with 2.4 t of propellant, the APS
     * with 15.6 kN (3500 lbf) and an isp of 311 s. The descent stage stays behind (the chapter only draws it).
     */
    public const ASCENT_MASS = 4547.0;
    public const ASCENT_PROPELLANT = 2376.0;
    public const APS_THRUST = 15600.0;
    public const APS_ISP = 311.0;

    /**
     * The injection target of P12 as the nominal values of P12.agc: VINJNOM 16.7924 m/cs (5509.5 ft/s, the
     * horizontal velocity) and RDOTDNOM 0.059436 m/cs (19.5 ft/s, the radial velocity), in m/s. The altitude
     * of the injection, 60 000 ft, is the HINJECT of the Apollo 11 flight; Luminary099 loads it from the pad
     * load, so its value is not in the source. Together they give an orbit of about 17 km by 53 km.
     */
    public const ASCENT_TARGET_HORIZONTAL_SPEED = 1679.24;
    public const ASCENT_TARGET_RADIAL_SPEED = 5.9436;
    public const ASCENT_TARGET_ALTITUDE = 18288.0;

    /** Seconds from the first PRO to TIG: V06N74 counts these down (P12TABLE shows N74 until TIG-5 s). */
    public const ASCENT_COUNTDOWN = 20;

    /** The ascent stage at rest on the surface; the Moon's rotation is left out of the 2D model. */
    public static function ascentState(): StateVector
    {
        return new StateVector(0.0, Body::Moon->radius(), 0.0, 0.0);
    }

    /** The ascent stage with the APS off, pointing up. */
    public static function ascentVehicle(): Vehicle
    {
        return new Vehicle(
            mass: self::ASCENT_MASS,
            dryMass: self::ASCENT_MASS - self::ASCENT_PROPELLANT,
            maxThrust: self::APS_THRUST,
            isp: self::APS_ISP,
            throttle: 0.0,
            pitch: M_PI / 2.0,
        );
    }

    /** The Saturn V on the pad at lift-off: the pad moves with the Earth (about 408 m/s at the Cape), nothing else. */
    public static function launchState(): StateVector
    {
        return new StateVector(0.0, Body::Earth->radius(), Body::Earth->rotationRate() * Body::Earth->radius(), 0.0);
    }
}
