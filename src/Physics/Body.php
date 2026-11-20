<?php

declare(strict_types=1);

namespace Apollo\Physics;

/** A celestial body, with its centre in the origin of the state vector's frame. */
enum Body
{
    case Moon;
    case Earth;

    /** Standard gravitational parameter in m^3/s^2. */
    public function mu(): float
    {
        return match ($this) {
            self::Moon => 4.9048695e12,
            self::Earth => 3.986004418e14,
        };
    }

    /** Mean radius in metres. */
    public function radius(): float
    {
        return match ($this) {
            self::Moon => 1737400.0,
            self::Earth => 6378137.0,
        };
    }

    /** The atmosphere of the body, or null when it has none. */
    public function atmosphere(): ?ExponentialAtmosphere
    {
        return match ($this) {
            self::Moon => null,
            self::Earth => new ExponentialAtmosphere(1.225, 7200.0),
        };
    }

    /**
     * Angular rate of the air in the plane of the 2D model, in rad/s (clockwise like the horizontal
     * of StateVector). It is the rotation of the Earth, 7.2921159e-5 rad/s, times the cosine of the
     * latitude of the Cape (28.5 degrees): the pad moves 408 m/s with the air around it.
     */
    public function rotationRate(): float
    {
        return match ($this) {
            self::Moon => 0.0,
            self::Earth => 7.2921159e-5 * cos(deg2rad(28.5)),
        };
    }
}
