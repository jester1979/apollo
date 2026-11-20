<?php

declare(strict_types=1);

namespace Apollo\Http;

/** A request that broke a TickRequest rule. The message is fixed text, safe to send to the client. */
final class InvalidRequest extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
