<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright (c) 2009-2026 Teampass.net
 * Licensed under the GNU General Public License, version 3 or later.
 * See <https://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../sources/secure_send_creation.php';
require_once __DIR__ . '/../../sources/secure_send_active.php';
require_once __DIR__ . '/ApiIdempotencyModel.php';

/** API adapter for the shared Secure Send business logic. */
class SecureSendModel
{
    /** Validate a JSON request and translate the public contract to the web domain input. */
    public function normalizeCreation(array $input): array
    {
        $allowed = ['send_type', 'item_id', 'payload', 'days', 'views', 'passphrase', 'public', 'include_totp'];
        if (array_diff(array_keys($input), $allowed) !== []
            || !in_array($input['send_type'] ?? null, ['item', 'note'], true)
        ) {
            throw new InvalidArgumentException('invalid_payload');
        }
        foreach (['days', 'views', 'item_id'] as $field) {
            if (array_key_exists($field, $input) && (!is_int($input[$field]) || $input[$field] <= 0)) {
                throw new InvalidArgumentException('invalid_payload');
            }
        }
        foreach (['public', 'include_totp'] as $field) {
            if (array_key_exists($field, $input) && !is_bool($input[$field])) {
                throw new InvalidArgumentException('invalid_payload');
            }
        }
        if (array_key_exists('passphrase', $input) && !is_string($input['passphrase'])) {
            throw new InvalidArgumentException('invalid_payload');
        }
        $normalized = [
            'send_type' => $input['send_type'],
            'passphrase' => $input['passphrase'] ?? '',
            'shared_globaly' => (int) ($input['public'] ?? false),
            'include_totp' => (int) ($input['include_totp'] ?? false),
        ];
        foreach (['days', 'views'] as $field) {
            if (isset($input[$field])) { $normalized[$field] = $input[$field]; }
        }
        if ($input['send_type'] === 'item') {
            if (!isset($input['item_id']) || array_key_exists('payload', $input)) {
                throw new InvalidArgumentException('invalid_payload');
            }
            $normalized['id'] = $input['item_id'];
        } else {
            if (array_key_exists('item_id', $input) || array_key_exists('include_totp', $input)
                || !is_array($input['payload'] ?? null)
                || array_diff(array_keys($input['payload']), ['title', 'secret', 'note', 'login', 'url']) !== []
            ) {
                throw new InvalidArgumentException('invalid_payload');
            }
            foreach ($input['payload'] as $value) {
                if (!is_string($value)) { throw new InvalidArgumentException('invalid_payload'); }
            }
            $normalized['payload'] = array_replace(
                ['title' => '', 'secret' => '', 'note' => '', 'login' => '', 'url' => ''], $input['payload']
            );
        }
        secureSendValidateInput($normalized);
        return $normalized;
    }

    /** Map an operation to its current API grants, independently of source-item permissions. */
    public function isAllowed(array $user, string $action, ?string $sendType = null): bool
    {
        if ((int) ($user['id'] ?? 0) <= 0) { return false; }
        return match ($action) {
            'create' => (int) ($user['allowed_to_create'] ?? 0) === 1
                && ($sendType !== 'item' || (int) ($user['allowed_to_read'] ?? 0) === 1),
            'list' => (int) ($user['allowed_to_read'] ?? 0) === 1,
            'revoke' => (int) ($user['allowed_to_delete'] ?? 0) === 1,
            default => false,
        };
    }

    /**
     * Create one encrypted send; persist only metadata for duplicate suppression.
     *
     * The storage callbacks lock and complete the lease in the link/audit transaction.
     * A lost response cannot recover its URL. Replays identify the original send only.
     * @return array{status:int,body:array,headers:array}
     */
    public function create(array $input, array $user, array $settings, ?string $key = null): array
    {
        if (!$this->isAllowed($user, 'create', $input['send_type'])) {
            throw new InvalidArgumentException('not_allowed');
        }
        $keys = ['private_key' => '', 'public_key' => ''];
        $readOnly = false;
        if ($input['send_type'] === 'item') {
            // Check current authorization before unlocking any source credentials.
            if (secureSendReadItem((int) $input['id'], (int) $user['id']) === []) {
                throw new InvalidArgumentException('not_allowed');
            }
            require_once __DIR__ . '/../inc/jwt_utils.php';
            $keys = get_user_keys((int) $user['id'], (string) $user['key_tempo'], $user['jti'] ?? null);
            if ($keys === null) { throw new InvalidArgumentException('cannot_decrypt'); }
            $readOnly = (int) DB::queryFirstField(
                'SELECT read_only FROM ' . prefixTable('users') . ' WHERE id = %i', (int) $user['id']
            ) === 1;
        }
        // Also revalidate current policy/source access before accepting a replay.
        $creation = secureSendPrepareCreation($input, (int) $user['id'],
            $keys['private_key'], $keys['public_key'], $readOnly, $settings);
        $idempotency = $key === null ? null : $this->idempotencyModel();
        $reservation = $idempotency === null ? null : $idempotency->reserve(
            (int) $user['id'], ApiIdempotencyModel::OPERATION_SECURE_SEND_CREATE, $key, $input
        );
        if (($reservation['state'] ?? '') === 'replay') {
            return ['status' => 200, 'body' => $reservation['response'], 'headers' => ['Idempotency-Replayed: true']];
        }
        if (in_array($reservation['state'] ?? '', ['conflict', 'processing'], true)) {
            return ['status' => 409, 'body' => [], 'headers' => isset($reservation['retry_after'])
                ? ['Retry-After: ' . (int) $reservation['retry_after']] : []];
        }
        $metadata = $this->creationMetadata($creation);
        try {
            secureSendCreationCleanup($settings);
            $id = secureSendStoreLink($creation['link'], $settings, null,
                $idempotency === null ? null : static function () use ($idempotency, $reservation): void {
                    $idempotency->lockReservation($reservation);
                },
                $idempotency === null ? null : static function (int $id) use ($idempotency, $reservation, $metadata): void {
                    $idempotency->completeReservation($reservation, $id, 200, ['id' => $id] + $metadata);
                });
        } catch (Throwable $e) {
            if ($idempotency !== null) {
                try { $idempotency->releaseReservation($reservation); }
                catch (Throwable $releaseError) {
                    error_log('TEAMPASS Secure Send reservation release failed (' . get_class($releaseError) . ')');
                }
            }
            throw $e;
        }
        return ['status' => 201, 'body' => ['id' => $id, 'url' => $creation['url']] + $metadata, 'headers' => []];
    }

    /** Construct the existing persistent idempotency adapter (overridable by isolated tests). */
    protected function idempotencyModel(): ApiIdempotencyModel
    {
        return new ApiIdempotencyModel();
    }

    /** Explicit allowlist for creation responses and stored replay metadata. */
    private function creationMetadata(array $creation): array
    {
        $link = $creation['link'];
        return ['send_type' => $link['send_type'] === 'note' ? 'note' : 'item',
            'item_id' => $link['item_id'], 'has_passphrase' => (bool) $link['has_passphrase'],
            'expires_at' => (int) $link['time_limit'], 'max_views' => (int) $link['max_views'],
            'public' => (bool) $link['shared_globaly'], 'description_truncated' => $creation['description_truncated']];
    }

    /** List only the caller's current inventory, including sends suspended by policy. */
    public function listOwned(int $userId, array $settings, int $limit, int $offset): array
    {
        $rows = secureSendActiveLinks($settings, $userId, null, null, true);
        $sends = [];
        foreach (array_slice($rows, $offset, $limit) as $row) {
            $note = $row['send_type'] === 'note';
            $sends[] = ['id' => (int) $row['id'], 'send_type' => $note ? 'note' : 'item',
                'item_id' => $note ? null : (int) $row['item_id'],
                'has_passphrase' => (bool) $row['has_passphrase'], 'suspended' => $row['suspended'],
                'remaining_views' => max(0, (int) $row['max_views'] - (int) $row['views']),
                'max_views' => (int) $row['max_views'], 'expires_at' => (int) $row['time_limit']];
        }
        return ['sends' => $sends, 'total' => count($rows)];
    }

    /** Revoke under the caller's ownership, even when Secure Send is disabled. */
    public function revoke(int $sendId, int $userId, array $settings): bool
    {
        return secureSendRevokeLink($sendId, $userId, $settings);
    }
}
