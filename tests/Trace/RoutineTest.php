<?php

declare(strict_types=1);

namespace Apollo\Tests\Trace;

use Apollo\Trace\Routine;
use PHPUnit\Framework\TestCase;

final class RoutineTest extends TestCase
{
    public function testEveryRoutinePointsAtALineOfTheOriginalSource(): void
    {
        foreach (Routine::cases() as $routine) {
            self::assertNotSame('', $routine->label());
            self::assertMatchesRegularExpression('#^(Luminary099|Comanche055)/#', $routine->file());
            self::assertGreaterThan(0, $routine->line());
            self::assertStringContainsString(Routine::COMMIT, $routine->url());
        }
    }

    public function testQuadGuidPointsAtItsLabel(): void
    {
        self::assertSame(
            [
                'label' => 'QUADGUID',
                'file' => 'Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc',
                'line' => 546,
                'url' => 'https://github.com/chrislgarry/Apollo-11/blob/e7576d50f7c279a707cfaa71be2020592dbf43a8/Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc#L546',
            ],
            Routine::QuadGuid->toArray(),
        );
    }
}
