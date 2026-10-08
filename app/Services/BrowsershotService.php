<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\BrowsershotGenerator;
use App\Support\Config;
use App\Support\ValidationException;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Spatie\Browsershot\Exceptions\ElementNotFound;
use Spatie\Browsershot\Exceptions\FileUrlNotAllowed;
use Spatie\Browsershot\Exceptions\HtmlIsNotAllowedToContainFile;
use Spatie\Browsershot\Exceptions\RemoteConnectionException;
use Spatie\Browsershot\Exceptions\UnsuccessfulResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

class BrowsershotService
{
    private RequestValidator $validator;

    private BrowsershotGenerator $generator;

    public function __construct(
        ?RequestValidator $validator = null,
        ?BrowsershotGenerator $generator = null,
        private readonly bool $debug = false,
    ) {
        $this->validator = $validator ?? new RequestValidator(Config::bool('BROWSERSHOT_ALLOW_REMOTE_INSTANCE'));
        $this->generator = $generator ?? new BrowsershotGenerator(Config::browsershot());
    }

    /**
     * Handle API request. Always returns an array with `status` and `code`
     * (the HTTP status code to send).
     */
    public function handleRequest(array $input): array
    {
        try {
            $request = $this->validator->validate($input);

            return [
                'status' => 'success',
                'code' => 200,
                'data' => $this->generator->run($request),
            ];
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (FileUrlNotAllowed|HtmlIsNotAllowedToContainFile $e) {
            return $this->error($e->getMessage(), 422);
        } catch (ElementNotFound $e) {
            return $this->error($e->getMessage(), 422);
        } catch (UnsuccessfulResponse $e) {
            return $this->error($e->getMessage(), 502);
        } catch (RemoteConnectionException $e) {
            return $this->error($e->getMessage(), 502);
        } catch (ProcessTimedOutException $e) {
            return $this->error('Browser timeout', 504, $this->debugInfo($e));
        } catch (ProcessFailedException $e) {
            // Exit codes from bin/browser-guard.cjs.
            return match ($e->getProcess()->getExitCode()) {
                124 => $this->error('Browser timeout', 504, $this->debugInfo($e)),
                default => $this->error('Browser failed to process the request', 500, $this->debugInfo($e)),
            };
        } catch (ProcessSignaledException $e) {
            // Node was killed by a signal PHP did not send (OOM killer, operator, ...).
            return $this->error('Browser process was killed', 500, $this->debugInfo($e));
        } catch (CouldNotTakeBrowsershot $e) {
            return $this->error('Browser failed to process the request', 500, $this->debugInfo($e));
        } catch (Throwable $e) {
            return $this->error('Internal server error', 500, $this->debugInfo($e));
        }
    }

    private function error(string $message, int $code, array $extra = []): array
    {
        return array_merge(['status' => 'error', 'code' => $code, 'message' => $message], $extra);
    }

    private function debugInfo(Throwable $e): array
    {
        return $this->debug ? ['error' => $e->getMessage()] : [];
    }
}
