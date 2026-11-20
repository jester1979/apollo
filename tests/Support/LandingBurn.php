<?php

declare(strict_types=1);

namespace Apollo\Tests\Support;

use Apollo\Chapter\Landing\LandingChapter;
use Apollo\Dsky\Key;
use Apollo\Simulation\TickInput;

/** Test helper: the crew's answer to the flashing V99N63 of the landing. */
final class LandingBurn
{
    /** Runs the chapter up to the moment V99N63 starts to flash (TIG-5 s) and presses PRO; the engine ignites on the next step. */
    public static function proceed(LandingChapter $chapter): void
    {
        $chapter->step(5.0, new TickInput());
        $chapter->agc()->dsky->press(Key::Proceed, $chapter->allowedPrograms());
    }
}
