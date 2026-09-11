<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/sources/folder_cache_logic.php';

/** Behavioral tests of the DB-free folder cache decisions. */
class FolderCacheLogicTest extends TestCase
{
    /** Mixed database/session IDs must not invalidate anonymous or unrelated users. */
    public function testIdentifiersArePositiveUniqueIntegers(): void
    {
        self::assertSame([7, 8], folderCacheNormalizeIds(['7', 7, 0, -1, '', '8', null]));
        self::assertSame([], folderCacheNormalizeIds([0, -7, null, '']));
    }

    /** An empty row carries no authoritative payload or completed build timestamp. */
    public function testFirstLoadStartsWithAnEmptyCache(): void
    {
        self::assertSame([
            'user_id' => 7, 'data' => '[]', 'visible_folders' => '[]',
            'folders' => '[]', 'timestamp' => 0, 'invalidated_at' => 0,
        ], folderCacheEmptyRow(7));
    }

    /** Payloads produced by the independent tree, dropdown and API readers. */
    public static function cacheRepresentations(): array
    {
        return [
            'tree and dropdown' => ['', '[{"id":"li_10"}]', '[{"id":10}]', 'data'],
            'dropdown only' => ['visible_folders', '[{"id":10}]', '', 'visible_folders'],
            'API only' => ['folders', '[10]', '', 'folders'],
        ];
    }

    /** Every writer retains invalidation history and uses its build start time. */
    #[DataProvider('cacheRepresentations')]
    public function testWritesPreserveInvalidationHistory(string $field, string $data, string $visible, string $target): void
    {
        $row = array_replace(folderCacheEmptyRow(7), ['invalidated_at' => 100]);
        $fields = folderCacheWriteFields($data, $field, $visible, 101);
        $updated = array_replace($row, $fields);
        self::assertSame(100, $updated['invalidated_at']);
        self::assertSame(101, $updated['timestamp']);
        self::assertSame($data, $updated[$target]);
        self::assertArrayNotHasKey('invalidated_at', $fields);
        if ($field !== '') {
            self::assertSame('[]', $updated['data']);
            self::assertCount(2, $fields);
        } else {
            self::assertSame($visible, $updated['visible_folders']);
        }
    }

    /** The dropdown-first request must never populate the jsTree field. */
    public function testDropdownFirstLeavesTreeAndApiEmpty(): void
    {
        $row = array_replace(folderCacheEmptyRow(7), folderCacheWriteFields('[{"id":10}]', 'visible_folders', '', 100));
        self::assertSame('[]', $row['data']);
        self::assertSame('[]', $row['folders']);
        self::assertSame('[{"id":10}]', $row['visible_folders']);
    }

    /** Partial API writes cannot replace unrelated representations. */
    public function testApiWritePreservesTreeAndDropdownPayloads(): void
    {
        $row = array_replace(folderCacheEmptyRow(7), ['data' => '[{"id":"li_10"}]', 'visible_folders' => '[{"id":10}]']);
        $updated = array_replace($row, folderCacheWriteFields('[10]', 'folders', '', 101));
        self::assertSame($row['data'], $updated['data']);
        self::assertSame($row['visible_folders'], $updated['visible_folders']);
    }

    /** An unsupported field cannot overwrite ownership or invalidation metadata. */
    public function testUnsupportedCacheFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        folderCacheWriteFields('0', 'invalidated_at', '', 100);
    }

    /** Revoked scope wins even when old session/cache rows still name the folder. */
    public function testRevokedFolderIsExcludedAfterScopeRefresh(): void
    {
        $staleSessionScope = [10, 11];
        $refreshedDatabaseScope = [10];
        $cachedRows = [
            ['id' => 10, 'title' => 'Allowed', 'parent_id' => 0],
            ['id' => 11, 'title' => 'Revoked', 'parent_id' => 0],
        ];
        self::assertContains(11, $staleSessionScope);
        $scope = folderCacheVisibleScope($refreshedDatabaseScope, [], []);
        self::assertSame([10], array_column(folderCacheVisibleRows($cachedRows, $scope, [], []), 'id'));
    }

    /** Denials and foreign personal folders override grants; root is synthetic. */
    public function testDeniedAndForeignFoldersAreExcluded(): void
    {
        self::assertSame([10], folderCacheVisibleScope([0, '10', 10, 11, 12], ['11'], ['12']));
        self::assertSame([], folderCacheVisibleScope([10], [10], []));
    }

    /** Personal descendants require no role and retain their ownership metadata. */
    public function testPersonalDescendantsWithoutRolesArePreserved(): void
    {
        $rows = [['id' => '11', 'title' => 'Personal child', 'parent_id' => '10']];
        $folders = folderCacheVisibleRows($rows, [11], [10, '11'], []);
        self::assertSame(11, $folders[0]['id']);
        self::assertSame(10, $folders[0]['parent_id']);
        self::assertSame(1, $folders[0]['perso']);
        self::assertSame(0, $folders[0]['disabled']);
    }

    /** Disabling personal access cannot be undone by old cache rows. */
    public function testDisabledPersonalScopeStaysEmpty(): void
    {
        $rows = [['id' => 11, 'title' => 'Old personal child', 'parent_id' => 10]];
        self::assertSame([], folderCacheVisibleRows($rows, folderCacheVisibleScope([], [], []), [], []));
    }

    /** Read-only folders remain visible while write controls are disabled. */
    public function testReadOnlyMetadataIsPreserved(): void
    {
        $folders = folderCacheVisibleRows([['id' => 10, 'title' => 'Read only', 'parent_id' => 0]], [10], [], ['10']);
        self::assertSame(1, $folders[0]['disabled']);
        self::assertSame(1, $folders[0]['is_visible_active']);
        self::assertSame(0, $folders[0]['perso']);
    }
}
