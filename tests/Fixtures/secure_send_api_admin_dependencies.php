<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright (c) 2009-2026 Teampass.net
 * Licensed under the GNU General Public License, version 3 or later.
 * See <https://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/secure_send_email_db.php';

/** Observe administration audit without defining a replacement in PHPUnit's discovery process. */
function logEvents(mixed ...$arguments): void
{
    $GLOBALS['secure_send_api_admin_audit'][] = $arguments;
}
