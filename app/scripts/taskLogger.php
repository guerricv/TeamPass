<?php
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
 * @file      taskLogger.php
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2026 Teampass.net
 * @license   GPL-3.0
 * @see       https://www.teampass.net
 */

require_once __DIR__ . '/../sources/runtime_files.functions.php';

/**
 * Class TaskLogger
 * Handles logging for background tasks in TeamPass
 */
class TaskLogger {
    private $settings;
    private $logFile;
    private static bool $destinationFailureReported = false;

    public function __construct(array $settings, string $logFile = '') {
        $this->settings = $settings;
        $this->logFile = $logFile;
    }

    /**
     * Logs a message to the specified log file or default error log
     *
     * @param string $message The message to log
     * @param string $level The log level (e.g., INFO, ERROR, DEBUG)
     */
    public function log(string $message, string $level = 'INFO') {
        if (!empty($this->settings['enable_tasks_log'])) {
            $formattedMessage = date($this->settings['date_format'] . ' ' . $this->settings['time_format']) . 
                                " - [$level] $message" . PHP_EOL;

            if (!empty($this->logFile)) {
                $path = tpResolveRuntimeLogPath($this->logFile, __DIR__);
                if (tpAppendRuntimeFile($path, $formattedMessage) === false && self::$destinationFailureReported === false) {
                    self::$destinationFailureReported = true;
                    // Task arguments may be sensitive: report the failure, not the lost entry.
                    error_log('Teampass: cannot append to the protected task log "' . $path
                        . '"; check its path, owner and permissions. The log entry was not recorded.'
                        . ' Further destination failures are suppressed for this process.');
                }
            } else {
                // Use default error log
                error_log($formattedMessage);
            }
        }
    }
}
