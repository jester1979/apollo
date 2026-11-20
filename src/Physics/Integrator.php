<?php

declare(strict_types=1);

namespace Apollo\Physics;

/** RK4 integration of gravity and engine thrust, with fuel consumption. */
final class Integrator
{
    /** Fixed physics step in seconds. */
    public const STEP = 0.1;

    /** Standard gravity in m/s^2, used in the rocket equation. */
    public const G0 = 9.80665;

    /**
     * Advance $state by $dt seconds. Burns fuel from $vehicle (its mass drops); the engine
     * cuts out when the fuel is used up.
     */
    public function step(Vehicle $vehicle, StateVector $state, Body $body, float $dt): StateVector
    {
        $thrust = $vehicle->fuelMass() > 0.0 ? $vehicle->throttle * $vehicle->maxThrust : 0.0;
        $burn = 0.0;
        if ($thrust > 0.0) {
            $fullBurn = $thrust / ($vehicle->isp * self::G0) * $dt;
            $burn = min($fullBurn, $vehicle->fuelMass());
            $thrust *= $burn / $fullBurn;
        }
        $mass = $vehicle->mass - $burn / 2.0;
        $thrustAcceleration = $thrust / $mass;

        $k1 = $this->derivative($state, $body, $thrustAcceleration, $vehicle->pitch);
        $k2 = $this->derivative($this->advance($state, $k1, $dt / 2.0), $body, $thrustAcceleration, $vehicle->pitch);
        $k3 = $this->derivative($this->advance($state, $k2, $dt / 2.0), $body, $thrustAcceleration, $vehicle->pitch);
        $k4 = $this->derivative($this->advance($state, $k3, $dt), $body, $thrustAcceleration, $vehicle->pitch);

        $vehicle->mass -= $burn;

        return new StateVector(
            $state->x + $dt / 6.0 * ($k1[0] + 2.0 * $k2[0] + 2.0 * $k3[0] + $k4[0]),
            $state->y + $dt / 6.0 * ($k1[1] + 2.0 * $k2[1] + 2.0 * $k3[1] + $k4[1]),
            $state->vx + $dt / 6.0 * ($k1[2] + 2.0 * $k2[2] + 2.0 * $k3[2] + $k4[2]),
            $state->vy + $dt / 6.0 * ($k1[3] + 2.0 * $k2[3] + 2.0 * $k3[3] + $k4[3]),
        );
    }

    /**
     * @param array{float, float, float, float} $derivative
     */
    private function advance(StateVector $state, array $derivative, float $h): StateVector
    {
        return new StateVector(
            $state->x + $derivative[0] * $h,
            $state->y + $derivative[1] * $h,
            $state->vx + $derivative[2] * $h,
            $state->vy + $derivative[3] * $h,
        );
    }

    /**
     * @return array{float, float, float, float} dx/dt, dy/dt, dvx/dt, dvy/dt
     */
    private function derivative(StateVector $state, Body $body, float $thrustAcceleration, float $pitch): array
    {
        $r = $state->radius();
        $gravity = -$body->mu() / ($r * $r * $r);

        // Thrust direction = cos(pitch) * horizontal + sin(pitch) * up (see StateVector).
        $upX = $state->x / $r;
        $upY = $state->y / $r;
        $horizontalX = $upY;
        $horizontalY = -$upX;

        return [
            $state->vx,
            $state->vy,
            $gravity * $state->x + $thrustAcceleration * (cos($pitch) * $horizontalX + sin($pitch) * $upX),
            $gravity * $state->y + $thrustAcceleration * (cos($pitch) * $horizontalY + sin($pitch) * $upY),
        ];
    }
}
