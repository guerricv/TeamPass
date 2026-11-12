# Secure Send API

Secure Send uses the existing TeamPass API authentication, HTTPS policy, network
ACL, rate limiting and per-request account/API-grant validation. Authenticate
through `/api/v1/authorize`, `/api/v1/authorizeToken` or an existing device
credential flow, then send `Authorization: Bearer <jwt>`. No additional password
or private key is accepted by these endpoints. Existing password and PAT sessions
unlock the caller's keys through the same server-side API session mechanism.

The endpoints also support the legacy `/api/secureSend/...` prefix and
`/api/index.php/v1/secureSend/...` when URL rewriting is unavailable. The
machine-readable contract is available at `/api/v1/openapi.json`.

| Endpoint | API grant | Purpose |
| --- | --- | --- |
| `POST /api/v1/secureSend/create` | `allowed_to_create`; also `allowed_to_read` for items | Create an item snapshot or independent note |
| `GET /api/v1/secureSend/list` | `allowed_to_read` | List the current user's active metadata |
| `DELETE /api/v1/secureSend/revoke?id=123` | `allowed_to_delete` | Revoke the current user's send |

All responses use `Cache-Control: no-store`. Errors use the API's
`application/problem+json` envelope. Unsupported methods return `405` with `Allow`.
Never log request bodies, Authorization headers or the secret-bearing creation URL.

## Create an item snapshot

```http
POST /api/v1/secureSend/create
Authorization: Bearer <jwt>
Content-Type: application/json
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000

{"send_type":"item","item_id":123,"days":2,"views":3,"passphrase":"<out-of-band phrase>","public":false}
```

The server checks **current** folder grants/denials, personal-tree ownership,
item restrictions, source availability and the caller's own sharekey. An
administrator has no implicit access to item secrets. Snapshot decryption uses
the existing migration-aware helper. No source item revision is allocated for
this read. A missing or unusable key fails before link creation.

The snapshot copies the same fields as the web: label, password, description,
login and URL. Oversized descriptions may be shortened; credentials are never
silently shortened. An optional `include_totp: true` copies the enabled TOTP
profile under the same web rules. A current read-only account cannot include
it. The recipient sees short-lived OTP codes, never the seed. Attachments and
custom fields are not part of this snapshot format.

Example `201 Created` (illustrative identifiers and URL placeholders only):

```json
{
  "id": 456,
  "url": "https://vault.example.test/index.php?otv=1&code=<lookup>&key=<link-secret>&stamp=1794477600",
  "send_type": "item",
  "item_id": 123,
  "has_passphrase": true,
  "expires_at": 1794650400,
  "max_views": 3,
  "public": false,
  "description_truncated": false
}
```

The URL appears **only in this original response**. Communicate the passphrase
separately. No URL is included in lists, audit events or idempotency records.

## Create an independent note

```http
POST /api/v1/secureSend/create
Authorization: Bearer <jwt>
Content-Type: application/json
Idempotency-Key: 8fe52f47-6f72-4a1a-b0d8-68a01c884d41

{"send_type":"note","payload":{"title":"Example","secret":"<example secret>","note":"For the intended recipient","login":"example","url":"https://example.test"},"days":1,"views":1,"public":false}
```

`secret` or `note` must contain non-whitespace text. All payload fields are
optional strings; omitted strings become empty. Secret/note whitespace is
preserved. Title, login and URL are trimmed as on the web. The response has the
same shape as item creation, with `send_type: "note"` and `item_id: null`.
Notes require `secure_send_allow_notes`; they need no item or unlocked item key.

## Options and policy

Creation accepts a JSON body only, with no query parameters or unknown fields.
`item_id` belongs only to item sends; `payload` belongs only to note sends.
Email invitations (`recipient_email`) are outside this first API lot; existing
web/e-mail flows continue to use the shared creation and delivery helpers.

| Field | Type / default | Rule |
| --- | --- | --- |
| `send_type` | Required `item` or `note` | Determines the payload |
| `days` | Positive integer; configured expiration period | Clamped to `otv_expiration_period` |
| `views` | Positive integer; `1` | Clamped to `secure_send_max_views` |
| `passphrase` | String; empty | At most 1024 UTF-8 bytes, whitespace preserved; must contain non-whitespace text when `secure_send_require_passphrase` is enabled |
| `public` | Boolean; `false` | Uses the administrator's public address rather than the main address |
| `include_totp` | Boolean; `false`, item only | Same explicit opt-in/read-only restrictions as the web |

`otv_is_enabled` gates creation and usable inventory. The configured public
address must be valid and use HTTPS; callers cannot supply an alternative host.
Recipient confirmation, expiration, view limits, passphrase protection, sender
eligibility and source-access revalidation remain the existing web lifecycle.

## Retrying a creation

Use one `Idempotency-Key` per user intent and keep it for all retries. Its syntax
matches item mutations: 1–128 visible ASCII characters without spaces. The
identity is scoped to the authenticated user and `secureSend.create`. The
fingerprint covers the normalized request, including note/phrase values and
requested options, using the existing server-secret HMAC; raw keys and bodies
are never persisted. Item contents are resolved server-side on the first creation.

An identical retry returns **200**, `Idempotency-Replayed: true` and the original
creation metadata, **without `url`**. It does not recreate or reactivate the send,
consume a view or repeat its audit. Metadata describes the original creation;
it is not proof the link remains usable. Current source access, API grants and
creation policy are rechecked before a replay is accepted.

```json
{"id":456,"send_type":"item","item_id":123,"has_passphrase":true,"expires_at":1794650400,"max_views":3,"public":false,"description_truncated":false}
```

Reusing the key for different input returns `409`. An active processing lease
returns `409` with `Retry-After`; retry with the same key after that delay. Stale
leases can be reclaimed. Link, lifecycle audit and reservation completion share
one transaction, so a failed audit/completion leaves no send. Replay retention
uses `offline_sync_window_days` (default 90 days, `0` unlimited); after cleanup,
an old key can represent a new request. Without a key, retries can create duplicates.

If the first response was lost, the URL cannot be recovered. Retry with the same
key to identify the committed send, revoke that id, then use a **new key** for a
replacement. Do not loop on new keys after ambiguous network failures.

## List and revoke

`GET /api/v1/secureSend/list?limit=50&offset=0` returns newest-first metadata and
`X-Total-Count`. `limit` is 1–500; `offset` is nonnegative. Filtering by ownership
and current source access happens before pagination. An empty page is `200` + `[]`.

```json
[{"id":456,"send_type":"item","item_id":123,"has_passphrase":true,"suspended":false,"remaining_views":3,"max_views":3,"expires_at":1794650400}]
```

The inventory follows **My secure sends**: expired, exhausted, unavailable-source
and inaccessible-source sends are omitted. Notes or unprotected sends suspended
by current policy remain listed with `suspended: true`. When Secure Send is
globally disabled the list is empty. It contains no labels, note contents, lookup
codes, protected keys, URLs or passphrases. There is no API access to another
sender's inventory, including for administrators.

`DELETE /api/v1/secureSend/revoke?id=456` returns `200`:

```json
{"id":456,"revoked":true}
```

Ownership is checked under a row lock; revocation and its audit commit together.
Revocation remains available when Secure Send/notes are disabled or source access
was lost, provided global API access and the delete grant remain enabled.
Unknown, already revoked and foreign ids all return the same `404`. Repeating
the DELETE cannot repeat a revocation event. A share URL is never management
authorization.

## Deployment and validation

No new setting, table, dependency or client change is required. Use the normal
TeamPass installation/upgrade procedure so the existing `otv`, `secure_send_audit`
and `api_idempotency` schemas are present. Do not provision real installation
secrets just to run tests.

Regression coverage: `tests/Unit/Api/ApiSecureSendTest.php` exercises real SQL
transactions in SQLite and Defuse encryption, per-token AES key unwrapping,
shared source checks, ownership, policy, transport, metadata-only retries and
rollback after audit/completion failures. Source ACL and RSA migration internals
have their own existing suites. SQLite tests do not prove MariaDB row-lock races;
exercise concurrent requests on an isolated MySQL/MariaDB instance as well.
