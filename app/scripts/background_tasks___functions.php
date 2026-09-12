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
 * ---3.0.0.22
 * @file      background_tasks___functions.php
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2026 Teampass.net
 * @license   GPL-3.0
 * @see       https://www.teampass.net
 */


// Load config
require_once __DIR__.'/../config/include.php';
require_once __DIR__.'/../config/settings.php';
header('Content-type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');


/**
 * Permits to log task status
 *
 * @param string $status
 * @param string $job
 * @param integer $enable_tasks_log
 * @param integer|null $id
 * @return integer
 */
function doLog(string $status, string $job, int $enable_tasks_log = 0, ?int $id = null, ?int $treated_objects = null): int
{
    clearTasksLog();
    clearTasksHistory();

    // is log enabled?
    if ((int) $enable_tasks_log === 1) {
        // is log start?
        if (is_null($id) === true) {
            DB::insert(
                prefixTable('background_tasks_logs'),
                array(
                    'created_at' => time(),
                    'job' => $job,
                    'status' => $status,
                )
            );
            return DB::insertId();
        }

        // Case is an update
        DB::update(
            prefixTable('background_tasks_logs'),
            array(
                'status' => $status,
                'finished_at' => time(),
                'treated_objects' => $treated_objects,
            ),
            'increment_id = %i',
            $id
        );
    }
    
    return -1;
}

function clearTasksHistory(): void
{
    global $SETTINGS;

    // Run only once per PHP process (doLog can be called multiple times in a run)
    static $alreadyDone = false;
    if ($alreadyDone === true) {
        return;
    }
    $alreadyDone = true;

    $historyDelay = isset($SETTINGS['tasks_history_delay']) === true ? (int) $SETTINGS['tasks_history_delay'] : 0;

    // Safety: this setting is meant to be in seconds (admin UI stores seconds),
    // but if for any reason it is stored in days, convert it.
    if ($historyDelay > 0 && $historyDelay < 86400) {
        $historyDelay = $historyDelay * 86400;
    }

    if ($historyDelay <= 0) {
        return;
    }

    $threshold = time() - $historyDelay;

    // Delete subtasks linked to old finished tasks (avoid orphans)
    DB::query(
        'DELETE s
         FROM ' . prefixTable('background_subtasks') . ' s
         INNER JOIN ' . prefixTable('background_tasks') . ' t ON t.increment_id = s.task_id
         WHERE t.finished_at > 0
           AND t.finished_at < %i',
        $threshold
    );

    // Delete old finished tasks from history
    DB::delete(
        prefixTable('background_tasks'),
        'finished_at > 0 AND finished_at < %i',
        $threshold
    );
}

function clearTasksLog()
{
    global $SETTINGS;
    $retentionDays = isset($SETTINGS['tasks_log_retention_delay']) === true ? $SETTINGS['tasks_log_retention_delay'] : 30;
    $timestamp = strtotime('-'.$retentionDays.' days');
    DB::delete(
        prefixTable('background_tasks_logs'),
        'created_at < %s',
        $timestamp
    );
}

/**
 * Permits to run a task
 *
 * @param string $message
 * @param array $SETTINGS
 * @return void
 */
function provideLog(string $message, array $SETTINGS)
{
    if (defined('LOG_TO_SERVER') && LOG_TO_SERVER === true) {
        error_log((string) date($SETTINGS['date_format'] . ' ' . $SETTINGS['time_format'], time()) . ' - '.$message);
    }
}

/**
 * Invalidate a user's folder views for the legacy user_build_cache_tree task.
 *
 * The request handlers rebuild the tree and dropdowns with current permissions.
 * A background update of visible_folders alone used to revalidate stale data
 * through the shared timestamp, even when the scheduler was working correctly.
 *
 * @param int $user_id User whose folder cache must be refreshed
 * @return void
 */
function performVisibleFoldersHtmlUpdate(int $user_id): void
{
    invalidateUserFolderCache([$user_id]);
}

function subTaskStatus($taskId)
{
    $subTasks = DB::query(
        'SELECT * FROM ' . prefixTable('background_subtasks') . ' WHERE task_id = %i',
        $taskId
    );

    $status = 0;
    foreach ($subTasks as $subTask) {
        if ($subTask['is_in_progress'] === 1) {
            $status = 1;
            break;
        }
    }

    return $status;
}
