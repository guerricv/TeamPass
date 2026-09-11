<?php

declare(strict_types=1);

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** Isolated subprocesses with a request/response protocol and bounded lifetimes. */
trait RuntimeProcessTestTrait
{
    private string $root;
    /** @var list<Process> */
    private array $processes = [];
    private int $requestId = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'teampass-task-runtime-test-' . bin2hex(random_bytes(8));
        foreach (['storage/logs', 'app/scripts', 'app/sources'] as $directory) {
            self::assertTrue(mkdir($this->root . '/' . $directory, 0700, true));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
        }
        $root = realpath($this->root);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($root === false || $temporaryRoot === false
            || !str_starts_with($root, $temporaryRoot . DIRECTORY_SEPARATOR . 'teampass-task-runtime-test-')) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry->isLink() && $entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }

    /** @return array{Process, InputStream} */
    private function startLockProbe(string $path): array
    {
        $input = new InputStream();
        $process = new Process([PHP_BINARY, __DIR__ . '/../Fixtures/background_task_lock_probe.php', $path]);
        $process->setInput($input);
        $process->setTimeout(15);
        $this->processes[] = $process;
        $process->start();
        $this->waitForMatch($process, '/ready\r?\n/');
        return [$process, $input];
    }

    /** @return array{id: int, result: bool, pid: int, inode: int|null, contents: string|null} */
    private function command(Process $process, InputStream $input, string $action): array
    {
        $id = ++$this->requestId;
        $input->write(json_encode(['id' => $id, 'action' => $action], JSON_THROW_ON_ERROR) . "\n");
        $line = $this->waitForMatch($process, '/\{"id":' . $id . ',[^\r\n]+\}\r?\n/');
        return json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Wait for a protocol response, never for an assumed scheduling delay. */
    private function waitForMatch(Process $process, string $pattern): string
    {
        do {
            $process->checkTimeout();
            if (preg_match($pattern, $process->getOutput(), $matches) === 1) {
                return $matches[0];
            }
            if (!$process->isRunning()) {
                self::fail('Probe exited before replying: ' . $process->getErrorOutput());
            }
            usleep(1000);
        } while (true);
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $options
     */
    private function runPhp(string $code, array $arguments, array $options = []): Process
    {
        $process = new Process(array_merge([PHP_BINARY], $options, ['-r', $code, '--'], $arguments));
        $process->setTimeout(15);
        $this->processes[] = $process;
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        return $process;
    }

    private function requirePosix(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')) {
            self::markTestSkipped('Linux/POSIX permission semantics are required.');
        }
    }
}
