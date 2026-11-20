<?php

declare(strict_types=1);

namespace Apollo\Http;

/**
 * Counts requests per client in fixed windows of one minute, in one small file per client and bucket.
 * The file name is a hash, so what the client sent never reaches a path. When the directory cannot be
 * used the limiter lets the request through: a broken counter must not take the site down.
 */
final class RateLimiter
{
    public const WINDOW_SECONDS = 60;

    /** One call in this many also removes the counters of windows that have passed. */
    private const SWEEP_ODDS = 100;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): int)|null $clock the time in seconds; defaults to time()
     */
    public function __construct(private readonly string $directory, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    /** Counts one request and says whether the client is still within $limit for this window. */
    public function allow(string $bucket, string $client, int $limit): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return true;
        }
        $handle = @fopen($this->directory . '/' . hash('sha256', $bucket . "\0" . $client) . '.rate', 'c+');
        if ($handle === false) {
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }
            $window = intdiv(($this->clock)(), self::WINDOW_SECONDS);
            $parts = explode(' ', (string) stream_get_contents($handle));
            $count = count($parts) === 2 && (int) $parts[0] === $window ? (int) $parts[1] : 0;
            $count++;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $window . ' ' . $count);
        } finally {
            fclose($handle); // Also releases the lock.
        }

        if (random_int(1, self::SWEEP_ODDS) === 1) {
            $this->sweep();
        }

        return $count <= $limit;
    }

    /** Removes the counters that were last written before the previous window. */
    public function sweep(): void
    {
        $limit = ($this->clock)() - 2 * self::WINDOW_SECONDS;
        foreach (glob($this->directory . '/*.rate') ?: [] as $file) {
            if ((int) @filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
