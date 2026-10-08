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

    /** @var list<string> Shell command lines, captured at call time like the real callBrowser(). */
    public array $shellCommands = [];

    /** Raw output returned by browser.cjs (decoded JSON). */
    public array $output = ['result' => ''];

    /** Exception to throw instead of returning output. */
    public ?\Throwable $throw = null;

    protected function callBrowser(array $command): string
    {
        $this->commands[] = $command;

        // Build the shell line now: it may create temp files (writeOptionsToFile)
        // inside the per-request work dir, which is removed after the render.
        $this->shellCommands[] = (string) $this->getFullCommand($command);
        $this->cleanupTemporaryOptionsFile();

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

    /**
     * Shell command line Spatie would execute. Returns the one captured during
     * the last browser call; falls back to building it for commands that were
     * never executed.
     */
    public function fullCommand(array $command): string
    {
        $index = array_search($command, $this->commands, true);

        if ($index !== false && isset($this->shellCommands[$index])) {
            return $this->shellCommands[$index];
        }

        return (string) $this->getFullCommand($command);
    }
}
