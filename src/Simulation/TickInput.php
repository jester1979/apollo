<?php

declare(strict_types=1);

namespace Apollo\Simulation;

use Apollo\Dsky\Key;

/** Validated input of one tick. Only TickRequest builds it from outside data. */
final readonly class TickInput
{
    /**
     * @param list<Key> $keys DSKY keys pressed since the previous tick
     * @param int $rod rate-of-descent clicks since the previous tick, -10..10
     * @param int $speed simulated seconds per tick are 0.1 times this: 1, 2, 5 or 10
     */
    public function __construct(
        public array $keys = [],
        public int $rod = 0,
        public int $speed = 1,
    ) {
    }
}
