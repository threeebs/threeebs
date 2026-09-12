<?php

declare(strict_types=1);

const SANDBOX_EDITOR_MAX_FILE_BYTES = 1048576;
const SANDBOX_EDITOR_MAX_ENTRIES = 1000;
const SANDBOX_EDITOR_MAX_DEPTH = 12;

function sandbox_editor_extensions(): array
{
    return ['html', 'htm', 'css', 'js', 'mjs', 'cjs', 'json', 'md', 'txt', 'xml', 'svg', 'php', 'sql'];
}

function sandbox_editor_normalize_path(string $path, bool $allowRoot = false): string
{
    $path = trim($path);
    if ($path === '' && $allowRoot) {
        return '';
    }
    if ($path === '' || strlen($path) > 500 || str_contains($path, "\0")
        || str_contains($path, '\\') || str_starts_with($path, '/')
        || str_ends_with($path, '/') || preg_match('//u', $path) !== 1) {
        throw new ValidationException('Caminho inválido no Sandbox.');
    }

    $segments = explode('/', $path);
    if (count($segments) > SANDBOX_EDITOR_MAX_DEPTH) {
        throw new ValidationException('O caminho excede a profundidade permitida.');
    }
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..'
            || str_starts_with($segment, '.') || strlen($segment) > 120
            || !preg_match('/^[\pL\pN][\pL\pN._ -]*$/u', $segment)) {
            throw new ValidationException('Nome de arquivo ou pasta inválido.');
        }
    }
    return implode('/', $segments);
}

function sandbox_editor_root(string $root): string
{
    $resolved = realpath($root);
    if ($resolved === false || !is_dir($resolved) || is_link($root)) {
        throw new RuntimeException('Sandbox indisponível.');
    }
    return $resolved;
}

function sandbox_editor_existing_path(
    string $root,
    string $relative,
    ?string $expectedType = null
): string {
    $root = sandbox_editor_root($root);
    $relative = sandbox_editor_normalize_path($relative);
    $candidate = $root . '/' . $relative;
    $resolved = realpath($candidate);
    if ($resolved === false || $resolved !== $candidate || is_link($candidate)
        || !str_starts_with($resolved . '/', $root . '/')) {
        throw new ValidationException('Arquivo ou pasta não encontrado.');
    }
    if ($expectedType === 'file' && !is_file($resolved)) {
        throw new ValidationException('O caminho não aponta para um arquivo.');
    }
    if ($expectedType === 'directory' && !is_dir($resolved)) {
        throw new ValidationException('O caminho não aponta para uma pasta.');
    }
    return $resolved;
}

function sandbox_editor_new_path(string $root, string $relative): array
{
    $root = sandbox_editor_root($root);
    $relative = sandbox_editor_normalize_path($relative);
    $parentRelative = dirname($relative);
    $parent = $parentRelative === '.'
        ? $root
        : sandbox_editor_existing_path($root, $parentRelative, 'directory');
    $target = $parent . '/' . basename($relative);
    if (file_exists($target) || is_link($target)) {
        throw new ValidationException('Já existe um arquivo ou pasta nesse caminho.');
    }
    return [$target, $relative];
}

function sandbox_editor_file_is_editable(string $relative): bool
{
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    return in_array($extension, sandbox_editor_extensions(), true);
}

function sandbox_editor_tree(string $root): array
{
    $root = sandbox_editor_root($root);
    $entries = 0;

    $walk = function (string $directory, string $prefix, int $depth) use (&$walk, &$entries, $root): array {
        if ($depth > SANDBOX_EDITOR_MAX_DEPTH) {
            return [];
        }
        $names = scandir($directory);
        if ($names === false) {
            throw new RuntimeException('Não foi possível listar o Sandbox.');
        }
        $nodes = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;
            try {
                sandbox_editor_normalize_path($relative);
            } catch (ValidationException) {
                continue;
            }
            $candidate = $directory . '/' . $name;
            if (is_link($candidate)) {
                continue;
            }
            $resolved = realpath($candidate);
            if ($resolved === false || $resolved !== $candidate
                || !str_starts_with($resolved . '/', $root . '/')) {
                continue;
            }
            $entries++;
            if ($entries > SANDBOX_EDITOR_MAX_ENTRIES) {
                throw new ValidationException('O Sandbox excede o limite de itens do editor.');
            }
            if (is_dir($resolved)) {
                $nodes[] = [
                    'name' => $name,
                    'path' => $relative,
                    'type' => 'directory',
                    'children' => $walk($resolved, $relative, $depth + 1),
                ];
            } elseif (is_file($resolved)) {
                $nodes[] = [
                    'name' => $name,
                    'path' => $relative,
                    'type' => 'file',
                    'editable' => sandbox_editor_file_is_editable($relative),
                    'size' => filesize($resolved) ?: 0,
                ];
            }
        }
        usort($nodes, static function (array $left, array $right): int {
            if ($left['type'] !== $right['type']) {
                return $left['type'] === 'directory' ? -1 : 1;
            }
            return strnatcasecmp((string) $left['name'], (string) $right['name']);
        });
        return $nodes;
    };

    return $walk($root, '', 1);
}

function sandbox_editor_read_file(string $root, string $relative): array
{
    $relative = sandbox_editor_normalize_path($relative);
    if (!sandbox_editor_file_is_editable($relative)) {
        throw new ValidationException('Esse tipo de arquivo não pode ser editado nesta Alpha.');
    }
    $file = sandbox_editor_existing_path($root, $relative, 'file');
    $size = filesize($file);
    if ($size === false || $size > SANDBOX_EDITOR_MAX_FILE_BYTES) {
        throw new ValidationException('O arquivo excede o limite de 1 MiB.');
    }
    $content = file_get_contents($file);
    if ($content === false || preg_match('//u', $content) !== 1) {
        throw new ValidationException('O arquivo não contém texto UTF-8 válido.');
    }
    return [
        'path' => $relative,
        'content' => $content,
        'hash' => hash('sha256', $content),
    ];
}

function sandbox_editor_write_file(
    string $root,
    string $relative,
    string $content,
    string $expectedHash
): string {
    $relative = sandbox_editor_normalize_path($relative);
    if (!sandbox_editor_file_is_editable($relative)) {
        throw new ValidationException('Esse tipo de arquivo não pode ser editado nesta Alpha.');
    }
    if (strlen($content) > SANDBOX_EDITOR_MAX_FILE_BYTES || preg_match('//u', $content) !== 1) {
        throw new ValidationException('O conteúdo deve ser UTF-8 e ter no máximo 1 MiB.');
    }
    $file = sandbox_editor_existing_path($root, $relative, 'file');
    $current = file_get_contents($file);
    if ($current === false) {
        throw new RuntimeException('Não foi possível ler a versão atual do arquivo.');
    }
    $currentHash = hash('sha256', $current);
    if ($expectedHash === '' || !hash_equals($currentHash, $expectedHash)) {
        throw new ValidationException('O arquivo mudou desde que foi aberto. Recarregue antes de salvar.');
    }
    $directory = dirname($file);
    $temporary = tempnam($directory, '.threeebs-');
    if ($temporary === false
        || file_put_contents($temporary, $content, LOCK_EX) === false
        || is_link($file)
        || !rename($temporary, $file)) {
        if (is_string($temporary) && is_file($temporary)) {
            unlink($temporary);
        }
        throw new RuntimeException('Não foi possível salvar o arquivo.');
    }
    chmod($file, 0664);
    return hash('sha256', $content);
}

function sandbox_editor_create_file(string $root, string $relative): void
{
    [$target, $relative] = sandbox_editor_new_path($root, $relative);
    if (!sandbox_editor_file_is_editable($relative)) {
        throw new ValidationException('Extensão não permitida para edição nesta Alpha.');
    }
    $handle = @fopen($target, 'x');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível criar o arquivo.');
    }
    fclose($handle);
    chmod($target, 0664);
}

function sandbox_editor_create_directory(string $root, string $relative): void
{
    [$target] = sandbox_editor_new_path($root, $relative);
    if (!mkdir($target, 0775)) {
        throw new RuntimeException('Não foi possível criar a pasta.');
    }
}

function sandbox_editor_rename(string $root, string $source, string $destination): void
{
    $source = sandbox_editor_normalize_path($source);
    $destination = sandbox_editor_normalize_path($destination);
    $existing = sandbox_editor_existing_path($root, $source);
    [$target] = sandbox_editor_new_path($root, $destination);
    if (is_file($existing)
        && (!sandbox_editor_file_is_editable($source)
            || !sandbox_editor_file_is_editable($destination))) {
        throw new ValidationException('Somente arquivos de texto editáveis podem ser renomeados.');
    }
    if (is_dir($existing) && str_starts_with($destination . '/', $source . '/')) {
        throw new ValidationException('Uma pasta não pode ser movida para dentro dela mesma.');
    }
    if (!rename($existing, $target)) {
        throw new RuntimeException('Não foi possível renomear o item.');
    }
}

function sandbox_editor_delete(string $root, string $relative): string
{
    $relative = sandbox_editor_normalize_path($relative);
    $target = sandbox_editor_existing_path($root, $relative);
    if (is_file($target)) {
        if (!unlink($target)) {
            throw new RuntimeException('Não foi possível excluir o arquivo.');
        }
        return 'file';
    }
    $contents = scandir($target);
    if ($contents === false || array_diff($contents, ['.', '..']) !== []) {
        throw new ValidationException('A pasta precisa estar vazia antes de ser excluída.');
    }
    if (!rmdir($target)) {
        throw new RuntimeException('Não foi possível excluir a pasta.');
    }
    return 'directory';
}
