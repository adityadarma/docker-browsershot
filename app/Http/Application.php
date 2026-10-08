<?php

declare(strict_types=1);

namespace App\Http;

use App\Services\BrowsershotService;
use Closure;

/**
 * Framework-less HTTP kernel. Pure function of the request, so it can be
 * exercised directly from feature tests without a web server.
 */
final class Application
{
    private Closure $serviceFactory;

    /**
     * @param  string|null  $appKey  Required `App-Key` header value; null/empty disables auth
     * @param  (callable(): BrowsershotService)|null  $serviceFactory
     */
    public function __construct(
        private readonly ?string $appKey = null,
        ?callable $serviceFactory = null,
    ) {
        $this->serviceFactory = $serviceFactory !== null
            ? Closure::fromCallable($serviceFactory)
            : static fn (): BrowsershotService => new BrowsershotService;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: array}
     */
    public function handle(string $method, string $uri, array $headers = [], string $body = ''): array
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $method = strtoupper($method);

        if ($path === '/health') {
            return $method === 'GET'
                ? [200, ['status' => 'success', 'message' => 'Server up and running']]
                : $this->methodNotAllowed();
        }

        if ($path === '/') {
            if ($method !== 'POST') {
                return $this->methodNotAllowed();
            }

            if (! $this->authorized($headers)) {
                return [401, ['status' => 'error', 'message' => 'Unauthorized: Invalid App-Key']];
            }

            return $this->render($body);
        }

        return [404, ['status' => 'error', 'message' => 'Endpoint not found']];
    }

    private function render(string $body): array
    {
        $input = json_decode($body === '' ? '{}' : $body, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($input) || ($input !== [] && array_is_list($input))) {
            return [400, ['status' => 'error', 'message' => 'Invalid JSON input']];
        }

        $result = ($this->serviceFactory)()->handleRequest($input);
        $code = (int) ($result['code'] ?? 500);

        return [$code >= 200 && $code < 600 ? $code : 500, $result];
    }

    private function authorized(array $headers): bool
    {
        if ($this->appKey === null || $this->appKey === '') {
            return true;
        }

        // Header names are case-insensitive (HTTP/2 lowercases them).
        $headers = array_change_key_case($headers, CASE_LOWER);
        $given = $headers['app-key'] ?? '';

        return is_string($given) && hash_equals($this->appKey, $given);
    }

    private function methodNotAllowed(): array
    {
        return [405, ['status' => 'error', 'message' => 'Method not allowed']];
    }
}
