<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/sources/runtime_files.functions.php';
require_once __DIR__ . '/../../app/sources/file_integrity.functions.php';

class RuntimeFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'teampass-runtime-files-test-' . bin2hex(random_bytes(8));
        foreach (['storage/logs', 'storage/files', 'app/includes/libraries/csrfp/log'] as $directory) {
            self::assertTrue(mkdir($this->root . '/' . $directory, 0700, true));
        }
    }

    protected function tearDown(): void
    {
        $root = realpath($this->root);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if (
            $root === false
            || $temporaryRoot === false
            || str_starts_with($root, $temporaryRoot . DIRECTORY_SEPARATOR . 'teampass-runtime-files-test-') === false
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

    /** @return array<string,array{int,int}> */
    public static function creationMasks(): array
    {
        return [
            'no mask' => [0000, 0640],
            'group-writable default' => [0002, 0640],
            'usual web default' => [0022, 0640],
            'restricted default' => [0027, 0640],
            'private default' => [0077, 0600],
        ];
    }

    /** Verify recreated locks and signals stay private under common deployment umasks. */
    #[DataProvider('creationMasks')]
    public function testCreationAndRecreationRestrictPermissionsWithoutChangingUmask(int $mask, int $expectedMode): void
    {
        $this->requirePosix();
        $previousMask = umask($mask);
        try {
            $path = $this->root . '/storage/logs/teampass_background_tasks.lock';
            for ($run = 0; $run < 2; $run++) {
                $handle = tpOpenRuntimeFile($path);
                self::assertIsResource($handle);
                try {
                    self::assertSame($expectedMode, fstat($handle)['mode'] & 0777);
                    self::assertSame($mask, umask());
                    self::assertSame('', stream_get_contents($handle));
                } finally {
                    fclose($handle);
                }
                self::assertTrue(unlink($path));
            }

            $signal = $this->root . '/storage/logs/teampass_background_tasks.trigger';
            for ($run = 0; $run < 2; $run++) {
                self::assertTrue(tpWriteRuntimeFile($signal, '1788806401'));
                clearstatcache(true, $signal);
                self::assertSame($expectedMode, fileperms($signal) & 0777);
                self::assertSame($mask, umask());
                self::assertTrue(unlink($signal));
            }
        } finally {
            umask($previousMask);
        }
    }

    /** @return array<string,array{int,int}> */
    public static function existingModes(): array
    {
        return [
            'old lock' => [0644, 0640],
            'world-writable lock' => [0666, 0640],
            'already restricted' => [0640, 0640],
            'already private' => [0600, 0600],
        ];
    }

    /** Restrict old files in place without granting access or damaging an active PID. */
    #[DataProvider('existingModes')]
    public function testExistingFilesAreRestrictedWithoutTruncatingOrReplacingThem(int $mode, int $expectedMode): void
    {
        $this->requirePosix();
        $path = $this->root . '/storage/logs/existing.lock';
        self::assertSame(5, file_put_contents($path, '12345'));
        self::assertTrue(chmod($path, $mode));
        clearstatcache(true, $path);
        $inode = fileinode($path);

        $handle = tpOpenRuntimeFile($path);
        self::assertIsResource($handle);
        try {
            self::assertSame($expectedMode, fstat($handle)['mode'] & 0777);
            self::assertSame($inode, fstat($handle)['ino']);
            self::assertSame('12345', stream_get_contents($handle));
        } finally {
            fclose($handle);
        }
    }

    /** Check complete signal replacement and lock release after a successful write. */
    public function testSignalWriterTruncatesOldContentsAndReleasesItsLock(): void
    {
        $path = $this->root . '/storage/logs/teampass_background_tasks.trigger';
        self::assertTrue(tpWriteRuntimeFile($path, 'a previous longer timestamp'));
        self::assertTrue(tpWriteRuntimeFile($path, '12345'));
        self::assertSame('12345', file_get_contents($path));
        $handle = tpOpenRuntimeFile($path);
        self::assertIsResource($handle);
        try {
            self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        } finally {
            fclose($handle);
        }
    }

    /** Reject unsupported targets without leaving files or a changed process umask. */
    public function testInvalidTargetsFailWithoutChangingUmaskOrDirectoryContents(): void
    {
        $mask = umask();
        self::assertFalse(tpOpenRuntimeFile($this->root . '/missing/scan.lock'));
        self::assertFalse(tpWriteRuntimeFile($this->root . '/missing/task.trigger', '123'));
        self::assertFalse(tpOpenRuntimeFile($this->root . '/storage/logs'));
        self::assertFalse(tpWriteRuntimeFile('php://memory', '123'));
        self::assertSame($mask, umask());
        self::assertSame(['.', '..'], scandir($this->root . '/storage/logs'));
    }

    /** Refuse to follow a lock path that points at another file. */
    public function testSymbolicLinkTargetIsNotWrittenOrChmodded(): void
    {
        $target = $this->root . '/storage/logs/target.txt';
        $link = $this->root . '/storage/logs/linked.lock';
        self::assertSame(9, file_put_contents($target, 'untouched'));
        if (@symlink($target, $link) === false) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }
        $mode = fileperms($target);
        self::assertFalse(tpOpenRuntimeFile($link));
        self::assertFalse(tpWriteRuntimeFile($link, '123'));
        self::assertSame('untouched', file_get_contents($target));
        clearstatcache(true, $target);
        self::assertSame($mode, fileperms($target));
    }

    /** Keep the active scan's PID intact when another scan competes for its lock. */
    public function testAnotherScanCannotAcquireOrTruncateAnActiveLock(): void
    {
        $path = tpFileIntegrityLockPath($this->root);
        $holder = tpOpenRuntimeFile($path);
        self::assertIsResource($holder);
        try {
            self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));
            self::assertSame(5, fwrite($holder, '12345'));
            self::assertTrue(fflush($holder));
            self::assertTrue(tpFileIntegrityIsRunning($this->root));
            try {
                tpFileIntegrityScan($this->root, $this->root . '/app/files_reference.txt', true, false);
                self::fail('A competing scan must not acquire the active lock.');
            } catch (RuntimeException $exception) {
                self::assertSame('A file integrity scan is already running.', $exception->getMessage());
            }
            rewind($holder);
            self::assertSame('12345', stream_get_contents($holder));
        } finally {
            fclose($holder);
        }
        self::assertFalse(tpFileIntegrityIsRunning($this->root));
    }

    /** Exercise the real scanner and its non-creating status probe. */
    public function testStatusDoesNotCreateMissingLockAndScanUsesRestrictedPermissions(): void
    {
        $this->requirePosix();
        $path = tpFileIntegrityLockPath($this->root);
        self::assertFalse(tpFileIntegrityIsRunning($this->root));
        self::assertFileDoesNotExist($path);
        self::assertSame(5, file_put_contents($this->root . '/app/known.txt', 'known'));
        $reference = $this->root . '/app/files_reference.txt';
        self::assertNotFalse(file_put_contents($reference, 'app/known.txt ' . md5('known') . "\n"));

        $mask = umask(0022);
        try {
            $report = tpFileIntegrityScan($this->root, $reference, true, false);
            self::assertSame('success', $report['status']);
            self::assertSame(0, $report['counts']['unknown']);
            clearstatcache(true, $path);
            self::assertSame(0640, fileperms($path) & 0777);
            self::assertFalse(tpFileIntegrityIsRunning($this->root));
            self::assertSame(0022, umask());
        } finally {
            umask($mask);
        }
    }

    /** Preserve permission auditing for unmanaged, unsafe runtime files. */
    public function testPermissionScanStillReportsUnsafeRuntimeFiles(): void
    {
        $this->requirePosix();
        $paths = [
            $this->root . '/storage/logs/teampass_background_tasks.lock',
            $this->root . '/storage/logs/teampass_background_tasks.trigger',
            tpFileIntegrityLockPath($this->root),
            tpFileIntegrityEnqueueLockPath($this->root),
        ];
        foreach ($paths as $path) {
            self::assertNotFalse(file_put_contents($path, '12345'));
            self::assertTrue(chmod($path, 0644));
            $handle = tpOpenRuntimeFile($path);
            self::assertIsResource($handle);
            fclose($handle);
        }
        $unsafe = $this->root . '/storage/logs/unsafe.lock';
        self::assertNotFalse(file_put_contents($unsafe, 'not managed by the helper'));
        self::assertTrue(chmod($unsafe, 0666));
        clearstatcache();

        $platform = tpFilePermissionsDetectPlatform("ID=ubuntu\n", 'Linux');
        $report = tpFilePermissionsScan($this->root, $platform, [
            'web_user' => 'test-web', 'web_group' => 'test-web', 'source' => 'test',
            'uid' => posix_geteuid(), 'gids' => [posix_getegid()],
        ]);
        $worldWritable = array_values(array_filter(
            $report['issues'],
            static fn (array $issue): bool => $issue['reason'] === 'world_writable'
        ));
        $worldReadable = array_values(array_filter(
            $report['issues'],
            static fn (array $issue): bool => $issue['reason'] === 'runtime_world_accessible'
        ));
        self::assertCount(1, $worldWritable);
        self::assertSame('storage/logs/unsafe.lock', $worldWritable[0]['path']);
        self::assertSame([], $worldReadable);
    }

    private function requirePosix(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || function_exists('posix_geteuid') === false) {
            self::markTestSkipped('Linux/POSIX permission semantics are required.');
        }
    }
}
