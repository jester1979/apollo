<?php

declare(strict_types=1);

namespace Apollo\Trace;

/**
 * Original AGC routines this port executes. Line numbers are for commit
 * e7576d50f7c279a707cfaa71be2020592dbf43a8 of chrislgarry/Apollo-11.
 *
 * Each slice adds the cases its routines need.
 */
enum Routine
{
    case P63Lm;        // Luminary099/THE_LUNAR_LANDING.agc:40
    case QuadGuid;     // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:546
    case RootPsrs;     // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:1272
    case StartP64;     // Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc:260
    case BurnBaby;     // Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc:159

    public const COMMIT = 'e7576d50f7c279a707cfaa71be2020592dbf43a8';

    /** The label exactly as it appears in the original source. */
    public function label(): string
    {
        return match ($this) {
            self::P63Lm => 'P63LM',
            self::QuadGuid => 'QUADGUID',
            self::RootPsrs => 'ROOTPSRS',
            self::StartP64 => 'STARTP64',
            self::BurnBaby => 'BURNBABY',
        };
    }

    /** Path of the original source file, relative to the repository root. */
    public function file(): string
    {
        return match ($this) {
            self::P63Lm => 'Luminary099/THE_LUNAR_LANDING.agc',
            self::QuadGuid, self::RootPsrs, self::StartP64 => 'Luminary099/LUNAR_LANDING_GUIDANCE_EQUATIONS.agc',
            self::BurnBaby => 'Luminary099/BURN_BABY_BURN--MASTER_IGNITION_ROUTINE.agc',
        };
    }

    /** Line of the label in the original file. */
    public function line(): int
    {
        return match ($this) {
            self::P63Lm => 40,
            self::QuadGuid => 546,
            self::RootPsrs => 1272,
            self::StartP64 => 260,
            self::BurnBaby => 159,
        };
    }

    public function url(): string
    {
        return sprintf(
            'https://github.com/chrislgarry/Apollo-11/blob/%s/%s#L%d',
            self::COMMIT,
            $this->file(),
            $this->line(),
        );
    }

    /** @return array{label: string, file: string, line: int, url: string} */
    public function toArray(): array
    {
        return [
            'label' => $this->label(),
            'file' => $this->file(),
            'line' => $this->line(),
            'url' => $this->url(),
        ];
    }
}
