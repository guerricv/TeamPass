<?php

declare(strict_types=1);

/**
 * Open a trusted local runtime lock or signal without truncating its contents.
 *
 * Restrict POSIX permissions before callers write: an inherited umask of 0022
 * otherwise produces 0644 files and triggers the Health permission warning.
 * Intersect with the current mode so a stricter deployment (e.g. 0600) stays
 * strict. Do not change the process-wide umask in a web request.
 *
 * @return resource|false The caller owns the stream and its advisory locking.
 */
function tpOpenRuntimeFile(string $path)
{
    clearstatcache(true, $path);
    if (
        str_contains($path, '://')
        || is_link($path)
        || (file_exists($path) && is_file($path) === false)
    ) {
        return false;
    }

    $handle = @fopen($path, 'c+b');
    if ($handle === false) {
        return false;
    }

    // Windows uses ACLs rather than the POSIX mode audited by System Health.
    if (PHP_OS_FAMILY === 'Windows') {
        return $handle;
    }

    $stat = fstat($handle);
    if ($stat === false) {
        fclose($handle);
        return false;
    }

    $currentMode = $stat['mode'] & 07777;
    $restrictedMode = $currentMode & 0640;
    if ($currentMode !== $restrictedMode && @chmod($path, $restrictedMode) === false) {
        error_log('Teampass: cannot restrict runtime file permissions for "' . $path . '".');
        fclose($handle);
        return false;
    }

    clearstatcache(true, $path);
    return $handle;
}

/**
 * Write a trusted local runtime signal using the same permissions as locks.
 *
 * Return false on an open, permission, lock or write failure so the caller can
 * report it. A signal may be consumed by the background handler after writing.
 */
function tpWriteRuntimeFile(string $path, string $contents): bool
{
    $handle = tpOpenRuntimeFile($path);
    if ($handle === false) {
        return false;
    }

    try {
        if (@flock($handle, LOCK_EX) === false || @ftruncate($handle, 0) === false) {
            return false;
        }

        return @fwrite($handle, $contents) === strlen($contents) && @fflush($handle);
    } finally {
        // Closing releases the advisory lock on both success and failure.
        fclose($handle);
    }
}
