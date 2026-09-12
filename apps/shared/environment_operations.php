<?php

declare(strict_types=1);

const ENVIRONMENT_OPERATION_TYPES = [
    'database.provision',
    'runtime.php.activate',
    'database.migrations.apply',
];

function environment_for_project(string $projectUuid, string $environmentUuid): array
{
    $stmt = db('control')->prepare(
        "SELECT a.*,p.uuid projeto_uuid,p.cliente_id,c.uuid cliente_uuid
         FROM ambientes a
         JOIN projetos p ON p.id=a.projeto_id
         JOIN clientes c ON c.id=p.cliente_id
         WHERE p.uuid=:project AND a.uuid=:environment
           AND p.status='ativo' AND a.status='ativo'
         LIMIT 1"
    );
    $stmt->execute(['project' => $projectUuid, 'environment' => $environmentUuid]);
    $environment = $stmt->fetch();
    if (!is_array($environment)) {
        throw new ValidationException('Ambiente ativo não encontrado neste projeto.');
    }
    return $environment;
}

function project_user_can_manage_database(array $project, ?array $user = null): bool
{
    $user = current_active_user($user ?? auth_user());
    if (!$user) {
        return false;
    }
    if (is_admin($user)) {
        return true;
    }
    $stmt = db('control')->prepare(
        "SELECT 1 FROM projeto_usuarios
         WHERE projeto_id=:project AND usuario_uuid=:user
           AND ativo=1 AND papel IN ('gestor','desenvolvedor')
         LIMIT 1"
    );
    $stmt->execute(['project' => $project['id'], 'user' => $user['uuid']]);
    return (bool) $stmt->fetchColumn();
}

function environment_operation_is_satisfied(array $environment, string $type): bool
{
    if ($type === 'database.provision') {
        $stmt = db('control')->prepare(
            "SELECT 1 FROM servicos_ambiente
             WHERE ambiente_id=:environment
               AND tipo='database.mysql.shared' AND nome='default' AND status='ativo'
             LIMIT 1"
        );
    } elseif ($type === 'runtime.php.activate') {
        $stmt = db('control')->prepare(
            "SELECT 1 FROM ambiente_runtimes
             WHERE ambiente_id=:environment AND tipo='runtime.php'
               AND execucao_habilitada=1 AND status='ativo'
             LIMIT 1"
        );
    } else {
        return false;
    }
    $stmt->execute(['environment' => $environment['id']]);
    return (bool) $stmt->fetchColumn();
}

function queue_environment_operation(
    array $environment,
    string $type,
    string $requestedByUserUuid,
    array $payload = []
): string {
    if (!in_array($type, ENVIRONMENT_OPERATION_TYPES, true)) {
        throw new InvalidArgumentException('Tipo de operação de ambiente inválido.');
    }
    if (environment_operation_is_satisfied($environment, $type)) {
        throw new ValidationException(
            $type === 'database.provision'
                ? 'O banco de dados deste ambiente já está ativo.'
                : 'O runtime PHP deste ambiente já está ativo.'
        );
    }

    $uuid = uuid_v4();
    try {
        $stmt = db('control')->prepare(
            "INSERT INTO ambiente_operacoes
                (uuid,ambiente_id,tipo,status,solicitado_por_usuario_uuid,payload)
             VALUES (:uuid,:environment,:type,'pendente',:requested_by,:payload)"
        );
        $stmt->execute([
            'uuid' => $uuid,
            'environment' => $environment['id'],
            'type' => $type,
            'requested_by' => $requestedByUserUuid,
            'payload' => $payload === []
                ? null
                : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (PDOException $error) {
        if ((string) $error->getCode() === '23000') {
            throw new ValidationException('Já existe uma operação igual na fila para este ambiente.');
        }
        throw $error;
    }

    audit_event(
        'ambiente.operacao_solicitada',
        'ambiente_operacao',
        $uuid,
        $environment['cliente_uuid'] ?? null,
        $environment['projeto_uuid'] ?? null,
        $environment['uuid'],
        ['tipo' => $type]
    );
    return $uuid;
}

function project_environment_operation_rows(int $projectId): array
{
    $stmt = db('control')->prepare(
        "SELECT
            a.id,a.uuid,a.nome,a.slug,a.tipo,a.diretorio,a.status,
            ar.tipo runtime_tipo,ar.execucao_habilitada,ar.status runtime_status,
            EXISTS(
                SELECT 1 FROM servicos_ambiente sa
                WHERE sa.ambiente_id=a.id
                  AND sa.tipo='database.mysql.shared'
                  AND sa.nome='default' AND sa.status='ativo'
            ) database_ativo,
            (
                SELECT ao.status FROM ambiente_operacoes ao
                WHERE ao.ambiente_id=a.id AND ao.tipo='database.provision'
                ORDER BY ao.id DESC LIMIT 1
            ) database_operacao_status,
            (
                SELECT ao.status FROM ambiente_operacoes ao
                WHERE ao.ambiente_id=a.id AND ao.tipo='runtime.php.activate'
                ORDER BY ao.id DESC LIMIT 1
            ) php_operacao_status,
            (
                SELECT ao.erro_codigo FROM ambiente_operacoes ao
                WHERE ao.ambiente_id=a.id
                  AND ao.tipo IN ('database.provision','runtime.php.activate')
                ORDER BY ao.id DESC LIMIT 1
            ) ultima_operacao_erro
         FROM ambientes a
         LEFT JOIN ambiente_runtimes ar ON ar.ambiente_id=a.id
         WHERE a.projeto_id=:project
         ORDER BY FIELD(a.tipo,'sandbox','production'),a.id"
    );
    $stmt->execute(['project' => $projectId]);
    return $stmt->fetchAll();
}

function environment_operation_status_label(?string $status): string
{
    return match ($status) {
        'pendente' => 'na fila',
        'executando' => 'em execução',
        'concluida' => 'concluída',
        'falhou' => 'falhou',
        default => 'não solicitado',
    };
}
