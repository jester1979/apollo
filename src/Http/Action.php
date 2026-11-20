<?php

declare(strict_types=1);

namespace Apollo\Http;

enum Action: string
{
    case Start = 'start';
    case Tick = 'tick';
}
