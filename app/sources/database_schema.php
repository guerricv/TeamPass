<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright 2009-2026 Teampass.net
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Read-only schema inspection. No application rows or decrypted values are read.
 */

/** Normalize server-specific integer display widths without hiding storage differences. */
function tpSchemaType(string $type): string
{
    $type = strtolower(trim($type));
    $type = preg_replace('/\s+/', ' ', $type);
    $type = preg_replace('/^integer\b/', 'int', $type);
    if (strpos($type, 'zerofill') === false) {
        $type = preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type);
    }
    return $type;
}

/** Build a portable column description from SHOW FULL COLUMNS, including SQL NULL defaults. */
function tpSchemaColumn(array $row): array
{
    $type = tpSchemaType((string) $row['Type']);
    $default = $row['Default'] === null ? null : (string) $row['Default'];
    if (preg_match('/^(timestamp|datetime)\b/', $type) === 1 && $default !== null
        && preg_match('/^(current_timestamp|localtimestamp)(?:\((\d*)\))?$/i', $default, $matches) === 1
    ) {
        $default = 'current_timestamp(' . ($matches[2] ?? '') . ')';
    }
    $extra = strtolower(trim((string) ($row['Extra'] ?? '')));
    // MySQL reports this implementation marker for expression defaults.
    $extra = trim(str_replace('default_generated', '', $extra));
    $extra = preg_replace('/\bcurrent_timestamp\b(?!\()/', 'current_timestamp()', $extra);
    return [
        'type' => $type,
        'nullable' => strtoupper((string) $row['Null']) === 'YES',
        'default' => $default,
        'extra' => preg_replace('/\s+/', ' ', $extra),
        'collation' => strtolower((string) ($row['Collation'] ?? '')),
    ];
}

/** Collect index definitions, preserving column order and indexed prefix lengths. */
function tpSchemaIndexes(array $rows): array
{
    $indexes = [];
    foreach ($rows as $row) {
        $name = (string) $row['Key_name'];
        $indexes[$name]['unique'] = (int) $row['Non_unique'] === 0;
        $indexes[$name]['type'] = strtoupper((string) $row['Index_type']);
        $indexes[$name]['columns'][(int) $row['Seq_in_index']] = [
            'name' => (string) ($row['Column_name'] ?? ''),
            'prefix' => isset($row['Sub_part']) ? (int) $row['Sub_part'] : null,
            'direction' => (string) ($row['Collation'] ?? 'A'),
            'expression' => (string) ($row['Expression'] ?? ''),
        ];
    }
    foreach ($indexes as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);
    ksort($indexes);
    return $indexes;
}

/** Read schema metadata through an injectable query callback; never select application rows. */
function tpSchemaInspect(callable $query, string $prefix): array
{
    $tables = [];
    foreach ($query('SHOW TABLE STATUS') as $row) {
        $name = (string) $row['Name'];
        // Literal prefix comparison: '_' and '%' are not LIKE wildcards here.
        if (!str_starts_with($name, $prefix)) {
            continue;
        }
        $logical = substr($name, strlen($prefix));
        $identifier = '`' . str_replace('`', '``', $name) . '`';
        $columns = [];
        foreach ($query('SHOW FULL COLUMNS FROM ' . $identifier) as $column) {
            $columns[(string) $column['Field']] = tpSchemaColumn($column);
        }
        if ($columns === []) {
            throw new RuntimeException('Schema metadata unavailable');
        }
        ksort($columns);
        $tables[$logical] = [
            'engine' => strtolower((string) ($row['Engine'] ?? '')),
            'collation' => strtolower((string) ($row['Collation'] ?? '')),
            'columns' => $columns,
            'indexes' => tpSchemaIndexes($query('SHOW INDEX FROM ' . $identifier)),
        ];
    }
    ksort($tables);
    return $tables;
}

/** Compare a complete snapshot with the shipped schema; extra objects never disappear silently. */
function tpSchemaCompare(array $expected, array $actual): array
{
    $issues = [];
    $add = static function (string $severity, string $code, string $table, string $object, $wanted, $found) use (&$issues): void {
        $issues[] = compact('severity', 'code', 'table', 'object') + ['expected' => $wanted, 'actual' => $found];
    };
    foreach ($expected as $table => $definition) {
        if (!isset($actual[$table])) {
            $add('danger', 'missing_table', $table, '', true, false);
            continue;
        }
        $found = $actual[$table];
        foreach (['engine', 'collation'] as $property) {
            if ($definition[$property] !== $found[$property]) {
                $add($property === 'engine' ? 'danger' : 'warning', 'table_' . $property, $table, '', $definition[$property], $found[$property]);
            }
        }
        foreach ($definition['columns'] as $column => $properties) {
            if (!isset($found['columns'][$column])) {
                $add('danger', 'missing_column', $table, $column, $properties, null);
                continue;
            }
            foreach ($properties as $property => $value) {
                if ($value !== $found['columns'][$column][$property]) {
                    $add($property === 'collation' ? 'warning' : 'danger', 'column_' . $property, $table, $column, $value, $found['columns'][$column][$property]);
                }
            }
        }
        foreach (array_diff_key($found['columns'], $definition['columns']) as $column => $properties) {
            $severity = !$properties['nullable'] && $properties['default'] === null && strpos($properties['extra'], 'auto_increment') === false ? 'danger' : 'warning';
            $add($severity, 'extra_column', $table, $column, null, $properties);
        }
        $remaining = $found['indexes'];
        foreach ($definition['indexes'] as $name => $index) {
            // PRIMARY is semantic; ordinary index names may legitimately differ after an upgrade.
            $match = null;
            foreach ($remaining as $candidate => $candidateIndex) {
                if (($name === 'PRIMARY') === ($candidate === 'PRIMARY') && $index === $candidateIndex) {
                    $match = $candidate;
                    break;
                }
            }
            if ($match !== null) {
                unset($remaining[$match]);
            } else {
                $add($index['unique'] ? 'danger' : 'warning', 'missing_index', $table, $name, $index, $found['indexes'][$name] ?? null);
            }
        }
        foreach ($remaining as $name => $index) {
            $add($index['unique'] ? 'danger' : 'warning', 'extra_index', $table, $name, null, $index);
        }
    }
    foreach (array_diff_key($actual, $expected) as $table => $definition) {
        $add('warning', 'extra_table', $table, '', null, true);
    }
    $counts = ['danger' => 0, 'warning' => 0];
    foreach ($issues as $issue) {
        ++$counts[$issue['severity']];
    }
    return [
        'status' => $counts['danger'] > 0 ? 'danger' : ($issues === [] ? 'success' : 'warning'),
        'expected_tables' => count($expected), 'inspected_tables' => count($actual),
        'counts' => $counts, 'issues' => $issues,
    ];
}

/** Validate the reference before using it; malformed or mismatched references cannot report success. */
function tpSchemaLoadReference(string $path, string $version): array
{
    $source = @file_get_contents($path);
    $reference = $source === false ? null : json_decode($source, true);
    if (!is_array($reference) || ($reference['format'] ?? null) !== 1
        || ($reference['version'] ?? '') !== $version || empty($reference['tables'])
    ) {
        throw new RuntimeException('Schema reference unavailable');
    }
    foreach ($reference['tables'] as $name => $table) {
        if (!is_string($name) || !is_array($table) || !isset($table['engine'], $table['collation'], $table['columns'], $table['indexes'])
            || !is_array($table['columns']) || $table['columns'] === [] || !is_array($table['indexes'])
        ) {
            throw new RuntimeException('Invalid schema reference');
        }
        foreach ($table['columns'] as $column) {
            if (!is_array($column) || !isset($column['type'], $column['nullable'], $column['extra'], $column['collation']) || !array_key_exists('default', $column)) {
                throw new RuntimeException('Invalid column reference');
            }
        }
    }
    return $reference;
}

/** Run an independent diagnostic; metadata failures cannot break the rest of Health System. */
function tpSchemaReport(callable $query, string $prefix, string $referencePath, string $version): array
{
    $base = ['checked_at' => gmdate('c'), 'version' => $version, 'prefix' => $prefix];
    try {
        $reference = tpSchemaLoadReference($referencePath, $version);
        $actual = tpSchemaInspect($query, $prefix);
        if ($actual === []) {
            throw new RuntimeException('Schema metadata unavailable');
        }
        return $base + tpSchemaCompare($reference['tables'], $actual);
    } catch (Throwable $e) {
        // Do not return driver exception messages, which may contain connection details.
        return $base + ['status' => 'unavailable', 'issues' => [], 'counts' => ['danger' => 0, 'warning' => 0]];
    }
}
