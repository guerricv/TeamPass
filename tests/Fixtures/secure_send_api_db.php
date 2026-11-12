<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright (c) 2009-2026 Teampass.net
 * Licensed under the GNU General Public License, version 3 or later.
 * See <https://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/secure_send_email_db.php';

/** Assert the API session key pair and migration identifier reach the shared decryptor. */
function decryptUserObjectKeyWithMigration(string $share, string $private, string $public, int $increment, string $table): string
{
    if ($private !== 'api-private-canary' || $public !== 'api-public-canary' || $increment !== 9 || $table !== 'sharekeys_items') {
        throw new RuntimeException('Unexpected key context');
    }
    return 'object-key';
}

/** Item cipher formats are tested separately; retain the snapshot's byte-exact password. */
function teampassDecryptPasswordValue(string $value, string $key, int $length, string $iv): string
{
    if ($key !== 'object-key') { throw new RuntimeException('Invalid object key'); }
    return ' item-password-canary ';
}
