<?php

declare(strict_types=1);

namespace Apollo\Physics;

/** The three stages of the Saturn V, in the order they burn. */
enum SaturnStage: int
{
    case SIC = 1;
    case SII = 2;
    case SIVB = 3;

    public function label(): string
    {
        return match ($this) {
            self::SIC => 'S-IC',
            self::SII => 'S-II',
            self::SIVB => 'S-IVB',
        };
    }
}
