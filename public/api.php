<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Apollo\Http\ApiController;
use Apollo\Simulation\SessionStore;

// Never print PHP errors into the response: they would leak paths.
ini_set('display_errors', '0');

session_name('apollo');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
]);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$response = (new ApiController(new SessionStore($_SESSION)))->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['CONTENT_TYPE'] ?? '',
    (string) file_get_contents('php://input', length: 4097),
);

http_response_code($response->status);
echo json_encode($response->body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
