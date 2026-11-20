<?php

declare(strict_types=1);

namespace Apollo\Dsky;

/**
 * Register formats of the noun table, named as the "SCALE AND DECIMAL POINT" column of
 * ASSEMBLY_AND_OPERATION_INFORMATION.agc writes them.
 *
 * @see Luminary099/ASSEMBLY_AND_OPERATION_INFORMATION.agc NORMAL NOUNS (scale and decimal point)
 */
enum Format: string
{
    case Decimal1 = 'xxxx.x';
    case Whole = 'xxxxx.';
    case Decimal2 = 'xxx.xx';
    case MinutesSeconds = 'xxBxx';
    case Hours = '00xxx.';
    case Minutes = '000xx.';
    case Seconds = '0xx.xx';
    case Octal = 'octal';
}
