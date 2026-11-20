<?php

declare(strict_types=1);

namespace Apollo\Dsky;

/**
 * Formats a register value the way the DSKY shows it: the sign plus five digits (the sign is left
 * out for the octal and minutes-seconds formats). A value beyond the range shows the largest
 * digits and raises no alarm. A value that is not a number gives a blank register.
 *
 * @see Luminary099/ASSEMBLY_AND_OPERATION_INFORMATION.agc NORMAL NOUNS (scale and decimal point)
 * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc DSPDECWD
 */
final class DisplayFormatter
{
    private const MAX_DIGITS = 99999;
    private const MAX_OCTAL = 0o77777;

    /** Formats a value that is in SI units for the given component of a noun. */
    public static function component(float $siValue, NounComponent $component): string
    {
        return self::format($component->unit->fromSi($siValue), $component->format);
    }

    /** Formats a value that is already in the unit of the format (seconds for the time formats). */
    public static function format(float $value, Format $format): string
    {
        if (is_nan($value)) {
            return '';
        }

        return match ($format) {
            Format::Decimal1 => self::signed($value * 10.0),
            Format::Whole => self::signed($value),
            Format::Decimal2 => self::signed($value * 100.0),
            Format::MinutesSeconds => self::minutesSeconds($value),
            Format::Hours => self::signed(min(floor(abs($value) / 3600.0), 999.0) * self::sign($value)),
            Format::Minutes => self::signed(floor(fmod(abs($value), 3600.0) / 60.0) * self::sign($value)),
            Format::Seconds => self::signed(fmod(abs($value), 60.0) * 100.0 * self::sign($value)),
            Format::Octal => self::octal($value),
        };
    }

    private static function signed(float $scaled): string
    {
        $digits = (int) min(abs(round($scaled)), self::MAX_DIGITS);

        return sprintf('%s%05d', $scaled < 0.0 && $digits > 0 ? '-' : '+', $digits);
    }

    private static function sign(float $value): float
    {
        return $value < 0.0 ? -1.0 : 1.0;
    }

    private static function minutesSeconds(float $seconds): string
    {
        $total = (int) min(abs(round($seconds)), 99 * 60 + 59);

        return sprintf('%s%02d %02d', $seconds < 0.0 && $total > 0 ? '-' : '', intdiv($total, 60), $total % 60);
    }

    private static function octal(float $value): string
    {
        return sprintf('%05o', (int) max(0.0, min(round($value), (float) self::MAX_OCTAL)));
    }
}
