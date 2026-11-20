<?php

declare(strict_types=1);

namespace Apollo\Dsky;

/** The DSKY keys a tick request may carry. */
enum Key: string
{
    case Verb = 'VERB';
    case Noun = 'NOUN';
    case Enter = 'ENTR';
    case Proceed = 'PRO';
    case Clear = 'CLR';
    case Reset = 'RSET';
    case KeyRelease = 'KEY_REL';
    case Plus = 'PLUS';
    case Minus = 'MINUS';
    case Zero = '0';
    case One = '1';
    case Two = '2';
    case Three = '3';
    case Four = '4';
    case Five = '5';
    case Six = '6';
    case Seven = '7';
    case Eight = '8';
    case Nine = '9';
}
