<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Apollo\Http\ApiController;
use Apollo\Http\RateLimiter;
use Apollo\Simulation\LazySessionStore;
use Apollo\Simulation\SessionStore;

// Never print PHP errors into the response: they would leak paths.
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Everything that can fail sits inside the try, so a failure outside the controller (the session, the
// encoding of the response) gives the same fixed JSON body as one inside it.
try {
    session_name('apollo');

    // The session starts only when the controller reads or saves a simulation: a refused request leaves
    // no session file behind.
    $store = new LazySessionStore(
        isset($_COOKIE[session_name()]),
        static function (): SessionStore {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true,
            ]);

            return new SessionStore($_SESSION);
        },
    );
    // The counters live next to the session files, in a folder of this account where that is set up.
    $limiter = new RateLimiter((session_save_path() ?: sys_get_temp_dir()) . '/apollo-rate');
    $client = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';

    $response = (new ApiController($store, null, $limiter, $client))->handle(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_SERVER['CONTENT_TYPE'] ?? '',
        (string) file_get_contents('php://input', length: 4097),
    );

    // A session that was opened for a cookie the server does not know holds nothing: remove its file.
    if (session_status() === PHP_SESSION_ACTIVE && $_SESSION === []) {
        session_destroy();
    }

    $status = $response->status;
    $body = json_encode($response->body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
} catch (\Throwable $e) {
    error_log(sprintf('%s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $status = 500;
    $body = '{"error":"Internal error"}';
}

if ($status === 429) {
    header('Retry-After: ' . RateLimiter::WINDOW_SECONDS);
}
http_response_code($status);
echo $body;
