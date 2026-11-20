<?php

declare(strict_types=1);

namespace Apollo\Simulation;

/**
 * Opens the PHP session only when a request needs it, so a request that is refused, or a tick from a
 * client without a session cookie, does not leave a session file behind. A read without a cookie has
 * nothing to find; a save always opens the session.
 */
final class LazySessionStore implements SimulationStore
{
    private ?SimulationStore $store = null;

    /**
     * @param bool $hasSessionCookie whether the request came with a session cookie
     * @param \Closure(): SimulationStore $open starts the session and returns the store on top of it
     */
    public function __construct(private readonly bool $hasSessionCookie, private readonly \Closure $open)
    {
    }

    public function simulation(string $tab): ?Simulation
    {
        if ($this->store === null && !$this->hasSessionCookie) {
            return null;
        }

        return $this->opened()->simulation($tab);
    }

    public function save(string $tab, Simulation $simulation): void
    {
        $this->opened()->save($tab, $simulation);
    }

    private function opened(): SimulationStore
    {
        return $this->store ??= ($this->open)();
    }
}
