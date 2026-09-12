<?php

declare(strict_types=1);

// Test-only command protocol. The parent owns this process and bounds its lifetime.
require_once __DIR__ . '/../../app/scripts/backgroundTaskLock.php';

$path = $argv[1];
$lock = new BackgroundTaskLock($path);
$opened = null;
$openedLocked = false;
echo "ready\n";
fflush(STDOUT);
while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $result = false;
    switch ($request['action']) {
        case 'acquire':
            $result = $lock->acquire();
            break;
        case 'release':
            $lock->release();
            $result = true;
            break;
        case 'open':
            $opened = tpOpenRuntimeFile($path);
            $result = is_resource($opened);
            break;
        case 'lock_opened':
            $result = is_resource($opened) && flock($opened, LOCK_EX | LOCK_NB);
            $openedLocked = $result;
            break;
        case 'acquire_opened':
            // Control open/flock ordering without copying the production guard.
            $result = is_resource($opened)
                && (new ReflectionMethod(BackgroundTaskLock::class, 'acquireHandle'))->invoke($lock, $opened);
            $opened = null; // The real acquisition owns or closes the descriptor.
            $openedLocked = false;
            break;
        case 'release_opened':
            if (is_resource($opened)) {
                fclose($opened);
                $opened = null;
            }
            $openedLocked = false;
            $result = true;
            break;
        default:
            throw new RuntimeException('Unknown test command.');
    }
    // Read through the owner's descriptor: Windows locks prohibit reads by outsiders.
    $handle = (new ReflectionProperty(BackgroundTaskLock::class, 'handle'))->getValue($lock);
    if ($handle === null && $openedLocked) {
        $handle = $opened;
    }
    $contents = null;
    if (is_resource($handle)) {
        $position = ftell($handle);
        rewind($handle);
        $contents = stream_get_contents($handle);
        fseek($handle, $position);
    }
    clearstatcache(true, $path);
    echo json_encode([
        'id' => $request['id'],
        'result' => $result,
        'pid' => getmypid(),
        'inode' => is_file($path) ? fileinode($path) : null,
        'contents' => $contents,
    ], JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
}
