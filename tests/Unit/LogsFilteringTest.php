<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/vendor/sergeytsalkov/meekrodb/db.class.php';
require_once __DIR__ . '/../../app/sources/logs_filter_logic.php';

/**
 * Exercise the shipped log predicates against disposable rows, including actual deletions.
 * SQLite is only an in-memory test fixture; production continues to use MySQL/MariaDB.
 */
class LogsFilteringTest extends TestCase
{
    private SQLite3 $database;
    private MeekroDB $parser;

    protected function setUp(): void
    {
        $this->database = new SQLite3(':memory:');
        $this->database->enableExceptions(true);
        // Keep MeekroDB's real placeholder/WhereClause handling, with SQLite string quoting.
        $this->parser = new class extends MeekroDB {
            public function escape($value)
            {
                return "'" . SQLite3::escapeString((string) $value) . "'";
            }
        };
        $this->database->exec('CREATE TABLE log_system (id INTEGER PRIMARY KEY, date INTEGER, type TEXT, label TEXT, qui TEXT, field_1 TEXT)');
        $this->database->exec('CREATE TABLE log_items (id INTEGER PRIMARY KEY, date INTEGER, id_user INTEGER, id_item INTEGER, action TEXT, raison TEXT)');
        $this->database->exec('CREATE TABLE items (id INTEGER, label TEXT, id_tree INTEGER)');
        $this->database->exec('CREATE TABLE users (id INTEGER, login TEXT, name TEXT, lastname TEXT)');
        $this->database->exec('CREATE TABLE nested_tree (id INTEGER, title TEXT, personal_folder INTEGER)');
    }

    protected function tearDown(): void
    {
        $this->database->close();
    }

    private function query(string $query, mixed ...$args): SQLite3Result
    {
        return $this->database->query($this->parser->parse($query, ...$args));
    }

    private function remainingIds(string $table): array
    {
        $result = $this->query('SELECT id FROM %l ORDER BY id', $table);
        $ids = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $ids[] = $row['id'];
        }
        return $ids;
    }

    private function purge(string $type, int $userId, string $action = 'all', ?string $login = null): void
    {
        $scope = buildLogsPurgeFilter($type, 100, 200, $userId, $action, $login);
        self::assertNotNull($scope);
        $this->query('DELETE FROM %l WHERE %l', $scope['table'], $scope['where']);
    }

    private function searchItems(mixed $column, string $searchValue): array
    {
        // Execute the actual handler's filtering block so a disconnected helper cannot pass.
        $source = file_get_contents(__DIR__ . '/../../app/sources/logs.datatables.php');
        $start = strpos($source, '//Columns name', strpos($source, '/* ITEMS */'));
        $end = strpos($source, '// Get the total number of records', $start);
        $params = ['search' => ['column' => $column], 'order' => [['column' => 0]]];
        eval(substr($source, $start, $end - $start));
        $result = $this->query(
            'SELECT l.id FROM log_items l JOIN items i ON i.id = l.id_item
            JOIN users u ON u.id = l.id_user JOIN nested_tree t ON t.id = i.id_tree WHERE %l ORDER BY l.id',
            $sWhere
        );
        $ids = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $ids[] = $row['id'];
        }
        return $ids;
    }

    public function testUserSearchExcludesMatchesInOtherColumnsAndIncludesDisplayedNames(): void
    {
        $this->database->exec("INSERT INTO users VALUES (42, 'alice', 'Alice', 'Example'), (43, 'bob', 'Robert', 'Example')");
        $this->database->exec("INSERT INTO items VALUES (10, 'VPN', 1), (20, 'alice portal', 1)");
        $this->database->exec("INSERT INTO nested_tree VALUES (1, 'General', 0)");
        $this->database->exec("INSERT INTO log_items VALUES (1, 150, 42, 10, 'at_shown', ''), (2, 151, 43, 20, 'at_shown', '')");

        self::assertSame([1, 2], $this->searchItems('all', 'alice'));
        self::assertSame([1], $this->searchItems('u.login', 'alice'));
        self::assertSame([2], $this->searchItems('i.label', 'alice'));
        self::assertSame([2], $this->searchItems('u.login', 'Robert'));
        self::assertSame([1, 2], $this->searchItems('u.login', 'Example'));
        self::assertSame([], $this->searchItems('t.title', 'alice'));
        self::assertSame([1, 2], $this->searchItems('u.login', ''));
    }

    public function testColumnSelectionOnlyAcceptsTheUiAllowList(): void
    {
        foreach (['l.date', 'i.id', 'i.label', 't.title', 'l.action', 'l.raison', 't.personal_folder'] as $column) {
            self::assertSame([$column], getItemLogSearchColumns($column));
        }
        foreach ([null, [], 'i.label) OR 1=1 --', 'u.name', 'unknown'] as $invalid) {
            self::assertSame(getItemLogSearchColumns('all'), getItemLogSearchColumns($invalid));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function systemTabs(): iterable
    {
        yield 'connections' => ['connections', 'user_connection'];
        yield 'errors' => ['errors', 'error'];
        yield 'admin actions' => ['admin', 'admin_action'];
        yield 'user management' => ['admin', 'user_mngt'];
    }

    #[DataProvider('systemTabs')]
    public function testSystemPurgeKeepsOtherUsersDatesAndTypes(string $tab, string $type): void
    {
        foreach ([[1, 100, $type, '42'], [2, 150, $type, '43'], [3, 150, 'other', '42'],
            [4, 99, $type, '42'], [5, 199, $type, '42'], [6, 200, $type, '42'], [7, 150, $type, '42.1.2.3']] as $row) {
            $this->query('INSERT INTO log_system (id, date, type, qui) VALUES (%i, %i, %s, %s)', ...$row);
        }
        $scope = buildLogsPurgeFilter($tab, 100, 200, 42, 'all');
        self::assertStringContainsString("qui = '42'", $this->parser->parse('%l', $scope['where']));
        $this->purge($tab, 42);
        self::assertSame([2, 3, 4, 6, 7], $this->remainingIds('log_system'));
        $this->purge($tab, -1);
        self::assertSame([3, 4, 6], $this->remainingIds('log_system'));
    }

    public function testItemAndCopyPurgeCombineUserActionAndDates(): void
    {
        $this->database->exec("INSERT INTO log_items (id, date, id_user, action) VALUES
            (1, 150, 42, 'at_copy'), (2, 150, 43, 'at_copy'), (3, 150, 42, 'at_shown'),
            (4, 99, 42, 'at_copy'), (5, 200, 42, 'at_copy')");
        $this->purge('copy', 42);
        self::assertSame([2, 3, 4, 5], $this->remainingIds('log_items'));
        $this->purge('items', -1, 'at_shown');
        self::assertSame([2, 4, 5], $this->remainingIds('log_items'));
        $this->purge('items', 43);
        self::assertSame([4, 5], $this->remainingIds('log_items'));
    }

    public function testFailedLoginPurgeUsesExactLoginAndRecognizedApiMarkerInsteadOfIp(): void
    {
        foreach ([[1, 'alice', 'password_is_not_correct'], [2, 'bob', 'password_is_not_correct'],
            [3, 'alice | tp_src=api', 'api_invalid_credentials'], [4, 'alice2', 'password_is_not_correct'],
            [5, 'alice | tp_src=api', 'password_is_not_correct'], [6, 'alice | tp_src=api', 'bruteforce_account_locked']] as $row) {
            $this->query("INSERT INTO log_system (id, date, type, field_1, label, qui) VALUES (%i, 150, 'failed_auth', %s, %s, '192.0.2.1')", ...$row);
        }
        $this->database->exec("INSERT INTO log_system VALUES (7, 200, 'failed_auth', 'password_is_not_correct', '192.0.2.1', 'alice')");
        $this->purge('failed', 42, 'all', 'alice');
        self::assertSame([2, 4, 5, 7], $this->remainingIds('log_system'));
        $this->purge('failed', -1);
        self::assertSame([7], $this->remainingIds('log_system'));
    }

    public function testLoginMetacharactersCannotBroadenThePurge(): void
    {
        $login = "a'_% OR 1=1 --";
        $this->query("INSERT INTO log_system VALUES (1, 150, 'failed_auth', 'password_is_not_correct', '192.0.2.1', %s)", $login);
        $this->database->exec("INSERT INTO log_system VALUES (2, 150, 'failed_auth', 'password_is_not_correct', '192.0.2.1', 'alice')");
        $this->purge('failed', 42, 'all', $login);
        self::assertSame([2], $this->remainingIds('log_system'));
    }

    public function testInvalidOrUnresolvablePurgeScopeIsRejected(): void
    {
        foreach (['unknown', 'kb', 'authentication_lockouts'] as $tab) {
            self::assertNull(buildLogsPurgeFilter($tab, 100, 200, -1, 'all'));
        }
        foreach ([0, -2] as $userId) {
            self::assertNull(buildLogsPurgeFilter('items', 100, 200, $userId, 'all'));
        }
        self::assertNull(buildLogsPurgeFilter('items', 200, 100, -1, 'all'));
        self::assertNull(buildLogsPurgeFilter('items', 100, 200, -1, 'unknown'));
        self::assertNull(buildLogsPurgeFilter('errors', 100, 200, -1, 'at_copy'));
        self::assertNull(buildLogsPurgeFilter('failed', 100, 200, 42, 'all'));
        self::assertNull(buildLogsPurgeFilter('failed', 100, 200, 42, 'all', ''));
    }

    public function testDateRangeIncludesTheLastDayAndHandlesDst(): void
    {
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Paris');
            foreach (['2026-09-08' => 24, '2026-03-29' => 23, '2026-10-25' => 25] as $day => $hours) {
                [$start, $end] = getLogsPurgeDateRange($day, $day);
                self::assertSame($hours * 3600, $end - $start);
                self::assertSame($day . ' 23:59:59', date('Y-m-d H:i:s', $end - 1));
            }
        } finally {
            date_default_timezone_set($timezone);
        }
        foreach ([['', ''], [null, null], [[], '2026-09-08'], ['2026-02-30', '2026-03-01'],
            ['2026-09-09', '2026-09-08'], ['2026-9-8', '2026-09-08'], ['today', 'tomorrow'], ["2026-09-08\0", '2026-09-08']] as [$start, $end]) {
            self::assertNull(getLogsPurgeDateRange($start, $end));
        }
    }
}
