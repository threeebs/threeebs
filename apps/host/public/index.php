<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');

function host_not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $portalUrl = rtrim((string) (getenv('PORTAL_URL') ?: ''), '/');
    if (!filter_var($portalUrl, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string) parse_url($portalUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        $homeUrl = '/';
        $helpUrl = '/';
    } else {
        $homeUrl = $portalUrl . '/';
        $helpUrl = $portalUrl . '/#interesse';
    }
    $homeUrl = htmlspecialchars($homeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $helpUrl = htmlspecialchars($helpUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit(<<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#07100f">
  <title>Projeto não encontrado · Threeebs</title>
  <link rel="icon" href="/assets/image/logo/logo-symbol-brand.png">
  <style>
    :root { color-scheme: dark; --bg: #07100f; --surface: #0b1b18; --line: #194039; --text: #f5f7f6; --muted: #a5b9b5; --accent: #38b99e; --accent-ink: #03100d; }
    * { box-sizing: border-box; }
    body { min-height: 100vh; margin: 0; color: var(--text); background: var(--bg); font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    body::before { position: fixed; inset: 0; content: ""; pointer-events: none; background: radial-gradient(circle at 77% 45%, rgb(56 185 158 / 16%), transparent 28%), linear-gradient(rgb(56 185 158 / 3%) 1px, transparent 1px), linear-gradient(90deg, rgb(56 185 158 / 3%) 1px, transparent 1px); background-size: auto, 48px 48px, 48px 48px; }
    a { color: inherit; }
    .shell { position: relative; display: grid; width: min(calc(100% - 2rem), 1180px); min-height: 100vh; margin: auto; grid-template-rows: auto 1fr auto; }
    header { display: flex; min-height: 5.5rem; align-items: center; border-bottom: 1px solid var(--line); }
    .brand { text-decoration: none; font-size: 1.2rem; font-weight: 850; letter-spacing: -.04em; }
    .brand span { color: var(--accent); }
    main { display: grid; grid-template-columns: minmax(0, 1fr) minmax(18rem, 25rem); gap: clamp(2rem, 8vw, 8rem); align-items: center; padding: clamp(3rem, 8vw, 7rem) 0; }
    .eyebrow { display: flex; align-items: center; gap: .65rem; color: var(--accent); font-size: .72rem; font-weight: 850; letter-spacing: .14em; text-transform: uppercase; }
    .eyebrow::before { width: 1.8rem; height: 1px; content: ""; background: var(--accent); }
    h1 { max-width: 10ch; margin: 1rem 0 1.25rem; font-size: clamp(3rem, 8vw, 6.5rem); line-height: .94; letter-spacing: -.065em; }
    .copy > p:not(.eyebrow) { max-width: 38rem; color: var(--muted); font-size: clamp(1rem, 2vw, 1.15rem); line-height: 1.7; }
    .actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 2rem; }
    .button { display: inline-flex; min-height: 3rem; align-items: center; justify-content: center; padding: .75rem 1rem; border: 1px solid var(--line); border-radius: .7rem; background: var(--surface); text-decoration: none; font-weight: 750; }
    .button:hover { border-color: var(--accent); }
    .button--primary { color: var(--accent-ink); border-color: var(--accent); background: var(--accent); }
    .mark { position: relative; display: grid; aspect-ratio: 1; place-items: center; border: 1px solid var(--line); border-radius: 50%; background: radial-gradient(circle, rgb(56 185 158 / 18%), transparent 64%); }
    .mark::before, .mark::after { position: absolute; inset: 15%; content: ""; border: 1px solid rgb(56 185 158 / 30%); border-radius: 50%; }
    .mark::after { inset: 31%; }
    .mark strong { position: relative; z-index: 1; color: var(--accent); font-size: clamp(4.5rem, 11vw, 8rem); letter-spacing: -.08em; }
    footer { padding: 1.5rem 0; color: var(--muted); border-top: 1px solid var(--line); font-size: .78rem; }
    @media (max-width: 760px) { main { grid-template-columns: 1fr; } .mark { width: min(75vw, 22rem); grid-row: 1; margin: auto; } .actions { flex-direction: column; } }
    @media (prefers-reduced-motion: no-preference) { .mark { animation: arrive .55s ease both; } @keyframes arrive { from { opacity: 0; transform: scale(.94); } } }
  </style>
</head>
<body>
  <div class="shell">
    <header><a class="brand" href="{$homeUrl}" aria-label="Threeebs — página inicial">Threeebs <span>:3</span></a></header>
    <main>
      <section class="copy">
        <p class="eyebrow">Endereço sem projeto ativo</p>
        <h1>Projeto não encontrado.</h1>
        <p>Este endereço ainda não está ligado a um projeto publicado. Você pode voltar para a Threeebs ou falar com a nossa equipe para tirar sua ideia do papel.</p>
        <div class="actions">
          <a class="button" href="{$homeUrl}">Ir para a página inicial</a>
          <a class="button button--primary" href="{$helpUrl}">Preciso de ajuda com meu website</a>
        </div>
      </section>
      <div class="mark" aria-hidden="true"><strong>:3</strong></div>
    </main>
    <footer>Threeebs :3 · Projetos digitais com caminho claro.</footer>
  </div>
</body>
</html>
HTML);
}

function host_unavailable(): never
{
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Threeebs :3 — serviço temporariamente indisponível.');
}

function positive_env_int(string $name, int $fallback, int $maximum): int
{
    $value = filter_var(getenv($name), FILTER_VALIDATE_INT);
    return is_int($value) && $value > 0 && $value <= $maximum ? $value : $fallback;
}

function runtime_container_name(string $environmentUuid): string
{
    $prefix = (string) (getenv('RUNTIME_CONTAINER_PREFIX') ?: '');
    if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $prefix)) {
        throw new RuntimeException('Prefixo de runtime inválido.');
    }
    return $prefix . str_replace('-', '', $environmentUuid);
}

function sanitized_set_cookie(string $value): string
{
    $value = preg_replace('/;\s*Domain=[^;]*/i', '', $value) ?? '';
    return str_replace(["\r", "\n"], '', $value);
}

function proxy_php_runtime(array $route, string $hostname): never
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS');
        exit;
    }

    $target = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($target === '' || str_contains($target, "\r") || str_contains($target, "\n")
        || strlen($target) > 8192) {
        host_not_found();
    }

    $maximumBody = positive_env_int('RUNTIME_PROXY_MAX_BODY_BYTES', 8388608, 67108864);
    $declaredLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? 0, FILTER_VALIDATE_INT);
    if ($declaredLength === false || $declaredLength < 0 || $declaredLength > $maximumBody) {
        http_response_code(413);
        exit('Requisição excede o limite permitido.');
    }
    $body = file_get_contents('php://input', false, null, 0, $maximumBody + 1);
    if (!is_string($body) || strlen($body) > $maximumBody) {
        http_response_code(413);
        exit('Requisição excede o limite permitido.');
    }

    $timeout = positive_env_int('RUNTIME_PROXY_TIMEOUT_SECONDS', 15, 60);
    $address = runtime_container_name((string) $route['environment_uuid']);
    $socket = @stream_socket_client(
        'tcp://' . $address . ':8080',
        $errorNumber,
        $errorMessage,
        (float) $timeout,
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($socket)) {
        error_log('PHP runtime unavailable: ' . $address . ' ' . $errorNumber);
        host_unavailable();
    }
    stream_set_timeout($socket, $timeout);

    $headers = [
        'Host: ' . $hostname,
        'Connection: close',
        'X-Forwarded-Host: ' . $hostname,
        'X-Forwarded-Proto: ' . ((string) (getenv('PUBLIC_ROUTE_SCHEME') ?: 'https')),
        'X-Forwarded-For: ' . preg_replace('/[^0-9a-fA-F:., ]/', '', (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
    ];
    foreach ([
        'HTTP_ACCEPT' => 'Accept',
        'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language',
        'HTTP_AUTHORIZATION' => 'Authorization',
        'HTTP_COOKIE' => 'Cookie',
        'HTTP_USER_AGENT' => 'User-Agent',
        'CONTENT_TYPE' => 'Content-Type',
    ] as $serverKey => $headerName) {
        $value = (string) ($_SERVER[$serverKey] ?? '');
        if ($value !== '' && !str_contains($value, "\r") && !str_contains($value, "\n")) {
            $headers[] = $headerName . ': ' . $value;
        }
    }
    if ($body !== '') {
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    $request = $method . ' ' . $target . " HTTP/1.0\r\n"
        . implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $written = 0;
    while ($written < strlen($request)) {
        $count = fwrite($socket, substr($request, $written));
        if ($count === false || $count === 0) {
            fclose($socket);
            host_unavailable();
        }
        $written += $count;
    }

    $statusLine = fgets($socket, 4096);
    if (!is_string($statusLine)
        || !preg_match('#^HTTP/\d(?:\.\d)?\s+([1-5][0-9]{2})(?:\s+.*)?\r?\n$#D', $statusLine, $match)) {
        fclose($socket);
        host_unavailable();
    }
    http_response_code((int) $match[1]);

    $headerBytes = strlen($statusLine);
    while (($line = fgets($socket, 8192)) !== false) {
        $headerBytes += strlen($line);
        if ($headerBytes > 65536) {
            fclose($socket);
            host_unavailable();
        }
        if ($line === "\r\n" || $line === "\n") {
            break;
        }
        $line = rtrim($line, "\r\n");
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode(':', $line, 2));
        $lower = strtolower($name);
        if (!preg_match('/^[a-z0-9-]+$/D', $lower)
            || in_array($lower, [
                'connection', 'transfer-encoding', 'keep-alive', 'proxy-authenticate',
                'proxy-authorization', 'te', 'trailer', 'upgrade', 'server', 'x-powered-by'
            ], true)) {
            continue;
        }
        if ($lower === 'set-cookie') {
            $value = sanitized_set_cookie($value);
            if ($value !== '') {
                header('Set-Cookie: ' . $value, false);
            }
            continue;
        }
        header($name . ': ' . str_replace(["\r", "\n"], '', $value), true);
    }

    if ($method !== 'HEAD') {
        while (!feof($socket)) {
            $chunk = fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
        }
    }
    fclose($socket);
    exit;
}

$hostname = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
$hostname = rtrim(explode(':', $hostname, 2)[0], '.');
if ($hostname === '' || strlen($hostname) > 253
    || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $hostname)) {
    host_not_found();
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('MYSQL_HOST') ?: 'mysql',
        getenv('MYSQL_PORT') ?: '3306',
        getenv('CONTROL_DB_NAME') ?: 'threeebs_control'
    );
    $pdo = new PDO($dsn, (string) getenv('DB_USER'), (string) getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $stmt = $pdo->prepare(
        "SELECT a.uuid environment_uuid,a.diretorio,
                COALESCE(ar.tipo,'runtime.static') runtime_type,
                COALESCE(ar.execucao_habilitada,0) execution_enabled,
                COALESCE(ar.status,'planejado') runtime_status
         FROM rotas_web r
         JOIN ambientes a ON a.id=r.ambiente_id
         JOIN projetos p ON p.id=a.projeto_id
         LEFT JOIN ambiente_runtimes ar ON ar.ambiente_id=a.id
         WHERE r.hostname=:hostname AND r.ativo=1 AND a.status='ativo' AND p.status='ativo'
         LIMIT 1"
    );
    $stmt->execute(['hostname' => $hostname]);
    $route = $stmt->fetch();
} catch (Throwable $error) {
    error_log($error->getMessage());
    host_unavailable();
}

if (!is_array($route)
    || !preg_match('/^[0-9a-f-]{36}$/D', (string) $route['environment_uuid'])
    || !preg_match(
        '#^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/(?:sandbox|production)$#D',
        (string) $route['diretorio']
    )) {
    host_not_found();
}

if ((string) $route['runtime_type'] === 'runtime.php') {
    if ((int) $route['execution_enabled'] !== 1 || (string) $route['runtime_status'] !== 'ativo') {
        host_not_found();
    }
    proxy_php_runtime($route, $hostname);
}
if ((string) $route['runtime_type'] !== 'runtime.static') {
    host_not_found();
}

$projectsRoot = realpath('/var/www/projects');
$environmentCandidate = $projectsRoot === false ? '' : $projectsRoot . '/' . $route['diretorio'];
$environmentRoot = $environmentCandidate === '' ? false : realpath($environmentCandidate);
if ($projectsRoot === false || $environmentRoot === false
    || $environmentRoot !== $environmentCandidate
    || !str_starts_with($environmentRoot . '/', $projectsRoot . '/')) {
    host_not_found();
}

$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri = rawurldecode((string) ($uri ?: '/'));
if (str_contains($uri, "\0") || str_contains($uri, '..') || str_contains($uri, '\\') || str_contains($uri, '%')) {
    host_not_found();
}
$relativeFile = $uri === '/' ? 'index.html' : ltrim($uri, '/');
$segments = explode('/', $relativeFile);
foreach ($segments as $segment) {
    if ($segment === '' || str_starts_with($segment, '.')) {
        host_not_found();
    }
}

$fileCandidate = $environmentRoot . '/' . $relativeFile;
$file = realpath($fileCandidate);
if ($file === false || $file !== $fileCandidate
    || !is_file($file)
    || !str_starts_with($file, $environmentRoot . '/')) {
    host_not_found();
}

$mime = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'application/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'txt' => 'text/plain; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'pdf' => 'application/pdf',
];
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if ($extension === 'php' || !isset($mime[$extension])) {
    host_not_found();
}

header('Content-Type: ' . $mime[$extension]);
header('Content-Length: ' . (string) filesize($file));
header('Cache-Control: no-cache');
readfile($file);
