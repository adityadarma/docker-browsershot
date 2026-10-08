<?php

declare(strict_types=1);

namespace App\Libraries;

use Symfony\Component\Process\Process;

/**
 * Cleans up after a render, no matter how it ended.
 *
 * Every Chromium process of a render has its private work dir in its command
 * line (`--user-data-dir=<workDir>/puppeteer_dev_chrome_profile-*`), so the
 * work dir doubles as a reliable handle on the whole process tree, even after
 * node was SIGKILLed and Chromium was re-parented.
 */
final class RenderCleanup
{
    /** How long to wait for killed processes to disappear. */
    public const KILL_WAIT_MS = 3000;

    /** How long to keep retrying the directory removal. */
    public const REMOVE_WAIT_MS = 2000;

    private const POLL_US = 50_000;

    public static function run(string $workDir): void
    {
        self::killProcessesUsing($workDir);
        self::removeDirectory($workDir);
    }

    /**
     * SIGKILL every process whose command line references $workDir and wait
     * until they are gone (or only zombies remain, which hold no memory and
     * cannot write files).
     *
     * @return list<int> PIDs that were killed
     */
    public static function killProcessesUsing(string $workDir): array
    {
        $killed = [];
        $deadline = microtime(true) + self::KILL_WAIT_MS / 1000;

        do {
            $pids = self::findPids($workDir);

            foreach ($pids as $pid) {
                self::kill($pid);
                $killed[$pid] = $pid;
            }

            if ($pids === []) {
                break;
            }

            usleep(self::POLL_US);
        } while (microtime(true) < $deadline);

        return array_values($killed);
    }

    /**
     * Remove $path recursively, retrying while something is still creating
     * files in it.
     */
    public static function removeDirectory(string $path): bool
    {
        $deadline = microtime(true) + self::REMOVE_WAIT_MS / 1000;

        do {
            self::removeOnce($path);

            if (! file_exists($path)) {
                return true;
            }

            usleep(self::POLL_US);
        } while (microtime(true) < $deadline);

        return ! file_exists($path);
    }

    /** @return list<int> */
    public static function findPids(string $needle): array
    {
        if ($needle === '') {
            return [];
        }

        return is_dir('/proc/self') ? self::findPidsInProc($needle) : self::findPidsWithPgrep($needle);
    }

    /** Linux: scan /proc directly, no subprocess. */
    private static function findPidsInProc(string $needle): array
    {
        $self = getmypid();
        $pids = [];

        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
            $pid = (int) basename($dir);

            if ($pid === $self) {
                continue;
            }

            // Zombies have an empty cmdline, so they never match.
            $cmdline = @file_get_contents($dir.'/cmdline');

            if ($cmdline !== false && $cmdline !== '' && str_contains($cmdline, $needle)) {
                $pids[] = $pid;
            }
        }

        return $pids;
    }

    /** macOS and other systems without /proc. */
    private static function findPidsWithPgrep(string $needle): array
    {
        $process = new Process(['pgrep', '-f', '--', preg_quote($needle, null)]);
        $process->run();

        $self = getmypid();

        return array_values(array_filter(
            array_map('intval', preg_split('/\s+/', trim($process->getOutput())) ?: []),
            static fn (int $pid) => $pid > 1 && $pid !== $self,
        ));
    }

    private static function kill(int $pid): void
    {
        if ($pid <= 1) {
            return;
        }

        if (function_exists('posix_kill')) {
            // Process group first (Chromium's main process leads its group), then the pid.
            @posix_kill(-$pid, 9);
            @posix_kill($pid, 9);

            return;
        }

        (new Process(['kill', '-9', (string) $pid]))->run();
    }

    private static function removeOnce(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($items as $item) {
                $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        } catch (\UnexpectedValueException) {
            // A subdirectory vanished or appeared mid-iteration; the caller retries.
        }

        @rmdir($path);
    }
}
