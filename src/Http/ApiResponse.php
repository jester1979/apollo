<?php

declare(strict_types=1);

namespace Apollo\Http;

final readonly class ApiResponse
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(public int $status, public array $body)
    {
    }
}
