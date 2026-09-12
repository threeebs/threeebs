<?php

declare(strict_types=1);

const PROJECT_MIGRATION_MAX_FILES = 100;
const PROJECT_MIGRATION_MAX_BYTES = 1048576;

function project_migration_manifest(string $environmentRoot): array
{
    $root = sandbox_editor_root($environmentRoot);
    $directory = $root . '/database/migrations';
    if (!is_dir($directory)) {
        return [];
    }
    $resolved = realpath($directory);
    if ($resolved === false || $resolved !== $directory || is_link($directory)
        || !str_starts_with($resolved . '/', $root . '/')) {
        throw new ValidationException('A pasta database/migrations não é válida.');
    }

    $names = scandir($directory);
    if ($names === false) {
        throw new RuntimeException('Não foi possível listar as migrations.');
    }
    $manifest = [];
    foreach ($names as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!preg_match('/^[0-9]{14}_[a-z0-9][a-z0-9_]{0,80}\.sql$/D', $name)) {
            throw new ValidationException(
                'Migration inválida: use AAAAMMDDHHMMSS_nome_em_snake_case.sql.'
            );
        }
        $file = $directory . '/' . $name;
        $fileResolved = realpath($file);
        if ($fileResolved !== $file || is_link($file) || !is_file($file)) {
            throw new ValidationException('A pasta de migrations contém um item inválido.');
        }
        $size = filesize($file);
        if ($size === false || $size < 1 || $size > PROJECT_MIGRATION_MAX_BYTES) {
            throw new ValidationException('Cada migration deve ter entre 1 byte e 1 MiB.');
        }
        $sql = file_get_contents($file);
        if ($sql === false || preg_match('//u', $sql) !== 1) {
            throw new ValidationException('As migrations precisam ser texto UTF-8 válido.');
        }
        project_migration_validate_policy($name, $sql);
        $manifest[] = ['name' => $name, 'sha256' => hash('sha256', $sql), 'size' => $size];
        if (count($manifest) > PROJECT_MIGRATION_MAX_FILES) {
            throw new ValidationException('O projeto excede o limite de 100 migrations.');
        }
    }
    usort($manifest, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    return $manifest;
}

function project_migration_validate_policy(string $name, string $sql): void
{
    $blocked = [
        '/\b(?:CREATE|DROP|ALTER)\s+(?:DATABASE|SCHEMA|USER)\b/i',
        '/\b(?:GRANT|REVOKE)\b/i',
        '/\bUSE\s+[`a-z0-9_]+/i',
        '/\bSET\s+(?:GLOBAL|PERSIST)\b/i',
        '/\bLOAD\s+DATA\b/i',
        '/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i',
        '/\bINSTALL\s+(?:PLUGIN|COMPONENT)\b/i',
        '/(?:^|\n)\s*(?:SOURCE|DELIMITER)\b/i',
    ];
    foreach ($blocked as $pattern) {
        if (preg_match($pattern, $sql) === 1) {
            throw new ValidationException(
                'A migration ' . $name . ' contém uma instrução não permitida.'
            );
        }
    }
}
