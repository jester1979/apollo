<?php

declare(strict_types=1);

namespace Apollo\Physics;

/**
 * RK4 integration of gravity, engine thrust, and drag and lift in the atmosphere (if the body has one and
 * the vehicle has a drag area), with fuel consumption. The air turns with the body. The lift is the drag
 * times the lift-to-drag ratio of the vehicle, perpendicular to the velocity relative to the air, scaled
 * with the cosine of the bank angle (in the 2D plane only the up or down part of the lift counts).
 */
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
        $dragPerKilogram = $vehicle->dragArea / $mass;
        $lift = $vehicle->liftToDrag * cos($vehicle->bank);

        $k1 = $this->derivative($state, $body, $thrustAcceleration, $vehicle->pitch, $dragPerKilogram, $lift);
        $k2 = $this->derivative($this->advance($state, $k1, $dt / 2.0), $body, $thrustAcceleration, $vehicle->pitch, $dragPerKilogram, $lift);
        $k3 = $this->derivative($this->advance($state, $k2, $dt / 2.0), $body, $thrustAcceleration, $vehicle->pitch, $dragPerKilogram, $lift);
        $k4 = $this->derivative($this->advance($state, $k3, $dt), $body, $thrustAcceleration, $vehicle->pitch, $dragPerKilogram, $lift);

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
     * @param float $dragPerKilogram drag area / mass in m^2/kg
     * @param float $lift lift-to-drag ratio times the cosine of the bank angle
     * @return array{float, float, float, float} dx/dt, dy/dt, dvx/dt, dvy/dt
     */
    private function derivative(
        StateVector $state,
        Body $body,
        float $thrustAcceleration,
        float $pitch,
        float $dragPerKilogram,
        float $lift,
    ): array {
        $r = $state->radius();
        $gravity = -$body->mu() / ($r * $r * $r);

        // Thrust direction = cos(pitch) * horizontal + sin(pitch) * up (see StateVector).
        $upX = $state->x / $r;
        $upY = $state->y / $r;
        $horizontalX = $upY;
        $horizontalY = -$upX;

        [$dragX, $dragY] = $this->dragAcceleration($state, $body, $dragPerKilogram, $lift);

        return [
            $state->vx,
            $state->vy,
            $gravity * $state->x + $thrustAcceleration * (cos($pitch) * $horizontalX + sin($pitch) * $upX) + $dragX,
            $gravity * $state->y + $thrustAcceleration * (cos($pitch) * $horizontalY + sin($pitch) * $upY) + $dragY,
        ];
    }

    /**
     * Drag acceleration: 1/2 * rho * v^2 * (Cd*A / m) against the velocity relative to the air, which
     * turns with the body at its rotation rate (clockwise, like the horizontal of StateVector). The
     * lift is $lift times that drag, perpendicular to the relative velocity, on the side of "up"
     * (negative $lift: the other side).
     *
     * @return array{float, float}
     */
    private function dragAcceleration(StateVector $state, Body $body, float $dragPerKilogram, float $lift): array
    {
        $atmosphere = $body->atmosphere();
        if ($atmosphere === null || $dragPerKilogram <= 0.0) {
            return [0.0, 0.0];
        }

        $rotation = $body->rotationRate();
        $relativeX = $state->vx - $rotation * $state->y;
        $relativeY = $state->vy + $rotation * $state->x;
        $factor = -0.5 * $atmosphere->density($state->altitude($body)) * $dragPerKilogram * hypot($relativeX, $relativeY);

        if ($lift === 0.0) {
            return [$factor * $relativeX, $factor * $relativeY];
        }

        // The perpendicular to the relative velocity that has a positive "up" part: turned a quarter
        // anticlockwise for a vehicle that flies clockwise (the positive horizontal of StateVector).
        $sign = ($state->x * -$relativeY + $state->y * $relativeX) >= 0.0 ? 1.0 : -1.0;
        $liftFactor = -$factor * $lift * $sign;

        return [
            $factor * $relativeX + $liftFactor * -$relativeY,
            $factor * $relativeY + $liftFactor * $relativeX,
        ];
    }
}
