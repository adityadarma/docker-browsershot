<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Set default headers
header('Content-Type: application/json');

/**
 * Middleware: Check header App-Key
 */
function checkAppKey(): void
{
    $headers = getallheaders();
    $validAppKey = $_ENV['APP_KEY'] ?? '';

    if (!isset($headers['App-Key']) || $headers['App-Key'] !== $validAppKey) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid App-Key']);
        exit;
    }
}

/**
 * Get JSON input from request
 */
function getJsonInput(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
        exit;
    }
    
    return $input ?? [];
}

/**
 * Main request handler
 */
function handleRequest(): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // Middleware
    checkAppKey();

    // Route handling
    if ($method === 'POST' && $uri === '/') {
        processBrowsershotRequest();
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Endpoint not found']);
    }
}

/**
 * Process browsershot request
 */
function processBrowsershotRequest(): void
{
    try {
        $input = getJsonInput();
        
        // Initialize service
        $service = new App\Services\BrowsershotService();
        
        // Process request
        $result = $service->handleRequest($input);
        
        // Send response
        http_response_code($result['status'] === 'success' ? 200 : 400);
        echo json_encode($result);
        
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Internal server error',
            'error' => $_ENV['APP_DEBUG'] ? $e->getMessage() : null
        ]);
    }
}

// Execute the request handler
handleRequest();