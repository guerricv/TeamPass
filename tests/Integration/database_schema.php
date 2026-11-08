<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright 2009-2026 Teampass.net
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Use ONLY an empty tp_schema_reference_* disposable database. No TeamPass bootstrap.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$temporaryReference = tempnam(sys_get_temp_dir(), 'tp-schema-');
$argv[1] = $temporaryReference;
try {
    // The builder validates the database name and refuses an existing/nonempty schema.
    require __DIR__ . '/../../app/scripts/build-schema-reference.php';
    $shipped = tpSchemaLoadReference($root . '/app/config/database_schema.json', $reference['version']);
    $checks = 0;
    $assert = static function (bool $condition, string $label) use (&$checks): void {
        if (!$condition) {
            throw new RuntimeException($label);
        }
        ++$checks;
    };
    $assert(tpSchemaCompare($shipped['tables'], $reference['tables'])['status'] === 'success', 'Fresh schema differs from shipped reference');
    $before = tpSchemaInspect($query, $prefix);
    $report = tpSchemaReport($query, $prefix, $root . '/app/config/database_schema.json', $reference['version']);
    $assert($report['status'] === 'success', 'Fresh schema report is not green');
    $assert($before === tpSchemaInspect($query, $prefix), 'Inspection changed schema');
    $query('ALTER TABLE `reference_items` MODIFY `label` VARCHAR(255) NOT NULL, ADD `custom_optional` INT NULL DEFAULT NULL');
    $query('ALTER TABLE `reference_items` DROP INDEX `uq_items_api_idempotency`');
    $query('CREATE TABLE `reference_additional` (`id` INT NOT NULL) ENGINE=InnoDB');
    $query('DROP TABLE `reference_tokens`');
    $changed = tpSchemaReport($query, $prefix, $root . '/app/config/database_schema.json', $reference['version']);
    $codes = array_column($changed['issues'], 'code');
    foreach (['column_type', 'extra_column', 'missing_index', 'extra_table', 'missing_table'] as $code) {
        $assert(in_array($code, $codes, true), 'Failed to detect ' . $code);
    }
    $assert($changed['status'] === 'danger', 'Incompatible schema did not report danger');
    $assert(count($changed['issues']) === 5, 'Unexpected differences / normalization regression');
    echo "OK ($checks integration checks)\n";
} finally {
    if (is_string($temporaryReference) && is_file($temporaryReference)) {
        unlink($temporaryReference);
    }
}
