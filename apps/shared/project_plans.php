<?php

declare(strict_types=1);

function storage_bytes_label(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2, ',', '.') . ' GiB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', '.') . ' MiB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2, ',', '.') . ' KiB';
    }
    return number_format($bytes, 0, ',', '.') . ' bytes';
}

function create_storage_plan(
    string $name,
    string $code,
    string $description,
    int $storageMiB,
    int $filesMax,
    int $foldersMax,
    int $fileMiB,
    string $price,
    string $currency
): array {
    $name = trim($name);
    $code = slug($code);
    $description = trim($description);
    $currency = strtoupper(trim($currency));
    $normalizedPrice = str_replace(',', '.', trim($price));
    if ($name === '' || strlen($name) > 180 || $code === '' || strlen($code) > 100) {
        throw new ValidationException('Informe nome e código válidos para o plano.');
    }
    if ($storageMiB < 1 || $filesMax < 1 || $foldersMax < 0 || $fileMiB < 1
        || $fileMiB > $storageMiB) {
        throw new ValidationException('Confira os limites de storage do plano.');
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)
        || !preg_match('/^\d{1,12}(?:\.\d{1,4})?$/', $normalizedPrice)
        || (float) $normalizedPrice <= 0) {
        throw new ValidationException('Informe preço positivo e moeda ISO com três letras.');
    }
    if (strlen($description) > 4000) {
        throw new ValidationException('A descrição deve ter no máximo 4.000 caracteres.');
    }

    $storageBytes = $storageMiB * 1048576;
    $fileBytes = $fileMiB * 1048576;
    $catalog = db('catalog');
    $catalog->beginTransaction();
    try {
        $itemUuid = uuid_v4();
        $insert = $catalog->prepare(
            "INSERT INTO itens
                (uuid,codigo,nome,descricao,tipo,categoria,unidade,status)
             VALUES
                (:uuid,:code,:name,:description,'servico','hospedagem','projeto_mes','ativo')"
        );
        $insert->execute([
            'uuid' => $itemUuid,
            'code' => $code,
            'name' => $name,
            'description' => $description === '' ? null : $description,
        ]);
        $itemId = (int) $catalog->lastInsertId();

        $limits = $catalog->prepare(
            'INSERT INTO item_limites_storage
                (item_id,armazenamento_bytes_max,arquivos_max,pastas_max,arquivo_bytes_max)
             VALUES (:item,:bytes,:files,:folders,:file_bytes)'
        );
        $limits->execute([
            'item' => $itemId,
            'bytes' => $storageBytes,
            'files' => $filesMax,
            'folders' => $foldersMax,
            'file_bytes' => $fileBytes,
        ]);

        $priceUuid = uuid_v4();
        $priceInsert = $catalog->prepare(
            'INSERT INTO precos
                (uuid,item_id,valor,moeda,vigencia_inicio)
             VALUES (:uuid,:item,:price,:currency,UTC_TIMESTAMP(6))'
        );
        $priceInsert->execute([
            'uuid' => $priceUuid,
            'item' => $itemId,
            'price' => $normalizedPrice,
            'currency' => $currency,
        ]);
        $catalog->commit();
    } catch (Throwable $error) {
        if ($catalog->inTransaction()) {
            $catalog->rollBack();
        }
        throw $error;
    }

    return [
        'uuid' => $itemUuid,
        'codigo' => $code,
        'nome' => $name,
        'preco_uuid' => $priceUuid,
        'valor' => $normalizedPrice,
        'moeda' => $currency,
        'armazenamento_bytes_max' => $storageBytes,
        'arquivos_max' => $filesMax,
        'pastas_max' => $foldersMax,
        'arquivo_bytes_max' => $fileBytes,
    ];
}

function storage_plan_catalog_items(): array
{
    $statement = db('catalog')->query(
        "SELECT i.uuid,i.codigo,i.nome,i.descricao,i.status,
                l.armazenamento_bytes_max,l.arquivos_max,l.pastas_max,l.arquivo_bytes_max,
                p.valor,p.moeda
         FROM itens i
         JOIN item_limites_storage l ON l.item_id=i.id
         LEFT JOIN precos p ON p.id=(
             SELECT current_price.id
             FROM precos current_price
             WHERE current_price.item_id=i.id
               AND current_price.vigencia_inicio<=UTC_TIMESTAMP(6)
               AND (current_price.vigencia_fim IS NULL
                    OR current_price.vigencia_fim>UTC_TIMESTAMP(6))
             ORDER BY current_price.vigencia_inicio DESC,current_price.id DESC
             LIMIT 1
         )
         WHERE i.status='ativo'
         ORDER BY i.nome,i.codigo"
    );
    return $statement->fetchAll();
}

function project_storage_summary(int $projectId): array
{
    $statement = db('control')->prepare(
        "SELECT
            COALESCE(q.armazenamento_bytes_max,104857600) armazenamento_bytes_max,
            COALESCE(q.arquivos_max,1000) arquivos_max,
            COALESCE(q.pastas_max,250) pastas_max,
            COALESCE(q.arquivo_bytes_max,1048576) arquivo_bytes_max,
            COALESCE(q.armazenamento_bytes_usados,0) armazenamento_bytes_usados,
            COALESCE(q.arquivos_usados,0) arquivos_usados,
            COALESCE(q.pastas_usadas,0) pastas_usadas,
            COALESCE(q.armazenamento_bytes_reservados,0) armazenamento_bytes_reservados,
            COALESCE(q.arquivos_reservados,0) arquivos_reservados,
            COALESCE(q.pastas_reservadas,0) pastas_reservadas,
            q.reconciliado_em,
            pp.uuid plano_atribuicao_uuid,
            pp.catalog_item_uuid,
            pp.catalog_item_codigo,
            pp.catalog_item_nome,
            pp.vigencia_inicio plano_vigencia_inicio
         FROM projetos p
         LEFT JOIN projeto_storage_quotas q ON q.projeto_id=p.id
         LEFT JOIN projeto_planos pp
           ON pp.projeto_id=p.id AND pp.status='ativo'
         WHERE p.id=:project
         ORDER BY pp.vigencia_inicio DESC,pp.id DESC
         LIMIT 1"
    );
    $statement->execute(['project' => $projectId]);
    $summary = $statement->fetch();
    if (!is_array($summary)) {
        throw new ValidationException('Projeto não encontrado.');
    }
    return $summary;
}

function assign_project_storage_plan(
    int $projectId,
    string $catalogItemUuid,
    string $assignedByUserUuid
): array {
    $catalog = db('catalog');
    $statement = $catalog->prepare(
        "SELECT i.uuid,i.codigo,i.nome,
                l.armazenamento_bytes_max,l.arquivos_max,l.pastas_max,l.arquivo_bytes_max
         FROM itens i
         JOIN item_limites_storage l ON l.item_id=i.id
         WHERE i.uuid=:uuid AND i.status='ativo'
         LIMIT 1"
    );
    $statement->execute(['uuid' => trim($catalogItemUuid)]);
    $plan = $statement->fetch();
    if (!is_array($plan)) {
        throw new ValidationException('Plano de storage ativo não encontrado no catálogo.');
    }

    $control = db('control');
    $control->beginTransaction();
    try {
        $lock = $control->prepare('SELECT id FROM projetos WHERE id=:project FOR UPDATE');
        $lock->execute(['project' => $projectId]);
        if (!$lock->fetchColumn()) {
            throw new ValidationException('Projeto não encontrado.');
        }

        $control->prepare(
            'INSERT IGNORE INTO projeto_storage_quotas (projeto_id) VALUES (:project)'
        )->execute(['project' => $projectId]);

        $control->prepare(
            "UPDATE projeto_planos
             SET status='encerrado',vigencia_fim=UTC_TIMESTAMP(6)
             WHERE projeto_id=:project AND status='ativo'"
        )->execute(['project' => $projectId]);

        $assignmentUuid = uuid_v4();
        $insert = $control->prepare(
            "INSERT INTO projeto_planos
                (uuid,projeto_id,catalog_item_uuid,catalog_item_codigo,catalog_item_nome,
                 status,armazenamento_bytes_max,arquivos_max,pastas_max,arquivo_bytes_max,
                 atribuido_por_usuario_uuid,vigencia_inicio)
             VALUES
                (:uuid,:project,:item_uuid,:item_code,:item_name,'ativo',
                 :bytes,:files,:folders,:file_bytes,:assigned_by,UTC_TIMESTAMP(6))"
        );
        $insert->execute([
            'uuid' => $assignmentUuid,
            'project' => $projectId,
            'item_uuid' => $plan['uuid'],
            'item_code' => $plan['codigo'],
            'item_name' => $plan['nome'],
            'bytes' => $plan['armazenamento_bytes_max'],
            'files' => $plan['arquivos_max'],
            'folders' => $plan['pastas_max'],
            'file_bytes' => $plan['arquivo_bytes_max'],
            'assigned_by' => $assignedByUserUuid,
        ]);

        $update = $control->prepare(
            'UPDATE projeto_storage_quotas
             SET armazenamento_bytes_max=:bytes,
                 arquivos_max=:files,
                 pastas_max=:folders,
                 arquivo_bytes_max=:file_bytes,
                 versao=versao+1
             WHERE projeto_id=:project'
        );
        $update->execute([
            'bytes' => $plan['armazenamento_bytes_max'],
            'files' => $plan['arquivos_max'],
            'folders' => $plan['pastas_max'],
            'file_bytes' => $plan['arquivo_bytes_max'],
            'project' => $projectId,
        ]);
        $control->commit();
    } catch (Throwable $error) {
        if ($control->inTransaction()) {
            $control->rollBack();
        }
        throw $error;
    }

    return [
        'assignment_uuid' => $assignmentUuid,
        'catalog_item_uuid' => $plan['uuid'],
        'catalog_item_codigo' => $plan['codigo'],
        'catalog_item_nome' => $plan['nome'],
        'armazenamento_bytes_max' => (int) $plan['armazenamento_bytes_max'],
        'arquivos_max' => (int) $plan['arquivos_max'],
        'pastas_max' => (int) $plan['pastas_max'],
        'arquivo_bytes_max' => (int) $plan['arquivo_bytes_max'],
    ];
}
