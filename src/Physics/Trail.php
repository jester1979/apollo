<?php

declare(strict_types=1);

namespace Apollo\Physics;

/**
 * The path a vehicle has flown, kept small: the simulation is serialized into the session on every
 * request. It takes a point every $interval seconds. When $capacity points are full it drops every
 * other point and doubles the interval, so the path always covers the whole flight at a coarser grain.
 */
final class Trail
{
    /** @var list<array{float, float}> positions in metres */
    private array $points = [];
    private float $nextAt = 0.0;

    public function __construct(
        private readonly int $capacity = 240,
        private float $interval = 4.0,
    ) {
        if ($this->capacity < 4 || $this->interval <= 0.0) {
            throw new \InvalidArgumentException('A trail needs room for at least 4 points and a positive interval');
        }
    }

    /** Records the position when the next sample is due; $time is the time of the flight in seconds. */
    public function record(float $time, StateVector $state): void
    {
        if ($time < $this->nextAt) {
            return;
        }

        $this->points[] = [$state->x, $state->y];
        $this->nextAt = $time + $this->interval;
        if (count($this->points) > $this->capacity) {
            $this->points = array_values(array_filter(
                $this->points,
                static fn (int $index): bool => $index % 2 === 0,
                ARRAY_FILTER_USE_KEY,
            ));
            $this->interval *= 2.0;
        }
    }

    /** @return list<array{float, float}> */
    public function points(): array
    {
        return $this->points;
    }
}
