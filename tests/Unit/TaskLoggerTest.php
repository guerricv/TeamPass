<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/../Fixtures/RuntimeProcessTestTrait.php';
require_once __DIR__ . '/../../app/scripts/taskLogger.php';
require_once __DIR__ . '/../../app/sources/file_permissions.functions.php';

final class TaskLoggerTest extends TestCase
{
    use RuntimeProcessTestTrait;

    /** Existing helper coverage must survive the test-suite consolidation. */
    public function testLegacyAndWindowsPathsUseTheSharedResolver(): void
    {
        self::assertSame(
            '/opt/teampass/app/scripts' . DIRECTORY_SEPARATOR . '../files/teampass_tasks.log',
            tpResolveRuntimeLogPath('../files/teampass_tasks.log', '/opt/teampass/app/scripts/')
        );
        foreach (['C:\\logs\\tasks.log', 'C:/logs/tasks.log', '\\\\server\\logs\\tasks.log'] as $path) {
            self::assertSame($path, tpResolveRuntimeLogPath($path, '/opt/scripts'));
        }
    }

    /** @return array<string, array{int, bool}> */
    public static function deniedModes(): array
    {
        return [
            'private' => [0600, true],
            'group readable' => [0640, true],
            'group writable' => [0660, true],
            'other readable' => [0664, false],
            'other writable' => [0662, false],
            'other executable' => [0661, false],
        ];
    }

    /** Chmod denial alone must not refuse a journal that has no other-user access. */
    #[DataProvider('deniedModes')]
    public function testStrictModeUsesOtherAccessRatherThanAnExactMode(int $mode, bool $allowed): void
    {
        $this->requirePosix();
        $path = $this->root . '/storage/logs/group.log';
        self::assertSame(8, file_put_contents($path, "history\n"));
        self::assertTrue(chmod($path, $mode));
        $probe = $this->runPhp(<<<'PHP'
if (function_exists('chmod')) {
    throw new RuntimeException('The chmod denial fixture was not enabled.');
} else {
    function chmod(string $path, int $mode): bool { return false; }
}
require $argv[1];
$handle = tpOpenRuntimeFile($argv[3], true);
$opened = is_resource($handle);
if ($opened) {
    fclose($handle);
}
for ($entry = 0; $entry < 5; ++$entry) {
    (new TaskLogger(json_decode($argv[2], true), $argv[3]))->log('private-task-arguments');
}
echo json_encode(['opened' => $opened]);
PHP, [__DIR__ . '/../../app/scripts/taskLogger.php', json_encode($this->settings()), $path], ['-d', 'disable_functions=chmod']);
        self::assertSame($allowed, json_decode($probe->getOutput(), true)['opened']);
        self::assertSame($allowed ? 0 : 1, substr_count($probe->getErrorOutput(), 'The log entry was not recorded'));
        self::assertStringNotContainsString('private-task-arguments', $probe->getErrorOutput());
        self::assertStringNotContainsString('cannot restrict runtime file permissions', $probe->getErrorOutput());
        self::assertSame($allowed ? 5 : 0, substr_count(file_get_contents($path), 'private-task-arguments'));
        self::assertStringStartsWith("history\n", file_get_contents($path));
        clearstatcache(true, $path);
        self::assertSame($mode, fileperms($path) & 0777);
    }

    /** A static diagnostic flag suppresses messages, never later attempts to write. */
    public function testDestinationFailureIsReportedOnceAndRepairResumesLogging(): void
    {
        $path = $this->root . '/missing/recoverable.log';
        $probe = $this->runPhp(<<<'PHP'
require $argv[1];
$settings = json_decode($argv[2], true);
for ($entry = 0; $entry < 20; ++$entry) {
    (new TaskLogger($settings, $argv[3]))->log('private-lost-entry');
}
mkdir(dirname($argv[3]), 0700);
(new TaskLogger($settings, $argv[3]))->log('recovered');
(new TaskLogger($settings, $argv[3] . '/invalid'))->log('another-private-lost-entry');
(new TaskLogger($settings, $argv[3]))->log('still working');
echo 'continued';
PHP, [__DIR__ . '/../../app/scripts/taskLogger.php', json_encode($this->settings()), $path]);
        self::assertSame('continued', $probe->getOutput());
        self::assertSame(1, substr_count($probe->getErrorOutput(), 'The log entry was not recorded'));
        self::assertStringNotContainsString('private-lost-entry', $probe->getErrorOutput());
        self::assertStringNotContainsString('private-lost-entry', file_get_contents($path));
        self::assertCount(2, file($path));
        self::assertStringContainsString('recovered', file_get_contents($path));
        self::assertStringContainsString('still working', file_get_contents($path));
    }

    /** @return array<string, array{string, bool, int}> */
    public static function handoffs(): array
    {
        return [
            'rotation once' => ['rotate_once', true, 2],
            'rotation twice' => ['rotate_twice', false, 2],
            'permissions changed while waiting' => ['permissions', false, 1],
            'replacement symlink' => ['symlink', false, 1],
            'partial write is not retried' => ['partial', false, 1],
        ];
    }

    /**
     * Inject a filesystem change exactly at the lock handoff, without timing races.
     * Native flock/concurrent appends are exercised separately by the three writers.
     */
    #[DataProvider('handoffs')]
    public function testLogHandoffRevalidatesAndRetriesAtMostOnce(string $scenario, bool $expected, int $attempts): void
    {
        $this->requirePosix();
        $path = $this->root . '/storage/logs/handoff.log';
        self::assertSame(8, file_put_contents($path, "history\n"));
        self::assertTrue(chmod($path, 0640));
        $probe = $this->runPhp(
            'require $argv[1];',
            [__DIR__ . '/../Fixtures/runtime_log_handoff_probe.php', $path, $scenario],
            ['-d', 'disable_functions=flock' . ($scenario === 'partial' ? ',fwrite' : '')]
        );
        $result = json_decode($probe->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, $result['result']);
        self::assertSame($attempts, $result['attempts']);
        self::assertSame('', $probe->getErrorOutput());
        if ($scenario === 'rotate_once') {
            self::assertSame("history\n", file_get_contents($path . '.old-1'));
            self::assertSame("rotated\nprivate-entry\n", file_get_contents($path));
        } elseif ($scenario === 'rotate_twice') {
            self::assertSame("history\n", file_get_contents($path . '.old-1'));
            self::assertSame("rotated\n", file_get_contents($path . '.old-2'));
            self::assertSame("rotated\n", file_get_contents($path));
        } elseif ($scenario === 'symlink') {
            self::assertSame("untouched\n", file_get_contents($path . '.target'));
            self::assertSame("history\n", file_get_contents($path . '.old-1'));
        } elseif ($scenario === 'partial') {
            self::assertSame("history\npri", file_get_contents($path));
        } else {
            self::assertSame("history\n", file_get_contents($path));
        }
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        return ['enable_tasks_log' => '1', 'date_format' => 'Y-m-d', 'time_format' => 'H:i:s'];
    }

    /** @return array<string, array{int, int}> */
    public static function masks(): array
    {
        return [
            'unrestricted' => [0000, 0640],
            'group writable' => [0002, 0640],
            'usual web default' => [0022, 0640],
            'restricted' => [0027, 0640],
            'private' => [0077, 0600],
        ];
    }

    /** Verify the actual logger creates and recreates a protected append-only history. */
    #[DataProvider('masks')]
    public function testLogCreationRecreationAndPermissions(int $mask, int $expected): void
    {
        $this->requirePosix();
        $path = $this->root . '/storage/logs/teampass_tasks.log';
        $logger = new TaskLogger($this->settings(), $path);
        $previous = umask($mask);
        try {
            for ($run = 0; $run < 2; ++$run) {
                $logger->log('first entry');
                clearstatcache(true, $path);
                $inode = fileinode($path);
                $logger->log('second entry', 'WARNING');
                clearstatcache(true, $path);
                self::assertSame($expected, fileperms($path) & 07777);
                self::assertSame($inode, fileinode($path));
                self::assertSame($mask, umask());
                $lines = file($path, FILE_IGNORE_NEW_LINES);
                self::assertCount(2, $lines);
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} - \[INFO\] first entry$/', $lines[0]);
                self::assertStringEndsWith(' - [WARNING] second entry', $lines[1]);
                self::assertTrue(unlink($path));
            }
        } finally {
            umask($previous);
        }
    }

    /** Existing private logs stay private; a permissive log is repaired in place. */
    public function testExistingLogModesAndAuditRemainConsistent(): void
    {
        $this->requirePosix();
        foreach ([0600, 0640, 0644, 0666] as $mode) {
            $path = $this->root . '/storage/logs/log-' . $mode;
            self::assertSame(8, file_put_contents($path, "history\n"));
            self::assertTrue(chmod($path, $mode));
            clearstatcache(true, $path);
            $inode = fileinode($path);
            (new TaskLogger($this->settings(), $path))->log('appended');
            clearstatcache(true, $path);
            self::assertSame($mode === 0600 ? 0600 : 0640, fileperms($path) & 07777);
            self::assertSame($inode, fileinode($path));
            self::assertStringStartsWith("history\n", file_get_contents($path));
        }
        $unsafe = $this->root . '/storage/logs/unmanaged.log';
        self::assertSame(6, file_put_contents($unsafe, 'unsafe'));
        self::assertTrue(chmod($unsafe, 0644));
        $issues = array_values(array_filter(
            tpFilePermissionsScan($this->root)['issues'],
            static fn (array $issue): bool => $issue['reason'] === 'runtime_world_accessible'
        ));
        self::assertCount(1, $issues);
        self::assertSame('storage/logs/unmanaged.log', $issues[0]['path']);
    }

    /** Absolute paths are not prefixed with app/scripts, including native Windows drive paths. */
    public function testAbsolutePathAndDisabledLogging(): void
    {
        $path = $this->root . '/storage/logs/absolute.log';
        (new TaskLogger(['enable_tasks_log' => '0'], $path))->log('disabled');
        self::assertFileDoesNotExist($path);
        (new TaskLogger($this->settings(), $path))->log('enabled');
        self::assertStringEndsWith(' - [INFO] enabled' . PHP_EOL, file_get_contents($path));
        (new TaskLogger(['enable_tasks_log' => '0'], $path))->log('disabled again');
        self::assertCount(1, file($path));
    }

    /** Exercise script-relative paths with unmodified production files in an isolated tree. */
    public function testRelativePathAndExplicitErrorLogFallback(): void
    {
        self::assertTrue(copy(__DIR__ . '/../../app/scripts/taskLogger.php', $this->root . '/app/scripts/taskLogger.php'));
        self::assertTrue(copy(__DIR__ . '/../../app/sources/runtime_files.functions.php', $this->root . '/app/sources/runtime_files.functions.php'));
        $probe = $this->runPhp(
            'require $argv[1]; $settings = json_decode($argv[2], true);'
            . ' (new TaskLogger($settings, "../../storage/logs/relative.log"))->log("relative");'
            . ' (new TaskLogger($settings))->log("explicit fallback");',
            [$this->root . '/app/scripts/taskLogger.php', json_encode($this->settings())]
        );
        self::assertStringContainsString(' - [INFO] relative', file_get_contents($this->root . '/storage/logs/relative.log'));
        self::assertStringContainsString('explicit fallback', $probe->getErrorOutput());
    }

    /** A failed configured destination must not leak the lost entry to another log. */
    public function testInvalidLogDestinationsDoNotLeakContents(): void
    {
        foreach ([$this->root . '/missing/log', $this->root . '/storage/logs', 'php://memory'] as $path) {
            $probe = $this->runPhp(
                'require $argv[1]; (new TaskLogger(json_decode($argv[2], true), $argv[3]))->log("private-task-arguments"); echo "continued";',
                [__DIR__ . '/../../app/scripts/taskLogger.php', json_encode($this->settings()), $path]
            );
            self::assertSame('continued', $probe->getOutput());
            self::assertStringContainsString('The log entry was not recorded', $probe->getErrorOutput());
            self::assertStringNotContainsString('private-task-arguments', $probe->getErrorOutput());
        }
    }

    /** Sensitive log entries are refused when only chmod fails; the warning stays auditable. */
    public function testLogPermissionFailureDoesNotStopTasksOrDiscloseContents(): void
    {
        $this->requirePosix();
        $path = $this->root . '/storage/logs/teampass_tasks.log';
        self::assertSame(8, file_put_contents($path, "history\n"));
        self::assertTrue(chmod($path, 0664));
        $probe = $this->runPhp(<<<'PHP'
if (function_exists('chmod')) {
    throw new RuntimeException('The chmod denial fixture was not enabled.');
} else {
    function chmod(string $path, int $mode): bool { return false; }
}
require $argv[1];
for ($entry = 0; $entry < 20; ++$entry) {
    (new TaskLogger(json_decode($argv[2], true), $argv[3]))->log('private-task-arguments');
}
echo 'continued';
PHP, [__DIR__ . '/../../app/scripts/taskLogger.php', json_encode($this->settings()), $path], ['-d', 'disable_functions=chmod']);
        self::assertSame('continued', $probe->getOutput());
        self::assertSame(1, substr_count($probe->getErrorOutput(), 'The log entry was not recorded'));
        self::assertStringNotContainsString('private-task-arguments', $probe->getErrorOutput());
        self::assertStringNotContainsString('Continuing with existing access', $probe->getErrorOutput());
        self::assertSame("history\n", file_get_contents($path));
        clearstatcache(true, $path);
        self::assertSame(0664, fileperms($path) & 0777);
        $issues = array_filter(tpFilePermissionsScan($this->root)['issues'],
            static fn (array $issue): bool => $issue['reason'] === 'runtime_world_accessible');
        self::assertCount(1, $issues);
    }

    /** A symlink must not redirect task arguments or permission repair to another file. */
    public function testLogSymlinkIsRejected(): void
    {
        $path = $this->root . '/storage/logs/linked.log';
        $target = $this->root . '/storage/logs/target.log';
        self::assertSame(9, file_put_contents($target, 'untouched'));
        if (!@symlink($target, $path)) {
            self::markTestSkipped('Symbolic links are unavailable.');
        }
        $mode = fileperms($target);
        $this->runPhp('require $argv[1]; (new TaskLogger(json_decode($argv[2], true), $argv[3]))->log("secret");',
            [__DIR__ . '/../../app/scripts/taskLogger.php', json_encode($this->settings()), $path]);
        self::assertSame('untouched', file_get_contents($target));
        clearstatcache(true, $target);
        self::assertSame($mode, fileperms($target));
    }

    /** Multiple workers must append every complete entry without overwriting each other. */
    public function testConcurrentLogWritersPreserveEveryEntry(): void
    {
        $path = $this->root . '/storage/logs/concurrent.log';
        $writers = [];
        for ($writer = 0; $writer < 3; ++$writer) {
            $process = new Process([
                PHP_BINARY, '-r',
                'require $argv[1]; $logger = new TaskLogger(json_decode($argv[2], true), $argv[3]);'
                . ' for ($entry = 0; $entry < 80; ++$entry) { $logger->log($argv[4] . ":" . $entry . ":" . str_repeat("x", 1024)); }',
                '--', __DIR__ . '/../../app/scripts/taskLogger.php', json_encode($this->settings()), $path, (string) $writer,
            ]);
            $process->setTimeout(15);
            $this->processes[] = $process;
            $process->start();
            $writers[] = $process;
        }
        foreach ($writers as $writer) {
            self::assertSame(0, $writer->wait(), $writer->getErrorOutput());
            self::assertSame('', $writer->getErrorOutput());
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertCount(240, $lines);
        $identifiers = [];
        foreach ($lines as $line) {
            self::assertSame(1, preg_match('/ - \[INFO\] ([0-2]):([0-9]+):(x{1024})$/', $line, $matches));
            $identifiers[] = $matches[1] . ':' . $matches[2];
        }
        self::assertCount(240, array_unique($identifiers));
    }

}
