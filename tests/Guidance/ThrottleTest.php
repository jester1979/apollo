<?php

declare(strict_types=1);

namespace Apollo\Tests\Guidance;

use Apollo\Guidance\Luminary\Throttle;
use PHPUnit\Framework\TestCase;

final class ThrottleTest extends TestCase
{
    public function testACommandInsideTheRangeIsPassedOn(): void
    {
        self::assertSame(0.4, (new Throttle())->command(0.4));
    }

    public function testACommandBelowTheMinimumIsRaisedToTenPercent(): void
    {
        self::assertSame(0.1, (new Throttle())->command(0.02));
    }

    public function testACommandAboveSixtyPercentGoesFlatOut(): void
    {
        self::assertSame(1.0, (new Throttle())->command(0.75));
    }

    public function testFlatOutIsKeptUntilTheCommandFallsToTheLowCriticalLevel(): void
    {
        $throttle = new Throttle();
        self::assertSame(1.0, $throttle->flatOut());

        self::assertSame(1.0, $throttle->command(0.58), 'between LOWCRIT and HIGHCRIT stays flat out');
        self::assertSame(0.5, $throttle->command(0.5), 'below LOWCRIT throttles again');
        self::assertSame(0.58, $throttle->command(0.58), 'and stays throttled below HIGHCRIT');
    }
}
