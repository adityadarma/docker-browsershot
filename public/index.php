<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\Http\Application;
use App\Services\BrowsershotService;
use App\Support\Config;

$app = new Application(
    Config::env('APP_KEY'),
    static fn () => new BrowsershotService(debug: Config::bool('APP_DEBUG')),
);

[$status, $payload] = $app->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
    function_exists('getallheaders') ? getallheaders() : [],
    (string) file_get_contents('php://input'),
);

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($payload, JSON_UNESCAPED_SLASHES);
