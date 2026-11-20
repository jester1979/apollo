<?php

declare(strict_types=1);

namespace Apollo\Dsky;

/**
 * The unit a DSKY register shows. The simulation works in SI (m, m/s, s); only the DSKY formatter
 * converts, because the original display is in feet, feet per second and nautical miles.
 *
 * @see Luminary099/ASSEMBLY_AND_OPERATION_INFORMATION.agc NORMAL NOUNS (units column)
 */
enum Unit
{
    case None;
    case Seconds;
    case Feet;
    case FeetPerSecond;
    case NauticalMiles;
    case Degrees;
    case G;

    private const STANDARD_GRAVITY = 9.80665;

    /** Converts a value in SI units (m, m/s, m/s^2, s, rad) to this unit. */
    public function fromSi(float $value): float
    {
        return match ($this) {
            self::None, self::Seconds => $value,
            self::Feet, self::FeetPerSecond => $value / 0.3048,
            self::NauticalMiles => $value / 1852.0,
            self::Degrees => rad2deg($value),
            self::G => $value / self::STANDARD_GRAVITY,
        };
    }
}
