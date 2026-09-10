<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/sources/runtime_files.functions.php';

/**
 * The background handler serialises itself with an advisory lock on a file it
 * also deletes. Deleting a flocked file detaches the inode from the path, so
 * two handlers can end up holding two different inodes and run together.
 * These tests pin the two halves of the guard that closes that race.
 */
class BackgroundTasksProcessLockTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'teampass-process-lock-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        $root = realpath($this->root);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if (
            $root === false
            || $temporaryRoot === false
            || str_starts_with($root, $temporaryRoot . DIRECTORY_SEPARATOR . 'teampass-process-lock-test-') === false
        ) {
            return;
        }

        foreach ((array) glob($root . '/*') as $entry) {
            if (is_string($entry)) {
                unlink($entry);
            }
        }
        rmdir($root);
    }

    private function handlerSource(): string
    {
        $source = file_get_contents(__DIR__ . '/../../app/scripts/background_tasks___handler.php');
        self::assertIsString($source);

        return $source;
    }

    /**
     * Reproduce the race with the real primitives: an orphaned inode can still
     * be locked, so lock ownership alone never proves exclusivity.
     */
    public function testAnOrphanedLockIsStillAcquirableAndMustBeRejectedByIdentity(): void
    {
        $path = $this->root . '/teampass_background_tasks.lock';

        $running = tpOpenRuntimeFile($path);
        self::assertIsResource($running);
        self::assertTrue(flock($running, LOCK_EX | LOCK_NB));

        // A second handler opens the very same inode before the first one exits.
        $contender = tpOpenRuntimeFile($path);
        self::assertIsResource($contender);
        self::assertFalse(flock($contender, LOCK_EX | LOCK_NB), 'The lock must be exclusive while held.');

        // The first handler finishes and detaches the inode from the path.
        self::assertTrue(unlink($path));
        self::assertTrue(flock($running, LOCK_UN));
        fclose($running);

        // The contender now acquires an inode no path names any more. This is
        // the race: it believes it is the single handler.
        self::assertTrue(flock($contender, LOCK_EX | LOCK_NB));
        $contenderStat = fstat($contender);
        self::assertIsArray($contenderStat);
        self::assertFalse(
            tpRuntimeFileMatchesPath($path, $contenderStat),
            'The guard must reject a lock held on a detached inode.'
        );

        // A third handler legitimately creates and locks a fresh file, which is
        // what would have made two handlers run side by side.
        $next = tpOpenRuntimeFile($path);
        self::assertIsResource($next);
        self::assertTrue(flock($next, LOCK_EX | LOCK_NB));
        $nextStat = fstat($next);
        self::assertIsArray($nextStat);
        self::assertTrue(tpRuntimeFileMatchesPath($path, $nextStat));

        fclose($contender);
        fclose($next);
    }

    /** An untouched lock keeps matching its path, so the guard costs no tick. */
    public function testAHealthyLockPassesTheIdentityCheck(): void
    {
        $path = $this->root . '/teampass_background_tasks.lock';
        $handle = tpOpenRuntimeFile($path);
        self::assertIsResource($handle);
        try {
            self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
            $stat = fstat($handle);
            self::assertIsArray($stat);
            self::assertTrue(tpRuntimeFileMatchesPath($path, $stat));
            self::assertSame(5, fwrite($handle, '12345'));
            self::assertTrue(fflush($handle));
            self::assertTrue(tpRuntimeFileMatchesPath($path, $stat));
        } finally {
            fclose($handle);
        }
    }

    /** The acquiring handler must validate identity after taking the lock. */
    public function testAcquireChecksDescriptorIdentityAfterLocking(): void
    {
        $source = $this->handlerSource();

        $lock = strpos($source, 'if (!flock($fp, LOCK_EX | LOCK_NB)) {');
        $identity = strpos($source, 'tpRuntimeFileMatchesPath($lockFile, $lockStat) === false');
        $write = strpos($source, '$pid = (string) getmypid();');

        self::assertIsInt($lock);
        self::assertIsInt($identity);
        self::assertIsInt($write);
        self::assertLessThan($identity, $lock, 'Identity must be checked after the lock is taken.');
        self::assertLessThan($write, $identity, 'The PID must only be written on a validated lock.');
    }

    /**
     * The releasing handler must unlink under its own lock, and never touch a
     * lock file it does not own.
     */
    public function testReleaseUnlinksUnderTheLockAndOnlyWhenItOwnsIt(): void
    {
        $source = $this->handlerSource();
        $release = strpos($source, 'private function releaseProcessLock(): void {');
        self::assertIsInt($release);
        $body = substr($source, $release);

        $guard = strpos($body, 'if ($this->lockFileHandle === null) {');
        $ownership = strpos($body, 'tpRuntimeFileMatchesPath($lockFile, $lockStat)');
        $unlink = strpos($body, 'unlink($lockFile);');
        $unlock = strpos($body, 'flock($this->lockFileHandle, LOCK_UN);');

        self::assertIsInt($guard);
        self::assertIsInt($ownership);
        self::assertIsInt($unlink);
        self::assertIsInt($unlock);
        self::assertLessThan($ownership, $guard, 'A handler that never acquired the lock must return early.');
        self::assertLessThan($unlink, $ownership, 'Only the owned inode may be unlinked.');
        self::assertLessThan($unlock, $unlink, 'Unlinking must happen while the lock is still held.');
    }
}
