<?php

declare(strict_types=1);

// This child injects changes at the flock return boundary. It never runs a handler.
$path = $argv[2];
$scenario = $argv[3];
$attempts = 0;
if (function_exists('flock')) {
    throw new RuntimeException('The lock-handoff fixture was not enabled.');
} else {
    function flock($handle, int $operation): bool
    {
        global $path, $scenario, $attempts;
        if ($operation !== LOCK_EX) {
            throw new RuntimeException('Expected exclusive append locking.');
        }
        ++$attempts;
        if ($scenario === 'permissions') {
            chmod($path, 0664);
        } elseif ($scenario === 'symlink') {
            rename($path, $path . '.old-1');
            file_put_contents($path . '.target', "untouched\n");
            symlink($path . '.target', $path);
        } elseif ($scenario === 'rotate_twice' || ($scenario === 'rotate_once' && $attempts === 1)) {
            rename($path, $path . '.old-' . $attempts);
            file_put_contents($path, "rotated\n");
            chmod($path, 0640);
        }
        return true;
    }
}
if ($scenario === 'partial') {
    if (function_exists('fwrite')) {
        throw new RuntimeException('The partial-write fixture was not enabled.');
    } else {
        function fwrite($handle, string $contents): int|false
        {
            return fputs($handle, substr($contents, 0, 3));
        }
    }
}
require_once __DIR__ . '/../../app/sources/runtime_files.functions.php';
$result = tpAppendRuntimeFile($path, "private-entry\n");
echo json_encode(['result' => $result, 'attempts' => $attempts], JSON_THROW_ON_ERROR);
