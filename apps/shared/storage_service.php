<?php

declare(strict_types=1);

final class ProjectStorageService
{
    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private PDO $control,
        private int $projectId,
        private string $projectUuid,
        private int $environmentId,
        private string $environmentUuid,
        private string $root,
        private array $projectEnvironmentRoots,
        private $audit = null
    ) {
        $this->root = sandbox_editor_root($root);
    }

    public function tree(): array
    {
        return $this->withProjectLock(function (): array {
            $this->ensureReconciledLocked();
            return sandbox_editor_tree($this->root);
        });
    }

    public function readFile(string $path): array
    {
        return $this->withProjectLock(function () use ($path): array {
            $this->ensureReconciledLocked();
            return sandbox_editor_read_file($this->root, $path);
        });
    }

    public function usage(): array
    {
        $this->ensureQuotaRow();
        $statement = $this->control->prepare(
            'SELECT armazenamento_bytes_max,arquivos_max,pastas_max,arquivo_bytes_max,
                    armazenamento_bytes_usados,arquivos_usados,pastas_usadas,
                    armazenamento_bytes_reservados,arquivos_reservados,pastas_reservadas,
                    reconciliado_em
             FROM projeto_storage_quotas WHERE projeto_id=:project'
        );
        $statement->execute(['project' => $this->projectId]);
        return $statement->fetch() ?: [];
    }

    public function createFile(string $path): void
    {
        $this->withProjectLock(function () use ($path): void {
            $this->ensureReconciledLocked();
            [$target, $relative] = sandbox_editor_new_path($this->root, $path);
            if (!sandbox_editor_file_is_editable($relative)) {
                throw new ValidationException('Extensão não permitida para edição nesta Alpha.');
            }
            $reservation = $this->reserveLocked('create_file', $relative, 0, 1, 0, 0);
            try {
                $handle = @fopen($target, 'x');
                if ($handle === false) {
                    throw new RuntimeException('Não foi possível criar o arquivo.');
                }
                fclose($handle);
                chmod($target, 0664);
            } catch (Throwable $error) {
                $this->cancelLocked($reservation);
                throw $error;
            }
            $this->confirmFileLocked($reservation, $relative, 0, hash('sha256', ''));
            $this->emitAudit('sandbox.arquivo_criado', ['arquivo' => $relative]);
        });
    }

    public function createDirectory(string $path): void
    {
        $this->withProjectLock(function () use ($path): void {
            $this->ensureReconciledLocked();
            [$target, $relative] = sandbox_editor_new_path($this->root, $path);
            $reservation = $this->reserveLocked('create_directory', $relative, 0, 0, 1, null);
            try {
                if (!mkdir($target, 0775)) {
                    throw new RuntimeException('Não foi possível criar a pasta.');
                }
            } catch (Throwable $error) {
                $this->cancelLocked($reservation);
                throw $error;
            }
            $this->confirmDirectoryLocked($reservation, $relative);
            $this->emitAudit('sandbox.pasta_criada', ['pasta' => $relative]);
        });
    }

    public function writeFile(string $path, string $content, string $expectedHash): string
    {
        return $this->withProjectLock(function () use ($path, $content, $expectedHash): string {
            $this->ensureReconciledLocked();
            $relative = sandbox_editor_normalize_path($path);
            if (!sandbox_editor_file_is_editable($relative) || preg_match('//u', $content) !== 1) {
                throw new ValidationException('O conteúdo precisa ser texto UTF-8 de um tipo permitido.');
            }
            $file = sandbox_editor_existing_path($this->root, $relative, 'file');
            $current = file_get_contents($file);
            if ($current === false) {
                throw new RuntimeException('Não foi possível ler a versão atual do arquivo.');
            }
            $currentHash = hash('sha256', $current);
            if ($expectedHash === '' || !hash_equals($currentHash, $expectedHash)) {
                throw new ValidationException('O arquivo mudou desde que foi aberto. Recarregue antes de salvar.');
            }
            $newSize = strlen($content);
            $delta = $newSize - strlen($current);
            $reservation = $this->reserveLocked('write_file', $relative, $delta, 0, 0, $newSize);
            $temporary = null;
            try {
                $temporary = tempnam(dirname($file), '.threeebs-');
                if ($temporary === false
                    || file_put_contents($temporary, $content, LOCK_EX) === false
                    || is_link($file)
                    || !rename($temporary, $file)) {
                    throw new RuntimeException('Não foi possível salvar o arquivo.');
                }
                chmod($file, 0664);
            } catch (Throwable $error) {
                if (is_string($temporary) && is_file($temporary)) {
                    unlink($temporary);
                }
                $this->cancelLocked($reservation);
                throw $error;
            }
            $hash = hash('sha256', $content);
            $this->confirmFileLocked($reservation, $relative, $newSize, $hash);
            $this->emitAudit('sandbox.arquivo_alterado', ['arquivo' => $relative]);
            return $hash;
        });
    }

    public function rename(string $source, string $destination): void
    {
        $this->withProjectLock(function () use ($source, $destination): void {
            $this->ensureReconciledLocked();
            $source = sandbox_editor_normalize_path($source);
            $destination = sandbox_editor_normalize_path($destination);
            $existing = sandbox_editor_existing_path($this->root, $source);
            [$target] = sandbox_editor_new_path($this->root, $destination);
            if (is_file($existing)
                && (!sandbox_editor_file_is_editable($source)
                    || !sandbox_editor_file_is_editable($destination))) {
                throw new ValidationException('Somente arquivos de texto editáveis podem ser renomeados.');
            }
            if (is_dir($existing) && str_starts_with($destination . '/', $source . '/')) {
                throw new ValidationException('Uma pasta não pode ser movida para dentro dela mesma.');
            }
            $reservation = $this->reserveLocked('rename', $source, 0, 0, 0, null);
            try {
                if (!rename($existing, $target)) {
                    throw new RuntimeException('Não foi possível renomear o item.');
                }
            } catch (Throwable $error) {
                $this->cancelLocked($reservation);
                throw $error;
            }
            $this->control->beginTransaction();
            try {
                $statement = $this->control->prepare(
                    'SELECT id,caminho FROM projeto_arquivos
                     WHERE ambiente_id=:environment
                       AND (caminho=:source OR caminho LIKE :prefix)
                     ORDER BY CHAR_LENGTH(caminho)'
                );
                $statement->execute([
                    'environment' => $this->environmentId,
                    'source' => $source,
                    'prefix' => $source . '/%',
                ]);
                $update = $this->control->prepare(
                    'UPDATE projeto_arquivos
                     SET caminho=:path,versao=versao+1 WHERE id=:id'
                );
                foreach ($statement->fetchAll() as $entry) {
                    $suffix = substr((string) $entry['caminho'], strlen($source));
                    $update->execute(['path' => $destination . $suffix, 'id' => $entry['id']]);
                }
                $this->markReservationLocked($reservation, 'confirmada');
                $this->control->commit();
            } catch (Throwable $error) {
                if ($this->control->inTransaction()) {
                    $this->control->rollBack();
                }
                throw $error;
            }
            $this->emitAudit('sandbox.item_renomeado', ['origem' => $source, 'destino' => $destination]);
        });
    }

    public function delete(string $path): string
    {
        return $this->withProjectLock(function () use ($path): string {
            $this->ensureReconciledLocked();
            $relative = sandbox_editor_normalize_path($path);
            $target = sandbox_editor_existing_path($this->root, $relative);
            $type = is_file($target) ? 'file' : 'directory';
            $bytesDelta = $type === 'file' ? -((int) (filesize($target) ?: 0)) : 0;
            if ($type === 'directory') {
                $contents = scandir($target);
                if ($contents === false || array_diff($contents, ['.', '..']) !== []) {
                    throw new ValidationException('A pasta precisa estar vazia antes de ser excluída.');
                }
            }
            $reservation = $this->reserveLocked(
                'delete_' . $type,
                $relative,
                $bytesDelta,
                $type === 'file' ? -1 : 0,
                $type === 'directory' ? -1 : 0,
                null
            );
            try {
                $deleted = $type === 'file' ? unlink($target) : rmdir($target);
                if (!$deleted) {
                    throw new RuntimeException('Não foi possível excluir o item.');
                }
            } catch (Throwable $error) {
                $this->cancelLocked($reservation);
                throw $error;
            }
            $this->confirmDeleteLocked($reservation, $relative);
            $this->emitAudit(
                $type === 'file' ? 'sandbox.arquivo_excluido' : 'sandbox.pasta_excluida',
                [$type === 'file' ? 'arquivo' : 'pasta' => $relative]
            );
            return $type;
        });
    }

    public function reconcile(): array
    {
        return $this->withProjectLock(fn (): array => $this->reconcileLocked());
    }

    private function ensureQuotaRow(): void
    {
        $statement = $this->control->prepare(
            'INSERT IGNORE INTO projeto_storage_quotas (projeto_id) VALUES (:project)'
        );
        $statement->execute(['project' => $this->projectId]);
    }

    private function ensureReconciledLocked(): void
    {
        $this->ensureQuotaRow();
        $statement = $this->control->prepare(
            "SELECT q.reconciliado_em,
                    EXISTS(
                        SELECT 1 FROM projeto_storage_reservas r
                        WHERE r.projeto_id=q.projeto_id AND r.status='pendente'
                    ) possui_reserva_pendente
             FROM projeto_storage_quotas q WHERE q.projeto_id=:project"
        );
        $statement->execute(['project' => $this->projectId]);
        $state = $statement->fetch();
        if (!$state || $state['reconciliado_em'] === null
            || (int) $state['possui_reserva_pendente'] === 1) {
            $this->reconcileLocked();
        }
    }

    private function reserveLocked(
        string $operation,
        string $path,
        int $bytesDelta,
        int $filesDelta,
        int $foldersDelta,
        ?int $resultingFileBytes
    ): string {
        $this->ensureQuotaRow();
        $this->control->beginTransaction();
        try {
            $statement = $this->control->prepare(
                'SELECT * FROM projeto_storage_quotas WHERE projeto_id=:project FOR UPDATE'
            );
            $statement->execute(['project' => $this->projectId]);
            $quota = $statement->fetch();
            if (!$quota) {
                throw new RuntimeException('Quota do projeto indisponível.');
            }
            $positiveBytes = max(0, $bytesDelta);
            $positiveFiles = max(0, $filesDelta);
            $positiveFolders = max(0, $foldersDelta);
            if ($resultingFileBytes !== null && $resultingFileBytes > (int) $quota['arquivo_bytes_max']) {
                throw new ValidationException('O arquivo excede o tamanho máximo permitido pelo projeto.');
            }
            $currentBytes = (int) $quota['armazenamento_bytes_usados']
                + (int) $quota['armazenamento_bytes_reservados'];
            $createsEntry = in_array($operation, ['create_file', 'create_directory'], true);
            if (($positiveBytes > 0 && $currentBytes + $positiveBytes > (int) $quota['armazenamento_bytes_max'])
                || ($createsEntry && $currentBytes >= (int) $quota['armazenamento_bytes_max'])) {
                throw new ValidationException('A quota de armazenamento do projeto foi atingida.');
            }
            if ($positiveFiles > 0
                && (int) $quota['arquivos_usados']
                    + (int) $quota['arquivos_reservados'] + $positiveFiles
                > (int) $quota['arquivos_max']) {
                throw new ValidationException('A quota de arquivos do projeto foi atingida.');
            }
            if ($positiveFolders > 0
                && (int) $quota['pastas_usadas']
                    + (int) $quota['pastas_reservadas'] + $positiveFolders
                > (int) $quota['pastas_max']) {
                throw new ValidationException('A quota de pastas do projeto foi atingida.');
            }
            $uuid = self::uuid();
            $insert = $this->control->prepare(
                "INSERT INTO projeto_storage_reservas
                    (uuid,projeto_id,ambiente_id,operacao,caminho,
                     armazenamento_bytes_delta,arquivos_delta,pastas_delta,
                     armazenamento_bytes_reservados,arquivos_reservados,pastas_reservadas,
                     status,expira_em)
                 VALUES (:uuid,:project,:environment,:operation,:path,
                         :bytes_delta,:files_delta,:folders_delta,
                         :bytes_reserved,:files_reserved,:folders_reserved,
                         'pendente',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE))"
            );
            $insert->execute([
                'uuid' => $uuid,
                'project' => $this->projectId,
                'environment' => $this->environmentId,
                'operation' => $operation,
                'path' => $path,
                'bytes_delta' => $bytesDelta,
                'files_delta' => $filesDelta,
                'folders_delta' => $foldersDelta,
                'bytes_reserved' => $positiveBytes,
                'files_reserved' => $positiveFiles,
                'folders_reserved' => $positiveFolders,
            ]);
            $update = $this->control->prepare(
                'UPDATE projeto_storage_quotas
                 SET armazenamento_bytes_reservados=armazenamento_bytes_reservados+:bytes,
                     arquivos_reservados=arquivos_reservados+:files,
                     pastas_reservadas=pastas_reservadas+:folders,
                     versao=versao+1
                 WHERE projeto_id=:project'
            );
            $update->execute([
                'bytes' => $positiveBytes,
                'files' => $positiveFiles,
                'folders' => $positiveFolders,
                'project' => $this->projectId,
            ]);
            $this->control->commit();
            return $uuid;
        } catch (Throwable $error) {
            if ($this->control->inTransaction()) {
                $this->control->rollBack();
            }
            throw $error;
        }
    }

    private function confirmFileLocked(string $reservation, string $path, int $size, string $hash): void
    {
        $this->confirmLocked($reservation, function (array $row) use ($path, $size, $hash): void {
            $statement = $this->control->prepare(
                "INSERT INTO projeto_arquivos
                    (ambiente_id,caminho,tipo,tamanho_bytes,hash_conteudo,versao)
                 VALUES (:environment,:path,'arquivo',:size,:hash,1)
                 ON DUPLICATE KEY UPDATE tipo='arquivo',tamanho_bytes=VALUES(tamanho_bytes),
                    hash_conteudo=VALUES(hash_conteudo),versao=versao+1"
            );
            $statement->execute([
                'environment' => $this->environmentId,
                'path' => $path,
                'size' => $size,
                'hash' => $hash,
            ]);
        });
    }

    private function confirmDirectoryLocked(string $reservation, string $path): void
    {
        $this->confirmLocked($reservation, function () use ($path): void {
            $statement = $this->control->prepare(
                "INSERT INTO projeto_arquivos
                    (ambiente_id,caminho,tipo,tamanho_bytes,hash_conteudo,versao)
                 VALUES (:environment,:path,'pasta',0,NULL,1)
                 ON DUPLICATE KEY UPDATE tipo='pasta',tamanho_bytes=0,
                    hash_conteudo=NULL,versao=versao+1"
            );
            $statement->execute(['environment' => $this->environmentId, 'path' => $path]);
        });
    }

    private function confirmDeleteLocked(string $reservation, string $path): void
    {
        $this->confirmLocked($reservation, function () use ($path): void {
            $statement = $this->control->prepare(
                'DELETE FROM projeto_arquivos WHERE ambiente_id=:environment AND caminho=:path'
            );
            $statement->execute(['environment' => $this->environmentId, 'path' => $path]);
        });
    }

    private function confirmLocked(string $reservation, callable $metadata): void
    {
        $this->control->beginTransaction();
        try {
            $row = $this->reservationForUpdate($reservation);
            $quota = $this->quotaForUpdate();
            $metadata($row);
            $update = $this->control->prepare(
                'UPDATE projeto_storage_quotas
                 SET armazenamento_bytes_usados=:used_bytes,
                     arquivos_usados=:used_files,
                     pastas_usadas=:used_folders,
                     armazenamento_bytes_reservados=:reserved_bytes,
                     arquivos_reservados=:reserved_files,
                     pastas_reservadas=:reserved_folders,
                     versao=versao+1
                 WHERE projeto_id=:project'
            );
            $update->execute([
                'used_bytes' => max(0, (int) $quota['armazenamento_bytes_usados'] + (int) $row['armazenamento_bytes_delta']),
                'used_files' => max(0, (int) $quota['arquivos_usados'] + (int) $row['arquivos_delta']),
                'used_folders' => max(0, (int) $quota['pastas_usadas'] + (int) $row['pastas_delta']),
                'reserved_bytes' => max(0, (int) $quota['armazenamento_bytes_reservados'] - (int) $row['armazenamento_bytes_reservados']),
                'reserved_files' => max(0, (int) $quota['arquivos_reservados'] - (int) $row['arquivos_reservados']),
                'reserved_folders' => max(0, (int) $quota['pastas_reservadas'] - (int) $row['pastas_reservadas']),
                'project' => $this->projectId,
            ]);
            $this->markReservationLocked($reservation, 'confirmada');
            $this->control->commit();
        } catch (Throwable $error) {
            if ($this->control->inTransaction()) {
                $this->control->rollBack();
            }
            throw $error;
        }
    }

    private function cancelLocked(string $reservation): void
    {
        $this->control->beginTransaction();
        try {
            $row = $this->reservationForUpdate($reservation);
            $quota = $this->quotaForUpdate();
            $statement = $this->control->prepare(
                'UPDATE projeto_storage_quotas
                 SET armazenamento_bytes_reservados=:bytes,
                     arquivos_reservados=:files,
                     pastas_reservadas=:folders,
                     versao=versao+1 WHERE projeto_id=:project'
            );
            $statement->execute([
                'bytes' => max(0, (int) $quota['armazenamento_bytes_reservados'] - (int) $row['armazenamento_bytes_reservados']),
                'files' => max(0, (int) $quota['arquivos_reservados'] - (int) $row['arquivos_reservados']),
                'folders' => max(0, (int) $quota['pastas_reservadas'] - (int) $row['pastas_reservadas']),
                'project' => $this->projectId,
            ]);
            $this->markReservationLocked($reservation, 'cancelada');
            $this->control->commit();
        } catch (Throwable $error) {
            if ($this->control->inTransaction()) {
                $this->control->rollBack();
            }
            throw $error;
        }
    }

    private function reservationForUpdate(string $uuid): array
    {
        $statement = $this->control->prepare(
            "SELECT * FROM projeto_storage_reservas
             WHERE uuid=:uuid AND projeto_id=:project AND status='pendente' FOR UPDATE"
        );
        $statement->execute(['uuid' => $uuid, 'project' => $this->projectId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new RuntimeException('Reserva de storage indisponível.');
        }
        return $row;
    }

    private function quotaForUpdate(): array
    {
        $statement = $this->control->prepare(
            'SELECT * FROM projeto_storage_quotas WHERE projeto_id=:project FOR UPDATE'
        );
        $statement->execute(['project' => $this->projectId]);
        $row = $statement->fetch();
        if (!$row) {
            throw new RuntimeException('Quota do projeto indisponível.');
        }
        return $row;
    }

    private function markReservationLocked(string $uuid, string $status): void
    {
        $statement = $this->control->prepare(
            'UPDATE projeto_storage_reservas
             SET status=:status,finalizada_em=UTC_TIMESTAMP(6) WHERE uuid=:uuid'
        );
        $statement->execute(['status' => $status, 'uuid' => $uuid]);
    }

    private function reconcileLocked(): array
    {
        $entries = [];
        foreach ($this->projectEnvironmentRoots as $environmentId => $root) {
            $root = sandbox_editor_root((string) $root);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $absolute = $item->getPathname();
                if ($item->isLink()) {
                    continue;
                }
                $relative = substr($absolute, strlen($root) + 1);
                if ($relative === '' || strlen($relative) > 500
                    || str_contains($relative, "\0") || preg_match('//u', $relative) !== 1) {
                    continue;
                }
                if ($item->isDir()) {
                    $entries[] = [(int) $environmentId, $relative, 'pasta', 0, null];
                } elseif ($item->isFile()) {
                    $size = $item->getSize();
                    $entries[] = [(int) $environmentId, $relative, 'arquivo', $size, hash_file('sha256', $absolute) ?: null];
                }
            }
        }
        $bytes = 0;
        $files = 0;
        $folders = 0;
        foreach ($entries as $entry) {
            $bytes += $entry[3];
            $files += $entry[2] === 'arquivo' ? 1 : 0;
            $folders += $entry[2] === 'pasta' ? 1 : 0;
        }

        $this->ensureQuotaRow();
        $this->control->beginTransaction();
        try {
            $this->quotaForUpdate();
            $delete = $this->control->prepare(
                'DELETE pa FROM projeto_arquivos pa
                 JOIN ambientes a ON a.id=pa.ambiente_id WHERE a.projeto_id=:project'
            );
            $delete->execute(['project' => $this->projectId]);
            $insert = $this->control->prepare(
                'INSERT INTO projeto_arquivos
                    (ambiente_id,caminho,tipo,tamanho_bytes,hash_conteudo,versao)
                 VALUES (:environment,:path,:type,:size,:hash,1)'
            );
            foreach ($entries as $entry) {
                $insert->execute([
                    'environment' => $entry[0],
                    'path' => $entry[1],
                    'type' => $entry[2],
                    'size' => $entry[3],
                    'hash' => $entry[4],
                ]);
            }
            $cancel = $this->control->prepare(
                "UPDATE projeto_storage_reservas
                 SET status='expirada',finalizada_em=UTC_TIMESTAMP(6)
                 WHERE projeto_id=:project AND status='pendente'"
            );
            $cancel->execute(['project' => $this->projectId]);
            $update = $this->control->prepare(
                'UPDATE projeto_storage_quotas
                 SET armazenamento_bytes_usados=:bytes,arquivos_usados=:files,pastas_usadas=:folders,
                     armazenamento_bytes_reservados=0,arquivos_reservados=0,pastas_reservadas=0,
                     reconciliado_em=UTC_TIMESTAMP(6),versao=versao+1
                 WHERE projeto_id=:project'
            );
            $update->execute([
                'bytes' => $bytes,
                'files' => $files,
                'folders' => $folders,
                'project' => $this->projectId,
            ]);
            $this->control->commit();
        } catch (Throwable $error) {
            if ($this->control->inTransaction()) {
                $this->control->rollBack();
            }
            throw $error;
        }
        return ['bytes' => $bytes, 'files' => $files, 'folders' => $folders];
    }

    private function withProjectLock(callable $operation): mixed
    {
        $key = 'threeebs:storage:' . $this->projectUuid;
        $statement = $this->control->prepare('SELECT GET_LOCK(:lock_key,:timeout)');
        $statement->execute(['lock_key' => $key, 'timeout' => self::LOCK_TIMEOUT_SECONDS]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('O storage do projeto está ocupado. Tente novamente.');
        }
        try {
            return $operation();
        } finally {
            $release = $this->control->prepare('SELECT RELEASE_LOCK(:lock_key)');
            $release->execute(['lock_key' => $key]);
        }
    }

    private function emitAudit(string $action, array $details): void
    {
        if (is_callable($this->audit)) {
            ($this->audit)($action, $details);
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}

function project_storage_service(array $access, ?callable $audit = null): ProjectStorageService
{
    $projectId = (int) $access['project']['id'];
    $statement = db('control')->prepare(
        "SELECT id,uuid,diretorio FROM ambientes
         WHERE projeto_id=:project AND status='ativo' ORDER BY id"
    );
    $statement->execute(['project' => $projectId]);
    $roots = [];
    foreach ($statement->fetchAll() as $environment) {
        $roots[(int) $environment['id']] = safe_environment_path((string) $environment['diretorio'], false);
    }
    return new ProjectStorageService(
        db('control'),
        $projectId,
        (string) $access['project']['uuid'],
        (int) $access['environment']['id'],
        (string) $access['environment']['uuid'],
        (string) $access['root'],
        $roots,
        $audit
    );
}
