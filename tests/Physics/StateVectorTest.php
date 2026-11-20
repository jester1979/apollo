<?php

declare(strict_types=1);

namespace Apollo\Tests\Physics;

use Apollo\Physics\Body;
use Apollo\Physics\StateVector;
use PHPUnit\Framework\TestCase;

final class StateVectorTest extends TestCase
{
    public function testACircularOrbitHasItsOwnAltitudeAsPerigee(): void
    {
        $radius = Body::Earth->radius() + 185000.0;
        $state = new StateVector(0.0, $radius, sqrt(Body::Earth->mu() / $radius), 0.0);

        self::assertEqualsWithDelta(185000.0, $state->perigeeAltitude(Body::Earth), 1.0);
    }

    public function testAnEllipseFromItsPerigeeKeepsThatPerigee(): void
    {
        $radius = Body::Moon->radius() + 110000.0;
        $state = new StateVector(0.0, $radius, sqrt(Body::Moon->mu() / $radius) * 1.1, 0.0);

        self::assertEqualsWithDelta(110000.0, $state->perigeeAltitude(Body::Moon), 1.0);
    }

    public function testAFallingVehicleHasANegativePerigee(): void
    {
        $state = new StateVector(0.0, Body::Earth->radius() + 100000.0, 3000.0, 0.0);

        self::assertLessThan(0.0, $state->perigeeAltitude(Body::Earth));
    }

    public function testAnEscapeOrbitHasAPerigeeToo(): void
    {
        $radius = Body::Moon->radius() + 110000.0;
        $state = new StateVector(0.0, $radius, sqrt(2.0 * Body::Moon->mu() / $radius) * 1.05, 0.0);

        self::assertEqualsWithDelta(110000.0, $state->perigeeAltitude(Body::Moon), 1.0);
    }
}
