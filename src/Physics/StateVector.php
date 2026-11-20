<?php

declare(strict_types=1);

namespace Apollo\Physics;

/**
 * 2D position (m) and velocity (m/s), with the centre of the body in the origin.
 *
 * Local frame: "up" is the unit vector (x, y) / r. "Horizontal" is the clockwise
 * tangent (y, -x) / r, so a vehicle starting on the +y axis and flying towards +x
 * has a positive horizontal velocity.
 */
final readonly class StateVector
{
    public function __construct(
        public float $x,
        public float $y,
        public float $vx,
        public float $vy,
    ) {
    }

    public function radius(): float
    {
        return hypot($this->x, $this->y);
    }

    public function altitude(Body $body): float
    {
        return $this->radius() - $body->radius();
    }

    /** Velocity along "up" in m/s (negative while descending). */
    public function radialVelocity(): float
    {
        return ($this->x * $this->vx + $this->y * $this->vy) / $this->radius();
    }

    /** Velocity along the local horizontal in m/s. */
    public function horizontalVelocity(): float
    {
        return ($this->y * $this->vx - $this->x * $this->vy) / $this->radius();
    }

    /** Ground-track angle in radians, measured clockwise from the +y axis. */
    public function angle(): float
    {
        return atan2($this->x, $this->y);
    }
}
