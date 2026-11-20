<?php

declare(strict_types=1);

namespace Apollo\Agc;

/** The two Apollo Guidance Computers: one in the command module, one in the lunar module. */
enum Computer: string
{
    case Cm = 'CM';
    case Lm = 'LM';
}
