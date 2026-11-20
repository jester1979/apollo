<?php

declare(strict_types=1);

namespace Apollo\Trace;

/** What a line of the event log reports. */
enum LogKind: string
{
    /** A DSKY key press and what the computer answered. */
    case Input = 'input';

    /** Something the computer did on its own, such as a program change. */
    case Agc = 'agc';

    /** A program alarm (1201, 1202, ...). */
    case Alarm = 'alarm';
}
