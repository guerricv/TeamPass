<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright (c) 2009-2026 Teampass.net
 * Licensed under the GNU General Public License, version 3 or later.
 * See <https://www.gnu.org/licenses/>.
 */

use TeampassClasses\ConfigManager\ConfigManager;

/** Check the optional grant column without breaking API installations awaiting upgrade. */
function secureSendApiSchemaReady(): bool
{
    return DB::queryFirstRow(
        'SHOW COLUMNS FROM ' . prefixTable('api') . ' LIKE %s', 'allowed_to_secure_send'
    ) !== null;
}

/**
 * Check explicit creation consent from current storage, never JWT claims or cached settings.
 *
 * This permission supplements the existing API create/read grants. It applies only
 * to creation (including idempotent retries); owner-scoped management stays available.
 */
function secureSendApiCanCreate(int $userId): bool
{
    if ($userId <= 0) { return false; }
    $setting = DB::queryFirstRow(
        'SELECT valeur FROM ' . prefixTable('misc') . ' WHERE type = %s AND intitule = %s',
        'admin', 'api_secure_send_enabled'
    );
    if ((int) ($setting['valeur'] ?? 0) !== 1 || !secureSendApiSchemaReady()) { return false; }
    $grant = DB::queryFirstRow(
        'SELECT a.allowed_to_secure_send FROM ' . prefixTable('api') . ' a
        INNER JOIN ' . prefixTable('users') . ' u ON u.id = a.user_id
        WHERE a.user_id = %i AND a.type = %s AND a.enabled = 1 AND a.allowed_to_create = 1
        AND u.disabled = 0 AND u.deleted_at IS NULL',
        $userId, 'user'
    );
    return (int) ($grant['allowed_to_secure_send'] ?? 0) === 1;
}

/** Accept only the binary values emitted by the administration toggles. */
function secureSendApiToggleValue(mixed $value): int
{
    if (!in_array($value, [0, 1, '0', '1'], true)) {
        throw new InvalidArgumentException('invalid_permission');
    }
    return (int) $value;
}

/** Add the opt-in grant and setting on upgrade, preserving every existing administrator choice. */
function secureSendApiUpgrade(): void
{
    if (!secureSendApiSchemaReady()) {
        DB::query('ALTER TABLE ' . prefixTable('api') . " ADD COLUMN allowed_to_secure_send INT(1) NOT NULL DEFAULT '0'");
    }
    DB::query('INSERT IGNORE INTO ' . prefixTable('misc') . ' (type, intitule, valeur) VALUES (%s, %s, %s)',
        'admin', 'api_secure_send_enabled', '0');
    ConfigManager::invalidateCache();
}
