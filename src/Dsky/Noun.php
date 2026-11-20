<?php

declare(strict_types=1);

namespace Apollo\Dsky;

/**
 * A noun: what the registers R1 to R3 show. One to three components.
 *
 * @see Luminary099/ASSEMBLY_AND_OPERATION_INFORMATION.agc NORMAL NOUNS
 */
final readonly class Noun
{
    /** @var list<NounComponent> */
    public array $components;

    public function __construct(
        public int $number,
        NounComponent ...$components,
    ) {
        if ($components === [] || count($components) > 3) {
            throw new \InvalidArgumentException('A noun has one to three components');
        }
        $this->components = array_values($components);
    }
}
