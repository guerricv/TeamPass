<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * ---
 * This file is part of the TeamPass project.
 *
 * TeamPass is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * TeamPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Certain components of this file may be under different licenses. For
 * details, see the `licenses` directory or individual file headers.
 * ---
 * @file      runtime_files.functions.php
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2026 Teampass.net
 * @license   GPL-3.0
 * @see       https://www.teampass.net
 */

/**
 * Check that a runtime path still names the regular file held by a stream.
 *
 * These checks detect replacements observed before/after chmod, but cannot make
 * path-based chmod atomic. Runtime directories must not be writable by third parties.
 *
 * @param array<string, int> $streamStat Metadata returned by fstat().
 * @phpstan-impure The filesystem can change between calls with the same arguments.
 */
function tpRuntimeFileMatchesPath(string $path, array $streamStat): bool
{
    clearstatcache(true, $path);
    $pathStat = @lstat($path);

    return $pathStat !== false
        && ($streamStat['mode'] & 0170000) === 0100000
        && ($pathStat['mode'] & 0170000) === 0100000
        && $streamStat['dev'] === $pathStat['dev']
        && $streamStat['ino'] === $pathStat['ino'];
}

/**
 * Open a trusted local runtime file without truncating its contents.
 *
 * Try to restrict POSIX permissions before callers write: an inherited umask of 0022
 * otherwise produces 0644 files and triggers the Health permission warning.
 * Preserve 0600 and owner read/write access without granting group/other access.
 * A chmod-only failure is logged but must not stop processing accessible locks
 * and signals. Permission auditing still reports the unresolved permissions.
 * Do not change the process-wide umask in a web request.
 *
 * @param bool $requireRestrictedPermissions Refuse world access; the log caller reports failures.
 * @return resource|false The caller owns the stream and its advisory locking.
 */
function tpOpenRuntimeFile(string $path, bool $requireRestrictedPermissions = false)
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

    $stat = @fstat($handle);
    if ($stat === false || tpRuntimeFileMatchesPath($path, $stat) === false) {
        fclose($handle);
        return false;
    }

    // Windows uses ACLs rather than the POSIX mode audited by System Health.
    if (PHP_OS_FAMILY === 'Windows') {
        return $handle;
    }

    $currentMode = $stat['mode'] & 07777;
    $restrictedMode = ($currentMode & 0640) | 0600;
    if ($currentMode !== $restrictedMode && @chmod($path, $restrictedMode) === false && !$requireRestrictedPermissions) {
        // Logs report failures once in TaskLogger, not once per attempted entry.
        error_log(
            'Teampass: cannot restrict runtime file permissions for "' . $path
            . '". Continuing with existing access; check the file owner and permissions.'
        );
    }

    // A chmod-only failure is recoverable, but a replaced/invalid target is not.
    if (tpRuntimeFileMatchesPath($path, $stat) === false) {
        fclose($handle);
        return false;
    }

    if ($requireRestrictedPermissions) {
        $securedStat = @fstat($handle);
        if ($securedStat === false || ($securedStat['mode'] & 0007) !== 0) {
            fclose($handle);
            return false;
        }
    }

    return $handle;
}

/**
 * Write a trusted local runtime signal using the same permissions as locks.
 *
 * Never wait for a competing producer in a web request. Return false on an open,
 * lock or write failure; distinguish contention from an I/O failure for callers.
 * A signal may be consumed by the background handler after writing.
 *
 * @param bool $wouldBlock Set to true only when another producer holds the lock.
 */
function tpWriteRuntimeFile(string $path, string $contents, bool &$wouldBlock = false): bool
{
    $wouldBlock = false;
    $handle = tpOpenRuntimeFile($path);
    if ($handle === false) {
        return false;
    }

    try {
        $lockWouldBlock = 0;
        if (@flock($handle, LOCK_EX | LOCK_NB, $lockWouldBlock) === false) {
            $wouldBlock = $lockWouldBlock === 1;
            return false;
        }
        if (@ftruncate($handle, 0) === false) {
            return false;
        }

        return @fwrite($handle, $contents) === strlen($contents) && @fflush($handle);
    } finally {
        // Closing releases the advisory lock on both success and failure.
        fclose($handle);
    }
}

/**
 * Resolve a configured runtime log path against a base directory.
 *
 * LOG_TASKS_FILE was a path relative to app/scripts/ before the storage/ layout
 * and is absolute since. Prepending the base directory to an absolute value
 * builds an unreachable path and silently disables logging, so only a relative
 * value is resolved. Windows drive-qualified paths count as absolute.
 */
function tpResolveRuntimeLogPath(string $logFile, string $baseDirectory): string
{
    if (preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $logFile) === 1) {
        return $logFile;
    }

    return rtrim($baseDirectory, '\\/') . DIRECTORY_SEPARATOR . $logFile;
}

/**
 * Append a protected log entry without truncating history or leaking its contents.
 *
 * Background CLI writers wait for each other. Reopen once if rotation replaced
 * the path while waiting; never retry an entry after any bytes were written.
 * A chmod-only failure is acceptable when no POSIX "other" access remains.
 * Callers report failures without forwarding the potentially sensitive entry.
 */
function tpAppendRuntimeFile(string $path, string $contents): bool
{
    for ($attempt = 0; $attempt < 2; ++$attempt) {
        $handle = tpOpenRuntimeFile($path, true);
        if ($handle === false) {
            return false;
        }

        try {
            if (@flock($handle, LOCK_EX) === false) {
                return false;
            }
            $stat = @fstat($handle);
            if ($stat === false) {
                return false;
            }
            if (tpRuntimeFileMatchesPath($path, $stat) === false) {
                // finally closes the old descriptor before the bounded retry.
                continue;
            }
            if (PHP_OS_FAMILY !== 'Windows' && ($stat['mode'] & 0007) !== 0) {
                return false;
            }
            // Seek only after acquiring the lock and validating its destination.
            if (@fseek($handle, 0, SEEK_END) !== 0) {
                return false;
            }

            return @fwrite($handle, $contents) === strlen($contents) && @fflush($handle);
        } finally {
            fclose($handle);
        }
    }

    return false;
}
