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
        $randomString = bin2hex(random_bytes(16));
        $input = json_decode(file_get_contents('php://input'), true);
        $filetype = $input['type'] ?? 'png';
        if (empty($input['url']) && empty($input['html'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Param url or html must be insert']);
            exit;
        }

        if (! empty($input['url'])) {
            $browsershot = \Spatie\Browsershot\Browsershot::url($input['url']);
        } elseif(! empty($input['html'])) {
            $browsershot = \Spatie\Browsershot\Browsershot::html($input['html']);
        }
        $browsershot->setOption('executablePath', '/usr/bin/chromium-browser');
        $browsershot->noSandbox();
        $browsershot->fullPage();
        $browsershot->hideBrowserHeaderAndFooter();

        if (! empty($input['width']) && ! empty($input['height'])) {
            if ($input['type'] === 'pdf') {
                $browsershot->paperSize($input['width'], $input['height']);
            } else {
                $browsershot->windowSize($input['width'], $input['height']);
            }
        }

        if (! empty($input['format'])) {
            $browsershot->format($input['format']);
        }
        
        $filename = "tmp/{$randomString}.{$filetype}";
        $browsershot->save($filename);

        $image_data = file_get_contents($filename);
        $base64string = base64_encode($image_data);
        unlink($filename);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => $base64string,
        ]);
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode(['status' => 'failed', 'message' => $th->getMessage()]);
    }
} else {
    http_response_code(404);
    echo json_encode(['status' => 'failed', 'message' => 'Not Found']);
}
