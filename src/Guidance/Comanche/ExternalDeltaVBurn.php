<?php

declare(strict_types=1);

namespace Apollo\Guidance\Comanche;

use Apollo\Physics\Integrator;
use Apollo\Physics\StateVector;
use Apollo\Physics\Vehicle;

/**
 * P40 with an external delta-V: the crew (here the chapter) gives the velocity to be gained as a vector,
 * and the burn is flown until that velocity is gained. It does not depend on the vehicle or the vector:
 * LOI gives a retrograde vector for the CSM with the LM, TEI a prograde one for the CSM alone.
 *
 * Three routines of P40 are ported:
 *  - UPDATEVG takes the velocity the accelerometers measured since the last pass off VG (the velocity
 *    to be gained), and adds it to the accumulated delta-V (DVTOTAL, shown in R3 of N40);
 *  - STEERING calls S40.8 each pass: the thrust is pointed along VG, and TGO, the time to go, follows
 *    from the rocket equation with the exhaust velocity;
 *  - S40.81 takes over when TGO is under 4 s: the steering stops (the attitude is held), and the engine
 *    is switched off at TGO, in a final tick of the remaining time instead of a whole pass.
 *
 * Everything is in SI in the inertial frame of the state vector, with the centre of the body in the origin.
 *
 * @see Comanche055/P40-P47.agc UPDATEVG
 * @see Comanche055/P40-P47.agc STEERING
 * @see Comanche055/P40-P47.agc S40.8
 * @see Comanche055/P40-P47.agc S40.81
 */
final class ExternalDeltaVBurn
{
    /** S40.8 hands over to S40.81 when TGO is less than FOURSEC (400 cs). */
    public const SHUTDOWN_TGO = 4.0;

    /** Below this velocity to be gained the burn counts as done, in m/s. */
    private const NEGLIGIBLE = 1e-6;

    private float $vgX;
    private float $vgY;
    private float $accumulated = 0.0;
    private float $directionX;
    private float $directionY;
    private bool $steering = true;

    /**
     * @param float $targetX x component of the delta-V to gain, in m/s
     * @param float $targetY y component of the delta-V to gain, in m/s
     * @param float $exhaustVelocity isp times standard gravity of the engine, in m/s
     */
    public function __construct(
        float $targetX,
        float $targetY,
        private readonly float $exhaustVelocity,
    ) {
        $magnitude = hypot($targetX, $targetY);
        if ($magnitude <= 0.0 || $this->exhaustVelocity <= 0.0) {
            throw new \InvalidArgumentException('A burn needs a delta-V and an engine');
        }
        $this->vgX = $targetX;
        $this->vgY = $targetY;
        $this->directionX = $targetX / $magnitude;
        $this->directionY = $targetY / $magnitude;
    }

    /** VGDISP: the magnitude of the velocity to be gained, in m/s. */
    public function velocityToBeGained(): float
    {
        return hypot($this->vgX, $this->vgY);
    }

    /** DVTOTAL: the velocity gained so far, in m/s. */
    public function accumulated(): float
    {
        return $this->accumulated;
    }

    /** False after S40.81 has taken the steering off. */
    public function isSteering(): bool
    {
        return $this->steering;
    }

    /**
     * The velocity the thrust adds in $dt seconds (what the PIPAs measure): the engine pushes along the
     * pitch of the vehicle. Call it before the integrator steps, with the vehicle as it was at the start of
     * the step: the mass is the one in the middle of the step, like the integrator takes it.
     *
     * @return array{float, float} x and y component, in m/s
     */
    public function sensedDeltaV(Vehicle $vehicle, StateVector $state, float $dt): array
    {
        if ($vehicle->throttle <= 0.0 || $vehicle->fuelMass() <= 0.0 || $dt <= 0.0) {
            return [0.0, 0.0];
        }

        $thrust = $vehicle->throttle * $vehicle->maxThrust;
        $fullBurn = $thrust / ($vehicle->isp * Integrator::G0) * $dt;
        $burn = min($fullBurn, $vehicle->fuelMass());
        $acceleration = $thrust * ($burn / $fullBurn) / ($vehicle->mass - $burn / 2.0);
        $r = $state->radius();
        $upX = $state->x / $r;
        $upY = $state->y / $r;

        return [
            $acceleration * $dt * (cos($vehicle->pitch) * $upY + sin($vehicle->pitch) * $upX),
            $acceleration * $dt * (-cos($vehicle->pitch) * $upX + sin($vehicle->pitch) * $upY),
        ];
    }

    /**
     * UPDATEVG: VG loses the velocity measured since the last update, and the accumulated delta-V grows by it.
     *
     * @param float $sensedX x component of the delta-V measured since the last update, in m/s
     * @param float $sensedY y component, in m/s
     */
    public function updateVg(float $sensedX, float $sensedY): void
    {
        $this->vgX -= $sensedX;
        $this->vgY -= $sensedY;
        $this->accumulated += hypot($sensedX, $sensedY);
    }

    /**
     * UPDATEVG then S40.8: the new steering direction and the time to go are computed from the updated VG.
     * After S40.81 the direction stays as it was.
     *
     * @param float $sensedX x component of the delta-V measured since the last pass, in m/s
     * @param float $sensedY y component, in m/s
     * @param float $mass mass of the vehicle now, in kg
     * @param float $thrust thrust of the engine now, in N
     */
    public function pass(float $sensedX, float $sensedY, float $mass, float $thrust): BurnCommand
    {
        $this->updateVg($sensedX, $sensedY);

        // S40.8: the thrust points along VG.
        $remaining = $this->velocityToBeGained();
        if ($this->steering && $remaining > self::NEGLIGIBLE) {
            $this->directionX = $this->vgX / $remaining;
            $this->directionY = $this->vgY / $remaining;
        }

        // TGO from the rocket equation: the propellant for VG at the exhaust velocity, at the burn rate of the engine.
        $burnRate = $thrust / $this->exhaustVelocity;
        $timeToGo = $burnRate > 0.0
            ? $mass / $burnRate * (1.0 - exp(-$remaining / $this->exhaustVelocity))
            : INF;

        $shutDown = $timeToGo < self::SHUTDOWN_TGO;
        if ($shutDown) {
            $this->steering = false; // S40.81: attitude hold, the engine goes off at TGO
        }

        return new BurnCommand($this->directionX, $this->directionY, $timeToGo, $shutDown);
    }

    /**
     * The pitch (above the local horizontal, as the integrator takes it) that points the thrust along a
     * direction of the inertial frame.
     */
    public static function pitchFor(StateVector $state, float $directionX, float $directionY): float
    {
        $r = $state->radius();
        $upX = $state->x / $r;
        $upY = $state->y / $r;

        return atan2($directionX * $upX + $directionY * $upY, $directionX * $upY - $directionY * $upX);
    }
}
