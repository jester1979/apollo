<?php

declare(strict_types=1);

namespace Apollo\Tests\Dsky;

use Apollo\Dsky\DisplayFormatter;
use Apollo\Dsky\Format;
use Apollo\Dsky\NounComponent;
use Apollo\Dsky\Unit;
use PHPUnit\Framework\TestCase;

final class DisplayFormatterTest extends TestCase
{
    public function testOneDecimalRoundsToTheSignAndFiveDigits(): void
    {
        self::assertSame('+12346', DisplayFormatter::format(1234.56, Format::Decimal1));
    }

    public function testAWholeNumberIsPaddedWithZerosAndKeepsItsSign(): void
    {
        self::assertSame('-00042', DisplayFormatter::format(-42.0, Format::Whole));
        self::assertSame('+00000', DisplayFormatter::format(0.0, Format::Whole));
    }

    public function testTwoDecimalsScaleByOneHundred(): void
    {
        self::assertSame('+12345', DisplayFormatter::format(123.45, Format::Decimal2));
    }

    public function testMinutesAndSecondsAreSeparatedByASpace(): void
    {
        self::assertSame('03 25', DisplayFormatter::format(205.0, Format::MinutesSeconds));
        self::assertSame('00 00', DisplayFormatter::format(0.0, Format::MinutesSeconds));
        self::assertSame('99 59', DisplayFormatter::format(1_000_000.0, Format::MinutesSeconds));
    }

    public function testAValueOutOfRangeShowsTheLargestDigitsWithoutAnAlarm(): void
    {
        self::assertSame('+99999', DisplayFormatter::format(123456.0, Format::Whole));
        self::assertSame('-99999', DisplayFormatter::format(-123456.0, Format::Whole));
        self::assertSame('+99999', DisplayFormatter::format(99999.9, Format::Decimal1));
    }

    public function testOctalShowsFiveOctalDigits(): void
    {
        self::assertSame('01202', DisplayFormatter::format(0o1202, Format::Octal));
        self::assertSame('77777', DisplayFormatter::format(1_000_000.0, Format::Octal));
    }

    public function testTheTimeFormatsSplitSecondsIntoHoursMinutesAndSeconds(): void
    {
        $seconds = 3600.0 * 2 + 60.0 * 5 + 7.25;

        self::assertSame('+00002', DisplayFormatter::format($seconds, Format::Hours));
        self::assertSame('+00005', DisplayFormatter::format($seconds, Format::Minutes));
        self::assertSame('+00725', DisplayFormatter::format($seconds, Format::Seconds));
    }

    public function testAValueThatIsNotANumberLeavesTheRegisterBlank(): void
    {
        self::assertSame('', DisplayFormatter::format(NAN, Format::Whole));
    }

    public function testAComponentConvertsFromSiToItsUnit(): void
    {
        $velocity = new NounComponent(Unit::FeetPerSecond, Format::Decimal1);
        $altitude = new NounComponent(Unit::Feet, Format::Whole);
        $range = new NounComponent(Unit::NauticalMiles, Format::Decimal1);

        self::assertSame('+03281', DisplayFormatter::component(100.0, $velocity), '100 m/s is 328.1 ft/s');
        self::assertSame('+32808', DisplayFormatter::component(10000.0, $altitude), '10 km is 32808 ft');
        self::assertSame('+01000', DisplayFormatter::component(1852.0 * 100.0, $range), '100 NM');
    }
}
