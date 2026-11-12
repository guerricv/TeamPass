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

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SecureSendApiAdministrationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Fixtures/secure_send_api_admin_dependencies.php';
        require_once __DIR__ . '/../../app/sources/secure_send_api_permissions.php';
        require_once __DIR__ . '/../../app/sources/secret_settings_logic.php';
        require_once __DIR__ . '/../../app/sources/storage_folder_logic.php';
        DB::$connection = new SQLite3(':memory:');
        DB::$connection->enableExceptions(true);
        DB::$connection->exec('CREATE TABLE email_test_api (increment_id INTEGER PRIMARY KEY, user_id INTEGER, allowed_to_secure_send INTEGER DEFAULT 0)');
        DB::$connection->exec('INSERT INTO email_test_api (increment_id, user_id) VALUES (1, 42), (2, 43)');
        DB::$connection->exec('CREATE TABLE email_test_misc (type TEXT, intitule TEXT, valeur TEXT, created_at TEXT, updated_at TEXT, UNIQUE(type, intitule))');
        DB::insert(prefixTable('misc'), ['type' => 'admin', 'intitule' => 'api_secure_send_enabled', 'valeur' => '0']);
    }

    /** Run the real handler body; adapt only bootstrapping, encrypted transport and audit transport. */
    private function dispatch(string $action, array $payload, int $admin = 1, string $key = 'session-key'): array
    {
        $source = (string) file_get_contents(__DIR__ . '/../../app/sources/admin.queries.php');
        $start = strpos($source, "case '" . $action . "':");
        $end = strpos($source, "\n    case ", $start + 1);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $handler = substr($source, $start, $end - $start);
        $handler = str_replace('__DIR__', var_export(realpath(__DIR__ . '/../../app/sources'), true), $handler);
        $handler = str_replace("require_once 'main.functions.php';", '', $handler);
        $handler = str_replace('ConfigManager::', '\\TeampassClasses\\ConfigManager\\ConfigManager::', $handler);
        $session = new class($admin) {
            public function __construct(private int $admin) {}
            public function get(string $name): int|string { return match ($name) {
                'user-admin' => $this->admin, 'user-id' => 99, 'user-login' => 'fixture-admin', default => 'session-key',
            }; }
        };
        $lang = new class { public function get(string $name): string { return $name; } };
        $SETTINGS = [];
        $post_key = $key;
        $post_data = json_encode($payload, JSON_THROW_ON_ERROR);
        ob_start();
        try {
            eval('switch (true) { case true: ' . $handler . ' }');
            return json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
        } finally { ob_end_clean(); }
    }

    public function testOnlyAnAdministratorWithTheSessionKeyCanGrantSharing(): void
    {
        $payload = ['increment_id' => 2, 'field' => 'allowed_to_secure_send', 'value' => 1];
        foreach ([[0, 'session-key'], [1, 'wrong-key']] as [$admin, $key]) {
            self::assertTrue($this->dispatch('save_user_change', $payload, $admin, $key)['error']);
            self::assertSame(0, DB::queryFirstField('SELECT allowed_to_secure_send FROM email_test_api WHERE user_id=43'));
        }
        self::assertFalse($this->dispatch('save_user_change', $payload)['error']);
        self::assertSame(1, DB::queryFirstField('SELECT allowed_to_secure_send FROM email_test_api WHERE user_id=43'));
        self::assertSame(0, DB::queryFirstField('SELECT allowed_to_secure_send FROM email_test_api WHERE user_id=42'));
        self::assertFalse($this->dispatch('save_user_change', array_replace($payload, ['value' => '0']))['error']);
        self::assertSame(0, DB::queryFirstField('SELECT allowed_to_secure_send FROM email_test_api WHERE user_id=43'));
    }

    public function testInstanceSwitchRequiresAdminKeyAndBinaryInput(): void
    {
        $payload = ['field' => 'api_secure_send_enabled', 'value' => 1];
        self::assertTrue($this->dispatch('save_option_change', $payload, 0)['error']);
        self::assertTrue($this->dispatch('save_option_change', $payload, 1, 'wrong-key')['error']);
        self::assertTrue($this->dispatch('save_option_change', array_replace($payload, ['value' => 'true']))['error']);
        self::assertSame('0', DB::queryFirstRow('SELECT valeur FROM email_test_misc')['valeur']);
        self::assertFalse($this->dispatch('save_option_change', $payload)['error']);
        self::assertSame('1', DB::queryFirstRow('SELECT valeur FROM email_test_misc')['valeur']);
        self::assertFalse($this->dispatch('save_option_change', array_replace($payload, ['value' => 0]))['error']);
        self::assertSame('0', DB::queryFirstRow('SELECT valeur FROM email_test_misc')['valeur']);
    }

    public function testMissingSchemaAndInvalidGrantsCannotBeSaved(): void
    {
        foreach (['true', 2, -1, null] as $value) {
            self::assertTrue($this->dispatch('save_user_change', ['increment_id' => 1, 'field' => 'allowed_to_secure_send', 'value' => $value])['error']);
        }
        DB::$connection->exec('ALTER TABLE email_test_api DROP COLUMN allowed_to_secure_send');
        self::assertTrue($this->dispatch('save_user_change', ['increment_id' => 1, 'field' => 'allowed_to_secure_send', 'value' => 1])['error']);
        self::assertTrue($this->dispatch('save_option_change', ['field' => 'api_secure_send_enabled', 'value' => 1])['error']);
    }

    public function testInstallerAndUpgradeRequireExplicitOptIn(): void
    {
        $installer = (string) file_get_contents(__DIR__ . '/../../public/install/install-steps/run.step5.php');
        self::assertStringContainsString("array('admin', 'api_secure_send_enabled', '0')", $installer);
        self::assertStringContainsString("`allowed_to_secure_send` int(1) NOT NULL DEFAULT '0'", $installer);
        $upgrade = (string) file_get_contents(__DIR__ . '/../../public/install/upgrade_run_3.2.4.php');
        self::assertLessThan(strpos($upgrade, '// Save upgrade timestamp'), strpos($upgrade, 'secureSendApiUpgrade();'));
    }
}

