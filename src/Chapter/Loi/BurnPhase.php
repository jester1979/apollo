<?php

declare(strict_types=1);

namespace Apollo\Chapter\Loi;

/** Where a P40 chapter is: the checklist, the coast to TIG, the burn, or finished. */
enum BurnPhase
{
    /** V50N25 flashes: the crew is asked to switch to CMC-AUTO. The clock and the vehicle wait for PRO. */
    case Checklist;
    /** V06N40 counts down to TIG; from TIG-5 s V99N40 flashes until PRO enables the engine. */
    case Countdown;
    /** The SPS burns, steered by UPDATEVG and STEERING. */
    case Burn;
    /** The burn is over (V16N40) or the chapter was stopped. */
    case Done;
}
