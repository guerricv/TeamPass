<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright (c) 2009-2026 Teampass.net
 * Licensed under the GNU General Public License, version 3 or later.
 * See <https://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/secure_send_access.php';
require_once __DIR__ . '/secure_send_snapshot.php';
require_once __DIR__ . '/secure_send_input.php';
require_once __DIR__ . '/secure_send_url.php';
require_once __DIR__ . '/secure_send_storage.php';
require_once __DIR__ . '/secure_send_email.php';


/**
 * Prepare an encrypted send under the same policy for web and API callers.
 *
 * Callers supply only their authenticated identity and unlocked keys, never an
 * originator from request data. Session/CSRF or JWT/API rights belong to callers.
 * The returned URL is ephemeral: do not persist or log it outside the sealed email task.
 * No link or audit is written here. Persist with secureSendStoreLink().
 *
 * @return array{link:array, url:string, delivery:?array, description_truncated:bool}
 * @throws InvalidArgumentException When policy, source access or input is invalid
 */
function secureSendPrepareCreation(
    array $dataReceived,
    int $userId,
    string $privateKey,
    string $publicKey,
    bool $readOnly,
    array $SETTINGS
): array {
    if ((int) ($SETTINGS['otv_is_enabled'] ?? 0) !== 1 || $userId <= 0) {
        throw new InvalidArgumentException('not_allowed');
    }
    secureSendValidateInput($dataReceived);
    $secureSendRecipient = null;
    if (array_key_exists('recipient_email', $dataReceived)) {
        if ((int) ($SETTINGS['secure_send_allow_email'] ?? 0) !== 1) {
            throw new InvalidArgumentException('email_not_allowed');
        }
        try {
            $secureSendRecipient = secureSendEmailRecipient($dataReceived['recipient_email']);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('invalid_email');
        }
    }

    // Determine the kind of send: an existing item or an ad-hoc note/secret
    $secureSendType = (isset($dataReceived['send_type']) === true && $dataReceived['send_type'] === 'note') ? 'note' : 'item';
    if ($secureSendType === 'note' && (int) ($SETTINGS['secure_send_allow_notes'] ?? 0) !== 1) {
        throw new InvalidArgumentException('notes_not_allowed');
    }

    // Optional recipient passphrase (transmitted out-of-band by the sender)
    $secureSendPassphrase = (string) ($dataReceived['passphrase'] ?? '');
    if ((int) ($SETTINGS['secure_send_require_passphrase'] ?? 0) === 1 && trim($secureSendPassphrase) === '') {
        throw new InvalidArgumentException('passphrase_required');
    }

    $secureSendLimits = secureSendLimits($SETTINGS, $dataReceived, time());

    // Build the plaintext payload to share
    $secureSendDescriptionTruncated = false;
    if ($secureSendType === 'item') {
        // Item sends keep a coherent encrypted copy of all displayed fields.
        $secureSendItemId = (int) ($dataReceived['id'] ?? 0);
        $itemQ = secureSendReadItem($secureSendItemId, $userId);
        if ($itemQ === []) {
            throw new InvalidArgumentException('not_allowed');
        }
        try {
            $secureSendPlaintext = secureSendItemPassword(
                $itemQ,
                $userId,
                $privateKey,
                $publicKey
            );
            $snapshot = secureSendEncodeSnapshot(
                $itemQ,
                $secureSendPlaintext,
                secureSendShouldIncludeTotp(
                    $dataReceived,
                    $readOnly
                ) ? secureSendItemTotp($secureSendItemId) : null
            );
            $secureSendPlaintext = $snapshot['plaintext'];
            $secureSendDescriptionTruncated = $snapshot['description_truncated'];
            $secureSendType = 'item_v2';
        } catch (InvalidArgumentException $e) {
            $secureSendError = $e->getMessage();
            throw new InvalidArgumentException(in_array($secureSendError, ['invalid_payload', 'totp_unusable'], true)
                ? $secureSendError : 'cannot_decrypt');
        }
    } else {
        // Note send: self-contained encrypted JSON, not bound to any item
        $secureSendItemId = null;
        $secureSendPayload = (isset($dataReceived['payload']) === true && is_array($dataReceived['payload']) === true)
            ? $dataReceived['payload']
            : array();
        if (trim((string) ($secureSendPayload['secret'] ?? '')) === ''
            && trim((string) ($secureSendPayload['note'] ?? '')) === ''
        ) {
            throw new InvalidArgumentException('empty_note');
        }
        $secureSendPlaintext = json_encode(
            array(
                'title'  => trim((string) ($secureSendPayload['title'] ?? '')),
                'secret' => (string) ($secureSendPayload['secret'] ?? ''),
                'note'   => (string) ($secureSendPayload['note'] ?? ''),
                'login'  => trim((string) ($secureSendPayload['login'] ?? '')),
                'url'    => trim((string) ($secureSendPayload['url'] ?? '')),
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    // Generate the lookup code and the link secret carried in the URL.
    // The Defuse key that encrypts the payload is wrapped by the link secret
    // (and, when set, the passphrase) so the URL alone is not enough to decrypt.
    $otv_code = GenerateCryptKey(32, false, true, true, false, true);
    $secureSendLinkSecret = GenerateCryptKey(32, false, true, true, false, true);
    $secureSendWrapPassword = $secureSendPassphrase === ''
        ? $secureSendLinkSecret
        : hash('sha256', $secureSendLinkSecret . '|' . $secureSendPassphrase);

    $secureSendProtectedKey = defuse_generate_personal_key($secureSendWrapPassword);
    $secureSendKeyEncoded = defuse_validate_personal_key(
        $secureSendWrapPassword,
        $secureSendProtectedKey
    );

    // Encrypt the payload with DEFUSE using the wrapped key
    $passwd = cryption(
        $secureSendPlaintext,
        $secureSendKeyEncoded,
        'encrypt',
        $SETTINGS
    );
    if (!empty($passwd['error']) || strlen($passwd['string']) > 65535) {
        throw new InvalidArgumentException('invalid_payload');
    }
    $timestampReference = time();

    // Item copies and standalone notes use the same administrator-controlled address.
    // The public address is opt-in: a request that does not ask for it gets the main URL.
    $secureSendShared = (int) ($dataReceived['shared_globaly'] ?? 0) === 1 ? 1 : 0;
    $url = secureSendUrl($SETTINGS, $secureSendShared === 1, [
        'otv' => 1, 'code' => $otv_code, 'key' => $secureSendLinkSecret, 'stamp' => $timestampReference,
    ]);
    return [
        'link' => [
            'id' => null,
            'item_id' => $secureSendItemId,
            'send_type' => $secureSendType,
            'timestamp' => $timestampReference,
            'originator' => $userId,
            'code' => $otv_code,
            'encrypted' => $passwd['string'],
            'protected_key' => $secureSendProtectedKey,
            'has_passphrase' => $secureSendPassphrase === '' ? 0 : 1,
            'failed_attempts' => 0,
            'time_limit' => $secureSendLimits['time_limit'],
            'max_views' => $secureSendLimits['views'],
            'shared_globaly' => $secureSendShared,
        ],
        'url' => $url,
        'delivery' => $secureSendRecipient === null ? null : ['recipient' => $secureSendRecipient, 'url' => $url],
        'description_truncated' => $secureSendDescriptionTruncated,
    ];
}

/** Best-effort maintenance must never block an otherwise valid creation. */
function secureSendCreationCleanup(array $settings): void
{
    try {
        secureSendPurgeExpiredLinks($settings);
        secureSendEmailPruneMetadata(time());
    } catch (Throwable $e) {
        error_log('TEAMPASS Secure Send cleanup failed (' . get_class($e) . ')');
    }
}
