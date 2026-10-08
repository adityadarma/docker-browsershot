<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Boots public/index.php with the PHP built-in server and talks real HTTP.
 * Covers paths that do not need Chrome (routing, auth, JSON, validation).
 */
final class HttpServerTest extends TestCase
{
    private const APP_KEY = 'test-key';

    private static ?Process $server = null;

    private static int $port;

    #[BeforeClass]
    public static function startServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        self::$server = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, '-t', 'public', 'public/index.php'],
            dirname(__DIR__, 2),
            ['APP_KEY' => self::APP_KEY, 'APP_DEBUG' => 'false'],
        );
        self::$server->start();

        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', self::$port)) {
                return;
            }
            usleep(100_000);
        }

        self::fail('PHP built-in server did not start: '.self::$server->getErrorOutput());
    }

    #[AfterClass]
    public static function stopServer(): void
    {
        self::$server?->stop();
    }

    /** @return array{0: int, 1: array, 2: string} status, decoded body, content-type */
    private function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        $response = file_get_contents('http://127.0.0.1:'.self::$port.$path, false, $context);
        $responseHeaders = http_get_last_response_headers();

        preg_match('#HTTP/\S+ (\d{3})#', $responseHeaders[0], $m);
        $contentType = '';
        foreach ($responseHeaders as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, 13));
            }
        }

        return [(int) $m[1], json_decode($response, true) ?? [], $contentType];
    }

    public function test_health(): void
    {
        [$status, $body, $type] = $this->request('GET', '/health');

        $this->assertSame(200, $status);
        $this->assertSame('success', $body['status']);
        $this->assertStringStartsWith('application/json', $type);
    }

    public function test_not_found(): void
    {
        $this->assertSame(404, $this->request('GET', '/missing')[0]);
    }

    public function test_requires_app_key(): void
    {
        [$status, $body] = $this->request('POST', '/', '{"url":"https://example.com"}');

        $this->assertSame(401, $status);
        $this->assertSame('Unauthorized: Invalid App-Key', $body['message']);
    }

    public function test_invalid_json(): void
    {
        $this->assertSame(400, $this->request('POST', '/', '{bad', ['App-Key' => self::APP_KEY])[0]);
    }

    public function test_validation_error(): void
    {
        [$status, $body] = $this->request('POST', '/', json_encode(['url' => 'file:///etc/passwd', 'type' => 'gif']), ['App-Key' => self::APP_KEY]);

        $this->assertSame(422, $status);
        $this->assertCount(2, $body['errors']);
    }

    public function test_browser_failure_does_not_leak_details_when_debug_is_off(): void
    {
        // Server config points at /usr/bin/node which does not exist on most dev machines
        // (or Chrome is missing), so this returns a 5xx without internals.
        [$status, $body] = $this->request('POST', '/', json_encode(['html' => '<p>x</p>', 'timeout' => 5]), ['App-Key' => self::APP_KEY]);

        if ($status === 200) {
            $this->markTestSkipped('A working browser is configured; covered by the Browser suite.');
        }

        $this->assertGreaterThanOrEqual(500, $status);
        $this->assertSame('error', $body['status']);
        $this->assertArrayNotHasKey('error', $body);
    }
}
