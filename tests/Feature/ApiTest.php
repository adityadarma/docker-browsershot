<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Application;
use App\Libraries\BrowsershotGenerator;
use App\Services\BrowsershotService;
use App\Services\RequestValidator;
use PHPUnit\Framework\TestCase;
use Spatie\Browsershot\Exceptions\ElementNotFound;
use Tests\Support\FakeBrowsershot;

/**
 * End-to-end through the HTTP kernel with a fake browser.
 */
final class ApiTest extends TestCase
{
    private FakeBrowsershot $fake;

    private function app(?string $appKey = null): Application
    {
        $this->fake = new FakeBrowsershot;
        $this->fake->output = ['result' => base64_encode('BINARY')];

        return new Application($appKey, fn () => new BrowsershotService(
            new RequestValidator,
            new BrowsershotGenerator([], fn () => $this->fake),
        ));
    }

    private function post(array|string $body, array $headers = [], ?string $appKey = null): array
    {
        return $this->app($appKey)->handle('POST', '/', $headers, is_string($body) ? $body : json_encode($body));
    }

    // ----------------------------------------------------------- routing

    public function test_health(): void
    {
        $this->assertSame([200, ['status' => 'success', 'message' => 'Server up and running']], $this->app()->handle('GET', '/health'));
    }

    public function test_health_ignores_query_string(): void
    {
        $this->assertSame(200, $this->app()->handle('GET', '/health?x=1')[0]);
    }

    public function test_unknown_endpoint_returns_404(): void
    {
        [$status, $body] = $this->app()->handle('GET', '/nope');

        $this->assertSame(404, $status);
        $this->assertSame('Endpoint not found', $body['message']);
    }

    public function test_wrong_method_returns_405(): void
    {
        $this->assertSame(405, $this->app()->handle('GET', '/')[0]);
        $this->assertSame(405, $this->app()->handle('POST', '/health')[0]);
    }

    // ----------------------------------------------------------- auth

    public function test_auth_disabled_when_no_app_key(): void
    {
        $this->assertSame(200, $this->post(['url' => 'https://example.com'])[0]);
    }

    public function test_missing_app_key_returns_401(): void
    {
        [$status, $body] = $this->post(['url' => 'https://example.com'], [], 'secret');

        $this->assertSame(401, $status);
        $this->assertSame('Unauthorized: Invalid App-Key', $body['message']);
        $this->assertSame([], $this->fake->commands);
    }

    public function test_wrong_app_key_returns_401(): void
    {
        $this->assertSame(401, $this->post(['url' => 'https://example.com'], ['App-Key' => 'wrong'], 'secret')[0]);
    }

    public function test_app_key_header_is_case_insensitive(): void
    {
        $this->assertSame(200, $this->post(['url' => 'https://example.com'], ['App-Key' => 'secret'], 'secret')[0]);
        $this->assertSame(200, $this->post(['url' => 'https://example.com'], ['app-key' => 'secret'], 'secret')[0]);
    }

    public function test_health_does_not_require_app_key(): void
    {
        $this->assertSame(200, $this->app('secret')->handle('GET', '/health')[0]);
    }

    // ----------------------------------------------------------- body

    public function test_invalid_json_returns_400(): void
    {
        [$status, $body] = $this->post('{invalid');

        $this->assertSame(400, $status);
        $this->assertSame('Invalid JSON input', $body['message']);
    }

    public function test_non_object_json_returns_400(): void
    {
        $this->assertSame(400, $this->post('"string"')[0]);
        $this->assertSame(400, $this->post('[1,2]')[0]);
    }

    public function test_empty_body_returns_validation_error(): void
    {
        [$status, $body] = $this->post('');

        $this->assertSame(422, $status);
        $this->assertContains('Param html atau url harus diisi', $body['errors']);
    }

    // ----------------------------------------------------------- render

    public function test_render_png(): void
    {
        [$status, $body] = $this->post(['url' => 'https://example.com']);

        $this->assertSame(200, $status);
        $this->assertSame('success', $body['status']);
        $this->assertSame([
            'size' => 6,
            'base64' => base64_encode('BINARY'),
            'mime_type' => 'image/png',
            'extension' => 'png',
        ], $body['data']);
    }

    public function test_render_pdf_from_html(): void
    {
        [$status, $body] = $this->post([
            'html' => '<h1>Invoice</h1>',
            'type' => 'pdf',
            'format' => 'A5',
            'landscape' => true,
            'margin' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
            'footerHtml' => '<span class="pageNumber"></span>',
        ]);

        $this->assertSame(200, $status);
        $this->assertSame('application/pdf', $body['data']['mime_type']);

        $command = $this->fake->lastCommand();
        $this->assertSame('pdf', $command['action']);
        $this->assertSame('A5', $command['options']['format']);
        $this->assertTrue($command['options']['landscape']);
        $this->assertSame('<span class="pageNumber"></span>', $command['options']['footerTemplate']);
    }

    public function test_body_html_action(): void
    {
        $app = $this->app();
        $this->fake->output = ['result' => '<html></html>'];

        [$status, $body] = $app->handle('POST', '/', [], json_encode(['url' => 'https://example.com', 'action' => 'bodyHtml']));

        $this->assertSame(200, $status);
        $this->assertSame(['html' => '<html></html>'], $body['data']);
    }

    public function test_console_messages_action(): void
    {
        $app = $this->app();
        $this->fake->output = ['result' => '', 'consoleMessages' => [['type' => 'log', 'message' => 'hi', 'location' => []]]];

        [, $body] = $app->handle('POST', '/', [], json_encode(['url' => 'https://example.com', 'action' => 'consoleMessages']));

        $this->assertSame([['type' => 'log', 'message' => 'hi', 'location' => []]], $body['data']['messages']);
    }

    // ----------------------------------------------------------- errors

    public function test_validation_error_returns_422(): void
    {
        [$status, $body] = $this->post(['url' => 'file:///etc/passwd']);

        $this->assertSame(422, $status);
        $this->assertSame('error', $body['status']);
    }

    public function test_element_not_found_returns_422(): void
    {
        $app = $this->app();
        $this->fake->throw = ElementNotFound::make('#missing');

        [$status] = $app->handle('POST', '/', [], json_encode(['url' => 'https://example.com', 'select' => '#missing']));

        $this->assertSame(422, $status);
    }

    public function test_unexpected_error_returns_500(): void
    {
        $app = $this->app();
        $this->fake->throw = new \RuntimeException('boom');

        [$status, $body] = $app->handle('POST', '/', [], json_encode(['url' => 'https://example.com']));

        $this->assertSame(500, $status);
        $this->assertSame('Internal server error', $body['message']);
        $this->assertArrayNotHasKey('error', $body);
    }
}
