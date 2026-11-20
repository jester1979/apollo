<?php

declare(strict_types=1);

namespace Apollo\Guidance\Luminary;

use Apollo\Physics\Body;
use Apollo\Physics\StateVector;
use Apollo\Scenario\LandingTargets;

/**
 * Landing guidance of the braking phase (P63): the quadratic guidance equation QUADGUID with the
 * time to go solved by the Newton root finder ROOTPSRS. One call to pass() is one 2 s guidance pass.
 *
 * Per axis, with TTF negative as in the AGC:
 *   ACG = ADG + 6 (VDG + VG) / TTF + 12 (RDG - RG) / TTF^2
 * TTF is the root of the jerk condition on the horizontal axis:
 *   JDG TTF^3 + 6 ADG TTF^2 + (18 VDG + 6 VG) TTF + 24 (RDG - RG) = 0
 * The thrust acceleration is ACG minus gravity; its direction gives the pitch, its size the throttle.
 *
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc QUADGUID
 * @see Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc ROOTPSRS
 */
final class LandingGuidance
{
    /** ROOTPSRS gives up after this many passes (BADROOT, "KLUMPP SAYS GIVE UP AFTER EIGHT PASSES"). */
    public const MAX_ITERATIONS = 8;

    /** PRECROOT: the root counts as found when the Newton step is below this fraction of the first guess. */
    public const ROOT_PRECISION = 1.0e-4;

    private ?float $ttf = null;

    public function __construct(
        private readonly LandingTargets $targets,
        /** Ground-track angle of the landing site in radians, clockwise from the +y axis. */
        private readonly float $siteAngle,
    ) {
    }

    /** Time to go of the last pass, negative; null before the first pass. */
    public function ttf(): ?float
    {
        return $this->ttf;
    }

    /**
     * One guidance pass: TTFINCR, TTF/8CL (ROOTPSRS), then QUADGUID.
     *
     * @param float $elapsed seconds since the previous pass (TTFINCR moves TTF that much towards zero)
     */
    public function pass(StateVector $state, float $mass, float $maxThrust, float $elapsed): GuidanceCommand
    {
        $site = $this->siteFrame($state);

        $guess = $this->ttf === null
            ? $this->initialTtf($site['rh'], $site['vh'])
            : min(-0.001, $this->ttf + $elapsed);

        // ROOTPSRS. On failure the (incremented) TTF stays as it is.
        [$root, $iterations, $converged] = $this->solveTtf($guess, $site['rh'], $site['vh']);
        $ttf = $converged && $root < 0.0 ? $root : $guess;
        $this->ttf = $ttf;

        $brakingComplete = -$ttf <= LandingTargets::SWITCH_TO_APPROACH_TTF;

        // QUADGUID, per axis.
        $t = $this->targets;
        $acgV = $t->accelerationVertical
            + 6.0 * ($t->velocityVertical + $site['vv']) / $ttf
            + 12.0 * ($t->positionVertical - $site['rv']) / ($ttf * $ttf);
        $acgH = $t->accelerationHorizontal
            + 6.0 * ($t->velocityHorizontal + $site['vh']) / $ttf
            + 12.0 * ($t->positionHorizontal - $site['rh']) / ($ttf * $ttf);

        // The thrust has to cancel gravity as well.
        $r = $state->radius();
        $gravityScale = -Body::Moon->mu() / ($r * $r * $r);
        $gravityV = $gravityScale * ($state->x * $site['upX'] + $state->y * $site['upY']);
        $gravityH = $gravityScale * ($state->x * $site['hX'] + $state->y * $site['hY']);
        $thrustV = $acgV - $gravityV;
        $thrustH = $acgH - $gravityH;

        // AFCCALC2: no more than the engine can give; the vertical part has priority.
        $maxAcceleration = $maxThrust / $mass;
        if (hypot($thrustV, $thrustH) > $maxAcceleration) {
            $room = $maxAcceleration ** 2 - $thrustV ** 2;
            $thrustH = ($thrustH < 0.0 ? -1.0 : 1.0) * sqrt(max(0.0, $room));
        }

        // Back to the global frame, then the pitch above the local horizontal at the lander.
        $thrustX = $thrustV * $site['upX'] + $thrustH * $site['hX'];
        $thrustY = $thrustV * $site['upY'] + $thrustH * $site['hY'];
        $localUpX = $state->x / $r;
        $localUpY = $state->y / $r;
        $pitch = atan2(
            $thrustX * $localUpX + $thrustY * $localUpY,
            $thrustX * $localUpY - $thrustY * $localUpX,
        );

        return new GuidanceCommand(
            ttf: $ttf,
            iterations: $iterations,
            converged: $converged,
            brakingComplete: $brakingComplete,
            pitch: $pitch,
            thrustFraction: hypot($thrustX, $thrustY) / $maxAcceleration,
        );
    }

    /**
     * ROOTPSRS: Newton's method on the TTF cubic, starting from $guess.
     *
     * @return array{float, int, bool} the root, the number of iterations, and whether it converged
     */
    public function solveTtf(float $guess, float $rg, float $vg): array
    {
        $t = $this->targets;
        $a3 = $t->jerkHorizontal;
        $a2 = 6.0 * $t->accelerationHorizontal;
        $a1 = 18.0 * $t->velocityHorizontal + 6.0 * $vg;
        $a0 = 24.0 * ($t->positionHorizontal - $rg);

        $critical = abs($guess) * self::ROOT_PRECISION;
        $root = $guess;
        for ($iteration = 1; $iteration <= self::MAX_ITERATIONS; $iteration++) {
            $residual = ((($a3 * $root + $a2) * $root) + $a1) * $root + $a0;
            $derivative = (3.0 * $a3 * $root + 2.0 * $a2) * $root + $a1;
            if ($derivative === 0.0) {
                return [$guess, $iteration, false];
            }
            $step = $residual / $derivative;
            $root -= $step;
            if (abs($step) <= $critical) {
                return [$root, $iteration, true];
            }
        }

        return [$guess, self::MAX_ITERATIONS, false];
    }

    /** The first guess for TTF: the time to cover the distance at the mean of the start and target speeds. */
    private function initialTtf(float $rh, float $vh): float
    {
        $meanSpeed = max(1.0, ($vh + $this->targets->velocityHorizontal) / 2.0);

        return -max(1.0, ($this->targets->positionHorizontal - $rh) / $meanSpeed);
    }

    /**
     * RGVGCALC: the state relative to the landing site, in the landing-site frame.
     *
     * @return array{rv: float, rh: float, vv: float, vh: float, upX: float, upY: float, hX: float, hY: float}
     */
    private function siteFrame(StateVector $state): array
    {
        $radius = Body::Moon->radius();
        $upX = sin($this->siteAngle);
        $upY = cos($this->siteAngle);
        $hX = $upY;
        $hY = -$upX;
        $dx = $state->x - $radius * $upX;
        $dy = $state->y - $radius * $upY;

        return [
            'rv' => $dx * $upX + $dy * $upY,
            'rh' => $dx * $hX + $dy * $hY,
            'vv' => $state->vx * $upX + $state->vy * $upY,
            'vh' => $state->vx * $hX + $state->vy * $hY,
            'upX' => $upX,
            'upY' => $upY,
            'hX' => $hX,
            'hY' => $hY,
        ];
    }
}
