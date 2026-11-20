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
}
