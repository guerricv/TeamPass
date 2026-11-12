<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright (c) 2009-2026 Teampass.net
 * Licensed under the GNU General Public License, version 3 or later.
 * See <https://www.gnu.org/licenses/>.
 */

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ApiSecureSendTest extends TestCase
{
    private SecureSendModel $model;
    private array $settings;
    private array $user;
    private string $log;
    private string $previousLog;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'tp-secure-api-');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
        require_once __DIR__ . '/../../Fixtures/secure_send_api_db.php';
        require_once __DIR__ . '/../../../app/api/Controller/Api/BaseController.php';
        require_once __DIR__ . '/../../../app/api/Controller/Api/SecureSendController.php';
        require_once __DIR__ . '/../../../app/sources/secure_send.functions.php';
        require_once __DIR__ . '/../../../app/api/inc/encryption_utils.php';
        if (!defined('API_ROOT_PATH')) { define('API_ROOT_PATH', realpath(__DIR__ . '/../../../app/api')); }
        DB::$connection = new SQLite3(':memory:');
        DB::$connection->enableExceptions(true);
        DB::$instanceKey = \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
        foreach ([
            'users (id INTEGER PRIMARY KEY, admin INTEGER DEFAULT 0, read_only INTEGER DEFAULT 0, disabled INTEGER DEFAULT 0, deleted_at INTEGER, name TEXT, lastname TEXT, public_key TEXT)',
            'items (id INTEGER PRIMARY KEY, id_tree INTEGER, inactif INTEGER DEFAULT 0, deleted_at INTEGER, label TEXT, description TEXT, login TEXT, url TEXT, pw TEXT, pw_len INTEGER, pw_iv TEXT)',
            'sharekeys_items (increment_id INTEGER, object_id INTEGER, user_id INTEGER, share_key TEXT)',
            'items_otp (item_id INTEGER, enabled INTEGER, secret TEXT, algorithm TEXT, digits INTEGER, period INTEGER)',
            'otv (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER, send_type TEXT, timestamp INTEGER, originator INTEGER, code TEXT, encrypted TEXT, protected_key TEXT, has_passphrase INTEGER, failed_attempts INTEGER, views INTEGER DEFAULT 0, time_limit INTEGER, max_views INTEGER, shared_globaly INTEGER)',
            'secure_send_audit (record TEXT)',
            'api_idempotency (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, operation TEXT, key_hash TEXT, request_fingerprint TEXT, status TEXT, owner_token_hash TEXT, resource_id INTEGER, http_status INTEGER, response_body TEXT, created_at INTEGER, updated_at INTEGER, locked_until INTEGER, expires_at INTEGER, UNIQUE(user_id, operation, key_hash))',
            'api_sessions (id INTEGER PRIMARY KEY, user_id INTEGER, jti TEXT, key_tempo TEXT, encrypted_private_key TEXT, session_aes_key TEXT, expires_at INTEGER, revoked_at INTEGER, last_used_at INTEGER)',
            'api (increment_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT DEFAULT "user", enabled INTEGER DEFAULT 1, allowed_to_create INTEGER DEFAULT 1, allowed_to_secure_send INTEGER DEFAULT 0, encrypted_private_key TEXT, session_key TEXT, session_aes_key TEXT)',
            'misc (type TEXT, intitule TEXT, valeur TEXT, UNIQUE(type, intitule))',
        ] as $ddl) { DB::$connection->exec('CREATE TABLE email_test_' . $ddl); }
        DB::insert(prefixTable('users'), ['id' => 42, 'public_key' => 'api-public-canary']);
        DB::insert(prefixTable('users'), ['id' => 43, 'public_key' => 'other-public']);
        DB::insert(prefixTable('api'), ['user_id' => 42, 'allowed_to_secure_send' => 1]);
        DB::insert(prefixTable('api'), ['user_id' => 43, 'allowed_to_secure_send' => 1]);
        DB::insert(prefixTable('misc'), ['type' => 'admin', 'intitule' => 'api_secure_send_enabled', 'valeur' => '1']);
        DB::insert(prefixTable('items'), ['id' => 123, 'id_tree' => 7, 'label' => 'R&amp;D O&#039;Brien &quot;x&quot; &lt;y&gt;',
            'description' => 'snapshot-description', 'login' => 'alice', 'url' => 'https://example.test', 'pw' => 'encrypted', 'pw_len' => 22, 'pw_iv' => '']);
        DB::insert(prefixTable('sharekeys_items'), ['increment_id' => 9, 'object_id' => 123, 'user_id' => 42, 'share_key' => 'wrapped']);
        $aes = random_bytes(32);
        DB::insert(prefixTable('api_sessions'), ['id' => 1, 'user_id' => 42, 'jti' => 'device-a', 'key_tempo' => 'session-reference',
            'encrypted_private_key' => encrypt_with_session_key('api-private-canary', $aes),
            'session_aes_key' => base64_encode($aes), 'expires_at' => time() + 3600]);
        $this->settings = ['otv_is_enabled' => 1, 'secure_send_allow_notes' => 1, 'otv_expiration_period' => 7,
            'secure_send_max_views' => 5, 'cpassman_url' => 'https://vault.example.test/team', 'otv_subdomain' => 'https://share.example.test'];
        $this->user = ['id' => 42, 'key_tempo' => 'session-reference', 'jti' => 'device-a',
            'allowed_to_create' => 1, 'allowed_to_read' => 1, 'allowed_to_delete' => 1];
        $this->model = new class extends SecureSendModel {
            protected function idempotencyModel(): ApiIdempotencyModel { return new ApiIdempotencyModel('test-hmac-key', 90); }
        };
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        unlink($this->log);
    }

    private function create(array $input, ?string $key = null): array
    {
        return $this->model->create($this->model->normalizeCreation($input), $this->user, $this->settings, $key);
    }

    private function note(): array { return ['send_type' => 'note', 'payload' => ['secret' => " note-canary \n", 'note' => 'R&D <example>']]; }

    public function testNoteUsesRealWrappedEncryptionAndAdministratorLimits(): void
    {
        $result = $this->create($this->note() + ['days' => 99, 'views' => 99, 'passphrase' => ' phrase-canary ', 'public' => true]);
        self::assertSame(201, $result['status']);
        self::assertStringStartsWith('https://share.example.test/index.php?', $result['body']['url']);
        self::assertSame(5, $result['body']['max_views']);
        self::assertEqualsWithDelta(time() + 7 * 86400, $result['body']['expires_at'], 3);
        $link = DB::queryFirstRow('SELECT * FROM email_test_otv');
        parse_str(parse_url($result['body']['url'], PHP_URL_QUERY), $credentials);
        $payload = secureSendDecryptPayload($link, $credentials['key'], ' phrase-canary ', $this->settings);
        self::assertSame(" note-canary \n", $payload['secret']);
        self::assertSame('R&D <example>', $payload['note']);
        self::assertStringNotContainsString('note-canary', json_encode($link));
        $journal = DB::queryFirstField('SELECT record FROM email_test_secure_send_audit');
        foreach (['note-canary', 'phrase-canary', $credentials['key'], $credentials['code']] as $secret) {
            self::assertStringNotContainsString($secret, $journal);
        }
    }

    public function testSnapshotUsesThePerTokenKeyAndMigrationAwareSharedDecryptor(): void
    {
        $result = $this->create(['send_type' => 'item', 'item_id' => 123]);
        parse_str(parse_url($result['body']['url'], PHP_URL_QUERY), $credentials);
        $link = DB::queryFirstRow('SELECT * FROM email_test_otv');
        self::assertSame('item_v2', $link['send_type']);
        $payload = secureSendDecryptPayload($link, $credentials['key'], '', $this->settings);
        self::assertSame(' item-password-canary ', $payload['password']);
        self::assertSame('R&amp;D O&#039;Brien &quot;x&quot; &lt;y&gt;', $payload['label']);
        DB::update(prefixTable('items'), ['description' => 'changed'], 'id=%i', 123);
        self::assertSame('snapshot-description', secureSendDecryptPayload($link, $credentials['key'], '', $this->settings)['description']);
    }

    public function testReplayNeverStoresOrReturnsTheUrlAndDoesNotRepeatTheAudit(): void
    {
        $first = $this->create($this->note(), 'network-intent');
        $second = $this->create($this->note(), 'network-intent');
        self::assertSame(200, $second['status']);
        self::assertSame($first['body']['id'], $second['body']['id']);
        self::assertArrayNotHasKey('url', $second['body']);
        self::assertSame(['Idempotency-Replayed: true'], $second['headers']);
        self::assertSame(1, DB::queryFirstField('SELECT COUNT(*) FROM email_test_otv'));
        self::assertSame(1, DB::queryFirstField('SELECT COUNT(*) FROM email_test_secure_send_audit'));
        $record = json_encode(DB::queryFirstRow('SELECT * FROM email_test_api_idempotency'));
        parse_str(parse_url($first['body']['url'], PHP_URL_QUERY), $credentials);
        foreach (['note-canary', 'network-intent', $first['body']['url'], $credentials['key'], $credentials['code']] as $secret) {
            self::assertStringNotContainsString($secret, $record);
        }
        self::assertSame(409, $this->create(['send_type' => 'note', 'payload' => ['note' => 'changed']], 'network-intent')['status']);
        DB::update(prefixTable('api_idempotency'), ['status' => 'processing', 'locked_until' => time() + 60], 'id=%i', 1);
        $busy = $this->create($this->note(), 'network-intent');
        self::assertSame(409, $busy['status']);
        self::assertStringStartsWith('Retry-After: ', $busy['headers'][0]);
        $adapter = new ApiIdempotencyModel('test-hmac-key', 90);
        $stale = $adapter->reserve(42, ApiIdempotencyModel::OPERATION_SECURE_SEND_CREATE,
            'interrupted-before-write', $this->model->normalizeCreation($this->note()));
        DB::update(prefixTable('api_idempotency'), ['locked_until' => time() - 1], 'id=%i', $stale['id']);
        self::assertSame(201, $this->create($this->note(), 'interrupted-before-write')['status']);
    }

    public function testLinkAuditAndReservationCompletionRollbackTogether(): void
    {
        foreach (['secure_send_audit', 'api_idempotency'] as $table) {
            // Failing completion is injected by a SQL trigger, after the link/audit INSERTs.
            if ($table === 'api_idempotency') {
                DB::$connection->exec("CREATE TRIGGER fail_completion BEFORE UPDATE ON email_test_api_idempotency WHEN NEW.status='completed' BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
            } else { DB::$failTable = prefixTable($table); }
            try { $this->create($this->note(), 'rollback'); self::fail('Expected rollback'); }
            catch (Exception $e) { self::assertNotSame('', $e->getMessage()); }
            self::assertSame(0, DB::queryFirstField('SELECT COUNT(*) FROM email_test_otv'));
            self::assertSame(0, DB::queryFirstField('SELECT COUNT(*) FROM email_test_secure_send_audit'));
            self::assertSame(0, DB::queryFirstField('SELECT COUNT(*) FROM email_test_api_idempotency'));
            DB::$failTable = '';
        }
    }

    public function testManagementIsOwnerScopedAndRevocationWorksWhenDisabled(): void
    {
        $first = $this->create($this->note());
        $id = $first['body']['id'];
        self::assertFalse($this->model->revoke($id, 43, $this->settings));
        self::assertSame([], $this->model->listOwned(43, $this->settings, 50, 0)['sends']);
        $this->settings['secure_send_allow_notes'] = 0;
        $inventory = $this->model->listOwned(42, $this->settings, 50, 0);
        self::assertTrue($inventory['sends'][0]['suspended']);
        foreach (['url', 'code', 'key', 'protected_key', 'encrypted', 'payload', 'passphrase'] as $field) {
            self::assertArrayNotHasKey($field, $inventory['sends'][0]);
        }
        $this->settings['otv_is_enabled'] = 0;
        self::assertTrue($this->model->revoke($id, 42, $this->settings));
        self::assertFalse($this->model->revoke($id, 42, $this->settings));
        self::assertSame(2, DB::queryFirstField('SELECT COUNT(*) FROM email_test_secure_send_audit'));
    }

    public function testApiGrantsAndItemAccessAreIndependentAndCurrent(): void
    {
        foreach (['admin', 'access', 'sharekey', 'session'] as $denial) {
            DB::update(prefixTable('users'), ['admin' => (int) ($denial === 'admin')], 'id=%i', 42);
            DB::$access = $denial !== 'access';
            if ($denial === 'sharekey') { DB::delete(prefixTable('sharekeys_items'), 'user_id=%i', 42); }
            if ($denial === 'session') { DB::update(prefixTable('api_sessions'), ['revoked_at' => time()], 'id=%i', 1); }
            try { $this->create(['send_type' => 'item', 'item_id' => 123]); self::fail('Denied item was shared'); }
            catch (InvalidArgumentException $e) { self::assertContains($e->getMessage(), ['not_allowed', 'cannot_decrypt']); }
        }
        self::assertSame(0, DB::queryFirstField('SELECT COUNT(*) FROM email_test_otv'));
        $this->user['allowed_to_read'] = 0;
        self::assertFalse($this->model->isAllowed($this->user, 'create', 'item'));
        self::assertTrue($this->model->isAllowed($this->user, 'create', 'note'));
        $this->user['allowed_to_create'] = 0;
        self::assertFalse($this->model->isAllowed($this->user, 'create', 'note'));
    }

    public function testMalformedAndOutOfScopeFieldsAreRejected(): void
    {
        foreach ([['originator' => 43], ['recipient_email' => 'example@example.test'], ['days' => []], ['days' => 1.5],
            ['public' => 'true'], ['passphrase' => null], ['payload' => ['secret' => []]], ['include_totp' => true]] as $extra) {
            try { $this->model->normalizeCreation(array_replace($this->note(), $extra)); self::fail('Malformed body accepted'); }
            catch (InvalidArgumentException $e) { self::assertSame('invalid_payload', $e->getMessage()); }
        }
        $this->settings['secure_send_require_passphrase'] = 1;
        $this->expectExceptionMessage('passphrase_required');
        $this->create($this->note());
    }

    /** Exercise the real controller with explicit HTTP requests and the SQL-backed model. */
    private function dispatch(string $action, Request $request, ?array $user = null): array
    {
        $controller = new class($request, $this->settings, $this->model) extends SecureSendController {
            public array $result = [];
            public function __construct(private Request $incoming, private array $config, private SecureSendModel $adapter) {}
            protected function request(): Request { return $this->incoming; }
            protected function settings(): array { return $this->config; }
            protected function model(): SecureSendModel { return $this->adapter; }
            protected function markApiFunctionalActivity(array $userData): void {}
            protected function sendOutput($data, array $httpHeaders = []): void
            {
                $this->result = ['body' => json_decode($data, true, 512, JSON_THROW_ON_ERROR), 'headers' => $httpHeaders];
            }
        };
        $controller->{$action . 'Action'}($user ?? $this->user);
        return $controller->result;
    }

    public function testControllerEnforcesMethodsJsonBodyAndApiGrants(): void
    {
        $wrongMethod = $this->dispatch('create', Request::create('/api/v1/secureSend/create', 'GET'));
        self::assertSame(405, $wrongMethod['body']['status']);
        self::assertContains('Allow: POST', $wrongMethod['headers']);
        $query = $this->dispatch('create', Request::create('/?passphrase=canary', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($this->note())));
        self::assertSame(400, $query['body']['status']);
        self::assertStringNotContainsString('canary', json_encode($query));
        foreach (['{broken', '[]', '{"send_type":"note","payload":{"note":false}}'] as $body) {
            $response = $this->dispatch('create', Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body));
            self::assertContains($response['body']['status'], [400, 422]);
        }
        $denied = $this->dispatch('create', Request::create('/', 'POST'), array_replace($this->user, ['allowed_to_create' => 0]));
        self::assertSame(403, $denied['body']['status']);
        $key = $this->dispatch('create', Request::create('/', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'bad key'], json_encode($this->note())));
        self::assertSame(400, $key['body']['status']);
        self::assertSame(0, DB::queryFirstField('SELECT COUNT(*) FROM email_test_otv'));
    }

    public function testControllerCreatesListsAndRevokesWithoutExposingCredentials(): void
    {
        $created = $this->dispatch('create', Request::create('/', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($this->note())));
        self::assertContains('HTTP/1.1 201 Created', $created['headers']);
        self::assertArrayHasKey('url', $created['body']);
        $listed = $this->dispatch('list', Request::create('/?limit=1&offset=0', 'GET'));
        self::assertContains('X-Total-Count: 1', $listed['headers']);
        self::assertSame($created['body']['id'], $listed['body'][0]['id']);
        self::assertStringNotContainsString('canary', json_encode($listed));
        $page = $this->dispatch('list', Request::create('/?offset=1', 'GET'));
        self::assertSame([], $page['body']);
        self::assertSame(400, $this->dispatch('list', Request::create('/?limit[]=1', 'GET'))['body']['status']);
        $foreign = $this->dispatch('revoke', Request::create('/?id=1', 'DELETE'), array_replace($this->user, ['id' => 43]));
        $unknown = $this->dispatch('revoke', Request::create('/?id=999', 'DELETE'));
        self::assertSame($unknown['body'], $foreign['body']);
        self::assertSame(404, $foreign['body']['status']);
        self::assertSame(['id' => 1, 'revoked' => true], $this->dispatch('revoke', Request::create('/?id=1', 'DELETE'))['body']);
    }

    public function testReplayRemainsOneTimeAfterRevocationAndUserScopesDoNotCollide(): void
    {
        $first = $this->create($this->note(), 'per-user-key');
        self::assertTrue($this->model->revoke($first['body']['id'], 42, $this->settings));
        $replayed = $this->create($this->note(), 'per-user-key');
        self::assertSame(200, $replayed['status']);
        self::assertArrayNotHasKey('url', $replayed['body']);
        self::assertSame(0, DB::queryFirstField('SELECT COUNT(*) FROM email_test_otv'));
        $this->user['id'] = 43;
        self::assertSame(201, $this->create($this->note(), 'per-user-key')['status']);
    }

    public function testCurrentPolicyAndSourceAccessAreRecheckedOnRetry(): void
    {
        $this->create(['send_type' => 'item', 'item_id' => 123], 'source-retry');
        DB::$access = false;
        try { $this->create(['send_type' => 'item', 'item_id' => 123], 'source-retry'); self::fail('Access loss ignored'); }
        catch (InvalidArgumentException $e) { self::assertSame('not_allowed', $e->getMessage()); }
        $this->create($this->note(), 'note-retry');
        $this->settings['secure_send_allow_notes'] = 0;
        try { $this->create($this->note(), 'note-retry'); self::fail('Policy loss ignored'); }
        catch (InvalidArgumentException $e) { self::assertSame('notes_not_allowed', $e->getMessage()); }
        $this->settings['otv_is_enabled'] = 0;
        $this->expectExceptionMessage('not_allowed');
        $this->create($this->note());
    }

    public function testTotpOptInUsesCurrentReadOnlyStateAndNeverReturnsTheSeed(): void
    {
        $seed = 'JBSWY3DPEHPK3PXP';
        DB::insert(prefixTable('items_otp'), ['item_id' => 123, 'enabled' => 1,
            'secret' => cryption($seed, '', 'encrypt')['string'], 'algorithm' => 'sha1', 'digits' => 6, 'period' => 30]);
        foreach ([0, 1] as $readOnly) {
            DB::update(prefixTable('users'), ['read_only' => $readOnly], 'id=%i', 42);
            $created = $this->create(['send_type' => 'item', 'item_id' => 123, 'include_totp' => true]);
            parse_str(parse_url($created['body']['url'], PHP_URL_QUERY), $credentials);
            $link = DB::queryFirstRow('SELECT * FROM email_test_otv WHERE id=%i', $created['body']['id']);
            $payload = secureSendDecryptPayload($link, $credentials['key'], '', $this->settings);
            self::assertSame($readOnly === 0, isset($payload['otp_code']));
            self::assertArrayNotHasKey('totp', $payload);
            self::assertStringNotContainsString($seed, json_encode($created));
        }
    }

    public function testBothCreationSwitchesDefaultOffAndIgnoreClaimsOrCachedSettings(): void
    {
        foreach (['global_off', 'global_absent', 'user_off', 'user_absent'] as $case) {
            DB::update(prefixTable('misc'), ['valeur' => '1'], 'intitule=%s', 'api_secure_send_enabled');
            DB::update(prefixTable('api'), ['allowed_to_secure_send' => 1], 'user_id=%i', 42);
            if ($case === 'global_off') {
                DB::update(prefixTable('misc'), ['valeur' => '0'], 'intitule=%s', 'api_secure_send_enabled');
            } elseif ($case === 'global_absent') {
                DB::delete(prefixTable('misc'), 'intitule=%s', 'api_secure_send_enabled');
            } elseif ($case === 'user_off') {
                DB::insert(prefixTable('misc'), ['type' => 'admin', 'intitule' => 'api_secure_send_enabled', 'valeur' => '1']);
                DB::update(prefixTable('api'), ['allowed_to_secure_send' => 0], 'user_id=%i', 42);
            } else { DB::delete(prefixTable('api'), 'user_id=%i', 42); }
            // These plausible stale/client-controlled values must never grant sharing.
            $this->user['allowed_to_secure_send'] = 1;
            $this->settings['api_secure_send_enabled'] = 1;
            $result = $this->dispatch('create', Request::create('/', 'POST', [], [], [],
                ['CONTENT_TYPE' => 'application/json'], json_encode($this->note())));
            self::assertSame(403, $result['body']['status'], $case);
            self::assertSame(0, DB::queryFirstField('SELECT COUNT(*) FROM email_test_otv'));
            self::assertSame(0, DB::queryFirstField('SELECT COUNT(*) FROM email_test_secure_send_audit'));
        }
    }

    public function testPermissionRemovalBlocksReplayButKeepsManagementAndWebCreation(): void
    {
        $created = $this->create($this->note(), 'consent-retry');
        foreach (['user', 'global'] as $gate) {
            if ($gate === 'user') { DB::update(prefixTable('api'), ['allowed_to_secure_send' => 0], 'user_id=%i', 42); }
            else {
                DB::update(prefixTable('api'), ['allowed_to_secure_send' => 1], 'user_id=%i', 42);
                DB::update(prefixTable('misc'), ['valeur' => '0'], 'intitule=%s', 'api_secure_send_enabled');
            }
            try { $this->create($this->note(), 'consent-retry'); self::fail('Sharing was not revoked'); }
            catch (InvalidArgumentException $e) { self::assertSame('not_allowed', $e->getMessage()); }
            self::assertSame(1, $this->model->listOwned(42, $this->settings, 50, 0)['total']);
        }
        self::assertTrue($this->model->revoke($created['body']['id'], 42, $this->settings));
        $web = secureSendPrepareCreation(['send_type' => 'note', 'payload' => ['note' => 'web note']], 42, '', '', false, $this->settings);
        self::assertGreaterThan(0, secureSendStoreLink($web['link'], $this->settings));
    }

    public function testMissingGrantColumnFailsClosedWithoutBreakingManagement(): void
    {
        DB::$connection->exec('ALTER TABLE email_test_api DROP COLUMN allowed_to_secure_send');
        self::assertFalse(secureSendApiCanCreate(42));
        self::assertSame([], $this->model->listOwned(42, $this->settings, 50, 0)['sends']);
        self::assertFalse($this->model->revoke(123, 42, $this->settings));
    }

    public function testPermissionMigrationIsRepeatableAndPreservesOptIns(): void
    {
        DB::$connection->exec('ALTER TABLE email_test_api DROP COLUMN allowed_to_secure_send');
        DB::delete(prefixTable('misc'), 'intitule=%s', 'api_secure_send_enabled');
        secureSendApiUpgrade();
        self::assertSame(0, DB::queryFirstField('SELECT allowed_to_secure_send FROM email_test_api WHERE user_id=42'));
        self::assertSame('0', DB::queryFirstRow('SELECT valeur FROM email_test_misc')['valeur']);
        DB::update(prefixTable('api'), ['allowed_to_secure_send' => 1], 'user_id=%i', 42);
        DB::update(prefixTable('misc'), ['valeur' => '1'], 'intitule=%s', 'api_secure_send_enabled');
        secureSendApiUpgrade();
        self::assertTrue(secureSendApiCanCreate(42));
        self::assertSame(1, DB::queryFirstRow('SELECT COUNT(*) AS total FROM email_test_misc')['total']);
    }

    public function testPermissionToggleRejectsCoercionAndUnknownValues(): void
    {
        foreach ([0, 1, '0', '1'] as $value) { self::assertSame((int) $value, secureSendApiToggleValue($value)); }
        foreach ([null, false, true, 1.0, '01', 'true', 2, [], '1 OR 1'] as $value) {
            try { secureSendApiToggleValue($value); self::fail('Invalid permission accepted'); }
            catch (InvalidArgumentException $e) { self::assertSame('invalid_permission', $e->getMessage()); }
        }
    }
}
