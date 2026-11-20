<?php

declare(strict_types=1);

namespace Apollo\Physics;

/** A vehicle with a throttleable engine. Mass is in kg, thrust in N, isp in seconds. */
final class Vehicle
{
    public function __construct(
        public float $mass,
        public readonly float $dryMass,
        public readonly float $maxThrust,
        public readonly float $isp,
        public float $throttle = 0.0,
        /** Thrust direction in radians above the local horizontal (0 = forward, pi = backward). */
        public float $pitch = 0.0,
        /** Drag coefficient times reference area in m^2: drag = 1/2 * rho * v^2 * dragArea. 0 = no drag. */
        public readonly float $dragArea = 0.0,
    ) {
    }

    public function fuelMass(): float
    {
        return max(0.0, $this->mass - $this->dryMass);
    }
}
