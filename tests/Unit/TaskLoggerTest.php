<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/sources/runtime_files.functions.php';
require_once __DIR__ . '/../../app/scripts/taskLogger.php';

class TaskLoggerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'teampass-task-logger-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/storage/logs', 0700, true));
    }

    protected function tearDown(): void
    {
        $root = realpath($this->root);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if (
            $root === false
            || $temporaryRoot === false
            || str_starts_with($root, $temporaryRoot . DIRECTORY_SEPARATOR . 'teampass-task-logger-test-') === false
        ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() === false && $entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }

    /** @return array<string,mixed> */
    private function settings(bool $enabled = true): array
    {
        return [
            'enable_tasks_log' => $enabled ? '1' : '0',
            'date_format' => 'd/m/Y',
            'time_format' => 'H:i:s',
        ];
    }

    /**
     * LOG_TASKS_FILE became absolute when the storage/ layout landed, but the
     * writer kept prepending app/scripts/ to it. The resulting path had no
     * existing parent directory, so enable_tasks_log silently wrote nothing.
     */
    public function testAnAbsoluteLogPathIsUsedVerbatim(): void
    {
        $path = $this->root . '/storage/logs/teampass_tasks.log';

        self::assertSame($path, tpResolveRuntimeLogPath($path, __DIR__ . '/../../app/scripts'));

        (new TaskLogger($this->settings(), $path))->log('handler started', 'INFO');

        self::assertFileExists($path);
        self::assertStringContainsString('[INFO] handler started', (string) file_get_contents($path));
        self::assertSame(
            [],
            glob($this->root . '/storage/logs/*' . DIRECTORY_SEPARATOR . '*') ?: [],
            'No nested path may be built from an absolute log file.'
        );
    }

    /** A pre-storage relative value must keep resolving against app/scripts/. */
    public function testALegacyRelativeLogPathStillResolvesAgainstTheScriptDirectory(): void
    {
        self::assertSame(
            '/opt/teampass/app/scripts' . DIRECTORY_SEPARATOR . '../files/teampass_tasks.log',
            tpResolveRuntimeLogPath('../files/teampass_tasks.log', '/opt/teampass/app/scripts/')
        );
        self::assertSame('C:\\logs\\tasks.log', tpResolveRuntimeLogPath('C:\\logs\\tasks.log', '/opt/scripts'));
    }

    /** The task log is a runtime file: it must not be created world readable. */
    public function testTheLogIsCreatedWithRestrictedPermissionsAndAppends(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('POSIX permission semantics are required.');
        }

        $path = $this->root . '/storage/logs/teampass_tasks.log';
        $logger = new TaskLogger($this->settings(), $path);
        $previousMask = umask(0022);
        try {
            $logger->log('first line');
            clearstatcache(true, $path);
            self::assertSame(0640, fileperms($path) & 0777);
            $inode = fileinode($path);

            $logger->log('second line', 'ERROR');
            clearstatcache(true, $path);
            self::assertSame(0640, fileperms($path) & 0777);
            self::assertSame($inode, fileinode($path), 'Appending must not replace the log file.');
            self::assertSame(0022, umask());
        } finally {
            umask($previousMask);
        }

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('[INFO] first line', $contents);
        self::assertStringContainsString('[ERROR] second line', $contents);
        self::assertSame(2, substr_count($contents, PHP_EOL));
    }

    /** Task logging stays opt-in: no file is created while the setting is off. */
    public function testNothingIsWrittenWhenTaskLoggingIsDisabled(): void
    {
        $path = $this->root . '/storage/logs/teampass_tasks.log';
        (new TaskLogger($this->settings(false), $path))->log('must not appear');

        self::assertFileDoesNotExist($path);
    }
}
