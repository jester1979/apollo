<?php

declare(strict_types=1);

namespace Apollo\Guidance\Comanche;

/**
 * The major modes of the entry this port flies. P65 (the up-control skip phase) is left out.
 *
 * @see Comanche055/P61-P67.agc P63
 * @see Comanche055/P61-P67.agc P64
 * @see Comanche055/P61-P67.agc P66
 * @see Comanche055/P61-P67.agc P67
 */
enum EntryProgram: int
{
    /** Waiting for the first drag (STARTENT). */
    case P63 = 63;
    /** Entry steering with the lift up (UPCONTRL). */
    case P64 = 64;
    /** The ballistic phase when the drag has fallen away (KEP2). */
    case P66 = 66;
    /** The final phase: the bank angle comes from the range predictor (PREDICT3). */
    case P67 = 67;
}
