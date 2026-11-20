<?php

declare(strict_types=1);

namespace Apollo\Physics;

/**
 * The conic section a vehicle follows when its engine stays off: an ellipse when it is bound to the
 * body, a hyperbola when it is not. It gives the shape of the orbit (eccentricity, periapsis and
 * apoapsis) and the points of the predicted path that the top view draws as a dashed line.
 *
 * The 2D frame is the one of StateVector: the centre of the body in the origin. Motion is
 * counter-clockwise when the angular momentum is positive and clockwise when it is negative.
 */
final readonly class Conic
{
    /** Below this eccentricity the orbit counts as a circle, which has no periapsis direction. */
    private const CIRCULAR = 1e-9;

    /** A hyperbola is drawn up to this fraction of its asymptote angle, where the radius runs to infinity. */
    private const ASYMPTOTE_MARGIN = 0.999;

    public float $eccentricity;
    /** Semi-latus rectum p = h^2 / mu, in metres. */
    public float $semiLatusRectum;
    /** Direction of the periapsis, in radians, counted from the +x axis. */
    private float $periapsisAngle;
    /** True anomaly of the vehicle, in radians, in -pi..pi. */
    private float $trueAnomaly;
    /** +1 for counter-clockwise motion, -1 for clockwise. */
    private float $direction;

    public function __construct(StateVector $state, private Body $body)
    {
        $mu = $body->mu();
        $r = $state->radius();
        $angularMomentum = $state->x * $state->vy - $state->y * $state->vx;
        $radialDot = $state->x * $state->vx + $state->y * $state->vy;
        $speedSquared = $state->vx ** 2 + $state->vy ** 2;

        // Eccentricity vector: ((v^2 - mu/r) r - (r.v) v) / mu.
        $ex = (($speedSquared - $mu / $r) * $state->x - $radialDot * $state->vx) / $mu;
        $ey = (($speedSquared - $mu / $r) * $state->y - $radialDot * $state->vy) / $mu;
        $this->eccentricity = hypot($ex, $ey);
        $this->semiLatusRectum = $angularMomentum ** 2 / $mu;
        $this->direction = $angularMomentum < 0.0 ? -1.0 : 1.0;

        if ($this->eccentricity < self::CIRCULAR) {
            $this->periapsisAngle = atan2($state->y, $state->x);
            $this->trueAnomaly = 0.0;

            return;
        }

        $this->periapsisAngle = atan2($ey, $ex);
        $cosine = max(-1.0, min(1.0, ($ex * $state->x + $ey * $state->y) / ($this->eccentricity * $r)));
        $this->trueAnomaly = $radialDot < 0.0 ? -acos($cosine) : acos($cosine);
    }

    public function isBound(): bool
    {
        return $this->eccentricity < 1.0;
    }

    /**
     * Orbital energy per kilogram relative to the body, in J/kg: negative when bound, zero on the parabola and
     * positive on a hyperbola. It follows from the shape: mu (e^2 - 1) / (2 p).
     */
    public function specificEnergy(): float
    {
        return $this->body->mu() * ($this->eccentricity ** 2 - 1.0) / (2.0 * $this->semiLatusRectum);
    }

    /** Radius of the lowest point of the conic, in metres. */
    public function periapsisRadius(): float
    {
        return $this->semiLatusRectum / (1.0 + $this->eccentricity);
    }

    /** Altitude of the lowest point above the surface, in metres (negative: the orbit hits the body). */
    public function periapsisAltitude(): float
    {
        return $this->periapsisRadius() - $this->body->radius();
    }

    /** Radius of the highest point, in metres, or null for an orbit that is not bound. */
    public function apoapsisRadius(): ?float
    {
        return $this->isBound() ? $this->semiLatusRectum / (1.0 - $this->eccentricity) : null;
    }

    /** Altitude of the highest point above the surface, in metres, or null for an orbit that is not bound. */
    public function apoapsisAltitude(): ?float
    {
        $radius = $this->apoapsisRadius();

        return $radius === null ? null : $radius - $this->body->radius();
    }

    /**
     * The path ahead of the vehicle. A bound orbit is one full revolution that ends where it began, so
     * the line closes. An orbit that is not bound runs from the vehicle outwards until it reaches
     * $maxRadius (or the asymptote): the line stays open.
     *
     * @param int $count number of points, at least 8
     * @return list<array{float, float}> positions in metres
     */
    public function path(int $count, float $maxRadius): array
    {
        $count = max(8, $count);
        if ($this->isBound()) {
            return $this->sample($this->trueAnomaly, $this->trueAnomaly + 2.0 * M_PI, $count);
        }

        $limit = acos(max(-1.0, min(1.0, ($this->semiLatusRectum / $maxRadius - 1.0) / $this->eccentricity)));
        $limit = min($limit, self::ASYMPTOTE_MARGIN * acos(-1.0 / $this->eccentricity));
        if ($limit <= $this->trueAnomaly) {
            return []; // the vehicle is already beyond the drawn range
        }

        return $this->sample($this->trueAnomaly, $limit, $count);
    }

    /**
     * @return list<array{float, float}>
     */
    private function sample(float $from, float $to, int $count): array
    {
        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $anomaly = $from + ($to - $from) * $i / ($count - 1);
            $radius = $this->semiLatusRectum / (1.0 + $this->eccentricity * cos($anomaly));
            $angle = $this->periapsisAngle + $this->direction * $anomaly;
            $points[] = [$radius * cos($angle), $radius * sin($angle)];
        }

        return $points;
    }
}
