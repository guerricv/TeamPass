<?php

declare(strict_types=1);

/**
 * Database-free filtering rules for the monitoring logs.
 *
 * @license GPL-3.0
 */

/**
 * Resolve an item-log search column against the columns offered by the page.
 * The User column displays the login and full name, so all three fields are searched.
 *
 * @return string[]
 */
function getItemLogSearchColumns(mixed $column): array
{
    $columns = ['l.date', 'i.id', 'i.label', 't.title', 'u.login', 'l.action'];
    if ($column === 'u.login') {
        return ['u.login', 'u.name', 'u.lastname'];
    }
    if (is_string($column) && in_array($column, $columns, true)) {
        return [$column];
    }

    return array_merge($columns, ['u.name', 'u.lastname']);
}

/**
 * Parse a calendar date range, including the whole last day in the server's timezone.
 *
 * @return array{0: int, 1: int}|null Start inclusive, end exclusive
 */
function getLogsPurgeDateRange(mixed $start, mixed $end): ?array
{
    if (!is_string($start) || !is_string($end)) {
        return null;
    }
    try {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    } catch (ValueError $error) {
        return null;
    }
    if ($startDate === false || $endDate === false
        || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end
        || $startDate > $endDate
    ) {
        return null;
    }

    return [$startDate->getTimestamp(), $endDate->modify('+1 day')->getTimestamp()];
}

/**
 * Build the exact deletion scope for a monitoring tab, without accessing a database.
 * An invalid scope must never fall back to purging all users or all log types.
 *
 * @return array{table: string, where: WhereClause}|null
 */
function buildLogsPurgeFilter(
    string $type,
    int $start,
    int $end,
    int $userId,
    string $action,
    ?string $userLogin = null
): ?array
{
    if ($end <= $start || ($userId !== -1 && $userId <= 0)) {
        return null;
    }

    $where = new WhereClause('AND');
    $where->add('date >= %i AND date < %i', $start, $end);
    $table = 'log_system';
    $userColumn = 'qui';

    switch ($type) {
        case 'items':
            $table = 'log_items';
            $userColumn = 'id_user';
            if ($action !== 'all') {
                $actions = ['at_creation', 'at_modification', 'at_shown', 'at_export', 'at_restored', 'at_delete', 'at_copy', 'at_moved'];
                if (!in_array($action, $actions, true)) {
                    return null;
                }
                $where->add('action = %s', $action);
            }
            break;
        case 'copy':
            $table = 'log_items';
            $userColumn = 'id_user';
            $where->add('action = %s', 'at_copy');
            break;
        case 'connections':
            $where->add('type = %s', 'user_connection');
            break;
        case 'errors':
            $where->add('type = %s', 'error');
            break;
        case 'admin':
            $where->add('type IN %ls', ['admin_action', 'user_mngt']);
            break;
        case 'failed':
            $where->add('type = %s', 'failed_auth');
            break;
        default:
            return null;
    }

    if ($type !== 'items' && $action !== 'all') {
        return null;
    }
    if ($userId !== -1) {
        if ($type === 'failed') {
            if ($userLogin === null || $userLogin === '') {
                return null;
            }
            // Failed authentications store the submitted login in field_1, and the IP in qui.
            // The API appends a marker; only API-compatible labels may match that variant.
            $userWhere = $where->addClause('OR');
            $userWhere->add('field_1 = %s', $userLogin);
            $userWhere->add(
                '(field_1 = %s AND label IN %ls)',
                $userLogin . ' | tp_src=api',
                ['api_invalid_credentials', 'api_invalid_apikey', 'api_invalid_token', 'api_token_decrypt_failed', 'bruteforce_account_locked']
            );
        } else {
            // qui is also used for IP addresses: compare as text to avoid MySQL numeric coercion.
            if ($userColumn === 'qui') {
                $where->add('qui = %s', (string) $userId);
            } else {
                $where->add('id_user = %i', $userId);
            }
        }
    }

    return ['table' => $table, 'where' => $where];
}
