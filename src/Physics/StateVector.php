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

    /** Speed relative to the air, which turns with the body (see Body::rotationRate()), in m/s. */
    public function airSpeed(Body $body): float
    {
        $rotation = $body->rotationRate();

        return hypot($this->vx - $rotation * $this->y, $this->vy + $rotation * $this->x);
    }

    /** Ground-track angle in radians, measured clockwise from the +y axis. */
    public function angle(): float
    {
        return atan2($this->x, $this->y);
    }

    /**
     * Altitude of the lowest point of the conic the vehicle follows when the engine stays off, in
     * metres above the surface. It is negative when that orbit hits the body, and it is valid for
     * ellipses and for escape orbits alike: the periapsis radius is p / (1 + e) with p = h^2 / mu.
     */
    public function perigeeAltitude(Body $body): float
    {
        $mu = $body->mu();
        $angularMomentum = $this->x * $this->vy - $this->y * $this->vx;
        $energy = ($this->vx ** 2 + $this->vy ** 2) / 2.0 - $mu / $this->radius();
        $eccentricity = sqrt(max(0.0, 1.0 + 2.0 * $energy * $angularMomentum ** 2 / ($mu * $mu)));

        return $angularMomentum ** 2 / $mu / (1.0 + $eccentricity) - $body->radius();
    }
}
