<?php

declare(strict_types=1);

namespace Apollo\Chapter\Ascent;

/** Where the ascent is: the target display, the countdown to TIG, the burn, or finished. */
enum AscentPhase
{
    /** V06N76 flashes: the crew is shown the injection target. The clock waits for PRO. */
    case Targets;
    /** V06N74 counts down to TIG; from TIG-5 s V99N74 flashes until PRO enables the engine. */
    case Countdown;
    /** The APS burns, steered by ASCENT. */
    case Burn;
    /** The burn is over (V16N63) or the chapter was stopped or missed. */
    case Done;
}
