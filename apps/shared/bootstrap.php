<?php

declare(strict_types=1);

const PROJECTS_ROOT = '/var/www/projects';

date_default_timezone_set('UTC');
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
ini_set('log_errors', '1');

final class ValidationException extends RuntimeException
{
}

require_once __DIR__ . '/sandbox_files.php';
require_once __DIR__ . '/storage_service.php';
require_once __DIR__ . '/project_plans.php';
require_once __DIR__ . '/realtime.php';

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $configuredScheme = strtolower((string) parse_url((string) (getenv('APP_URL') ?: ''), PHP_URL_SCHEME));
    $trustProxyHeaders = strtolower((string) (getenv('TRUST_PROXY_HEADERS') ?: 'false')) === 'true';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $configuredScheme === 'https'
        || ($trustProxyHeaders
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $https ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_lifetime', '0');
    session_name('threeebs_' . preg_replace('/[^a-z0-9_]/', '', strtolower((string) (getenv('APP_CONTEXT') ?: 'app'))));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cache-Control: no-store');
    $context = strtolower((string) (getenv('APP_CONTEXT') ?: 'app'));
    if ($context === 'sandbox') {
        header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; worker-src 'self' blob:");
    } elseif (in_array($context, ['admin', 'portal'], true)) {
        header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'none'");
    }
}

function env_required(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Variável obrigatória ausente: {$name}");
    }
    return $value;
}

function db(string $domain): PDO
{
    static $connections = [];
    $allowed = [
        'identity' => 'IDENTITY_DB_NAME',
        'control' => 'CONTROL_DB_NAME',
        'work' => 'WORK_DB_NAME',
        'catalog' => 'CATALOG_DB_NAME',
        'finance' => 'FINANCE_DB_NAME',
        'audit' => 'AUDIT_DB_NAME',
    ];
    if (!isset($allowed[$domain])) {
        throw new InvalidArgumentException('Domínio de banco inválido.');
    }
    if (isset($connections[$domain])) {
        return $connections[$domain];
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('MYSQL_HOST') ?: 'mysql',
        getenv('MYSQL_PORT') ?: '3306',
        env_required($allowed[$domain])
    );
    $connections[$domain] = new PDO($dsn, env_required('DB_USER'), env_required('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    return $connections[$domain];
}

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function request_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = '/' . trim((string) $path, '/');
    return $path === '/' ? '/' : rtrim($path, '/');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function require_csrf(): void
{
    $sent = $_POST['csrf'] ?? null;
    $known = $_SESSION['csrf'] ?? null;
    if (!is_string($sent) || !is_string($known) || !hash_equals($known, $sent)) {
        header('HTTP/1.1 419 Page Expired', true);
        exit('CSRF inválido. Recarregue a página.');
    }
}

function uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function slug(string $value): string
{
    $value = strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function normalize_hostname(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('#^https?://#', '', $value) ?? $value;
    $value = explode('/', $value, 2)[0];
    $value = rtrim(explode(':', $value, 2)[0], '.');
    if ($value === '' || strlen($value) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value)) {
        throw new InvalidArgumentException('Hostname inválido. Informe apenas o domínio, sem protocolo ou caminho.');
    }
    return $value;
}

function auth_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function current_active_user(?array $user = null): ?array
{
    static $users = [];
    $user ??= auth_user();
    $uuid = is_array($user) ? (string) ($user['uuid'] ?? '') : '';
    if ($uuid === '') {
        return null;
    }
    if (!array_key_exists($uuid, $users)) {
        $stmt = db('identity')->prepare(
            'SELECT id,uuid,nome,email,status
             FROM usuarios
             WHERE uuid=:uuid LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();
        $users[$uuid] = is_array($row) && (string) $row['status'] === 'ativo' ? $row : null;
    }
    if (is_array($users[$uuid]) && isset($_SESSION['user'])
        && (string) ($_SESSION['user']['uuid'] ?? '') === $uuid) {
        $_SESSION['user'] = $users[$uuid];
    }
    return $users[$uuid];
}

function installation(): array
{
    $row = db('control')->query('SELECT * FROM configuracao_instalacao WHERE id = 1')->fetch();
    return is_array($row) ? $row : ['status' => 'pendente'];
}

function platform_membership(?array $user = null): ?array
{
    static $memberships = [];
    $user = current_active_user($user);
    $userUuid = is_array($user) ? (string) ($user['uuid'] ?? '') : '';
    if ($userUuid === '') {
        return null;
    }
    if (array_key_exists($userUuid, $memberships)) {
        return $memberships[$userUuid];
    }
    $stmt = db('control')->prepare(
        'SELECT uuid,usuario_uuid,papel,ativo
         FROM plataforma_usuarios
         WHERE usuario_uuid=:user LIMIT 1'
    );
    $stmt->execute(['user' => $userUuid]);
    $membership = $stmt->fetch();
    $memberships[$userUuid] = is_array($membership) ? $membership : null;
    return $memberships[$userUuid];
}

function platform_role(?array $user = null): ?string
{
    $membership = platform_membership($user);
    return $membership && (int) $membership['ativo'] === 1
        ? (string) $membership['papel']
        : null;
}

function is_admin(?array $user = null): bool
{
    return in_array(platform_role($user), ['owner', 'admin'], true);
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function partner_application_by_email(string $email): ?array
{
    $stmt = db('control')->prepare(
        'SELECT * FROM parceiro_candidaturas WHERE email=:email LIMIT 1'
    );
    $stmt->execute(['email' => normalize_email($email)]);
    $application = $stmt->fetch();
    return is_array($application) ? $application : null;
}

function partner_application_by_uuid(string $uuid): ?array
{
    if ($uuid === '') {
        return null;
    }
    $stmt = db('control')->prepare(
        'SELECT * FROM parceiro_candidaturas WHERE uuid=:uuid LIMIT 1'
    );
    $stmt->execute(['uuid' => $uuid]);
    $application = $stmt->fetch();
    return is_array($application) ? $application : null;
}

function partner_application_for_user(?array $user = null): ?array
{
    $user = current_active_user($user ?? auth_user());
    if (!$user) {
        return null;
    }
    $stmt = db('control')->prepare(
        'SELECT * FROM parceiro_candidaturas
         WHERE usuario_uuid=:user OR email=:email
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['user' => $user['uuid'], 'email' => normalize_email((string) $user['email'])]);
    $application = $stmt->fetch();
    return is_array($application) ? $application : null;
}

function active_partner(?array $user = null): ?array
{
    $user = current_active_user($user ?? auth_user());
    if (!$user) {
        return null;
    }
    $stmt = db('control')->prepare(
        "SELECT p.*,pc.email,pc.nome
         FROM parceiros p
         JOIN parceiro_candidaturas pc
           ON pc.id=p.candidatura_id AND pc.status='aprovado'
         WHERE p.usuario_uuid=:user AND p.status='ativo'
         LIMIT 1"
    );
    $stmt->execute(['user' => $user['uuid']]);
    $partner = $stmt->fetch();
    return is_array($partner) ? $partner : null;
}

function is_partner(?array $user = null): bool
{
    return active_partner($user) !== null;
}

function require_partner(): array
{
    $user = require_auth();
    $partner = active_partner($user);
    if (!$partner) {
        http_response_code(403);
        exit('Acesso de parceiro ainda não liberado.');
    }
    return ['user' => $user, 'partner' => $partner];
}

function partner_owned_client(string $clientUuid, string $userUuid): ?array
{
    $stmt = db('control')->prepare(
        "SELECT c.*
         FROM clientes c
         JOIN parceiros partner
           ON partner.usuario_uuid=:user AND partner.status='ativo'
         WHERE c.uuid=:client
           AND c.criado_por_usuario_uuid=partner.usuario_uuid
           AND c.status='ativo'
         LIMIT 1"
    );
    $stmt->execute(['client' => $clientUuid, 'user' => $userUuid]);
    $client = $stmt->fetch();
    return is_array($client) ? $client : null;
}

function partner_owned_project(string $projectUuid, string $userUuid): ?array
{
    $stmt = db('control')->prepare(
        "SELECT p.*,c.uuid cliente_uuid,c.nome cliente_nome
         FROM projetos p
         JOIN clientes c ON c.id=p.cliente_id
         JOIN parceiros partner
           ON partner.usuario_uuid=:user AND partner.status='ativo'
         WHERE p.uuid=:project
           AND c.criado_por_usuario_uuid=partner.usuario_uuid
           AND p.status='ativo' AND c.status='ativo'
         LIMIT 1"
    );
    $stmt->execute(['project' => $projectUuid, 'user' => $userUuid]);
    $project = $stmt->fetch();
    return is_array($project) ? $project : null;
}

function n8n_webhook_event(string $event, array $payload, string $idempotencyKey): array
{
    $url = trim((string) (getenv('N8N_WEBHOOK_URL') ?: ''));
    $secret = (string) (getenv('N8N_WEBHOOK_SECRET') ?: '');
    if ($url === '' || $secret === '' || str_starts_with($secret, 'TROQUE_')) {
        return ['success' => false, 'status' => 'configuracao_ausente', 'error' => 'Integração n8n não configurada.'];
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $production = (string) (getenv('APP_ENV') ?: 'development') === 'production';
    if (!filter_var($url, FILTER_VALIDATE_URL)
        || !in_array($scheme, $production ? ['https'] : ['http', 'https'], true)) {
        return ['success' => false, 'status' => 'configuracao_invalida', 'error' => 'URL do n8n inválida.'];
    }

    $timestamp = (string) time();
    $body = json_encode([
        'event' => $event,
        'event_id' => $idempotencyKey,
        'occurred_at' => gmdate('c'),
        'data' => $payload,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    $timeout = env_int('N8N_WEBHOOK_TIMEOUT_SECONDS', 5, 1, 20);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Threeebs-Public/1.0',
                'X-Threeebs-Event: ' . $event,
                'X-Threeebs-Timestamp: ' . $timestamp,
                'X-Threeebs-Signature: sha256=' . $signature,
                'Idempotency-Key: ' . $idempotencyKey,
            ]),
            'content' => $body,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $statusCode = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string) $headers[0], $match)) {
        $statusCode = (int) $match[1];
    }
    if ($response !== false && $statusCode >= 200 && $statusCode < 300) {
        return ['success' => true, 'status' => 'enviado', 'error' => null];
    }
    return [
        'success' => false,
        'status' => 'falhou',
        'error' => $statusCode > 0 ? 'Resposta HTTP ' . $statusCode . ' do n8n.' : 'Falha de transporte ao n8n.',
    ];
}

function password_policy_errors(string $password): array
{
    $errors = [];
    $length = strlen($password);
    if ($length < 12 || $length > 128) {
        $errors[] = 'ter entre 12 e 128 caracteres';
    }
    if (preg_match('/\p{Ll}/u', $password) !== 1) {
        $errors[] = 'incluir uma letra minúscula';
    }
    if (preg_match('/\p{Lu}/u', $password) !== 1) {
        $errors[] = 'incluir uma letra maiúscula';
    }
    if (preg_match('/\p{N}/u', $password) !== 1) {
        $errors[] = 'incluir um número';
    }
    if (preg_match('/[^\p{L}\p{N}]/u', $password) !== 1) {
        $errors[] = 'incluir um símbolo';
    }
    return $errors;
}

function require_strong_password(string $password, string $confirmation): void
{
    if (!hash_equals($password, $confirmation)) {
        throw new ValidationException('As duas senhas precisam ser iguais.');
    }
    $errors = password_policy_errors($password);
    if ($errors !== []) {
        throw new ValidationException('A senha deve ' . implode(', ', $errors) . '.');
    }
}

function portal_auth_url(string $path, string $token): string
{
    $base = rtrim((string) (getenv('PORTAL_URL') ?: getenv('APP_URL') ?: ''), '/');
    if ($base === '' || !str_starts_with($path, '/')) {
        throw new RuntimeException('URL pública de autenticação não configurada.');
    }
    return $base . $path . '?token=' . rawurlencode($token);
}

function request_password_reset(string $email): array
{
    $email = normalize_email($email);
    $neutral = ['issued' => false, 'webhook' => null, 'retry_after' => 0];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        authentication_event('password_reset_requested', false, null, $email, ['motivo' => 'identificador_invalido']);
        return $neutral;
    }

    $identity = db('identity');
    $cooldown = env_int('PASSWORD_RESET_COOLDOWN_SECONDS', 40, 40, 3600);
    $token = bin2hex(random_bytes(32));
    $tokenUuid = uuid_v4();
    $ttl = env_int('PASSWORD_RESET_TTL_SECONDS', 3600, 300, 86400);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);
    $resetUrl = portal_auth_url('/redefinir-senha', $token);
    $identity->beginTransaction();
    try {
        $stmt = $identity->prepare(
            "SELECT id,uuid,nome,email FROM usuarios
             WHERE email=:email AND status='ativo' LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if (!is_array($user)) {
            $identity->rollBack();
            authentication_event('password_reset_requested', false, null, $email, ['motivo' => 'conta_indisponivel']);
            return $neutral;
        }
        $stmt = $identity->prepare(
            "SELECT created_at FROM tokens
             WHERE usuario_id=:user AND tipo='password_reset'
               AND created_at>=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL {$cooldown} SECOND)
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute(['user' => $user['id']]);
        $lastRequest = $stmt->fetchColumn();
        if (is_string($lastRequest) && $lastRequest !== '') {
            $identity->commit();
            $neutral['retry_after'] = max(1, $cooldown - max(0, time() - (int) strtotime($lastRequest)));
            return $neutral;
        }
        $stmt = $identity->prepare(
            "UPDATE tokens SET revogado_em=UTC_TIMESTAMP(6)
             WHERE usuario_id=:user AND tipo='password_reset'
               AND usado_em IS NULL AND revogado_em IS NULL"
        );
        $stmt->execute(['user' => $user['id']]);
        $stmt = $identity->prepare(
            "INSERT INTO tokens (uuid,usuario_id,tipo,token_hash,expira_em)
             VALUES (:uuid,:user,'password_reset',:hash,:expires)"
        );
        $stmt->execute([
            'uuid' => $tokenUuid,
            'user' => $user['id'],
            'hash' => hash('sha256', $token),
            'expires' => $expiresAt,
        ]);
        $identity->commit();
    } catch (Throwable $error) {
        if ($identity->inTransaction()) {
            $identity->rollBack();
        }
        throw $error;
    }

    $webhook = n8n_webhook_event('autenticacao.redefinicao_senha_solicitada', [
        'user_uuid' => $user['uuid'],
        'email' => $user['email'],
        'name' => $user['nome'],
        'reset_url' => $resetUrl,
        'expires_at' => $expiresAt,
    ], 'password-reset:' . $tokenUuid);
    authentication_event('password_reset_requested', true, (int) $user['id'], $email, [
        'webhook_status' => $webhook['status'] ?? 'falhou',
    ]);
    return ['issued' => true, 'webhook' => $webhook, 'retry_after' => $cooldown];
}

function create_user_with_first_access(string $name, string $email, array $invitedBy): array
{
    $name = trim($name);
    $email = normalize_email($email);
    if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 190) {
        throw new ValidationException('Informe um nome e um e-mail válido.');
    }

    $identity = db('identity');
    $token = bin2hex(random_bytes(32));
    $tokenUuid = uuid_v4();
    $userUuid = uuid_v4();
    $ttl = env_int('FIRST_ACCESS_TTL_SECONDS', 86400, 300, 604800);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);
    $firstAccessUrl = portal_auth_url('/primeiro-acesso', $token);
    $identity->beginTransaction();
    try {
        $stmt = $identity->prepare('SELECT id FROM usuarios WHERE email=:email LIMIT 1 FOR UPDATE');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn() !== false) {
            throw new ValidationException('Já existe um usuário com este e-mail.');
        }
        $stmt = $identity->prepare(
            "INSERT INTO usuarios (uuid,nome,email,status) VALUES (:uuid,:name,:email,'ativo')"
        );
        $stmt->execute(['uuid' => $userUuid, 'name' => $name, 'email' => $email]);
        $userId = (int) $identity->lastInsertId();
        $stmt = $identity->prepare(
            "INSERT INTO tokens (uuid,usuario_id,tipo,token_hash,expira_em)
             VALUES (:uuid,:user,'first_access',:hash,:expires)"
        );
        $stmt->execute([
            'uuid' => $tokenUuid,
            'user' => $userId,
            'hash' => hash('sha256', $token),
            'expires' => $expiresAt,
        ]);
        $identity->commit();
    } catch (Throwable $error) {
        if ($identity->inTransaction()) {
            $identity->rollBack();
        }
        throw $error;
    }

    $webhook = n8n_webhook_event('autenticacao.primeiro_acesso_solicitado', [
        'user_uuid' => $userUuid,
        'email' => $email,
        'name' => $name,
        'invited_by_user_uuid' => $invitedBy['uuid'] ?? null,
        'first_access_url' => $firstAccessUrl,
        'expires_at' => $expiresAt,
    ], 'first-access:' . $tokenUuid);
    authentication_event('first_access_requested', true, $userId, $email, [
        'webhook_status' => $webhook['status'] ?? 'falhou',
    ]);
    return [
        'user' => ['id' => $userId, 'uuid' => $userUuid, 'nome' => $name, 'email' => $email],
        'webhook' => $webhook,
    ];
}

function password_token_context(string $token, array $allowedTypes = ['password_reset', 'first_access']): array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || $allowedTypes === []) {
        throw new ValidationException('Este link é inválido, expirou ou já foi utilizado.');
    }
    $placeholders = implode(',', array_fill(0, count($allowedTypes), '?'));
    $stmt = db('identity')->prepare(
        "SELECT t.id token_id,t.tipo,t.expira_em,u.id usuario_id,u.uuid usuario_uuid,u.nome,u.email,u.status
         FROM tokens t JOIN usuarios u ON u.id=t.usuario_id
         WHERE t.token_hash=? AND t.tipo IN ({$placeholders})
           AND t.usado_em IS NULL AND t.revogado_em IS NULL
           AND t.expira_em>UTC_TIMESTAMP(6) AND u.status='ativo'
         LIMIT 1"
    );
    $stmt->execute(array_merge([hash('sha256', $token)], $allowedTypes));
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new ValidationException('Este link é inválido, expirou ou já foi utilizado.');
    }
    return $row;
}

function complete_password_token(string $token, string $password, string $confirmation): array
{
    require_strong_password($password, $confirmation);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new ValidationException('Este link é inválido, expirou ou já foi utilizado.');
    }

    $identity = db('identity');
    $identity->beginTransaction();
    try {
        $stmt = $identity->prepare(
            "SELECT t.id token_id,t.tipo,u.id usuario_id,u.uuid usuario_uuid,u.nome,u.email,u.status
             FROM tokens t JOIN usuarios u ON u.id=t.usuario_id
             WHERE t.token_hash=:hash AND t.tipo IN ('password_reset','first_access')
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!is_array($row) || (string) $row['status'] !== 'ativo') {
            throw new ValidationException('Este link é inválido, expirou ou já foi utilizado.');
        }
        $valid = $identity->prepare(
            'SELECT 1 FROM tokens WHERE id=:id AND usado_em IS NULL AND revogado_em IS NULL
             AND expira_em>UTC_TIMESTAMP(6)'
        );
        $valid->execute(['id' => $row['token_id']]);
        if ($valid->fetchColumn() === false) {
            throw new ValidationException('Este link é inválido, expirou ou já foi utilizado.');
        }
        $stmt = $identity->prepare(
            "INSERT INTO credenciais (uuid,usuario_id,tipo,identificador,segredo_hash,ativa)
             VALUES (:uuid,:user,'senha','',:hash,1)
             ON DUPLICATE KEY UPDATE segredo_hash=VALUES(segredo_hash),ativa=1,
                 expira_em=NULL,ultimo_uso_em=NULL"
        );
        $stmt->execute([
            'uuid' => uuid_v4(),
            'user' => $row['usuario_id'],
            'hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $stmt = $identity->prepare('UPDATE tokens SET usado_em=UTC_TIMESTAMP(6) WHERE id=:id');
        $stmt->execute(['id' => $row['token_id']]);
        $stmt = $identity->prepare(
            "UPDATE tokens SET revogado_em=UTC_TIMESTAMP(6)
             WHERE usuario_id=:user AND tipo IN ('password_reset','first_access')
               AND id<>:token AND usado_em IS NULL AND revogado_em IS NULL"
        );
        $stmt->execute(['user' => $row['usuario_id'], 'token' => $row['token_id']]);
        $stmt = $identity->prepare(
            'UPDATE usuarios SET email_verificado_em=COALESCE(email_verificado_em,UTC_TIMESTAMP(6)),
             bloqueado_ate=NULL,bloqueio_motivo=NULL WHERE id=:id'
        );
        $stmt->execute(['id' => $row['usuario_id']]);
        $stmt = $identity->prepare(
            'UPDATE sessoes SET revogada_em=UTC_TIMESTAMP(6)
             WHERE usuario_id=:user AND revogada_em IS NULL'
        );
        $stmt->execute(['user' => $row['usuario_id']]);
        $identity->commit();
    } catch (Throwable $error) {
        if ($identity->inTransaction()) {
            $identity->rollBack();
        }
        throw $error;
    }

    authentication_event(
        (string) $row['tipo'] === 'first_access' ? 'first_access_completed' : 'password_reset_completed',
        true,
        (int) $row['usuario_id'],
        (string) $row['email']
    );
    return $row;
}

function update_partner_webhook_delivery(string $applicationUuid, string $phase, array $result): void
{
    $columns = [
        'confirmacao' => 'confirmacao_webhook',
        'aprovacao' => 'aprovacao_webhook',
    ];
    if (!isset($columns[$phase])) {
        throw new InvalidArgumentException('Fase de webhook inválida.');
    }
    $prefix = $columns[$phase];
    $stmt = db('control')->prepare(
        "UPDATE parceiro_candidaturas
         SET {$prefix}_status=:status,
             {$prefix}_tentativas={$prefix}_tentativas+1,
             {$prefix}_ultimo_erro=:error,
             {$prefix}_ultimo_envio_em=UTC_TIMESTAMP(6)
         WHERE uuid=:uuid"
    );
    $stmt->execute([
        'status' => substr((string) ($result['status'] ?? 'falhou'), 0, 30),
        'error' => isset($result['error']) ? substr((string) $result['error'], 0, 255) : null,
        'uuid' => $applicationUuid,
    ]);
}

function request_partner_email_confirmation(string $email): array
{
    $email = normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new ValidationException('Informe um e-mail válido.');
    }
    $existing = partner_application_by_email($email);
    if ($existing && in_array((string) $existing['status'], ['aguardando_analise', 'aprovado'], true)) {
        return ['application' => $existing, 'webhook' => null];
    }

    $identity = db('identity');
    $maximum = env_int('PARTNER_CONFIRMATION_MAX_PER_HOUR', 5, 1, 20);
    $count = $identity->prepare(
        "SELECT COUNT(*) FROM convites
         WHERE email=:email AND finalidade='parceiro_email_confirmacao'
           AND created_at >= UTC_TIMESTAMP(6) - INTERVAL 1 HOUR"
    );
    $count->execute(['email' => $email]);
    if ((int) $count->fetchColumn() >= $maximum) {
        return ['application' => $existing, 'webhook' => null];
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $inviteUuid = uuid_v4();
    $applicationUuid = $existing['uuid'] ?? uuid_v4();
    $ttl = env_int('PARTNER_CONFIRMATION_TTL_SECONDS', 86400, 300, 604800);
    $expiresAt = gmdate('Y-m-d H:i:s.u', time() + $ttl);

    $identity->beginTransaction();
    try {
        $stmt = $identity->prepare(
            "UPDATE convites
             SET revogado_em=UTC_TIMESTAMP(6)
             WHERE email=:email AND finalidade='parceiro_email_confirmacao'
               AND aceito_em IS NULL AND revogado_em IS NULL"
        );
        $stmt->execute(['email' => $email]);
        $stmt = $identity->prepare(
            "INSERT INTO convites
                (uuid,email,finalidade,token_hash,expira_em)
             VALUES (:uuid,:email,'parceiro_email_confirmacao',:hash,:expires)"
        );
        $stmt->execute([
            'uuid' => $inviteUuid,
            'email' => $email,
            'hash' => $tokenHash,
            'expires' => $expiresAt,
        ]);
        $stmt = $identity->prepare(
            "INSERT INTO threeebs_control.parceiro_candidaturas
                (uuid,convite_uuid,email,status,confirmacao_webhook_status)
             VALUES (:uuid,:invite,:email,'email_pendente','pendente')
             ON DUPLICATE KEY UPDATE
                convite_uuid=VALUES(convite_uuid),
                confirmacao_webhook_status='pendente',
                confirmacao_webhook_ultimo_erro=NULL"
        );
        $stmt->execute(['uuid' => $applicationUuid, 'invite' => $inviteUuid, 'email' => $email]);
        $identity->commit();
    } catch (Throwable $error) {
        if ($identity->inTransaction()) {
            $identity->rollBack();
        }
        throw $error;
    }

    $application = partner_application_by_email($email);
    $confirmationUrl = rtrim((string) (getenv('PORTAL_URL') ?: getenv('APP_URL') ?: ''), '/')
        . '/parceiro/confirmar?token=' . rawurlencode($token);
    $webhook = n8n_webhook_event('parceiro.confirmacao_solicitada', [
        'application_uuid' => $applicationUuid,
        'email' => $email,
        'confirmation_url' => $confirmationUrl,
        'expires_at' => $expiresAt,
    ], 'partner-confirmation:' . $applicationUuid . ':' . $inviteUuid);
    update_partner_webhook_delivery($applicationUuid, 'confirmacao', $webhook);
    return ['application' => $application, 'webhook' => $webhook];
}

function confirm_partner_email_token(string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new ValidationException('Link de confirmação inválido ou expirado.');
    }
    $identity = db('identity');
    $identity->beginTransaction();
    try {
        $stmt = $identity->prepare(
            "SELECT i.id invite_id,i.uuid invite_uuid,i.email,i.expira_em,i.aceito_em,i.revogado_em,
                    c.uuid application_uuid,c.status
             FROM convites i
             JOIN threeebs_control.parceiro_candidaturas c ON c.convite_uuid=i.uuid
             WHERE i.token_hash=:hash AND i.finalidade='parceiro_email_confirmacao'
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!is_array($row) || $row['aceito_em'] !== null || $row['revogado_em'] !== null
            || strtotime((string) $row['expira_em']) <= time()) {
            throw new ValidationException('Link de confirmação inválido, expirado ou já utilizado.');
        }
        $stmt = $identity->prepare(
            'UPDATE convites SET aceito_em=UTC_TIMESTAMP(6) WHERE id=:id AND aceito_em IS NULL'
        );
        $stmt->execute(['id' => $row['invite_id']]);
        if ($stmt->rowCount() !== 1) {
            throw new ValidationException('Este link de confirmação já foi utilizado.');
        }
        $stmt = $identity->prepare(
            "UPDATE threeebs_control.parceiro_candidaturas
             SET email_confirmado_em=COALESCE(email_confirmado_em,UTC_TIMESTAMP(6)),
                 status=IF(status='email_pendente','email_confirmado',status)
             WHERE uuid=:uuid AND convite_uuid=:invite"
        );
        $stmt->execute(['uuid' => $row['application_uuid'], 'invite' => $row['invite_uuid']]);
        $identity->commit();
    } catch (Throwable $error) {
        if ($identity->inTransaction()) {
            $identity->rollBack();
        }
        throw $error;
    }
    $application = partner_application_by_uuid((string) $row['application_uuid']);
    if (!$application) {
        throw new RuntimeException('Candidatura não encontrada após confirmação.');
    }
    return $application;
}

function create_partner_client(array $user, string $name, string $clientSlug): array
{
    if (!active_partner($user)) {
        throw new ValidationException('Seu perfil de parceiro não está ativo.');
    }
    $name = trim($name);
    $clientSlug = slug($clientSlug);
    if ($name === '' || $clientSlug === '') {
        throw new ValidationException('Nome e slug do cliente são obrigatórios.');
    }
    $control = db('control');
    $check = $control->prepare('SELECT 1 FROM clientes WHERE slug=:slug LIMIT 1');
    $check->execute(['slug' => $clientSlug]);
    if ($check->fetchColumn()) {
        throw new ValidationException('Este slug de cliente já está em uso.');
    }
    $uuid = uuid_v4();
    $control->beginTransaction();
    try {
        $stmt = $control->prepare(
            "INSERT INTO clientes (uuid,nome,slug,status,criado_por_usuario_uuid)
             VALUES (:uuid,:name,:slug,'ativo',:user)"
        );
        $stmt->execute(['uuid' => $uuid, 'name' => $name, 'slug' => $clientSlug, 'user' => $user['uuid']]);
        $clientId = (int) $control->lastInsertId();
        $stmt = $control->prepare(
            "INSERT INTO cliente_usuarios
                (cliente_id,usuario_uuid,papel,ativo,concedido_por_usuario_uuid)
             VALUES (:client,:member_user,'proprietario',1,:granted_by)"
        );
        $stmt->execute([
            'client' => $clientId,
            'member_user' => $user['uuid'],
            'granted_by' => $user['uuid'],
        ]);
        $control->commit();
    } catch (Throwable $error) {
        if ($control->inTransaction()) {
            $control->rollBack();
        }
        throw $error;
    }
    audit_event('cliente.criado', 'cliente', $uuid, $uuid, null, null, ['origem' => 'parceiro']);
    return ['id' => $clientId, 'uuid' => $uuid, 'nome' => $name, 'slug' => $clientSlug];
}

function create_partner_project(
    array $user,
    string $clientUuid,
    string $name,
    string $projectSlug,
    string $description
): array {
    if (!active_partner($user)) {
        throw new ValidationException('Seu perfil de parceiro não está ativo.');
    }
    $client = partner_owned_client($clientUuid, (string) $user['uuid']);
    $name = trim($name);
    $projectSlug = slug($projectSlug);
    if (!$client) {
        throw new ValidationException('Cliente próprio ativo não encontrado.');
    }
    if ($name === '' || $projectSlug === '') {
        throw new ValidationException('Nome e slug do projeto são obrigatórios.');
    }
    $baseDomain = normalize_hostname((string) (getenv('PREVIEW_BASE_DOMAIN') ?: getenv('BASE_DOMAIN') ?: ''));
    $hostnames = [
        'production' => normalize_hostname($projectSlug . '.' . $baseDomain),
        'sandbox' => normalize_hostname('sandbox-' . $projectSlug . '.' . $baseDomain),
    ];
    $control = db('control');
    $routeCheck = $control->prepare(
        'SELECT hostname FROM rotas_web WHERE hostname IN (:production,:sandbox) LIMIT 1'
    );
    $routeCheck->execute(['production' => $hostnames['production'], 'sandbox' => $hostnames['sandbox']]);
    if ($routeCheck->fetchColumn()) {
        throw new ValidationException('O endereço deste projeto já está em uso. Escolha outro slug.');
    }
    $serverId = $control->query(
        "SELECT id FROM servidores WHERE padrao=1 AND status='ativo' ORDER BY id LIMIT 1"
    )->fetchColumn();
    if (!$serverId) {
        throw new ValidationException('Servidor padrão ativo não encontrado.');
    }

    $projectUuid = uuid_v4();
    $environments = [];
    $control->beginTransaction();
    try {
        $stmt = $control->prepare(
            "INSERT INTO projetos (uuid,cliente_id,nome,slug,descricao,status)
             VALUES (:uuid,:client,:name,:slug,:description,'ativo')"
        );
        $stmt->execute([
            'uuid' => $projectUuid,
            'client' => $client['id'],
            'name' => $name,
            'slug' => $projectSlug,
            'description' => trim($description),
        ]);
        $projectId = (int) $control->lastInsertId();
        $stmt = $control->prepare(
            "INSERT INTO projeto_usuarios
                (projeto_id,usuario_uuid,papel,ativo,concedido_por_usuario_uuid)
             VALUES (:project,:member_user,'gestor',1,:granted_by)"
        );
        $stmt->execute([
            'project' => $projectId,
            'member_user' => $user['uuid'],
            'granted_by' => $user['uuid'],
        ]);

        $environmentInsert = $control->prepare(
            "INSERT INTO ambientes
                (uuid,projeto_id,servidor_id,tipo,nome,slug,diretorio,status)
             VALUES (:uuid,:project,:server,:type,:name,:slug,:directory,'ativo')"
        );
        $runtimeInsert = $control->prepare(
            "INSERT INTO ambiente_runtimes
                (uuid,ambiente_id,tipo,execucao_habilitada,mount_target,status,configuracao)
             VALUES (:uuid,:environment,'runtime.static',0,'/var/www/project','planejado',
                     JSON_OBJECT('document_root','/var/www/project'))"
        );
        $routeInsert = $control->prepare(
            "INSERT INTO rotas_web (uuid,ambiente_id,hostname,tipo,ativo)
             VALUES (:uuid,:environment,:hostname,'subdominio',1)"
        );
        foreach ([
            'sandbox' => ['Sandbox', 'sandbox'],
            'production' => ['Produção', 'production'],
        ] as $type => [$label, $folder]) {
            $environmentUuid = uuid_v4();
            $relative = $projectUuid . '/' . $folder;
            $environmentInsert->execute([
                'uuid' => $environmentUuid,
                'project' => $projectId,
                'server' => $serverId,
                'type' => $type,
                'name' => $label,
                'slug' => $folder,
                'directory' => $relative,
            ]);
            $environmentId = (int) $control->lastInsertId();
            $runtimeInsert->execute([
                'uuid' => uuid_v4(),
                'environment' => $environmentId,
            ]);
            $routeInsert->execute([
                'uuid' => uuid_v4(),
                'environment' => $environmentId,
                'hostname' => $hostnames[$type],
            ]);
            $directory = safe_environment_path($relative, true);
            $index = $directory . '/index.html';
            if (!is_file($index)) {
                atomic_write(
                    $index,
                    '<h1>Threeebs ' . ($type === 'sandbox' ? 'Sandbox' : 'Production') . ' :3</h1>'
                    . "\n" . '<p>Projeto: ' . h($name) . '</p>' . "\n",
                    true
                );
            }
            $environments[$type] = [
                'uuid' => $environmentUuid,
                'hostname' => $hostnames[$type],
            ];
        }
        $control->commit();
    } catch (Throwable $error) {
        if ($control->inTransaction()) {
            $control->rollBack();
        }
        throw $error;
    }
    audit_event('projeto.criado', 'projeto', $projectUuid, $clientUuid, $projectUuid, null, ['origem' => 'parceiro']);
    audit_event('ambientes.criados', 'projeto', $projectUuid, $clientUuid, $projectUuid, null, [
        'origem' => 'parceiro',
        'tipos' => ['sandbox', 'production'],
    ]);
    foreach ($environments as $type => $environment) {
        audit_event('rota.criada', 'rota_web', null, $clientUuid, $projectUuid, $environment['uuid'], [
            'origem' => 'parceiro',
            'tipo_ambiente' => $type,
            'hostname' => $environment['hostname'],
            'tipo_rota' => 'subdominio',
        ]);
    }
    return [
        'id' => $projectId,
        'uuid' => $projectUuid,
        'nome' => $name,
        'slug' => $projectSlug,
        'cliente_uuid' => $clientUuid,
    ];
}

function public_error_message(Throwable $error): string
{
    return $error instanceof ValidationException
        ? $error->getMessage()
        : 'Não foi possível concluir a operação. Tente novamente.';
}

function client_ip(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $trustProxyHeaders = strtolower((string) (getenv('TRUST_PROXY_HEADERS') ?: 'false')) === 'true';
    $forwarded = $trustProxyHeaders
        ? trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''))
        : '';
    $candidate = $forwarded !== '' ? $forwarded : $remote;
    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : '';
}

function env_int(string $name, int $default, int $minimum, int $maximum): int
{
    $raw = getenv($name);
    if (!is_string($raw) || !preg_match('/^\d+$/', $raw)) {
        return $default;
    }
    return max($minimum, min($maximum, (int) $raw));
}

function authentication_event(
    string $type,
    bool $success,
    ?int $userId,
    string $identifier,
    array $details = []
): void {
    try {
        $encodedDetails = $details === []
            ? null
            : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = db('identity')->prepare(
            'INSERT INTO eventos_autenticacao
                (usuario_id,tipo,sucesso,identificador_hash,ip_hash,user_agent_hash,detalhes)
             VALUES (:user,:type,:success,:identifier,:ip,:user_agent,:details)'
        );
        $remoteAddress = client_ip();
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $stmt->execute([
            'user' => $userId,
            'type' => $type,
            'success' => $success ? 1 : 0,
            'identifier' => $identifier === '' ? null : hash('sha256', $identifier),
            'ip' => $remoteAddress === '' ? null : hash('sha256', $remoteAddress),
            'user_agent' => $userAgent === '' ? null : hash('sha256', $userAgent),
            'details' => $encodedDetails,
        ]);
    } catch (Throwable $error) {
        error_log('Threeebs authentication event failure: ' . $error->getMessage());
    }
}

function audit_event(
    string $action,
    ?string $entityType = null,
    ?string $entityUuid = null,
    ?string $clientUuid = null,
    ?string $projectUuid = null,
    ?string $environmentUuid = null,
    array $details = []
): void {
    try {
        $user = auth_user();
        $requestId = preg_replace(
            '/[^a-zA-Z0-9._:-]/',
            '',
            (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '')
        ) ?? '';
        $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $encodedDetails = $details === []
            ? null
            : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $stmt = db('audit')->prepare(
            'INSERT INTO eventos
                (uuid,ator_tipo,ator_uuid,origem,acao,entidade_tipo,entidade_uuid,
                 cliente_uuid,projeto_uuid,ambiente_uuid,request_id,ip_hash,detalhes)
             VALUES
                (:uuid,:actor_type,:actor,:origin,:action,:entity_type,:entity,
                 :client,:project,:environment,:request,:ip,:details)'
        );
        $stmt->execute([
            'uuid' => uuid_v4(),
            'actor_type' => $user ? 'usuario' : 'sistema',
            'actor' => $user['uuid'] ?? null,
            'origin' => substr((string) (getenv('APP_CONTEXT') ?: 'app'), 0, 50),
            'action' => $action,
            'entity_type' => $entityType,
            'entity' => $entityUuid,
            'client' => $clientUuid,
            'project' => $projectUuid,
            'environment' => $environmentUuid,
            'request' => $requestId === '' ? null : substr($requestId, 0, 100),
            'ip' => $remoteAddress === '' ? null : hash('sha256', $remoteAddress),
            'details' => $encodedDetails,
        ]);
    } catch (Throwable $error) {
        error_log('Threeebs audit failure: ' . $error->getMessage());
    }
}

function login_user(string $email, string $password): bool
{
    $email = strtolower(trim($email));
    $identity = db('identity');
    $identifierHash = hash('sha256', $email);
    $ip = client_ip();
    $ipHash = $ip === '' ? null : hash('sha256', $ip);
    $maximumAttempts = env_int('LOGIN_MAX_ATTEMPTS', 5, 1, 100);
    $windowSeconds = env_int('LOGIN_WINDOW_SECONDS', 600, 1, 86400);
    $ipMaximumAttempts = env_int('LOGIN_IP_MAX_ATTEMPTS', 25, $maximumAttempts, 1000);

    $stmt = $identity->prepare(
        "SELECT u.id,u.uuid,u.nome,u.email,u.status,u.bloqueado_ate,u.bloqueio_motivo,
                (u.bloqueado_ate IS NOT NULL AND u.bloqueado_ate > UTC_TIMESTAMP(6))
                    AS esta_bloqueado,
                (u.bloqueado_ate IS NOT NULL AND u.bloqueado_ate <= UTC_TIMESTAMP(6))
                    AS bloqueio_expirado,
                c.segredo_hash
         FROM usuarios u
         JOIN credenciais c ON c.usuario_id=u.id
         WHERE u.email=:email AND c.tipo='senha' AND c.ativa=1
         ORDER BY c.id DESC LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    $userId = is_array($user) ? (int) $user['id'] : null;

    if ($ipHash !== null) {
        $ipAttempts = $identity->prepare(
            "SELECT COUNT(*)
             FROM eventos_autenticacao
             WHERE tipo='login_falha'
               AND ip_hash=:ip
               AND ocorrido_em>=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL {$windowSeconds} SECOND)"
        );
        $ipAttempts->execute(['ip' => $ipHash]);
        if ((int) $ipAttempts->fetchColumn() >= $ipMaximumAttempts) {
            authentication_event('login_bloqueado', false, $userId, $email, ['motivo' => 'limite_ip']);
            return false;
        }
    }

    if ($user && (int) $user['esta_bloqueado'] === 1) {
        authentication_event(
            'login_bloqueado',
            false,
            $userId,
            $email,
            ['motivo' => (string) ($user['bloqueio_motivo'] ?: 'seguranca')]
        );
        return false;
    }

    if ($user && (int) $user['bloqueio_expirado'] === 1) {
        $clear = $identity->prepare(
            'UPDATE usuarios
             SET bloqueado_ate=NULL,bloqueio_motivo=NULL
             WHERE id=:id AND bloqueado_ate<=UTC_TIMESTAMP(6)'
        );
        $clear->execute(['id' => $userId]);
    }

    $credentialHash = $user
        ? (string) $user['segredo_hash']
        : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $passwordMatches = password_verify($password, $credentialHash);
    $valid = $user && (string) $user['status'] === 'ativo' && $passwordMatches;

    if (!$valid) {
        authentication_event('login_falha', false, $userId, $email);
        $pairAttempts = $identity->prepare(
            "SELECT COUNT(*)
             FROM eventos_autenticacao
             WHERE tipo='login_falha'
               AND identificador_hash=:identifier
               AND ((:ip_null IS NULL AND ip_hash IS NULL) OR ip_hash=:ip_value)
               AND ocorrido_em>=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL {$windowSeconds} SECOND)"
        );
        $pairAttempts->execute([
            'identifier' => $identifierHash,
            'ip_null' => $ipHash,
            'ip_value' => $ipHash,
        ]);
        if ($user && (string) $user['status'] === 'ativo'
            && (int) $pairAttempts->fetchColumn() >= $maximumAttempts) {
            $lockSeconds = env_int('LOGIN_LOCK_SECONDS', 900, 1, 86400);
            $lock = $identity->prepare(
                "UPDATE usuarios
                 SET bloqueado_ate=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL {$lockSeconds} SECOND),
                     bloqueio_motivo='tentativas_login'
                 WHERE id=:id"
            );
            $lock->execute(['id' => $userId]);
            authentication_event(
                'login_bloqueado',
                false,
                $userId,
                $email,
                ['motivo' => 'tentativas_login']
            );
        }
        return false;
    }

    $update = $identity->prepare(
        'UPDATE usuarios
         SET ultimo_login_em=UTC_TIMESTAMP(6),bloqueado_ate=NULL,bloqueio_motivo=NULL
         WHERE id=:id'
    );
    $update->execute(['id' => $userId]);
    authentication_event('login_sucesso', true, $userId, $email);

    session_regenerate_id(true);
    unset(
        $user['segredo_hash'],
        $user['bloqueado_ate'],
        $user['bloqueio_motivo'],
        $user['esta_bloqueado'],
        $user['bloqueio_expirado']
    );
    $_SESSION['user'] = $user;
    unset($_SESSION['csrf']);
    return true;
}

function logout_user(): void
{
    $user = auth_user();
    if ($user) {
        authentication_event(
            'logout',
            true,
            isset($user['id']) ? (int) $user['id'] : null,
            (string) ($user['email'] ?? '')
        );
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ]);
    }
    session_destroy();
}

function require_auth(): array
{
    $sessionUser = auth_user();
    if (!$sessionUser) {
        redirect('/login');
    }
    $user = current_active_user($sessionUser);
    if (!$user) {
        logout_user();
        redirect('/login');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_auth();
    if (!is_admin($user)) {
        http_response_code(403);
        exit('Acesso administrativo negado.');
    }
    return $user;
}

function authorized_project(string $projectUuid, ?array $user = null): ?array
{
    $user = current_active_user($user ?? auth_user());
    if (!$user || $projectUuid === '') {
        return null;
    }
    if (is_admin($user)) {
        $stmt = db('control')->prepare(
            'SELECT p.*,c.nome cliente_nome,c.uuid cliente_uuid
             FROM projetos p
             JOIN clientes c ON c.id=p.cliente_id
             WHERE p.uuid=:project LIMIT 1'
        );
        $stmt->execute(['project' => $projectUuid]);
    } else {
        $stmt = db('control')->prepare(
            "SELECT p.*,c.nome cliente_nome,c.uuid cliente_uuid
             FROM projetos p
             JOIN clientes c ON c.id=p.cliente_id
             JOIN cliente_usuarios cu
               ON cu.cliente_id=c.id AND cu.usuario_uuid=:client_user AND cu.ativo=1
             JOIN projeto_usuarios pu
               ON pu.projeto_id=p.id AND pu.usuario_uuid=:project_user AND pu.ativo=1
             WHERE p.uuid=:project
               AND p.status='ativo'
               AND c.status='ativo'
             LIMIT 1"
        );
        $stmt->execute([
            'project' => $projectUuid,
            'client_user' => $user['uuid'],
            'project_user' => $user['uuid'],
        ]);
    }
    $project = $stmt->fetch();
    return is_array($project) ? $project : null;
}

function authorized_projects(?array $user = null): array
{
    $user = current_active_user($user ?? auth_user());
    if (!$user) {
        return [];
    }
    if (is_admin($user)) {
        return db('control')->query(
            "SELECT p.uuid,p.nome,p.status,c.nome cliente_nome,c.uuid cliente_uuid
             FROM projetos p
             JOIN clientes c ON c.id=p.cliente_id
             ORDER BY p.nome"
        )->fetchAll();
    }
    $stmt = db('control')->prepare(
        "SELECT p.uuid,p.nome,p.status,c.nome cliente_nome,c.uuid cliente_uuid
         FROM projetos p
         JOIN clientes c ON c.id=p.cliente_id
         JOIN cliente_usuarios cu
           ON cu.cliente_id=c.id AND cu.usuario_uuid=:client_user AND cu.ativo=1
         JOIN projeto_usuarios pu
           ON pu.projeto_id=p.id AND pu.usuario_uuid=:project_user AND pu.ativo=1
         WHERE p.status='ativo' AND c.status='ativo'
         ORDER BY p.nome"
    );
    $stmt->execute([
        'client_user' => $user['uuid'],
        'project_user' => $user['uuid'],
    ]);
    return $stmt->fetchAll();
}

function authorized_clients(?array $user = null): array
{
    $user = current_active_user($user ?? auth_user());
    if (!$user) {
        return [];
    }
    if (is_admin($user)) {
        return db('control')->query(
            'SELECT uuid,nome,status,NULL papel FROM clientes ORDER BY nome'
        )->fetchAll();
    }
    $stmt = db('control')->prepare(
        "SELECT c.uuid,c.nome,c.status,cu.papel
         FROM clientes c
         JOIN cliente_usuarios cu ON cu.cliente_id=c.id
         WHERE cu.usuario_uuid=:user AND cu.ativo=1 AND c.status='ativo'
         ORDER BY c.nome"
    );
    $stmt->execute(['user' => $user['uuid']]);
    return $stmt->fetchAll();
}

function user_has_project(string $projectUuid, ?array $user = null): bool
{
    return authorized_project($projectUuid, $user) !== null;
}

function require_project(string $projectUuid): array
{
    $user = require_auth();
    $project = authorized_project($projectUuid, $user);
    if (!$project) {
        http_response_code(is_admin($user) ? 404 : 403);
        exit(is_admin($user) ? 'Projeto não encontrado.' : 'Você não possui acesso a este projeto.');
    }
    return $project;
}

function require_active_sandbox(string $projectUuid): array
{
    $project = require_project($projectUuid);
    $stmt = db('control')->prepare(
        "SELECT id,uuid,diretorio,status
         FROM ambientes
         WHERE projeto_id=:project AND tipo='sandbox' AND status='ativo'
         LIMIT 1"
    );
    $stmt->execute(['project' => $project['id']]);
    $environment = $stmt->fetch();
    if (!is_array($environment) || !is_string($environment['diretorio'])
        || $environment['diretorio'] === '') {
        http_response_code(403);
        exit('Sandbox ativo indisponível.');
    }
    return ['project' => $project, 'environment' => $environment];
}

function public_route_url(string $hostname): string
{
    $hostname = normalize_hostname($hostname);
    $configured = strtolower((string) (getenv('PUBLIC_ROUTE_SCHEME') ?: ''));
    $scheme = in_array($configured, ['http', 'https'], true)
        ? $configured
        : ((string) (getenv('APP_ENV') ?: 'development') === 'production' ? 'https' : 'http');
    return $scheme . '://' . $hostname;
}

function editor_project_url(string $projectUuid): string
{
    $base = rtrim((string) (getenv('SANDBOX_URL') ?: 'http://127.0.0.1:6016'), '/');
    return $base . '/projeto?uuid=' . rawurlencode($projectUuid);
}

function project_environment_navigation(int $projectId): array
{
    $stmt = db('control')->prepare(
        "SELECT a.uuid,a.tipo,a.nome,a.diretorio,a.status,
                (SELECT r.hostname
                   FROM rotas_web r
                  WHERE r.ambiente_id=a.id AND r.ativo=1
                  ORDER BY r.id LIMIT 1) hostname
         FROM ambientes a
         WHERE a.projeto_id=:project
         ORDER BY a.tipo,a.id"
    );
    $stmt->execute(['project' => $projectId]);
    $result = [];
    foreach ($stmt as $environment) {
        $type = (string) $environment['tipo'];
        if (!isset($result[$type])) {
            $environment['url'] = is_string($environment['hostname'])
                && $environment['hostname'] !== ''
                ? public_route_url($environment['hostname'])
                : null;
            $result[$type] = $environment;
        }
    }
    return $result;
}

function advance_user_journey(string $assignmentUuid): string
{
    $control = db('control');
    $control->beginTransaction();
    try {
        $stmt = $control->prepare(
            "SELECT uj.id, uj.jornada_id, uj.status, uj.etapa_atual_id,
                    j.status jornada_status, e.ordem etapa_atual_ordem
             FROM usuario_jornadas uj
             JOIN jornadas j ON j.id = uj.jornada_id
             LEFT JOIN jornada_etapas e ON e.id = uj.etapa_atual_id
             WHERE uj.uuid = :uuid
             FOR UPDATE"
        );
        $stmt->execute(['uuid' => $assignmentUuid]);
        $assignment = $stmt->fetch();
        if (!$assignment) {
            throw new ValidationException('Jornada do usuário não encontrada.');
        }
        if ($assignment['jornada_status'] !== 'ativa') {
            throw new ValidationException('A jornada está inativa.');
        }
        if ($assignment['status'] === 'concluida') {
            $control->commit();
            return 'A jornada já estava concluída.';
        }

        if ($assignment['status'] === 'pendente') {
            $next = $control->prepare(
                "SELECT id, titulo FROM jornada_etapas
                 WHERE jornada_id = :journey AND status = 'ativa'
                 ORDER BY ordem, id LIMIT 1"
            );
            $next->execute(['journey' => $assignment['jornada_id']]);
            $stage = $next->fetch();
            if (!$stage) {
                throw new ValidationException('A jornada não possui etapas ativas.');
            }
            $history = $control->prepare(
                "INSERT INTO usuario_jornada_etapas
                    (usuario_jornada_id, jornada_etapa_id, status, iniciada_em)
                 VALUES (:assignment, :stage, 'em_andamento', UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE
                    status = IF(status = 'concluida', status, 'em_andamento'),
                    iniciada_em = COALESCE(iniciada_em, UTC_TIMESTAMP(6))"
            );
            $history->execute([
                'assignment' => $assignment['id'],
                'stage' => $stage['id'],
            ]);
            $update = $control->prepare(
                "UPDATE usuario_jornadas
                 SET status = 'em_andamento', etapa_atual_id = :stage,
                     iniciada_em = COALESCE(iniciada_em, UTC_TIMESTAMP(6)),
                     concluida_em = NULL
                 WHERE id = :id"
            );
            $update->execute(['stage' => $stage['id'], 'id' => $assignment['id']]);
            $control->commit();
            return 'Jornada iniciada em: ' . $stage['titulo'] . '.';
        }

        if (!$assignment['etapa_atual_id'] || $assignment['etapa_atual_ordem'] === null) {
            throw new ValidationException('A jornada em andamento não possui etapa atual válida.');
        }

        $complete = $control->prepare(
            "INSERT INTO usuario_jornada_etapas
                (usuario_jornada_id, jornada_etapa_id, status, iniciada_em, concluida_em)
             VALUES (:assignment, :stage, 'concluida', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
                status = 'concluida',
                concluida_em = COALESCE(concluida_em, UTC_TIMESTAMP(6))"
        );
        $complete->execute([
            'assignment' => $assignment['id'],
            'stage' => $assignment['etapa_atual_id'],
        ]);

        $next = $control->prepare(
            "SELECT e.id, e.titulo
             FROM jornada_etapas e
             WHERE e.jornada_id = :journey
               AND e.status = 'ativa'
               AND e.ordem > :current_order
               AND NOT EXISTS (
                   SELECT 1 FROM usuario_jornada_etapas uje
                   WHERE uje.usuario_jornada_id = :assignment
                     AND uje.jornada_etapa_id = e.id
                     AND uje.status = 'concluida'
               )
             ORDER BY e.ordem, e.id LIMIT 1"
        );
        $next->execute([
            'journey' => $assignment['jornada_id'],
            'current_order' => $assignment['etapa_atual_ordem'],
            'assignment' => $assignment['id'],
        ]);
        $stage = $next->fetch();

        if (!$stage) {
            $update = $control->prepare(
                "UPDATE usuario_jornadas
                 SET status = 'concluida', etapa_atual_id = NULL,
                     concluida_em = UTC_TIMESTAMP(6)
                 WHERE id = :id"
            );
            $update->execute(['id' => $assignment['id']]);
            $control->commit();
            return 'Jornada concluída.';
        }

        $history = $control->prepare(
            "INSERT INTO usuario_jornada_etapas
                (usuario_jornada_id, jornada_etapa_id, status, iniciada_em)
             VALUES (:assignment, :stage, 'em_andamento', UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
                status = IF(status = 'concluida', status, 'em_andamento'),
                iniciada_em = COALESCE(iniciada_em, UTC_TIMESTAMP(6))"
        );
        $history->execute([
            'assignment' => $assignment['id'],
            'stage' => $stage['id'],
        ]);
        $update = $control->prepare(
            "UPDATE usuario_jornadas
             SET etapa_atual_id = :stage, status = 'em_andamento'
             WHERE id = :id"
        );
        $update->execute(['stage' => $stage['id'], 'id' => $assignment['id']]);
        $control->commit();
        return 'Próxima etapa: ' . $stage['titulo'] . '.';
    } catch (Throwable $error) {
        if ($control->inTransaction()) {
            $control->rollBack();
        }
        throw $error;
    }
}

function safe_environment_path(string $relative, bool $create = false): string
{
    $relative = trim($relative, '/');
    if (!preg_match(
        '#^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/(?:sandbox|production)$#D',
        $relative
    )) {
        throw new RuntimeException('Diretório de ambiente inválido.');
    }
    $root = realpath(PROJECTS_ROOT);
    if ($root === false || is_link(PROJECTS_ROOT)) {
        throw new RuntimeException('Storage de projetos indisponível.');
    }

    $current = $root;
    foreach (explode('/', $relative) as $segment) {
        $current .= '/' . $segment;
        if (is_link($current)) {
            throw new RuntimeException('Diretório de ambiente inválido.');
        }
        if ($create && !is_dir($current)
            && !mkdir($current, 0775)
            && !is_dir($current)) {
            throw new RuntimeException('Não foi possível criar o diretório do ambiente.');
        }
    }

    $resolved = realpath($current);
    if ($resolved === false
        || $resolved !== $current
        || !str_starts_with($resolved . '/', $root . '/')) {
        throw new RuntimeException('Diretório fora do storage permitido.');
    }
    return $resolved;
}

function atomic_write(string $path, string $content, bool $provisioning = false): void
{
    $root = realpath(PROJECTS_ROOT);
    $directory = realpath(dirname($path));
    $environmentType = $directory === false ? '' : basename($directory);
    $context = strtolower((string) (getenv('APP_CONTEXT') ?: 'app'));
    $prepareProjects = strtolower((string) (getenv('THREEEBS_PREPARE_PROJECTS') ?: 'false')) === 'true';
    $trustedProvisioning = $provisioning
        && $prepareProjects
        && in_array($context, ['admin', 'portal'], true);
    $writeAllowed = $environmentType === 'sandbox'
        || ($context === 'admin' && $environmentType === 'production')
        || ($trustedProvisioning && $environmentType === 'production');
    if ($root === false || $directory === false
        || basename($path) !== 'index.html'
        || !$writeAllowed
        || is_link(dirname($path))
        || is_link($path)
        || !str_starts_with($directory . '/', $root . '/')) {
        throw new RuntimeException('Destino de escrita inválido.');
    }

    $temporary = tempnam($directory, '.threeebs-');
    if ($temporary === false
        || file_put_contents($temporary, $content, LOCK_EX) === false
        || !rename($temporary, $directory . '/index.html')) {
        if (is_string($temporary) && is_file($temporary)) {
            unlink($temporary);
        }
        throw new RuntimeException('Não foi possível salvar o arquivo.');
    }
    chmod($directory . '/index.html', 0664);
}

function page_start(string $title): void
{
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . '</title><link rel="icon" href="/assets/identity/image/logo/logo-symbol-brand.png"></head><body>';
    echo '<header><h1>' . h($title) . '</h1></header><hr>';
}

function page_end(): void
{
    echo '</body></html>';
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function show_flash(): void
{
    if (isset($_SESSION['flash'])) {
        echo '<p><strong>' . h($_SESSION['flash']) . '</strong></p>';
        unset($_SESSION['flash']);
    }
}
