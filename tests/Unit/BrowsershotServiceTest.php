<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\BrowsershotGenerator;
use App\Services\BrowsershotService;
use App\Services\RequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Spatie\Browsershot\Exceptions\ElementNotFound;
use Spatie\Browsershot\Exceptions\RemoteConnectionException;
use Spatie\Browsershot\Exceptions\UnsuccessfulResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Support\FakeBrowsershot;

final class BrowsershotServiceTest extends TestCase
{
    private FakeBrowsershot $fake;

    private function service(bool $debug = false): BrowsershotService
    {
        $this->fake = new FakeBrowsershot;
        $this->fake->output = ['result' => base64_encode('PNGDATA')];

        return new BrowsershotService(
            new RequestValidator,
            new BrowsershotGenerator([], fn () => $this->fake),
            $debug,
        );
    }

    public function test_success_response(): void
    {
        $result = $this->service()->handleRequest(['url' => 'https://example.com']);

        $this->assertSame('success', $result['status']);
        $this->assertSame(200, $result['code']);
        $this->assertSame('image/png', $result['data']['mime_type']);
        $this->assertSame(base64_encode('PNGDATA'), $result['data']['base64']);
        $this->assertSame(7, $result['data']['size']);
    }

    public function test_validation_error_returns_422_with_error_list(): void
    {
        $result = $this->service()->handleRequest(['type' => 'gif']);

        $this->assertSame('error', $result['status']);
        $this->assertSame(422, $result['code']);
        $this->assertContains('Either html or url is required', $result['errors']);
        $this->assertStringContainsString('type must be one of', $result['message']);
        $this->assertSame([], $this->fake->commands, 'browser must not be called on invalid input');
    }

    public function test_forbidden_html_returns_422(): void
    {
        $result = $this->service()->handleRequest(['html' => '<img src="file:///etc/passwd">']);

        $this->assertSame(422, $result['code']);
        $this->assertStringContainsString('file://', $result['message']);
    }

    public static function browserFailures(): array
    {
        $process = new Process(['false']);

        return [
            'element not found' => [ElementNotFound::make('#missing'), 422, 'did not match any elements'],
            'unsuccessful response' => [UnsuccessfulResponse::make('https://example.com', 404), 502, 'responds with code 404'],
            'remote connection' => [RemoteConnectionException::make('refused'), 502, 'Failed to connect to remote browser'],
            'timeout' => [new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL), 504, 'Browser timeout'],
            'empty output' => [CouldNotTakeBrowsershot::chromeOutputEmpty('x.png', ''), 500, 'Browser failed to process the request'],
            'unexpected' => [new \LogicException('secret detail'), 500, 'Internal server error'],
        ];
    }

    #[DataProvider('browserFailures')]
    public function test_browser_failures_are_mapped(\Throwable $exception, int $code, string $message): void
    {
        $service = $this->service();
        $this->fake->throw = $exception;

        $result = $service->handleRequest(['url' => 'https://example.com']);

        $this->assertSame('error', $result['status']);
        $this->assertSame($code, $result['code']);
        $this->assertStringContainsString($message, $result['message']);
    }

    public function test_process_failed_exception_is_mapped_to_500(): void
    {
        $process = new Process(['php', '-r', 'fwrite(STDERR, "chrome crashed"); exit(1);']);
        $process->run();

        $service = $this->service();
        $this->fake->throw = new ProcessFailedException($process);

        $result = $service->handleRequest(['url' => 'https://example.com']);

        $this->assertSame(500, $result['code']);
        $this->assertSame('Browser failed to process the request', $result['message']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_guard_deadline_exit_code_is_mapped_to_504(): void
    {
        // bin/browser-guard.cjs exits 124 when the render deadline is hit.
        $process = new Process(['php', '-r', 'exit(124);']);
        $process->run();

        $service = $this->service();
        $this->fake->throw = new ProcessFailedException($process);

        $result = $service->handleRequest(['url' => 'https://example.com']);

        $this->assertSame(504, $result['code']);
        $this->assertSame('Browser timeout', $result['message']);
    }

    public function test_killed_browser_process_is_mapped_to_500(): void
    {
        // A child that SIGKILLs itself, like the OOM killer would. Symfony throws
        // ProcessSignaledException from run() itself; reuse that real exception.
        try {
            (new Process(['sh', '-c', 'kill -9 $$']))->run();
            $this->fail('Expected ProcessSignaledException');
        } catch (ProcessSignaledException $signaled) {
        }

        $service = $this->service();
        $this->fake->throw = $signaled;

        $result = $service->handleRequest(['url' => 'https://example.com']);

        $this->assertSame(500, $result['code']);
        $this->assertSame('Browser process was killed', $result['message']);
    }

    public function test_internal_details_are_hidden_without_debug(): void
    {
        $service = $this->service(debug: false);
        $this->fake->throw = new \LogicException('secret detail');

        $this->assertArrayNotHasKey('error', $service->handleRequest(['url' => 'https://example.com']));
    }

    public function test_internal_details_are_exposed_with_debug(): void
    {
        $service = $this->service(debug: true);
        $this->fake->throw = new \LogicException('secret detail');

        $this->assertSame('secret detail', $service->handleRequest(['url' => 'https://example.com'])['error']);
    }

    public function test_does_not_write_files_to_disk(): void
    {
        $before = glob(sys_get_temp_dir().'/*') ?: [];

        $this->service()->handleRequest(['html' => '<p>x</p>', 'type' => 'pdf']);

        $after = glob(sys_get_temp_dir().'/*') ?: [];
        $this->assertSame([], array_values(array_diff($after, $before)));
    }
}
