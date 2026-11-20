<?php

declare(strict_types=1);

namespace Apollo\Simulation;

/**
 * Keeps the visitor's simulations in the PHP session, one per browser tab, so a start in one tab does
 * not take over the run in another. Session data lives on the server, so the unserialize here never
 * sees client input. The store serializes the simulation itself: the session then holds only strings,
 * and a simulation written by an older version of the code cannot make session_start() fail. Such a
 * simulation is dropped, and the tab has to start again.
 */
final class SessionStore implements SimulationStore
{
    /** A session keeps the simulations of the tabs used last; an older tab has to start again. */
    public const MAX_TABS = 4;

    private const KEY = 'simulations';

    /** @var array<string, mixed> */
    private array $session;

    /**
     * @param array<string, mixed> $session usually $_SESSION, written to by reference
     */
    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    public function simulation(string $tab): ?Simulation
    {
        $stored = $this->stored()[$tab] ?? null;
        if ($stored === null) {
            return null;
        }

        try {
            $simulation = unserialize($stored);
        } catch (\Throwable) {
            $simulation = null;
        }
        if (!$simulation instanceof Simulation) {
            $this->forget($tab);

            return null;
        }

        return $simulation;
    }

    public function save(string $tab, Simulation $simulation): void
    {
        $stored = $this->stored();
        unset($stored[$tab]); // Back in at the end: the order is from least to most recently used.
        $stored[$tab] = serialize($simulation);

        $this->session[self::KEY] = array_slice($stored, -self::MAX_TABS, null, true);
        unset($this->session['simulation']); // The single simulation of the version before tabs.
    }

    private function forget(string $tab): void
    {
        $stored = $this->stored();
        unset($stored[$tab]);
        $this->session[self::KEY] = $stored;
    }

    /** @return array<string, string> */
    private function stored(): array
    {
        $stored = $this->session[self::KEY] ?? null;
        if (!is_array($stored)) {
            return [];
        }

        $valid = [];
        foreach ($stored as $tab => $value) {
            if (is_string($value)) {
                $valid[(string) $tab] = $value;
            }
        }

        return $valid;
    }
}
