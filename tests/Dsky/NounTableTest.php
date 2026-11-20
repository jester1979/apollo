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
        self::assertNull(NounTable::for(Computer::Cm)->find(68));
        self::assertNotNull(NounTable::for(Computer::Lm)->find(68));
        // N63 exists in both tables, with different registers: braking velocity in the LM, range to splash in the CM.
        self::assertSame(Unit::FeetPerSecond, NounTable::for(Computer::Lm)->find(63)?->components[0]->unit);
        self::assertSame(Unit::NauticalMiles, NounTable::for(Computer::Cm)->find(63)?->components[0]->unit);
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

    public function testNoun25OfTheCmIsTheChecklistCodeInThreeWholeRegisters(): void
    {
        $noun = NounTable::for(Computer::Cm)->find(25);

        self::assertNotNull($noun);
        self::assertSame(
            [[Unit::None, Format::Whole], [Unit::None, Format::Whole], [Unit::None, Format::Whole]],
            array_map(static fn ($component) => [$component->unit, $component->format], $noun->components),
        );
        self::assertNull(NounTable::for(Computer::Lm)->find(25));
    }

    public function testNoun40OfTheCmIsTimeFromIgnitionVelocityToBeGainedAndDeltaV(): void
    {
        $noun = NounTable::for(Computer::Cm)->find(40);

        self::assertNotNull($noun);
        self::assertSame(
            [
                [Unit::Seconds, Format::MinutesSeconds],
                [Unit::FeetPerSecond, Format::Decimal1],
                [Unit::FeetPerSecond, Format::Decimal1],
            ],
            array_map(static fn ($component) => [$component->unit, $component->format], $noun->components),
        );
        self::assertNull(NounTable::for(Computer::Lm)->find(40));
    }

    public function testNoun76OfTheLmIsDesiredVelocitiesAndCrossRange(): void
    {
        $noun = NounTable::for(Computer::Lm)->find(76);

        self::assertNotNull($noun);
        self::assertSame(
            [
                [Unit::FeetPerSecond, Format::Decimal1],
                [Unit::FeetPerSecond, Format::Decimal1],
                [Unit::NauticalMiles, Format::Decimal1],
            ],
            array_map(static fn ($component) => [$component->unit, $component->format], $noun->components),
        );
        self::assertNull(NounTable::for(Computer::Cm)->find(76));
    }

    public function testNoun74OfTheLmIsTimeFromIgnitionAndTheAnglesAfterVehicleRise(): void
    {
        $noun = NounTable::for(Computer::Lm)->find(74);

        self::assertNotNull($noun);
        self::assertSame(
            [
                [Unit::Seconds, Format::MinutesSeconds],
                [Unit::Degrees, Format::Decimal2],
                [Unit::Degrees, Format::Decimal2],
            ],
            array_map(static fn ($component) => [$component->unit, $component->format], $noun->components),
        );
        self::assertSame(45.0, Unit::Degrees->fromSi(M_PI / 4.0));
        self::assertSame(Unit::Degrees, NounTable::for(Computer::Cm)->find(74)?->components[0]->unit, 'the CM N74 is the bank angle');
    }

    public function testTheEntryNounsOfTheCmFollowTheNounListOfComanche(): void
    {
        $table = NounTable::for(Computer::Cm);
        $expected = [
            // N60: GMAX XXX.XX g, VPRED XXXXX. ft/s, GAMMA EI XXX.XX deg.
            60 => [[Unit::G, Format::Decimal2], [Unit::FeetPerSecond, Format::Whole], [Unit::Degrees, Format::Decimal2]],
            // N63: RTGO XXXX.X nm, VIO XXXXX. ft/s, TFE XXBXX min/sec.
            63 => [[Unit::NauticalMiles, Format::Decimal1], [Unit::FeetPerSecond, Format::Whole], [Unit::Seconds, Format::MinutesSeconds]],
            // N64: drag XXX.XX g, VI XXXXX. ft/s, range to splash XXXX.X nm.
            64 => [[Unit::G, Format::Decimal2], [Unit::FeetPerSecond, Format::Whole], [Unit::NauticalMiles, Format::Decimal1]],
            // N65: sampled time 00XXX. hours, 000XX. minutes, 0XX.XX seconds.
            65 => [[Unit::Seconds, Format::Hours], [Unit::Seconds, Format::Minutes], [Unit::Seconds, Format::Seconds]],
            // N66: BETA XXX.XX deg, cross range XXXX.X nm, down range XXXX.X nm.
            66 => [[Unit::Degrees, Format::Decimal2], [Unit::NauticalMiles, Format::Decimal1], [Unit::NauticalMiles, Format::Decimal1]],
            // N67: range to target XXXX.X nm, latitude and longitude XXX.XX deg.
            67 => [[Unit::NauticalMiles, Format::Decimal1], [Unit::Degrees, Format::Decimal2], [Unit::Degrees, Format::Decimal2]],
            // N69: BETA XXX.XX deg, DL XXX.XX g, VL XXXXX. ft/s.
            69 => [[Unit::Degrees, Format::Decimal2], [Unit::G, Format::Decimal2], [Unit::FeetPerSecond, Format::Whole]],
            // N74: BETA XXX.XX deg, VI XXXXX. ft/s, drag XXX.XX g.
            74 => [[Unit::Degrees, Format::Decimal2], [Unit::FeetPerSecond, Format::Whole], [Unit::G, Format::Decimal2]],
        ];

        foreach ($expected as $number => $components) {
            $noun = $table->find($number);
            self::assertNotNull($noun, sprintf('N%d', $number));
            self::assertSame(
                $components,
                array_map(static fn ($component) => [$component->unit, $component->format], $noun->components),
                sprintf('N%d', $number),
            );
        }
        self::assertEqualsWithDelta(1.0, Unit::G->fromSi(9.80665), 1e-12);
    }
}
