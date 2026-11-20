<?php

declare(strict_types=1);

namespace Apollo\Dsky;

/**
 * One register of a noun: the unit its value is in and the format it is shown in.
 *
 * @see Luminary099/ASSEMBLY_AND_OPERATION_INFORMATION.agc NORMAL NOUNS (components)
 */
final readonly class NounComponent
{
    public function __construct(
        public Unit $unit,
        public Format $format,
    ) {
    }
}
