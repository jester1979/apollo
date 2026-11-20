<?php

declare(strict_types=1);

namespace Apollo\Dsky;

use Apollo\Agc\Computer;

/**
 * The nouns one computer knows, with the components, units and formats from the noun lists of
 * ASSEMBLY_AND_OPERATION_INFORMATION.agc. Only the nouns a chapter shows are in the table; the
 * slices that show more nouns add them. A noun that is not in the table is unknown to the computer.
 *
 * @see Luminary099/ASSEMBLY_AND_OPERATION_INFORMATION.agc NORMAL NOUNS / MIXED NOUNS (:284-479)
 * @see Comanche055/ASSEMBLY_AND_OPERATION_INFORMATION.agc NORMAL NOUNS / MIXED NOUNS
 */
final readonly class NounTable
{
    /** @var array<int, Noun> */
    private array $nouns;

    public function __construct(Noun ...$nouns)
    {
        $byNumber = [];
        foreach ($nouns as $noun) {
            $byNumber[$noun->number] = $noun;
        }
        $this->nouns = $byNumber;
    }

    public static function for(Computer $computer): self
    {
        return match ($computer) {
            Computer::Lm => self::lm(),
            Computer::Cm => self::cm(),
        };
    }

    public function find(int $number): ?Noun
    {
        return $this->nouns[$number] ?? null;
    }

    /** @return list<int> */
    public function numbers(): array
    {
        return array_keys($this->nouns);
    }

    private static function lm(): self
    {
        $velocity = new NounComponent(Unit::FeetPerSecond, Format::Decimal1);
        $altitude = new NounComponent(Unit::Feet, Format::Whole);
        $minutesSeconds = new NounComponent(Unit::Seconds, Format::MinutesSeconds);
        $octal = new NounComponent(Unit::None, Format::Octal);

        return new self(
            // 09: alarm codes, octal only for each.
            new Noun(9, $octal, $octal, $octal),
            // 60: horizontal velocity, altitude rate, computed altitude.
            new Noun(60, $velocity, $velocity, $altitude),
            // 63: absolute value of velocity, altitude rate, computed altitude.
            new Noun(63, $velocity, $velocity, $altitude),
            // 64: time left for redesignation, altitude rate, computed altitude.
            new Noun(64, $minutesSeconds, $velocity, $altitude),
            // 68: slant range to landing site, time to go in braking phase, LR altitude minus computed altitude.
            new Noun(68, new NounComponent(Unit::NauticalMiles, Format::Decimal1), $minutesSeconds, $altitude),
        );
    }

    private static function cm(): self
    {
        $octal = new NounComponent(Unit::None, Format::Octal);
        $velocity = new NounComponent(Unit::FeetPerSecond, Format::Whole);

        return new self(
            // 09: alarm codes, octal only for each.
            new Noun(9, $octal, $octal, $octal),
            // 62: inertial velocity magnitude (VI), altitude rate change (HDOT), altitude above pad radius (H).
            new Noun(62, $velocity, $velocity, new NounComponent(Unit::NauticalMiles, Format::Decimal1)),
        );
    }
}
