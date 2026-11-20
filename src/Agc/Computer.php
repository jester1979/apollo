<?php

declare(strict_types=1);

namespace Apollo\Agc;

/**
 * The two Apollo Guidance Computers: one in the command module, one in the lunar module. Each runs its own
 * program, and the verb and noun lists differ per computer; this enum only tells them apart.
 *
 * @see Luminary099/ASSEMBLY_AND_OPERATION_INFORMATION.agc VERB LIST FOR LUMINARY
 * @see Comanche055/ASSEMBLY_AND_OPERATION_INFORMATION.agc VERB LIST FOR CSM
 */
enum Computer: string
{
    case Cm = 'CM';
    case Lm = 'LM';
}
