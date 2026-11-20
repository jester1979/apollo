<?php

declare(strict_types=1);

namespace Apollo\Tests\Physics;

use Apollo\Physics\Body;
use Apollo\Physics\Conic;
use Apollo\Physics\StateVector;
use PHPUnit\Framework\TestCase;

final class ConicTest extends TestCase
{
    private const PERILUNE_RADIUS = 1847400.0;

    public function testACircularOrbitHasNoEccentricity(): void
    {
        $speed = sqrt(Body::Moon->mu() / self::PERILUNE_RADIUS);

        $conic = new Conic(new StateVector(0.0, self::PERILUNE_RADIUS, $speed, 0.0), Body::Moon);

        self::assertEqualsWithDelta(0.0, $conic->eccentricity, 1e-9);
        self::assertTrue($conic->isBound());
        self::assertEqualsWithDelta(110000.0, $conic->periapsisAltitude(), 1.0);
    }

    public function testAnEllipseHasItsApolunePerilunePathClosedAndEveryPointOnTheConic(): void
    {
        // Perilune of 110 km at 1.67 km/s: an ellipse of about 110 by 310 km, flown clockwise.
        $state = new StateVector(0.0, self::PERILUNE_RADIUS, 1671.0, 0.0);
        $conic = new Conic($state, Body::Moon);

        self::assertTrue($conic->isBound());
        self::assertEqualsWithDelta(110000.0, $conic->periapsisAltitude(), 10.0);
        self::assertEqualsWithDelta(311000.0, $conic->apoapsisAltitude() ?? 0.0, 5000.0);
        self::assertEqualsWithDelta($state->perigeeAltitude(Body::Moon), $conic->periapsisAltitude(), 1e-3);

        $path = $conic->path(120, 3.6e6);
        self::assertCount(120, $path);
        self::assertEqualsWithDelta($path[0][0], $path[119][0], 1.0, 'closed');
        self::assertEqualsWithDelta($path[0][1], $path[119][1], 1.0, 'closed');
        self::assertEqualsWithDelta($state->x, $path[0][0], 1e-6, 'the path starts at the vehicle');
        $radii = array_map(static fn (array $point): float => hypot($point[0], $point[1]), $path);
        self::assertEqualsWithDelta(self::PERILUNE_RADIUS, min(...$radii), 100.0);
        self::assertEqualsWithDelta($conic->apoapsisRadius() ?? 0.0, max(...$radii), 3000.0);
    }

    public function testAHyperbolaIsOpenAndStopsAtTheLimitRadius(): void
    {
        $state = new StateVector(-430200.0, 1824300.0, 2370.5, 253.9);
        $conic = new Conic($state, Body::Moon);

        self::assertFalse($conic->isBound());
        self::assertNull($conic->apoapsisAltitude());
        self::assertGreaterThan(1.0, $conic->eccentricity);

        $path = $conic->path(90, 3.0e6);
        self::assertCount(90, $path);
        $last = $path[89];
        self::assertEqualsWithDelta(3.0e6, hypot($last[0], $last[1]), 1000.0);
        self::assertGreaterThan(5000.0, hypot($last[0] - $path[0][0], $last[1] - $path[0][1]), 'open');
    }

    public function testTheMotionDirectionDecidesWhichWayThePathRuns(): void
    {
        $clockwise = (new Conic(new StateVector(0.0, self::PERILUNE_RADIUS, 1671.0, 0.0), Body::Moon))->path(120, 3.6e6);
        $counter = (new Conic(new StateVector(0.0, self::PERILUNE_RADIUS, -1671.0, 0.0), Body::Moon))->path(120, 3.6e6);

        self::assertGreaterThan(0.0, $clockwise[5][0], 'moving towards +x');
        self::assertLessThan(0.0, $counter[5][0], 'moving towards -x');
    }

    public function testAnOpenOrbitAlreadyBeyondTheLimitHasNoPath(): void
    {
        $state = new StateVector(0.0, 4.0e6, 2000.0, 100.0);

        self::assertSame([], (new Conic($state, Body::Moon))->path(50, 3.0e6));
    }

    public function testTheSpecificEnergyMatchesTheVisVivaEnergyForBoundAndOpenOrbits(): void
    {
        foreach ([1500.0, 1671.0, 2304.0, 2700.0] as $speed) {
            $state = new StateVector(0.0, self::PERILUNE_RADIUS, $speed, 0.0);
            $expected = $speed ** 2 / 2.0 - Body::Moon->mu() / self::PERILUNE_RADIUS;

            self::assertEqualsWithDelta($expected, (new Conic($state, Body::Moon))->specificEnergy(), abs($expected) * 1e-9 + 1e-3);
        }
    }
}
