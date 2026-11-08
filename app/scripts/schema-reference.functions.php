<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * Copyright 2009-2026 Teampass.net
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Development-only helpers. Installer PHP is tokenized, never evaluated or included.
 */

/** Fingerprint all sources that can change the installation or upgrade schema. */
function tpSchemaReferenceSources(string $root): array
{
    $paths = array_merge(
        glob($root . '/public/install/upgrade_run_*.php'),
        [$root . '/public/install/install-steps/run.step5.php', $root . '/public/install/tp.functions.php',
            $root . '/public/install/upgrade_manifest.php', $root . '/public/install/upgrade_operations.php',
            $root . '/app/sources/device_pairing_logic.php', $root . '/app/sources/device_pairing_schema.php',
            $root . '/app/sources/secure_send_audit.php', $root . '/app/config/include.php']
    );
    $hashes = [];
    foreach ($paths as $path) {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Missing schema source');
        }
        $hashes[substr($path, strlen($root) + 1)] = hash('sha256', str_replace("\r\n", "\n", $source));
    }
    ksort($hashes);
    return $hashes;
}

/** Decode a constant PHP string without executing PHP expressions. */
function tpSchemaReferenceString(string $literal): string
{
    $value = substr($literal, 1, -1);
    if ($literal[0] === "'") {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
    }
    return preg_replace_callback('/\\\\([nrtvef\\\\$"])/', static function (array $match): string {
        return ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\x1b", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'][$match[1]];
    }, $value);
}

/** Extract fresh-install DDL, including its additive index statements, using a strict token allowlist. */
function tpSchemaReferenceDdl(string $source, string $prefix): array
{
    $tokens = token_get_all($source);
    $sql = [];
    $seenCreate = false;
    $prefixExpression = '$this->inputData[\'tablePrefix\']';
    for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $first = tpSchemaReferenceString($tokens[$i][1]);
        if (preg_match('/^\s*(CREATE TABLE|ALTER TABLE)\b/i', $first) !== 1) {
            continue;
        }
        // The earlier migrateToUtf8mb4 action operates on existing tables, not a fresh schema.
        if (stripos(ltrim($first), 'CREATE TABLE') === 0) {
            $seenCreate = true;
        } elseif (!$seenCreate) {
            continue;
        }
        $query = '';
        $expression = '';
        for (; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token === ')' || $token === ',') {
                break;
            }
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $expression === '') {
                $query .= tpSchemaReferenceString($token[1]);
            } elseif ($token === '.' && $expression === '') {
                continue;
            } else {
                $expression .= is_array($token) ? $token[1] : $token;
                if ($expression === $prefixExpression) {
                    $query .= $prefix;
                    $expression = '';
                }
            }
        }
        if ($expression !== '') {
            throw new RuntimeException('Unsupported installer DDL expression: update the reference extractor');
        }
        $sql[] = $query;
    }
    return $sql;
}

/** Verify coverage of installer table actions, including actions using shared DDL helpers. */
function tpSchemaReferenceTableNames(string $source): array
{
    preg_match_all('/private function\s+([a-zA-Z0-9_]+)\s*\(/', $source, $matches);
    $names = array_values(array_diff($matches[1], ['checkAndSetInnoDBEngine', 'migrateToUtf8mb4', 'utf8']));
    sort($names);
    return $names;
}
