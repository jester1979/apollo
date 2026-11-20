<?php

declare(strict_types=1);

namespace Apollo\Tests\Dsky;

use Apollo\Agc\Computer;
use Apollo\Dsky\Format;
use Apollo\Dsky\NounTable;
use Apollo\Dsky\Unit;
use PHPUnit\Framework\TestCase;

final class NounTableTest extends TestCase
{
    public function testNoun63OfTheLmIsVelocityAltitudeRateAndAltitude(): void
    {
        $noun = NounTable::for(Computer::Lm)->find(63);

        self::assertNotNull($noun);
        self::assertSame(
            [
                [Unit::FeetPerSecond, Format::Decimal1],
                [Unit::FeetPerSecond, Format::Decimal1],
                [Unit::Feet, Format::Whole],
            ],
            array_map(static fn ($component) => [$component->unit, $component->format], $noun->components),
        );
    }

    public function testEachComputerKnowsItsOwnNouns(): void
    {
        self::assertContains(63, NounTable::for(Computer::Lm)->numbers());
        self::assertNull(NounTable::for(Computer::Cm)->find(63));
        self::assertNotNull(NounTable::for(Computer::Cm)->find(9));
        self::assertNull(NounTable::for(Computer::Lm)->find(99));
    }

    public function testNoun68HasAMinutesSecondsMiddleComponent(): void
    {
        $noun = NounTable::for(Computer::Lm)->find(68);

        self::assertNotNull($noun);
        self::assertSame(Format::MinutesSeconds, $noun->components[1]->format);
    }

    public function testNoun62OfTheCmIsInertialVelocityAltitudeRateAndAltitudeAbovePadRadius(): void
    {
        $noun = NounTable::for(Computer::Cm)->find(62);

        self::assertNotNull($noun);
        self::assertSame(
            [
                [Unit::FeetPerSecond, Format::Whole],
                [Unit::FeetPerSecond, Format::Whole],
                [Unit::NauticalMiles, Format::Decimal1],
            ],
            array_map(static fn ($component) => [$component->unit, $component->format], $noun->components),
        );
        self::assertNull(NounTable::for(Computer::Lm)->find(62));
    }
}
