<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\RenderCleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RenderCleanupTest extends TestCase
{
    private string $dir;

    /** @var list<Process> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/browsershot-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            $process->stop(0);
        }

        RenderCleanup::removeDirectory($this->dir);
    }

    /** Start a PHP process that references the work dir in its command line. */
    private function spawn(string $code): Process
    {
        $process = new Process([PHP_BINARY, '-r', $code, '--', $this->dir]);
        $process->start();
        $this->processes[] = $process;

        // Wait until it is visible to the process scan.
        $deadline = microtime(true) + 3;
        while (RenderCleanup::findPids($this->dir) === [] && microtime(true) < $deadline) {
            usleep(20_000);
        }

        return $process;
    }

    public function test_removes_nested_directory(): void
    {
        mkdir($this->dir.'/profile/Default', 0700, true);
        file_put_contents($this->dir.'/index.html', '<p>x</p>');
        file_put_contents($this->dir.'/profile/Default/Cookies', 'x');
        symlink('/etc', $this->dir.'/link');

        $this->assertTrue(RenderCleanup::removeDirectory($this->dir));
        $this->assertDirectoryDoesNotExist($this->dir);
        $this->assertDirectoryExists('/etc', 'symlink target must not be touched');
    }

    public function test_missing_directory_is_a_no_op(): void
    {
        RenderCleanup::removeDirectory($this->dir);

        $this->assertTrue(RenderCleanup::removeDirectory($this->dir));
    }

    public function test_kills_processes_that_reference_the_work_dir(): void
    {
        $process = $this->spawn('sleep(60);');
        // Symfony returns null for the pid once the process has exited.
        $pid = $process->getPid();

        $killed = RenderCleanup::killProcessesUsing($this->dir);

        $this->assertContains($pid, $killed);
        $this->assertSame([], RenderCleanup::findPids($this->dir));
        $this->assertFalse($process->isRunning());
    }

    public function test_does_not_touch_unrelated_processes(): void
    {
        $other = new Process([PHP_BINARY, '-r', 'sleep(60);', '--', sys_get_temp_dir().'/browsershot-unrelated']);
        $other->start();
        $this->processes[] = $other;
        usleep(200_000);

        RenderCleanup::killProcessesUsing($this->dir);

        $this->assertTrue($other->isRunning());
    }

    public function test_removes_directory_while_a_process_keeps_writing_to_it(): void
    {
        // Simulates Chromium flushing its profile after node was SIGKILLed.
        $this->spawn('$d = $argv[1]; for ($i = 0; ; $i++) { @mkdir("$d/p/$i", 0700, true); @file_put_contents("$d/p/$i/f", str_repeat("x", 1024)); usleep(1000); }');

        usleep(200_000);
        $this->assertNotEmpty(glob($this->dir.'/p/*'));

        RenderCleanup::run($this->dir);

        $this->assertDirectoryDoesNotExist($this->dir);
        $this->assertSame([], RenderCleanup::findPids($this->dir));
    }

    public function test_empty_needle_matches_nothing(): void
    {
        $this->assertSame([], RenderCleanup::findPids(''));
    }
}
