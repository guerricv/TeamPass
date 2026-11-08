<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright 2009-2026 Teampass.net
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Build the reference in an explicitly designated, empty, disposable database.
 * Never bootstrap a TeamPass installation or read its settings/secrets.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/schema-reference.functions.php';
require_once __DIR__ . '/../sources/database_schema.php';
require_once __DIR__ . '/../sources/device_pairing_logic.php';
require_once __DIR__ . '/../sources/secure_send_audit.php';

$root = dirname(__DIR__, 2);
$database = (string) getenv('TP_SCHEMA_DATABASE');
if (preg_match('/^tp_schema_reference_[a-z0-9_]+$/D', $database) !== 1) {
    fwrite(STDERR, "Set TP_SCHEMA_DATABASE to an empty disposable database named tp_schema_reference_*.\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli(getenv('TP_SCHEMA_HOST') ?: 'localhost', getenv('TP_SCHEMA_USER') ?: '',
    getenv('TP_SCHEMA_PASSWORD') ?: '', $database, (int) (getenv('TP_SCHEMA_PORT') ?: 3306));
$connection->set_charset('utf8mb4');
$query = static function (string $sql) use ($connection): array {
    $result = $connection->query($sql);
    return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
};
if ($query('SHOW TABLES') !== []) {
    fwrite(STDERR, "Refusing to modify a nonempty database.\n");
    exit(1);
}
$prefix = 'reference_';
$installerSource = file_get_contents($root . '/public/install/install-steps/run.step5.php');
$ddl = tpSchemaReferenceDdl($installerSource, $prefix);
foreach ($ddl as $statement) {
    $query($statement);
}
$query(devicePairingSchemaSql($prefix . 'device_pairings'));
$query(secureSendAuditSchemaSql($prefix . 'secure_send_audit'));
$include = file_get_contents($root . '/app/config/include.php');
preg_match("/define\('TP_VERSION', '([^']+)'\)/", $include, $major);
preg_match("/define\('TP_VERSION_MINOR', '([^']+)'\)/", $include, $minor);
$reference = ['format' => 1, 'version' => $major[1] . '.' . $minor[1],
    'sources' => tpSchemaReferenceSources($root), 'tables' => tpSchemaInspect($query, $prefix)];
if (array_keys($reference['tables']) !== tpSchemaReferenceTableNames($installerSource)) {
    throw new RuntimeException('Not every installer table action is covered: update the reference builder.');
}
$output = $argv[1] ?? $root . '/app/config/database_schema.json';
file_put_contents($output, json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo count($reference['tables']) . " table definitions written. The disposable database is retained for verification.\n";
