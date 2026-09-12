<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixtures/RuntimeProcessTestTrait.php';
require_once __DIR__ . '/../../app/scripts/backgroundTaskLock.php';

final class BackgroundTasksProcessLockTest extends TestCase
{
    use RuntimeProcessTestTrait;

    /** Observe real flock ownership at the unlink boundary, not source-text offsets. */
    public function testReleaseUnlinksWhileItStillOwnsTheLock(): void
    {
        $this->requirePosix();
        $path = $this->root . '/storage/logs/release-order.lock';
        $probe = $this->runPhp(<<<'PHP'
if (function_exists('unlink')) {
    throw new RuntimeException('The unlink checkpoint was not enabled.');
} else {
    function unlink(string $path): bool {
        $contender = fopen($path, 'c+b');
        $acquired = flock($contender, LOCK_EX | LOCK_NB);
        fclose($contender);
        echo json_encode(['stillLocked' => !$acquired]);
        return true;
    }
}
require $argv[1];
$lock = new BackgroundTaskLock($argv[2]);
if (!$lock->acquire()) {
    throw new RuntimeException('The owner must acquire the lock.');
}
$lock->release();
PHP, [__DIR__ . '/../../app/scripts/backgroundTaskLock.php', $path], ['-d', 'disable_functions=unlink']);
        self::assertSame(['stillLocked' => true], json_decode($probe->getOutput(), true));
        self::assertSame('', $probe->getErrorOutput());
    }

    /** The real acquisition rejects an orphan opened before the previous owner exited. */
    public function testPreopenedContenderIsRejectedAfterLocking(): void
    {
        $this->requirePosix();
        $path = $this->root . '/storage/logs/teampass_background_tasks.lock';
        [$first, $firstInput] = $this->startLockProbe($path);
        $initial = $this->command($first, $firstInput, 'acquire');
        self::assertTrue($initial['result']);
        [$second, $secondInput] = $this->startLockProbe($path);
        self::assertTrue($this->command($second, $secondInput, 'open')['result']);
        self::assertFalse($this->command($second, $secondInput, 'lock_opened')['result']);
        self::assertSame((string) $initial['pid'], $this->command($first, $firstInput, 'acquire')['contents']);
        $this->command($first, $firstInput, 'release');
        self::assertFileDoesNotExist($path);

        // flock on the old inode succeeds, but the production identity check refuses it.
        self::assertFalse($this->command($second, $secondInput, 'acquire_opened')['result']);
        [$third, $thirdInput] = $this->startLockProbe($path);
        $next = $this->command($third, $thirdInput, 'acquire');
        self::assertTrue($next['result']);
        self::assertSame((string) $next['pid'], $next['contents']);

        $this->command($first, $firstInput, 'release');
        $this->command($second, $secondInput, 'release');
        self::assertFileExists($path);
        self::assertFalse($this->command($first, $firstInput, 'acquire')['result']);
        $this->command($third, $thirdInput, 'release');
        self::assertFileDoesNotExist($path);
    }

    /** Exercise the same opened-descriptor path on a valid inode, not just the rejection. */
    public function testPreopenedHealthyDescriptorCanBeAcquired(): void
    {
        $path = $this->root . '/storage/logs/healthy.lock';
        [$probe, $input] = $this->startLockProbe($path);
        self::assertTrue($this->command($probe, $input, 'open')['result']);
        $acquired = $this->command($probe, $input, 'acquire_opened');
        self::assertTrue($acquired['result']);
        self::assertSame((string) $acquired['pid'], $acquired['contents']);
        $this->command($probe, $input, 'release');
        self::assertFileDoesNotExist($path);
    }

    /** Normal releases delete the file, preserve the active PID and admit the next handler. */
    public function testNormalHandoffsRemoveTheFileAndKeepOneOwner(): void
    {
        $path = $this->root . '/storage/logs/teampass_background_tasks.lock';
        [$first, $firstInput] = $this->startLockProbe($path);
        [$second, $secondInput] = $this->startLockProbe($path);
        for ($run = 0; $run < 3; ++$run) {
            $initial = $this->command($first, $firstInput, 'acquire');
            self::assertTrue($initial['result']);
            self::assertFalse($this->command($second, $secondInput, 'acquire')['result']);
            self::assertSame((string) $initial['pid'], $this->command($first, $firstInput, 'acquire')['contents']);
            $this->command($first, $firstInput, 'release');
            self::assertFileDoesNotExist($path);
            $next = $this->command($second, $secondInput, 'acquire');
            self::assertTrue($next['result']);
            self::assertSame((string) $next['pid'], $next['contents']);
            self::assertFalse($this->command($first, $firstInput, 'acquire')['result']);
            $this->command($second, $secondInput, 'release');
            self::assertFileDoesNotExist($path);
        }
    }

    /** Releasing a detached owner must leave the replacement owner's path and PID alone. */
    public function testReleaseDoesNotUnlinkAReplacementFile(): void
    {
        $this->requirePosix();
        $path = $this->root . '/storage/logs/replaced.lock';
        [$first, $firstInput] = $this->startLockProbe($path);
        self::assertTrue($this->command($first, $firstInput, 'acquire')['result']);
        self::assertTrue(rename($path, $path . '.old'));
        [$second, $secondInput] = $this->startLockProbe($path);
        $replacement = $this->command($second, $secondInput, 'acquire');
        self::assertTrue($replacement['result']);
        $this->command($first, $firstInput, 'release');
        self::assertFileExists($path);
        self::assertSame((string) $replacement['pid'], $this->command($second, $secondInput, 'acquire')['contents']);
        self::assertFalse($this->command($first, $firstInput, 'acquire')['result']);
        $this->command($second, $secondInput, 'release');
        self::assertFileDoesNotExist($path);
    }

    /** Destruction follows normal release; a lock never acquired never deletes its target. */
    public function testDestructionRemovesOnlyAnAcquiredLock(): void
    {
        $path = $this->root . '/storage/logs/destructor.lock';
        $owner = new BackgroundTaskLock($path);
        self::assertTrue($owner->acquire());
        $idle = new BackgroundTaskLock($path);
        unset($idle);
        self::assertFileExists($path);
        unset($owner);
        self::assertFileDoesNotExist($path);
    }

    /** A killed process leaves a reusable file when the next process has access. */
    public function testAbruptExitAllowsRestartOnTheSameFile(): void
    {
        // A real POSIX SIGKILL must not run PHP destructors.
        $this->requirePosix();
        $path = $this->root . '/storage/logs/teampass_background_tasks.lock';
        [$first, $firstInput] = $this->startLockProbe($path);
        $acquired = $this->command($first, $firstInput, 'acquire');
        self::assertTrue($acquired['result']);
        $first->signal(9);
        $first->wait();
        self::assertFalse($first->isRunning());
        self::assertTrue($first->hasBeenSignaled());
        self::assertSame(9, $first->getTermSignal());
        self::assertFileExists($path);
        self::assertSame((string) $acquired['pid'], file_get_contents($path));
        [$next, $nextInput] = $this->startLockProbe($path);
        $restarted = $this->command($next, $nextInput, 'acquire');
        self::assertTrue($restarted['result']);
        self::assertSame($acquired['inode'], $restarted['inode']);
        self::assertSame((string) $restarted['pid'], $restarted['contents']);
    }

    /** Coordination locks still tolerate chmod denial after successful opening. */
    public function testLockRetainsBestEffortPermissionPolicy(): void
    {
        $this->requirePosix();
        $path = $this->root . '/storage/logs/teampass_background_tasks.lock';
        self::assertSame(5, file_put_contents($path, '12345'));
        self::assertTrue(chmod($path, 0664));
        clearstatcache(true, $path);
        $probe = $this->runPhp(<<<'PHP'
if (function_exists('chmod')) {
    throw new RuntimeException('The chmod denial fixture was not enabled.');
} else {
    function chmod(string $path, int $mode): bool { return false; }
}
require $argv[1];
$lock = new BackgroundTaskLock($argv[2]);
$acquired = $lock->acquire();
$mode = fileperms($argv[2]) & 0777;
$pid = file_get_contents($argv[2]);
$lock->release();
echo json_encode(['acquired' => $acquired, 'mode' => $mode, 'pid' => $pid, 'expectedPid' => getmypid()]);
PHP, [__DIR__ . '/../../app/scripts/backgroundTaskLock.php', $path], ['-d', 'disable_functions=chmod']);
        $result = json_decode($probe->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($result['acquired']);
        self::assertSame((string) $result['expectedPid'], $result['pid']);
        self::assertSame(0664, $result['mode']);
        self::assertFileDoesNotExist($path);
        self::assertStringContainsString('Continuing with existing access', $probe->getErrorOutput());
    }

    /** Opening failures remain explicit and never remove a configured directory. */
    public function testInvalidLockTargetsAreReportedWithoutDeletion(): void
    {
        foreach ([$this->root . '/missing/lock', $this->root . '/storage/logs'] as $path) {
            $probe = $this->runPhp(
                'require $argv[1]; $lock = new BackgroundTaskLock($argv[2]);'
                . ' echo json_encode($lock->acquire()); $lock->release();',
                [__DIR__ . '/../../app/scripts/backgroundTaskLock.php', $path]
            );
            self::assertSame('false', $probe->getOutput());
            self::assertStringContainsString('cannot open a valid lock file', $probe->getErrorOutput());
        }
        self::assertDirectoryExists($this->root . '/storage/logs');
        self::assertDirectoryDoesNotExist($this->root . '/missing');
    }

    /** Losing or releasing an unacquired lock must not disturb its target. */
    public function testFailedAndRepeatedAcquisitionDoesNotLoseOwnership(): void
    {
        $path = $this->root . '/storage/logs/teampass_background_tasks.lock';
        $owner = new BackgroundTaskLock($path);
        $contender = new BackgroundTaskLock($path);
        self::assertTrue($owner->acquire());
        try {
            self::assertTrue($owner->acquire());
            self::assertFalse($contender->acquire());
            $contender->release();
            unset($contender);
            self::assertFileExists($path);
            $other = new BackgroundTaskLock($path);
            self::assertFalse($other->acquire());
            $owner->release();
            self::assertTrue($other->acquire());
            $other->release();
            self::assertFileDoesNotExist($path);
        } finally {
            $owner->release();
        }
    }

}
