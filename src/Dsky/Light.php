<?php

declare(strict_types=1);

namespace Apollo\Dsky;

/**
 * The lights of the DSKY panel. The value is the key in the tick response.
 *
 * @see Luminary099/PINBALL_GAME_BUTTONS_AND_LIGHTS.agc RELDSPON
 */
enum Light: string
{
    case CompActy = 'compActy';
    case Prog = 'prog';
    case OprErr = 'oprErr';
    case KeyRel = 'keyRel';
    case Restart = 'restart';
    case UplinkActy = 'uplinkActy';
    case NoAtt = 'noAtt';
    case Stby = 'stby';
    case Temp = 'temp';
    case GimbalLock = 'gimbalLock';
    case Tracker = 'tracker';
    case Alt = 'alt';
    case Vel = 'vel';
}
