<?php

declare(strict_types=1);

namespace Apollo\Guidance\Luminary;

/**
 * Descent engine throttle logic: the DPS is throttleable between 10% and 60% of full thrust,
 * or flat out at 100%. The region in between is avoided, with a hysteresis so the command
 * does not flip between the two on every guidance pass.
 *
 * @see Luminary099/THROTTLE_CONTROL_ROUTINES.agc THROTTLE
 * @see Luminary099/THROTTLE_CONTROL_ROUTINES.agc FLATOUT
 */
final class Throttle
{
    /** Lowest throttle setting (the manual throttle at minimum). */
    public const MINIMUM = 0.10;

    /** HIGHCRIT: above this commanded fraction the engine goes flat out. */
    public const HIGH_CRITICAL = 0.60;

    /** LOWCRIT: while flat out, the commanded fraction must fall to this before throttling again. */
    public const LOW_CRITICAL = 0.55;

    private bool $flatOut = false;

    /** FLATOUT: throttle the engine up to 100%. */
    public function flatOut(): float
    {
        $this->flatOut = true;

        return 1.0;
    }

    /**
     * THROTTLE: turn the thrust the guidance wants (as a fraction of full thrust) into a throttle setting.
     */
    public function command(float $desiredFraction): float
    {
        if ($this->flatOut) {
            if ($desiredFraction <= self::LOW_CRITICAL) {
                $this->flatOut = false;
            }
        } elseif ($desiredFraction > self::HIGH_CRITICAL) {
            $this->flatOut = true;
        }

        if ($this->flatOut) {
            return 1.0;
        }

        return min(self::HIGH_CRITICAL, max(self::MINIMUM, $desiredFraction));
    }
}
