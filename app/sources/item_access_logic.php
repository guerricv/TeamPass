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
 * DB-free decisions used by the web item access checks.
 *
 * @file      item_access_logic.php
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2026 Teampass.net
 * @license   GPL-3.0
 * @see       https://www.teampass.net
 */

/**
 * Tell whether a folder belongs to the user's resolved item access scope.
 *
 * The scope is the one identifyUserRights() stores in session: accessible folders
 * (roles, direct grants, read-only folders) and the user's own personal folders,
 * minus explicit denials and other users' personal folders. A folder the tree only
 * shows as the blocked ancestor of an accessible folder is not part of it.
 *
 * @param int   $folderId        Folder holding the item
 * @param array $accessible      Session user-accessible_folders
 * @param array $personal        Session user-personal_folders
 * @param array $denied          Session user-no_access_folders
 * @param array $foreignPersonal Session user-forbiden_personal_folders
 *
 * @return bool True when the folder may authorize access to its items
 */
function itemAccessFolderIsInScope(
    int $folderId,
    array $accessible,
    array $personal,
    array $denied,
    array $foreignPersonal
): bool {
    if ($folderId <= 0) {
        return false;
    }

    // Session arrays mix string and integer IDs
    $contains = static fn (array $ids): bool => in_array($folderId, array_map('intval', $ids), true);

    if ($contains($denied) || $contains($foreignPersonal)) {
        return false;
    }

    return $contains($accessible) || $contains($personal);
}
