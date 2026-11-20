<?php

declare(strict_types=1);

namespace Apollo\Chapter;

enum Outcome: string
{
    case Success = 'success';
    case Crashed = 'crashed';
    case OutOfFuel = 'out_of_fuel';
    case Missed = 'missed';
}
