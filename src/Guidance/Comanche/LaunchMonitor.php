<?php

declare(strict_types=1);

namespace Apollo\Guidance\Comanche;

use Apollo\Physics\Body;
use Apollo\Physics\StateVector;

/**
 * The part of P11 this port runs: the display values of VHHDOT, which the program computes and
 * shows as N62 every 2 seconds (R1 VI, R2 HDOT, R3 H). P11 only monitors the launch. The Saturn V
 * itself was flown by the Launch Vehicle Digital Computer in the instrument unit, whose code is not
 * in the source, so nothing here steers: the rocket flies the fixed programme of Physics\SaturnV.
 *
 * @see Comanche055/P11.agc P11
 * @see Comanche055/P11.agc VHHDOT
 */
final class LaunchMonitor
{
    /** VHHDOT runs every 2 s ("VHHDOT IS EXECUTED EVERY 2 SECONDS TO DISPLAY ON DSKY"), in physics steps of 0.1 s. */
    public const PERIOD_STEPS = 20;

    /**
     * The values of N62 in SI units: the inertial velocity magnitude VI (m/s), the rate of change of
     * altitude HDOT (m/s) and the altitude above the pad radius H (m), as VHHDOT loads them with S11.1.
     *
     * @return array{float, float, float}
     */
    public function displayValues(StateVector $state, Body $body): array
    {
        return [
            hypot($state->vx, $state->vy),
            $state->radialVelocity(),
            $state->altitude($body),
        ];
    }
}
