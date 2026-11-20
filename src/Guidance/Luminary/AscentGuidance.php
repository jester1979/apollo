<?php

declare(strict_types=1);

namespace Apollo\Guidance\Luminary;

use Apollo\Physics\Body;
use Apollo\Physics\StateVector;

/**
 * Ascent guidance of P12: one call to pass() is one 2 s pass of ASCENT. The in-plane version of the
 * original, with the cross-range axis left out.
 *
 * The velocity to be gained VG is the error in horizontal velocity (ZDOT) and in radial velocity (RDOT),
 * with the radial part compensated for the effective gravity over the time to go:
 *   VG = (ZDOTD - ZDOT) Z + (RDOTD - RDOT + TGO GEFF) UR
 * The burn time follows from the rocket equation as MAINENG writes it, with x = VG / VE:
 *   TGO = TBUP x (1 - KT x) - TTO     (TBUP = VE / AT, KT = 1/2)
 * The radial acceleration comes from the error in altitude and in radial velocity over TGO. The
 * acceleration is a straight line in time, a + b t, that removes both errors in TGO seconds:
 *   a = -2 DRDOT / TGO + 6 DR / TGO^2        b = (6 DRDOT TGO - 12 DR) / TGO^3
 * with DRDOT = RDOTD - RDOT and DR = RCO - R - RDOT TGO. As in the original, b may not be positive and is
 * limited (PRLIMIT), and under 10 s of TGO it is zero (FLPC): then the acceleration is only the one that
 * removes the radial velocity error. Under 2 s of TGO the pass holds the last acceleration. The thrust has
 * the size AT of the engine, the radial part of it is the commanded radial acceleration plus the effective
 * gravity, and the horizontal part is what is left (ATP = sqrt(AT^2 - AH^2), signed like DZDOT). The pitch
 * is the angle between that thrust and the local horizontal.
 *
 * The vertical rise comes first (FLVR): the thrust points straight up until RDOT reaches 40 ft/s, or the
 * altitude 25 000 ft. The engine goes off when TGO falls below 4 s (ENGOFF): the caller switches it off
 * at TGO, in a final tick.
 *
 * @see Luminary099/ASCENT_GUIDANCE.agc ASCENT
 * @see Luminary099/ASCENT_GUIDANCE.agc ATMAG
 * @see Luminary099/ASCENT_GUIDANCE.agc MAINENG
 * @see Luminary099/ASCENT_GUIDANCE.agc ENGOFF
 */
final class AscentGuidance
{
    /** The pass runs every 2 s (the SERVICER cycle); the acceleration is evaluated at the end of the cycle (100CS). */
    public const CYCLE_SECONDS = 2.0;

    /** KT1: the factor of the second-order term of the burn time. */
    public const KT = 0.5;

    /** The vertical rise ends at this radial velocity, 40 ft/s (40FPS), in m/s. */
    public const VERTICAL_RISE_EXIT_SPEED = 12.192;

    /** The vertical rise ends at this altitude, 25 000 ft (25KFT), in metres. */
    public const VERTICAL_RISE_MAX_ALTITUDE = 7620.0;

    /** ENGOFF: with less time to go than this the engine goes off at TGO (4SEC(17)), in seconds. */
    public const ENGINE_OFF_SECONDS = 4.0;

    /** Under this TGO the radial acceleration rate is zero (T3), in seconds. */
    public const RATES_END_SECONDS = 10.0;

    /** Under this TGO the pass keeps the last radial acceleration (T2A), in seconds. */
    public const HOLD_SECONDS = 2.0;

    /** PRLIMIT: the radial acceleration may fall by at most 0.1 ft/s per second, in m/s^3. */
    public const MAX_JERK = 0.03048;

    private ?float $timeToGo = null;
    private bool $verticalRise = true;
    private float $radialAcceleration = 0.0;

    public function __construct(
        /** ZDOTD: the horizontal velocity of the injection, in m/s. */
        private readonly float $horizontalVelocityDesired,
        /** RDOTD: the radial velocity of the injection, in m/s. */
        private readonly float $radialVelocityDesired,
        /** RCO: the radius of the injection, in metres from the centre of the Moon. */
        private readonly float $radiusDesired,
        /** VE: the exhaust velocity of the engine, in m/s. */
        private readonly float $exhaustVelocity,
    ) {
    }

    public function isVerticalRise(): bool
    {
        return $this->verticalRise;
    }

    /** TGO of the last pass in seconds; null before the first pass. */
    public function timeToGo(): ?float
    {
        return $this->timeToGo;
    }

    /**
     * One pass of ASCENT.
     *
     * @param float $mass the mass of the vehicle in kg
     * @param float $thrust the thrust of the engine in N
     */
    public function pass(StateVector $state, float $mass, float $thrust): AscentCommand
    {
        $radius = $state->radius();
        $horizontal = $state->horizontalVelocity();
        $radial = $state->radialVelocity();
        $thrustAcceleration = $thrust / $mass; // AT
        $burnTime = $this->exhaustVelocity / $thrustAcceleration; // TBUP
        // GEFF: the gravity left after the centrifugal term of the orbital motion, positive downwards.
        $gravity = Body::Moon->mu() / ($radius * $radius) - $horizontal * $horizontal / $radius;

        $horizontalError = $this->horizontalVelocityDesired - $horizontal; // DZDOT
        $radialError = $this->radialVelocityDesired - $radial; // DRDOT

        $previous = $this->timeToGo ?? $this->burnTimeFor(hypot($horizontalError, $radialError), $burnTime);
        $velocityToGo = hypot($horizontalError, $radialError + $previous * $gravity);
        $timeToGo = $this->burnTimeFor($velocityToGo, $burnTime);
        $this->timeToGo = $timeToGo;

        if ($this->verticalRise
            && ($radial >= self::VERTICAL_RISE_EXIT_SPEED || $state->altitude(Body::Moon) >= self::VERTICAL_RISE_MAX_ALTITUDE)
        ) {
            $this->verticalRise = false;
        }

        $pitch = M_PI / 2.0;
        if (!$this->verticalRise) {
            $pitch = $this->steer(
                $thrustAcceleration,
                $gravity,
                $horizontalError,
                $radialError,
                $this->radiusDesired - $radius - $radial * $timeToGo,
                $timeToGo,
            );
        }

        return new AscentCommand(
            $pitch,
            $timeToGo,
            $velocityToGo,
            $this->verticalRise,
            $timeToGo < self::ENGINE_OFF_SECONDS,
        );
    }

    /** MAINENG: TGO = TBUP x (1 - KT x), with x = VG / VE. */
    private function burnTimeFor(float $velocityToGo, float $burnTime): float
    {
        $x = $velocityToGo / $this->exhaustVelocity;

        return max(0.0, $burnTime * $x * (1.0 - self::KT * $x));
    }

    /** CMPONENT, AIMER: the thrust direction from the radial acceleration and the size of the thrust. */
    private function steer(
        float $thrustAcceleration,
        float $gravity,
        float $horizontalError,
        float $radialError,
        float $altitudeError,
        float $timeToGo,
    ): float {
        if ($timeToGo >= self::HOLD_SECONDS) {
            $this->radialAcceleration = $this->radialAccelerationFor($radialError, $altitudeError, $timeToGo);
        }

        // ATR: the thrust has to carry the effective gravity as well; AH cannot exceed AT (NO-ATP).
        $radialThrust = max(-$thrustAcceleration, min($thrustAcceleration, $this->radialAcceleration + $gravity));
        $horizontalThrust = sqrt(max(0.0, $thrustAcceleration ** 2 - $radialThrust ** 2));
        if ($horizontalError < 0.0) {
            $horizontalThrust = -$horizontalThrust; // SIGN DZDOT: too fast, thrust backwards
        }

        return atan2($radialThrust, $horizontalThrust);
    }

    /** The straight-line acceleration a + b t, taken at the end of the cycle. */
    private function radialAccelerationFor(float $radialError, float $altitudeError, float $timeToGo): float
    {
        if ($timeToGo < self::RATES_END_SECONDS) {
            return $radialError / $timeToGo; // FLPC: B = 0
        }

        $rate = (6.0 * $radialError * $timeToGo - 12.0 * $altitudeError) / $timeToGo ** 3;
        $rate = max(-self::MAX_JERK, min(0.0, $rate)); // B > 0 NOT PERMITTED, PRLIMIT
        $start = ($radialError - $rate * $timeToGo ** 2 / 2.0) / $timeToGo;

        return $start + $rate * self::CYCLE_SECONDS;
    }
}
