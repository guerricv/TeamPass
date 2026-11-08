<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright 2009-2026 Teampass.net
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/sources/database_schema.php';
require_once __DIR__ . '/../../app/scripts/schema-reference.functions.php';

final class DatabaseSchemaTest extends TestCase
{
    private function schema(): array
    {
        return ['items' => ['engine' => 'innodb', 'collation' => 'utf8mb4_unicode_ci',
            'columns' => ['label' => ['type' => 'varchar(500)', 'nullable' => false, 'default' => null,
                'extra' => '', 'collation' => 'utf8mb4_unicode_ci']],
            'indexes' => ['idx_label' => ['unique' => false, 'type' => 'BTREE',
                'columns' => [['name' => 'label', 'prefix' => 20, 'direction' => 'A', 'expression' => '']]]]]];
    }

    public function testIdenticalSchemaAndEquivalentIndexNamesMatch(): void
    {
        $actual = $this->schema();
        $actual['items']['indexes']['legacy_name'] = $actual['items']['indexes']['idx_label'];
        unset($actual['items']['indexes']['idx_label']);
        self::assertSame('success', tpSchemaCompare($this->schema(), $actual)['status']);
    }

    public function testTypesNormalizeOnlyEquivalentIntegerRepresentations(): void
    {
        self::assertSame('int unsigned', tpSchemaType('INT(12) UNSIGNED'));
        self::assertSame('tinyint', tpSchemaType('tinyint(1)'));
        self::assertSame('int', tpSchemaType('integer'));
        self::assertSame('int(12) zerofill', tpSchemaType('int(12) zerofill'));
        self::assertNotSame(tpSchemaType('varchar(255)'), tpSchemaType('varchar(500)'));
        self::assertNotSame(tpSchemaType('int'), tpSchemaType('int unsigned'));
        self::assertNotSame(tpSchemaType('decimal(10,2)'), tpSchemaType('decimal(10,3)'));
    }

    public function testMissingTableAndColumnsAreErrors(): void
    {
        self::assertSame('missing_table', tpSchemaCompare($this->schema(), [])['issues'][0]['code']);
        $actual = $this->schema();
        $actual['items']['columns'] = [];
        self::assertSame('missing_column', tpSchemaCompare($this->schema(), $actual)['issues'][0]['code']);
        self::assertSame('danger', tpSchemaCompare($this->schema(), $actual)['status']);
    }

    public function testAllColumnAttributesAreComparedAndNullIsNotAString(): void
    {
        foreach (['type' => 'varchar(255)', 'nullable' => true, 'default' => 'NULL',
            'extra' => 'auto_increment', 'collation' => 'utf8mb4_bin'] as $property => $value) {
            $actual = $this->schema();
            $actual['items']['columns']['label'][$property] = $value;
            $result = tpSchemaCompare($this->schema(), $actual);
            self::assertCount(1, $result['issues']);
            self::assertSame('column_' . $property, $result['issues'][0]['code']);
        }
    }

    public function testAdditionalObjectsAreReportedAndRequiredExtraColumnsAreDangerous(): void
    {
        $actual = $this->schema();
        $actual['old_items'] = $actual['items'];
        $actual['items']['columns']['custom'] = $actual['items']['columns']['label'];
        $result = tpSchemaCompare($this->schema(), $actual);
        self::assertSame(['extra_column', 'extra_table'], array_column($result['issues'], 'code'));
        self::assertSame('danger', $result['issues'][0]['severity']);
        $actual['items']['columns']['custom']['nullable'] = true;
        self::assertSame('warning', tpSchemaCompare($this->schema(), $actual)['status']);
    }

    public function testIndexOrderPrefixesAndUniquenessMatter(): void
    {
        $actual = $this->schema();
        $actual['items']['indexes']['idx_label']['columns'][0]['prefix'] = null;
        self::assertSame('warning', tpSchemaCompare($this->schema(), $actual)['status']);
        self::assertSame('missing_index', tpSchemaCompare($this->schema(), $actual)['issues'][0]['code']);
        $actual['items']['indexes']['idx_label']['unique'] = true;
        self::assertSame('danger', tpSchemaCompare($this->schema(), $actual)['status']);
        $expected = $this->schema();
        $expected['items']['indexes']['PRIMARY'] = $expected['items']['indexes']['idx_label'];
        $expected['items']['indexes']['PRIMARY']['unique'] = true;
        self::assertSame('danger', tpSchemaCompare($expected, $this->schema())['status']);
        $expected = $this->schema();
        $expected['items']['indexes']['idx_label']['columns'][] = ['name' => 'other', 'prefix' => null, 'direction' => 'A', 'expression' => ''];
        $actual = $expected;
        $actual['items']['indexes']['idx_label']['columns'] = array_reverse($actual['items']['indexes']['idx_label']['columns']);
        self::assertSame('missing_index', tpSchemaCompare($expected, $actual)['issues'][0]['code']);
    }

    public function testTimestampDefaultsAndServerMarkersAreNormalized(): void
    {
        $row = ['Type' => 'timestamp', 'Null' => 'YES', 'Default' => 'CURRENT_TIMESTAMP', 'Extra' => 'DEFAULT_GENERATED on update CURRENT_TIMESTAMP'];
        $other = ['Type' => 'timestamp', 'Null' => 'YES', 'Default' => 'current_timestamp()', 'Extra' => 'on update current_timestamp()'];
        self::assertSame(tpSchemaColumn($row), tpSchemaColumn($other));
        $row['Type'] = 'varchar(100)';
        self::assertSame('CURRENT_TIMESTAMP', tpSchemaColumn($row)['default'], 'Text defaults are not SQL expressions.');
    }

    public function testLiteralPrefixAndIdentifiersDoNotBecomeSqlPatterns(): void
    {
        $queries = [];
        $query = static function (string $sql) use (&$queries): array {
            $queries[] = $sql;
            if ($sql === 'SHOW TABLE STATUS') {
                return [['Name' => 'a_%items`odd', 'Engine' => 'InnoDB'], ['Name' => 'abcitems', 'Engine' => 'InnoDB']];
            }
            if (str_starts_with($sql, 'SHOW FULL COLUMNS')) {
                return [['Field' => 'R&D O\'Brien "<test>"', 'Type' => 'int(12)', 'Null' => 'YES', 'Default' => null]];
            }
            return [];
        };
        $snapshot = tpSchemaInspect($query, 'a_%');
        self::assertSame(['items`odd'], array_keys($snapshot));
        self::assertSame('SHOW FULL COLUMNS FROM `a_%items``odd`', $queries[1]);
        self::assertCount(3, $queries);
    }

    public function testMetadataFailureNeverReturnsSuccessOrDriverSecrets(): void
    {
        $root = dirname(__DIR__, 2);
        $report = tpSchemaReport(static function (): array { throw new RuntimeException('password=secret'); },
            'custom_', $root . '/app/config/database_schema.json', '3.2.4.1');
        self::assertSame('unavailable', $report['status']);
        $report = tpSchemaReport(static fn (): array => [], '', $root . '/app/config/database_schema.json', '3.2.4.1');
        self::assertSame('unavailable', $report['status'], 'An empty metadata result cannot prove conformity.');
        self::assertStringNotContainsString('secret', json_encode($report));
        $report = tpSchemaReport(static fn (): array => [], '', $root . '/app/config/database_schema.json', 'wrong-version');
        self::assertSame('unavailable', $report['status']);
    }

    public function testReferenceTracksEverySchemaSourceAndShippedVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $include = file_get_contents($root . '/app/config/include.php');
        preg_match("/define\('TP_VERSION', '([^']+)'\)/", $include, $major);
        preg_match("/define\('TP_VERSION_MINOR', '([^']+)'\)/", $include, $minor);
        $reference = tpSchemaLoadReference($root . '/app/config/database_schema.json', $major[1] . '.' . $minor[1]);
        self::assertSame(tpSchemaReferenceSources($root), $reference['sources'],
            'Schema sources changed: rebuild and review database_schema.json in a disposable database.');
        $ddl = tpSchemaReferenceDdl(file_get_contents($root . '/public/install/install-steps/run.step5.php'), 'reference_');
        self::assertGreaterThan(80, count($ddl));
        preg_match_all('/CREATE TABLE IF NOT EXISTS/i', implode("\n", $ddl), $matches);
        self::assertCount(count($matches[0]) + 2, $reference['tables'], 'Include both shared DDL helpers.');
        self::assertSame(tpSchemaReferenceTableNames(file_get_contents($root . '/public/install/install-steps/run.step5.php')), array_keys($reference['tables']));
    }

    public function testExtractorRejectsUnexpectedExecutableExpressions(): void
    {
        $this->expectException(RuntimeException::class);
        tpSchemaReferenceDdl('<?php DB::query("CREATE TABLE " . getenv("INPUT"));', 'reference_');
    }
}
