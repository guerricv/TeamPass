<?php

declare(strict_types=1);

namespace TeamPassTests\FolderCache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Stubs/folder_cache_runtime.php';

/** Regression coverage for #5330, executing the production cache and folder lifecycle. */
class FolderCacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        SessionManager::$active = false;
        SessionManager::$values = ['user-id' => 7, 'user-tree_last_refresh_timestamp' => 1000];
        ConfigManager::$settings = ['subfolder_rights_as_parent' => 1, 'duplicate_folder' => 1];
        DB::$events = [];
        DB::$failRoleInsert = false;
        DB::$resultCount = 1;
        DB::$tables = [
            'cache_tree' => [],
            'nested_tree' => [10 => ['title' => 'Parent', 'parent_id' => 0, 'personal_folder' => 0, 'bloquer_creation' => 0, 'bloquer_modification' => 0]],
            'roles_values' => [['role_id' => 2, 'folder_id' => 10, 'type' => 'W']],
            'users_roles' => [['user_id' => 7, 'role_id' => 2], ['user_id' => 8, 'role_id' => 2]],
            'users_groups' => [],
            'misc' => ['last_folder_change' => 1000],
        ];
        foreach ([1, 7, 8, 9] as $userId) {
            DB::$tables['cache_tree'][$userId] = [
                'increment_id' => $userId, 'user_id' => $userId,
                'data' => '[{"id":"li_10","text":"Parent"}]',
                'visible_folders' => '[{"id":10,"title":"Parent","disabled":0}]',
                'folders' => '[10]', 'timestamp' => 1000, 'invalidated_at' => 0,
            ];
        }
    }

    private function createFolder(array $overrides = []): array
    {
        $lang = new class { public function get(string $key): string { return $key; } };
        return (new FolderManager($lang))->createNewFolder(array_merge([
            'title' => 'New folder', 'parent_id' => 10, 'personal_folder' => 0,
            'complexity' => 0, 'icon' => 'folder', 'icon_selected' => 'folder-open',
            'access_rights' => 'W', 'user_is_admin' => 0, 'user_is_manager' => 1,
            'user_can_create_root_folder' => 0, 'user_id' => 7, 'user_roles' => '2',
            'user_accessible_folders' => [10],
        ], $overrides), [
            'rebuildFolderTree' => true, 'manageFolderPermissions' => true,
            'refreshCacheForUsersWithSimilarRoles' => true,
        ]);
    }

    public function testManagerCreationInvalidatesOwnAndOtherRoleUsersEvenInSameSecond(): void
    {
        $unrelated = DB::$tables['cache_tree'][9];
        self::assertSame(['error' => false, 'newId' => 11], $this->createFolder());
        foreach ([1, 7, 8] as $userId) {
            self::assertTrue(loadTreeStrategy(1000, $userId, 0)['state']);
            foreach (['data', 'visible_folders', 'folders'] as $field) {
                self::assertSame('[]', DB::$tables['cache_tree'][$userId][$field]);
            }
        }
        self::assertSame($unrelated, DB::$tables['cache_tree'][9]);
        self::assertFalse(loadFoldersListByCache('visible_folders', 'folders')['state']);
        self::assertLessThan(array_search('rebuild', DB::$events), array_search('commit', DB::$events));
        self::assertLessThan(array_search('cache_update', DB::$events), array_search('rebuild', DB::$events));
    }

    public function testPersonalCreationWithoutRolesInvalidatesOwner(): void
    {
        DB::$tables['nested_tree'][10]['personal_folder'] = 1;
        DB::$tables['roles_values'] = [];
        DB::$tables['users_roles'] = [];
        SessionManager::$active = true;
        self::assertFalse($this->createFolder(['personal_folder' => 1, 'user_roles' => '', 'user_is_manager' => 0])['error']);
        self::assertTrue(loadTreeStrategy(1000, 7, 0)['state']);
        self::assertSame([11], SessionManager::$values['user-personal_folders']);
        self::assertSame([], DB::$tables['roles_values']);
        self::assertFalse(loadTreeStrategy(1000, 8, 0)['state']);
    }

    public function testRootCreationKeepsCreatorRolesAndDoesNotStartApiSession(): void
    {
        self::assertFalse($this->createFolder(['parent_id' => 0, 'user_can_create_root_folder' => 1])['error']);
        self::assertContains(['role_id' => '2', 'folder_id' => 11, 'type' => 'W'], DB::$tables['roles_values']);
        self::assertTrue(loadTreeStrategy(1000, 7, 0)['state']);
        self::assertArrayNotHasKey('user-accessible_folders', SessionManager::$values);
        self::assertFalse(SessionManager::$active);
    }

    public function testAdminCreationWithoutAssignedRolesDoesNotGrantAccess(): void
    {
        $roles = DB::$tables['roles_values'];
        self::assertFalse($this->createFolder(['parent_id' => 0, 'user_id' => 1, 'user_is_admin' => 1, 'user_roles' => ''])['error']);
        self::assertSame($roles, DB::$tables['roles_values']);
        self::assertTrue(loadTreeStrategy(1000, 1, 0)['state']);
    }

    public static function permissionTypes(): array
    {
        return array_map(static fn ($type) => [$type], ['W', 'R', 'ND', 'NE', 'NDNE']);
    }

    #[DataProvider('permissionTypes')]
    public function testInheritedPermissionTypesArePreserved(string $type): void
    {
        DB::$tables['roles_values'][0]['type'] = $type;
        self::assertFalse($this->createFolder()['error']);
        self::assertContains(['role_id' => 2, 'folder_id' => 11, 'type' => $type], DB::$tables['roles_values']);
        self::assertCount(2, DB::$tables['roles_values']);
    }

    public function testDisabledInheritanceUsesCreatorsSelectedPermission(): void
    {
        ConfigManager::$settings['subfolder_rights_as_parent'] = 0;
        self::assertFalse($this->createFolder(['access_rights' => 'R'])['error']);
        self::assertContains(['role_id' => '2', 'folder_id' => 11, 'type' => 'R'], DB::$tables['roles_values']);
    }

    public function testInheritanceKeepsEveryRoleAndItsRestriction(): void
    {
        DB::$tables['roles_values'][] = ['role_id' => 3, 'folder_id' => 10, 'type' => 'R'];
        self::assertFalse($this->createFolder(['user_roles' => '2;3'])['error']);
        $childPermissions = array_values(array_filter(DB::$tables['roles_values'], static fn ($row) => $row['folder_id'] === 11));
        self::assertSame([
            ['role_id' => 2, 'folder_id' => 11, 'type' => 'W'],
            ['role_id' => 3, 'folder_id' => 11, 'type' => 'R'],
        ], $childPermissions);
    }

    public function testStandardUserStillNeedsCreationPermission(): void
    {
        $before = DB::$tables;
        self::assertTrue($this->createFolder(['user_is_manager' => 0])['error']);
        self::assertSame($before, DB::$tables);
        ConfigManager::$settings['enable_user_can_create_folders'] = 1;
        self::assertFalse($this->createFolder(['user_is_manager' => 0])['error']);
        self::assertTrue(loadTreeStrategy(1000, 7, 0)['state']);
    }

    public function testCreationWithNoCacheDoesNotPublishAnIncompleteFolderList(): void
    {
        unset(DB::$tables['cache_tree'][7]);
        self::assertFalse($this->createFolder()['error']);
        self::assertArrayNotHasKey(7, DB::$tables['cache_tree']);
        self::assertTrue(loadTreeStrategy(1000, 7, 0)['state']);
    }

    public function testDeniedCreationLeavesCacheAndPermissionsUntouched(): void
    {
        $before = DB::$tables;
        self::assertTrue($this->createFolder(['user_accessible_folders' => []])['error']);
        self::assertSame($before, DB::$tables);
        self::assertSame([], DB::$events);
    }

    public function testFailedPermissionWriteRollsBackWithoutInvalidation(): void
    {
        $before = DB::$tables;
        DB::$failRoleInsert = true;
        self::assertTrue($this->createFolder()['db_error']);
        self::assertSame($before, DB::$tables);
        self::assertSame(['begin', 'rollback'], DB::$events);
    }

    public function testBackgroundTaskDiscardsOldTreeWithoutGrantingPermissions(): void
    {
        $roles = DB::$tables['roles_values'];
        $unrelated = DB::$tables['cache_tree'][9];
        performVisibleFoldersHtmlUpdate(7);
        self::assertTrue(loadTreeStrategy(1000, 7, 0)['state']);
        self::assertFalse(loadFoldersListByCache('visible_folders', 'folders')['state']);
        self::assertSame('[]', DB::$tables['cache_tree'][7]['folders']);
        self::assertSame($unrelated, DB::$tables['cache_tree'][9]);
        self::assertSame($roles, DB::$tables['roles_values']);
        self::assertNotContains('rebuild', DB::$events);
    }

    public function testBackgroundTaskWithoutCacheLeavesFirstLoadToNormalReaders(): void
    {
        unset(DB::$tables['cache_tree'][7]);
        performVisibleFoldersHtmlUpdate(7);
        self::assertArrayNotHasKey(7, DB::$tables['cache_tree']);
        self::assertTrue(loadTreeStrategy(1000, 7, 0)['state']);
    }

    public function testDirectGrantUserIsInvalidatedWithoutAnyRoles(): void
    {
        DB::$tables['users_groups'] = [['user_id' => 9, 'group_id' => 10]];
        invalidateCacheForFolderUsers(10);
        self::assertTrue(loadTreeStrategy(1000, 9, 0)['state']);
        self::assertSame('[]', DB::$tables['cache_tree'][9]['folders']);
    }

    public function testCacheMissPreservesPersonalDescendantsWithoutRoles(): void
    {
        DB::$tables['nested_tree'][11] = ['title' => 'Private child', 'parent_id' => 10, 'personal_folder' => 0];
        SessionManager::$values['user-accessible_folders'] = ['10', '11'];
        SessionManager::$values['user-personal_folders'] = ['10', '11'];
        DB::$tables['users_roles'] = [];
        performVisibleFoldersHtmlUpdate(7);
        $result = getUserVisibleFolders(7);
        self::assertSame([10, 11], array_column($result, 'id'));
        self::assertSame([1, 1], array_column($result, 'perso'));
        self::assertSame([0, 0], array_column($result, 'disabled'));
    }

    public function testCacheMissPreservesReadOnlyAndExcludesForbiddenAndForeignFolders(): void
    {
        foreach ([11, 12] as $id) {
            DB::$tables['nested_tree'][$id] = ['title' => 'Excluded', 'parent_id' => 0, 'personal_folder' => 1];
        }
        SessionManager::$values['user-accessible_folders'] = [10, 11, 12];
        SessionManager::$values['user-no_access_folders'] = ['11'];
        SessionManager::$values['user-forbiden_personal_folders'] = ['12'];
        SessionManager::$values['user-read_only_folders'] = ['10'];
        invalidateUserFolderCache([7]);
        $result = getUserVisibleFolders(7);
        self::assertSame([10], array_column($result, 'id'));
        self::assertSame(1, $result[0]['disabled']);
        self::assertSame(1, $result[0]['is_visible_active']);
        self::assertSame(0, $result[0]['perso']);
    }

    public function testCacheMissUsesCurrentScopeWithoutAddingRoleOrPersonalFolders(): void
    {
        // Covers revoked/disabled personal access and a direct grant already resolved by core.php.
        SessionManager::$values['user-accessible_folders'] = [];
        invalidateUserFolderCache([7]);
        self::assertSame([], getUserVisibleFolders(7));
        SessionManager::$values['user-accessible_folders'] = [10];
        DB::$tables['users_roles'] = [];
        self::assertSame([10], array_column(getUserVisibleFolders(7), 'id'));
        self::assertSame([], buildVisibleFoldersOnTheFly(8));
    }

    public function testApiPartialWriteCannotRevalidateAnOldWebTree(): void
    {
        $this->createFolder();
        // API writes only its folder IDs and the shared freshness metadata.
        DB::update(prefixTable('cache_tree'), ['folders' => '[10,11]', 'timestamp' => 1000, 'invalidated_at' => 0], 'user_id = %i', 7);
        self::assertTrue(loadTreeStrategy(1000, 7, 0)['state']);
        self::assertFalse(loadFoldersListByCache('visible_folders', 'folders')['state']);
    }

    public function testDropdownRefreshOnMissingCacheDoesNotBecomeTreeData(): void
    {
        unset(DB::$tables['cache_tree'][7]);
        $dropdown = '[{"id":11,"title":"New folder","disabled":1}]';
        cacheTreeUserHandler(7, $dropdown, [], 'visible_folders');
        self::assertSame($dropdown, DB::$tables['cache_tree'][7]['visible_folders']);
        self::assertSame('[]', DB::$tables['cache_tree'][7]['data']);
        self::assertTrue(loadTreeStrategy(1000, 7, 0)['state']);
    }

    public function testRebuiltTreeIsReusedUntilNextInvalidation(): void
    {
        $this->createFolder();
        $freshTree = '[{"id":"li_10"},{"id":"li_11"}]';
        $freshDropdown = '[{"id":10},{"id":11}]';
        cacheTreeUserHandler(7, $freshTree, [], '', $freshDropdown);
        $result = loadTreeStrategy(1000, 7, 0);
        self::assertFalse($result['state']);
        self::assertSame($freshTree, $result['data']);
        self::assertSame($freshDropdown, loadFoldersListByCache('visible_folders', 'folders')['data']);
        self::assertTrue(loadTreeStrategy(1000, 7, 1)['state']);
        invalidateUserFolderCache([7]);
        self::assertTrue(loadTreeStrategy(1000, 7, 0)['state']);
    }

    public function testRebuiltDropdownPreservesReadOnlyAndDisabledAncestorMetadata(): void
    {
        $tree = [
            10 => (object) ['title' => 'Parent', 'parent_id' => 0, 'nlevel' => 1, 'personal_folder' => 0],
            11 => (object) ['title' => 'New folder', 'parent_id' => 10, 'nlevel' => 2, 'personal_folder' => 0],
        ];
        $result = buildVisibleFoldersFromTree([['id' => 'li_10'], ['id' => 'li_11']], $tree, [
            'visibleFolders' => [11], 'readOnlyFolders' => [11], 'userId' => 7, 'userLogin' => 'Manager',
        ]);
        self::assertSame([1, 1], array_column($result, 'disabled'));
        self::assertSame([0, 1], array_column($result, 'is_visible_active'));
        self::assertSame('Parent', $result[1]['path']);
        self::assertSame(2, $result[1]['level']);
    }

    public function testInvalidUserIdsDoNotInvalidateUnrelatedRows(): void
    {
        $before = DB::$tables;
        invalidateUserFolderCache([0, -1, '']);
        self::assertSame($before, DB::$tables);
        self::assertSame([], DB::$events);
    }
}
