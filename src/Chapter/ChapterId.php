<?php

declare(strict_types=1);

namespace Apollo\Chapter;

use Apollo\Agc\Computer;

enum ChapterId: string
{
    case Launch = 'launch';
    case LunarOrbitInsertion = 'loi';
    case Landing = 'landing';
    case Ascent = 'ascent';
    case TransEarthInjection = 'tei';
    case Entry = 'entry';

    public function computer(): Computer
    {
        return match ($this) {
            self::Launch, self::LunarOrbitInsertion, self::TransEarthInjection, self::Entry => Computer::Cm,
            self::Landing, self::Ascent => Computer::Lm,
        };
    }

    /** The next chapter in mission order, or null after the last one. */
    public function next(): ?self
    {
        return match ($this) {
            self::Launch => self::LunarOrbitInsertion,
            self::LunarOrbitInsertion => self::Landing,
            self::Landing => self::Ascent,
            self::Ascent => self::TransEarthInjection,
            self::TransEarthInjection => self::Entry,
            self::Entry => null,
        };
    }
}
