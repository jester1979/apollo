<?php

declare(strict_types=1);

namespace Apollo\Simulation;

/** Where the simulation of one browser tab is kept between two requests. */
interface SimulationStore
{
    /** The simulation of this tab, or null when the tab has none (or it can no longer be read). */
    public function simulation(string $tab): ?Simulation;

    public function save(string $tab, Simulation $simulation): void;
}
