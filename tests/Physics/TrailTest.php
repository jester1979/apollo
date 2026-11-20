<?php

declare(strict_types=1);

namespace Apollo\Tests\Physics;

use Apollo\Physics\StateVector;
use Apollo\Physics\Trail;
use PHPUnit\Framework\TestCase;

final class TrailTest extends TestCase
{
    public function testItTakesOnePointPerIntervalAndStaysWithinItsCapacity(): void
    {
        $trail = new Trail(capacity: 20, interval: 2.0);

        for ($step = 0; $step <= 10000; $step++) {
            $trail->record($step * 0.1, new StateVector($step * 1.0, 0.0, 0.0, 0.0));
            self::assertLessThanOrEqual(21, count($trail->points()));
        }

        $points = $trail->points();
        self::assertSame([0.0, 0.0], $points[0], 'the first point is kept');
        self::assertGreaterThan(10, count($points));
        self::assertGreaterThan(500.0, $points[count($points) - 1][0], 'the whole flight stays covered at a coarser grain');
    }

    public function testItIgnoresASampleBeforeTheNextIsDue(): void
    {
        $trail = new Trail(capacity: 10, interval: 4.0);
        $trail->record(0.0, new StateVector(1.0, 0.0, 0.0, 0.0));
        $trail->record(3.9, new StateVector(2.0, 0.0, 0.0, 0.0));
        $trail->record(4.0, new StateVector(3.0, 0.0, 0.0, 0.0));

        self::assertSame([[1.0, 0.0], [3.0, 0.0]], $trail->points());
    }

    public function testItSerializesWithTheSimulation(): void
    {
        $trail = new Trail();
        $trail->record(0.0, new StateVector(1.0, 2.0, 0.0, 0.0));

        self::assertEquals($trail, unserialize(serialize($trail)));
    }
}
