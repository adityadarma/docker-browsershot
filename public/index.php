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
        // Ambil input JSON
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Simulasikan response
        // echo json_encode([
        //     'message' => 'Data diterima',
        //     'data' => $input['url'],
        // ]);
        return \Spatie\Browsershot\Browsershot::url('https://www.google.com')
            ->setOption('executablePath', '/usr/bin/chromium-browser')
            ->setIncludePath('$PATH:/usr/local/bin')
            ->noSandbox()
            // ->fullPage()
            ->savePdf('test.pdf');
    } catch (\Throwable $th) {
        http_response_code(404);
        echo json_encode(['error' => $th->getMessage()]);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
}
