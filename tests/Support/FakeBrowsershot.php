<?php

declare(strict_types=1);

namespace Tests\Support;

use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\ChromiumResult;

/**
 * Browsershot that never spawns Node/Chrome. Records every command it would
 * have sent to bin/browser.cjs and answers with a configurable payload.
 */
class FakeBrowsershot extends Browsershot
{
    /** @var list<array> */
    public array $commands = [];

    /** Raw output returned by browser.cjs (decoded JSON). */
    public array $output = ['result' => ''];

    /** Exception to throw instead of returning output. */
    public ?\Throwable $throw = null;

    protected function callBrowser(array $command): string
    {
        $this->commands[] = $command;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->chromiumResult = new ChromiumResult($this->output);

        return $this->chromiumResult->getResult();
    }

    public function lastCommand(): array
    {
        return $this->commands[array_key_last($this->commands)];
    }

    /** Expose Spatie's shell command builder for assertions. */
    public function fullCommand(array $command): string
    {
        return $this->getFullCommand($command);
    }
}
