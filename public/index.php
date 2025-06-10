<?php

declare(strict_types=1);

require __DIR__ . './../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . './..');
$dotenv->load();

// Middleware: Check header App-Key
function checkAppKeyMiddleware()
{
    $headers = getallheaders();
    $validAppKey = $_ENV['APP_KEY'] ?? '';

    if (!isset($headers['App-Key']) || $headers['App-Key'] !== $validAppKey) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized: Invalid App-Key']);
        exit;
    }
}

// Router
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Middleware
checkAppKeyMiddleware();

// Check route and method
if ($method === 'POST' && $uri === '/') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['url']) && empty($input['html'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Param url or html must be insert']);
            exit;
        }

        if ($input['url']) {
            $browsershot = \Spatie\Browsershot\Browsershot::url($input['url']);
        } elseif($input['html']) {
            $browsershot = \Spatie\Browsershot\Browsershot::html($input['html']);
        }
        $data = $browsershot->setOption('executablePath', '/usr/bin/chromium-browser')
            ->noSandbox()
            ->fullPage()
            ->base64Screenshot();
        echo json_encode([
            'status' => 'success',
            'data' => $data,
        ]);
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(['error' => $th->getMessage()]);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
}
