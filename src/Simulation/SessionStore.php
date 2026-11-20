<?php

declare(strict_types=1);

namespace Apollo\Simulation;

/**
 * Keeps the visitor's simulation in the PHP session. Session data lives on the server, so PHP's
 * unserialize of it never sees client input. Swapping the storage only touches this class.
 */
final class SessionStore
{
    /** @var array<string, mixed> */
    private array $session;

    /**
     * @param array<string, mixed> $session usually $_SESSION, written to by reference
     */
    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    public function simulation(): ?Simulation
    {
        $simulation = $this->session['simulation'] ?? null;

        return $simulation instanceof Simulation ? $simulation : null;
    }

    public function save(Simulation $simulation): void
    {
        $this->session['simulation'] = $simulation;
    }
}
