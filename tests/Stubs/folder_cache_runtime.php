<?php

declare(strict_types=1);

namespace TeamPassTests\FolderCache;

/**
 * Run the production declarations without bootstrapping a web request or a real DB.
 * The namespace isolates the doubles from the production autoloader and other tests.
 * No cache or permission algorithm is copied into this fixture.
 */
function loadDeclaration(string $path, int $kind, string $name): void
{
    $tokens = token_get_all((string) file_get_contents(__DIR__ . '/../../' . $path));
    foreach ($tokens as $start => $token) {
        if (!is_array($token) || $token[0] !== $kind) {
            continue;
        }
        $next = $start + 1;
        while (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }
        if (!is_array($tokens[$next]) || $tokens[$next][1] !== $name) {
            continue;
        }
        $source = '';
        $depth = 0;
        $opened = false;
        for ($index = $start; $index < count($tokens); $index++) {
            $part = $tokens[$index];
            $source .= is_array($part) ? $part[1] : $part;
            if ($part === '{' || (is_array($part) && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $opened = true;
            } elseif ($part === '}') {
                $depth--;
                if ($opened && $depth === 0) {
                    eval('namespace ' . __NAMESPACE__ . '; use Throwable; ' . $source);
                    return;
                }
            }
        }
    }
    throw new \RuntimeException('Production declaration not found: ' . $name);
}

function prefixTable(string $name): string { return 'custom_' . $name; }
function loadClasses(string $name): void {}
function time(): int { return 1000; }
function session_status(): int { return SessionManager::$active ? PHP_SESSION_ACTIVE : PHP_SESSION_NONE; }
function getUsersWithRoles(array $roles): array { return [8]; }
function error_log(string $message): bool { return true; }

class SessionManager
{
    public static bool $active = false;
    public static array $values = [];
    public static function getSession(): self { return new self(); }
    public function get(string $key) { return self::$values[$key] ?? null; }
    public static function addRemoveFromSessionArray(string $key, array $ids, string $action): void
    {
        self::$values[$key] = array_unique(array_merge(self::$values[$key] ?? [], $ids));
    }
}

class NestedTree
{
    public function __construct(...$arguments) {}
    public function rebuild(): void { DB::$events[] = 'rebuild'; }
}

class ConfigManager
{
    public static array $settings = [];
    public function getAllSettings(): array { return self::$settings; }
}

class DB
{
    public static array $tables = [];
    public static array $events = [];
    public static array $transaction = [];
    public static bool $failRoleInsert = false;
    public static int $resultCount = 1;

    private static function table(string $name): string
    {
        if (!str_starts_with($name, 'custom_')) {
            throw new \RuntimeException('Unprefixed table: ' . $name);
        }
        return substr($name, 7);
    }

    public static function startTransaction(): void
    {
        self::$transaction = self::$tables;
        self::$events[] = 'begin';
    }
    public static function commit(): void { self::$events[] = 'commit'; }
    public static function rollback(): void { self::$tables = self::$transaction; self::$events[] = 'rollback'; }
    public static function insertId(): int { return 11; }
    public static function count(): int { return self::$resultCount; }

    public static function insert(string $table, array $row): void
    {
        $name = self::table($table);
        if ($name === 'roles_values' && self::$failRoleInsert) {
            throw new \RuntimeException('Simulated permission write failure');
        }
        if ($name === 'cache_tree') {
            $row['increment_id'] = $row['user_id'];
            self::$tables[$name][$row['user_id']] = $row;
        } elseif ($name === 'nested_tree') {
            self::$tables[$name][11] = $row;
        } else {
            self::$tables[$name][] = $row;
        }
    }

    public static function update(string $table, array $fields, string $where, ...$args): void
    {
        $name = self::table($table);
        if ($name === 'misc') {
            self::$tables['misc']['last_folder_change'] = $fields['valeur'];
            return;
        }
        if ($name !== 'cache_tree' || !in_array($where, ['user_id IN %li', 'user_id = %i', 'increment_id = %i'], true)) {
            throw new \RuntimeException('Unexpected update: ' . $table . ' ' . $where);
        }
        self::$events[] = 'cache_update';
        foreach ((array) $args[0] as $id) {
            if (isset(self::$tables['cache_tree'][$id])) {
                self::$tables['cache_tree'][$id] = array_merge(self::$tables['cache_tree'][$id], $fields);
            }
        }
    }

    public static function queryFirstRow(string $sql, ...$args): ?array
    {
        if (str_contains($sql, 'custom_cache_tree')) {
            return self::$tables['cache_tree'][(int) $args[0]] ?? null;
        }
        if (str_contains($sql, 'custom_nested_tree')) {
            $row = self::$tables['nested_tree'][(int) $args[0]] ?? null;
            self::$resultCount = $row === null ? 0 : 1;
            return $row;
        }
        if (str_contains($sql, 'custom_misc')) {
            self::$resultCount = 1;
            return ['valeur' => str_contains($sql, 'SELECT valeur FROM') ? self::$tables['misc']['last_folder_change'] : 0];
        }
        if (str_contains($sql, 'custom_background_tasks')) {
            return ['count' => 0];
        }
        throw new \RuntimeException('Unexpected SELECT: ' . $sql);
    }

    public static function queryFirstColumn(string $sql, ...$args): array
    {
        if (str_contains($sql, 'custom_users_groups')) {
            return array_column(array_filter(self::$tables['users_groups'], static fn ($row) => $row['group_id'] === $args[0]), 'user_id');
        }
        if (str_contains($sql, 'custom_users_roles')) {
            $roles = array_column(array_filter(self::$tables['roles_values'], static fn ($row) => $row['folder_id'] === $args[0]), 'role_id');
            return array_column(array_filter(self::$tables['users_roles'], static fn ($row) => in_array($row['role_id'], $roles)), 'user_id');
        }
        if (str_contains($sql, 'custom_users') && str_contains($sql, 'admin = 1')) {
            return [1];
        }
        throw new \RuntimeException('Unexpected column SELECT: ' . $sql);
    }

    public static function query(string $sql, ...$args): array
    {
        if (str_starts_with($sql, 'INSERT INTO %l')) {
            self::table($args[0]);
            return [];
        }
        if (str_contains($sql, 'custom_roles_values')) {
            return array_values(array_filter(self::$tables['roles_values'], static fn ($row) => $row['folder_id'] === $args[0]));
        }
        throw new \RuntimeException('Unexpected query: ' . $sql);
    }
}

foreach (['cacheTreeUserHandler', 'invalidateUserFolderCache', 'invalidateCacheForFolderUsers', 'loadFoldersListByCache'] as $function) {
    loadDeclaration('app/sources/main.functions.php', T_FUNCTION, $function);
}
foreach (['loadTreeStrategy', 'buildVisibleFoldersFromTree'] as $function) {
    loadDeclaration('app/sources/tree.php', T_FUNCTION, $function);
}
loadDeclaration('app/scripts/background_tasks___functions.php', T_FUNCTION, 'performVisibleFoldersHtmlUpdate');
loadDeclaration('app/sources/folders.class.php', T_CLASS, 'FolderManager');
loadDeclaration('app/sources/items.queries.php', T_FUNCTION, 'getUserVisibleFolders');
loadDeclaration('app/sources/items.queries.php', T_FUNCTION, 'buildVisibleFoldersOnTheFly');
