<?php

declare(strict_types=1);

final class RealtimeAuthorizationException extends RuntimeException
{
}

const REALTIME_TICKET_PREFIX = 'threeebs:realtime:ticket:';

function realtime_redis(): Redis
{
    static $connection = null;
    if ($connection instanceof Redis) {
        return $connection;
    }
    if (!class_exists(Redis::class)) {
        throw new RuntimeException('Extensão Redis indisponível.');
    }
    $connection = new Redis();
    $connection->connect(
        (string) (getenv('REDIS_HOST') ?: 'redis'),
        env_int('REDIS_PORT', 6379, 1, 65535),
        3.0
    );
    $connection->auth(env_required('REDIS_PASSWORD'));
    return $connection;
}

function realtime_room_id(string $projectUuid, string $environmentUuid, string $path): string
{
    return hash('sha256', $projectUuid . "\n" . $environmentUuid . "\n" . $path);
}

function realtime_issue_ticket(array $access, array $user, string $path): array
{
    $file = project_storage_service($access)->readFile($path);
    $normalizedPath = (string) $file['path'];
    $room = realtime_room_id(
        (string) $access['project']['uuid'],
        (string) $access['environment']['uuid'],
        $normalizedPath
    );
    $ttl = env_int('REALTIME_TICKET_TTL_SECONDS', 30, 5, 120);
    $expiresAt = time() + $ttl;
    $token = bin2hex(random_bytes(32));
    $controlToken = bin2hex(random_bytes(32));
    $payload = [
        'user_uuid' => (string) $user['uuid'],
        'user_name' => (string) ($user['nome'] ?? 'Pessoa'),
        'project_uuid' => (string) $access['project']['uuid'],
        'environment_uuid' => (string) $access['environment']['uuid'],
        'environment_type' => 'sandbox',
        'path' => $normalizedPath,
        'room_id' => $room,
        'capabilities' => ['read', 'write'],
        'control_token' => $controlToken,
        'expires_at' => $expiresAt,
    ];
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (!realtime_redis()->setex(REALTIME_TICKET_PREFIX . $token, $ttl, $encoded)) {
        throw new RuntimeException('Não foi possível emitir o ticket realtime.');
    }

    return [
        'ticket' => $token,
        'room' => $room,
        'url' => '/realtime',
        'checkpoint_url' => '/realtime-api/checkpoint/' . $room,
        'control_token' => $controlToken,
        'expires_in' => $ttl,
        'user' => [
            'id' => (string) $user['uuid'],
            'name' => (string) ($user['nome'] ?? 'Pessoa'),
        ],
    ];
}

function realtime_require_internal_request(): void
{
    $configured = (string) (getenv('REALTIME_INTERNAL_SECRET') ?: '');
    $received = (string) ($_SERVER['HTTP_X_THREEEBS_REALTIME_SECRET'] ?? '');
    if ($configured === '' || str_starts_with($configured, 'TROQUE_')
        || $received === '' || !hash_equals($configured, $received)) {
        http_response_code(403);
        exit('Acesso interno negado.');
    }
}

function realtime_internal_access(
    string $userUuid,
    string $projectUuid,
    string $environmentUuid
): array {
    if (!preg_match('/^[0-9a-f-]{36}$/D', $userUuid)
        || !preg_match('/^[0-9a-f-]{36}$/D', $projectUuid)
        || !preg_match('/^[0-9a-f-]{36}$/D', $environmentUuid)) {
        throw new ValidationException('Identidade realtime inválida.');
    }

    $user = current_active_user(['uuid' => $userUuid]);
    $project = $user ? authorized_project($projectUuid, $user) : null;
    if (!$user || !$project || (string) ($project['status'] ?? '') !== 'ativo') {
        throw new RealtimeAuthorizationException('Acesso realtime revogado.');
    }

    $statement = db('control')->prepare(
        "SELECT id,uuid,diretorio,status,tipo
         FROM ambientes
         WHERE uuid=:environment AND projeto_id=:project
           AND tipo='sandbox' AND status='ativo'
         LIMIT 1"
    );
    $statement->execute([
        'environment' => $environmentUuid,
        'project' => $project['id'],
    ]);
    $environment = $statement->fetch();
    if (!is_array($environment)) {
        throw new RealtimeAuthorizationException('Ambiente realtime inválido.');
    }

    return [
        'user' => $user,
        'project' => $project,
        'environment' => $environment,
        'root' => safe_environment_path((string) $environment['diretorio'], false),
    ];
}

function realtime_validate_room(array $access, string $path, string $room): void
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $room)
        || !hash_equals(
            realtime_room_id(
                (string) $access['project']['uuid'],
                (string) $access['environment']['uuid'],
                $path
            ),
            $room
        )) {
        throw new ValidationException('Sala realtime inválida.');
    }
}
