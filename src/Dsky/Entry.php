<?php

declare(strict_types=1);

namespace Apollo\Dsky;

/**
 * The register the digit keys are going into while the user keys something in: the two verb digits,
 * the two noun digits, or (after V37 ENTR) the two digits of the new program, shown in the noun cells.
 *
 * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc MMCHANG
 */
enum Entry: string
{
    case None = 'none';
    case Verb = 'verb';
    case Noun = 'noun';
    case Program = 'program';
}
