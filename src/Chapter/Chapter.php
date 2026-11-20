<?php

declare(strict_types=1);

namespace Apollo\Chapter;

use Apollo\Agc\Agc;
use Apollo\Simulation\TickInput;

interface Chapter
{
    public function id(): ChapterId;

    public function agc(): Agc;

    /** Advance $dt seconds of simulated time, in 0.1 s physics steps. */
    public function step(float $dt, TickInput $input): void;

    /**
     * Data for the vehicle view; the browser view module of the same name reads only these fields.
     *
     * @return array<string, mixed>
     */
    public function vehicleView(): array;

    /** Null while the chapter is running. */
    public function outcome(): ?Outcome;
}
