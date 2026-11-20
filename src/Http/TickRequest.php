<?php

declare(strict_types=1);

namespace Apollo\Http;

use Apollo\Chapter\ChapterId;
use Apollo\Dsky\Key;
use Apollo\Simulation\TickInput;

/**
 * The only place where input is checked. The rules run in this order; the first one that fails
 * decides the status. Messages are fixed text: no paths, traces or received values.
 */
final readonly class TickRequest
{
    public const MAX_BODY_BYTES = 4096;
    private const MAX_KEYS = 32;
    private const MAX_ROD = 10;
    private const SPEEDS = [1, 2, 5, 10];

    private function __construct(
        public Action $action,
        public ?ChapterId $chapter,
        public bool $scenario,
        public ?TickInput $input,
    ) {
    }

    /**
     * @throws InvalidRequest
     */
    public static function fromHttp(string $method, string $contentType, string $body, bool $simulationStarted): self
    {
        // 1. Method
        if ($method !== 'POST') {
            throw new InvalidRequest(405, 'Method not allowed');
        }
        // 2. Content type
        if (!str_starts_with(strtolower($contentType), 'application/json')) {
            throw new InvalidRequest(415, 'Content-Type must be application/json');
        }
        // 3. Body size
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidRequest(413, 'Request body too large');
        }
        // 4. JSON object
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidRequest(400, 'Body must be a JSON object');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidRequest(400, 'Body must be a JSON object');
        }
        // 5. Action
        $action = is_string($data['action'] ?? null) ? Action::tryFrom($data['action']) : null;
        if ($action === null) {
            throw new InvalidRequest(400, 'Unknown action');
        }

        if ($action === Action::Start) {
            // 6. Chapter and scenario
            $chapter = is_string($data['chapter'] ?? null) ? ChapterId::tryFrom($data['chapter']) : null;
            if ($chapter === null) {
                throw new InvalidRequest(400, 'Invalid chapter');
            }
            $scenario = $data['scenario'] ?? null;
            if (!is_bool($scenario) || ($scenario && $chapter !== ChapterId::Landing)) {
                throw new InvalidRequest(400, 'Invalid scenario');
            }

            return new self($action, $chapter, $scenario, null);
        }

        // 7. Keys
        $rawKeys = $data['keys'] ?? null;
        if (!is_array($rawKeys) || !array_is_list($rawKeys) || count($rawKeys) > self::MAX_KEYS) {
            throw new InvalidRequest(400, 'Invalid keys');
        }
        $keys = [];
        foreach ($rawKeys as $rawKey) {
            $key = is_string($rawKey) ? Key::tryFrom($rawKey) : null;
            if ($key === null) {
                throw new InvalidRequest(400, 'Invalid keys');
            }
            $keys[] = $key;
        }
        // 8. ROD clicks
        $rod = $data['rod'] ?? null;
        if (!is_int($rod) || abs($rod) > self::MAX_ROD) {
            throw new InvalidRequest(400, 'Invalid rod');
        }
        // 9. Speed
        $speed = $data['speed'] ?? null;
        if (!is_int($speed) || !in_array($speed, self::SPEEDS, true)) {
            throw new InvalidRequest(400, 'Invalid speed');
        }
        // 10. A started simulation
        if (!$simulationStarted) {
            throw new InvalidRequest(409, 'No simulation started');
        }

        return new self($action, null, false, new TickInput($keys, $rod, $speed));
    }
}
