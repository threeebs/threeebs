<?php

declare(strict_types=1);

require '/var/www/shared/bootstrap.php';
require '/var/www/shared/project_migrations.php';
require '/var/www/shared/environment_operations.php';

$path = request_path();
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

function sandbox_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sandbox_page_start(string $title, bool $editor = false): void
{
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . '</title>'
        . '<link rel="icon" href="/assets/identity/image/logo/logo-symbol-brand.png">'
        . '<link rel="stylesheet" href="/assets/css/editor.css">';
    if ($editor) {
        echo '<script defer src="/vendor/monaco/vs/loader.js"></script>'
            . '<script defer src="/vendor/realtime/realtime-client.js?v=20260907-2"></script>'
            . '<script defer src="/assets/js/editor.js?v=20260907-2"></script>';
    }
    echo '</head><body>';
}

function sandbox_editor_access(string $projectUuid): array
{
    $access = require_active_sandbox($projectUuid);
    $access['root'] = safe_environment_path((string) $access['environment']['diretorio'], false);
    return $access;
}

function sandbox_editor_audit(array $access, string $action, array $details): void
{
    audit_event(
        $action,
        'arquivo_sandbox',
        null,
        $access['project']['cliente_uuid'] ?? null,
        $access['project']['uuid'],
        $access['environment']['uuid'],
        $details
    );
}

if ($method === 'GET' && $path === '/api/realtime/file') {
    try {
        realtime_require_internal_request();
        $access = realtime_internal_access(
            (string) ($_GET['user_uuid'] ?? ''),
            (string) ($_GET['project_uuid'] ?? ''),
            (string) ($_GET['environment_uuid'] ?? '')
        );
        $storage = project_storage_service($access);
        $file = $storage->readFile((string) ($_GET['path'] ?? ''));
        realtime_validate_room($access, (string) $file['path'], (string) ($_GET['room'] ?? ''));
        sandbox_json(['file' => $file]);
    } catch (Throwable $error) {
        error_log('Threeebs realtime read failure: ' . $error->getMessage());
        sandbox_json(
            ['error' => public_error_message($error)],
            $error instanceof RealtimeAuthorizationException
                ? 403
                : ($error instanceof ValidationException ? 422 : 500)
        );
    }
}

if ($method === 'GET' && in_array($path, ['/api/editor/tree', '/api/editor/file'], true)) {
    try {
        $access = sandbox_editor_access((string) ($_GET['uuid'] ?? ''));
        $storage = project_storage_service($access);
        if ($path === '/api/editor/tree') {
            sandbox_json(['tree' => $storage->tree(), 'usage' => $storage->usage()]);
        }
        sandbox_json([
            'file' => $storage->readFile((string) ($_GET['path'] ?? '')),
        ]);
    } catch (Throwable $error) {
        error_log('Threeebs editor read failure: ' . $error->getMessage());
        sandbox_json(['error' => public_error_message($error)], $error instanceof ValidationException ? 422 : 500);
    }
}

if ($method === 'POST' && $path === '/api/realtime/checkpoint') {
    try {
        realtime_require_internal_request();
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > 17 * 1024 * 1024) {
            throw new ValidationException('Checkpoint realtime inválido.');
        }
        $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new ValidationException('Checkpoint realtime inválido.');
        }
        $access = realtime_internal_access(
            (string) ($payload['user_uuid'] ?? ''),
            (string) ($payload['project_uuid'] ?? ''),
            (string) ($payload['environment_uuid'] ?? '')
        );
        $storage = project_storage_service(
            $access,
            fn (string $storageAction, array $details) => sandbox_editor_audit(
                $access,
                $storageAction,
                $details + [
                    'origem' => 'realtime',
                    'ator_usuario_uuid' => (string) $access['user']['uuid'],
                ]
            )
        );
        $existing = $storage->readFile((string) ($payload['path'] ?? ''));
        realtime_validate_room($access, (string) $existing['path'], (string) ($payload['room'] ?? ''));
        $hash = $storage->writeFile(
            (string) $existing['path'],
            (string) ($payload['content'] ?? ''),
            (string) ($payload['expected_hash'] ?? '')
        );
        sandbox_json(['ok' => true, 'hash' => $hash]);
    } catch (Throwable $error) {
        error_log('Threeebs realtime checkpoint failure: ' . $error->getMessage());
        sandbox_json(
            ['error' => public_error_message($error)],
            $error instanceof RealtimeAuthorizationException
                ? 403
                : ($error instanceof ValidationException ? 422 : 500)
        );
    }
}

if ($method === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');
    try {
        if ($action === 'login') {
            if (!login_user((string) ($_POST['email'] ?? ''), (string) ($_POST['senha'] ?? ''))) {
                throw new ValidationException('E-mail ou senha inválidos.');
            }
            redirect('/');
        }
        if ($action === 'logout') {
            logout_user();
            redirect('/login');
        }
        if (in_array($action, ['migration_validate', 'migration_apply'], true)) {
            $access = sandbox_editor_access((string) ($_POST['projeto_uuid'] ?? ''));
            if (!project_user_can_manage_database($access['project'])) {
                http_response_code(403);
                throw new ValidationException(
                    'Somente gestores e desenvolvedores do projeto podem operar migrations.'
                );
            }
            $manifest = project_migration_manifest((string) $access['root']);
            if ($manifest === []) {
                throw new ValidationException(
                    'Crie ao menos uma migration em database/migrations antes de continuar.'
                );
            }
            if ($action === 'migration_validate') {
                sandbox_editor_audit($access, 'database.migrations_validadas', [
                    'arquivos' => count($manifest),
                ]);
                flash(count($manifest) . ' migration(s) validada(s) com sucesso.');
            } else {
                $environment = environment_for_project(
                    (string) $access['project']['uuid'],
                    (string) $access['environment']['uuid']
                );
                if (!environment_operation_is_satisfied($environment, 'database.provision')) {
                    throw new ValidationException(
                        'Crie o banco de dados do Sandbox no painel Admin antes de aplicar migrations.'
                    );
                }
                queue_environment_operation(
                    $environment,
                    'database.migrations.apply',
                    (string) current_active_user()['uuid'],
                    ['migrations' => $manifest]
                );
                flash('Aplicação das migrations adicionada à fila do Sandbox.');
            }
            redirect('/migrations?uuid=' . rawurlencode((string) $access['project']['uuid']));
        }

        if (str_starts_with($action, 'editor_')) {
            $access = sandbox_editor_access((string) ($_POST['projeto_uuid'] ?? ''));
            $itemPath = (string) ($_POST['path'] ?? '');
            $storage = project_storage_service(
                $access,
                fn (string $storageAction, array $details) => sandbox_editor_audit(
                    $access,
                    $storageAction,
                    $details
                )
            );
            if ($action === 'editor_realtime_ticket') {
                $user = current_active_user();
                if (!$user) {
                    throw new ValidationException('Sessão inválida.');
                }
                sandbox_json(realtime_issue_ticket($access, $user, $itemPath));
            }
            if ($action === 'editor_save') {
                $hash = $storage->writeFile(
                    $itemPath,
                    (string) ($_POST['content'] ?? ''),
                    (string) ($_POST['expected_hash'] ?? '')
                );
                sandbox_json(['ok' => true, 'hash' => $hash]);
            }
            if ($action === 'editor_create_file') {
                $storage->createFile($itemPath);
                sandbox_json(['ok' => true]);
            }
            if ($action === 'editor_create_directory') {
                $storage->createDirectory($itemPath);
                sandbox_json(['ok' => true]);
            }
            if ($action === 'editor_rename') {
                $destination = (string) ($_POST['destination'] ?? '');
                $storage->rename($itemPath, $destination);
                sandbox_json(['ok' => true]);
            }
            if ($action === 'editor_delete') {
                $storage->delete($itemPath);
                sandbox_json(['ok' => true]);
            }
            throw new ValidationException('Ação do editor inválida.');
        }
    } catch (Throwable $error) {
        error_log('Threeebs sandbox failure: ' . $error->getMessage());
        if (str_starts_with($action, 'editor_')) {
            sandbox_json(
                ['error' => public_error_message($error)],
                $error instanceof ValidationException ? 422 : 500
            );
        }
        flash(public_error_message($error));
        $return = (string) ($_POST['_return'] ?? '/login');
        redirect(str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : '/');
    }
}

if ($path === '/login' && !auth_user()) {
    sandbox_page_start('Threeebs Sandbox :3 — Entrar');
    echo '<main class="sandbox-auth"><h1>Entrar no Editor Threeebs</h1>';
    show_flash();
    echo '<form method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="login">'
        . '<p><label>E-mail<br><input type="email" name="email" required></label></p>'
        . '<p><label>Senha<br><input type="password" name="senha" required></label></p>'
        . '<button>Entrar</button></form></main></body></html>';
    exit;
}

$user = require_auth();

if ($path === '/') {
    sandbox_page_start('Editor Threeebs :3');
    echo '<header class="sandbox-header"><p class="sandbox-kicker">Threeebs :3 / Monaco edita</p>'
        . '<h1>Meus projetos</h1></header><main class="sandbox-projects">';
    show_flash();
    echo '<ul>';
    foreach (authorized_projects($user) as $project) {
        echo '<li><a href="/projeto?uuid=' . h($project['uuid']) . '">'
            . h($project['nome']) . '</a> — ' . h($project['cliente_nome']) . '</li>';
    }
    echo '</ul><form method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="logout"><button>Sair</button>'
        . '</form></main></body></html>';
    exit;
}

if ($path === '/migrations') {
    $access = sandbox_editor_access((string) ($_GET['uuid'] ?? ''));
    if (!project_user_can_manage_database($access['project'], $user)) {
        http_response_code(403);
        exit('Somente gestores e desenvolvedores do projeto podem operar migrations.');
    }
    $manifest = project_migration_manifest((string) $access['root']);
    $environment = environment_for_project(
        (string) $access['project']['uuid'],
        (string) $access['environment']['uuid']
    );
    $databaseActive = environment_operation_is_satisfied($environment, 'database.provision');
    $operation = db('control')->prepare(
        "SELECT status,erro_codigo,solicitado_em,concluido_em
         FROM ambiente_operacoes
         WHERE ambiente_id=:environment AND tipo='database.migrations.apply'
         ORDER BY id DESC LIMIT 1"
    );
    $operation->execute(['environment' => $environment['id']]);
    $latestOperation = $operation->fetch();

    sandbox_page_start('Migrations — ' . (string) $access['project']['nome']);
    echo '<header class="sandbox-header"><p class="sandbox-kicker">Threeebs :3 / Database</p>'
        . '<div class="sandbox-nav"><h1>Migrations do Sandbox</h1>'
        . '<a href="/projeto?uuid=' . h($access['project']['uuid']) . '">Voltar ao editor</a></div></header>'
        . '<main class="sandbox-auth">';
    show_flash();
    echo '<p>Banco de dados: <strong>' . ($databaseActive ? 'ativo' : 'não configurado') . '</strong></p>'
        . '<p>Diretório versionado: <code>database/migrations</code></p>';
    if ($latestOperation) {
        echo '<p>Última aplicação: <strong>'
            . h(environment_operation_status_label((string) $latestOperation['status']))
            . '</strong>';
        if (!empty($latestOperation['erro_codigo'])) {
            echo ' — erro <code>' . h($latestOperation['erro_codigo']) . '</code>';
        }
        echo '</p>';
    }
    if ($manifest === []) {
        echo '<p>Nenhuma migration encontrada. Use o padrão '
            . '<code>AAAAMMDDHHMMSS_nome_em_snake_case.sql</code>.</p>';
    } else {
        echo '<table><thead><tr><th>Migration</th><th>SHA-256</th><th>Tamanho</th></tr></thead><tbody>';
        foreach ($manifest as $migration) {
            echo '<tr><td><code>' . h($migration['name']) . '</code></td>'
                . '<td><code>' . h(substr((string) $migration['sha256'], 0, 12)) . '…</code></td>'
                . '<td>' . h($migration['size']) . ' bytes</td></tr>';
        }
        echo '</tbody></table>';
    }
    $busy = is_array($latestOperation)
        && in_array((string) $latestOperation['status'], ['pendente', 'executando'], true);
    echo '<form method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="migration_validate">'
        . '<input type="hidden" name="projeto_uuid" value="' . h($access['project']['uuid']) . '">'
        . '<input type="hidden" name="_return" value="/migrations?uuid=' . h($access['project']['uuid']) . '">'
        . '<button' . ($manifest === [] ? ' disabled' : '') . '>Validar migrations</button></form>'
        . '<form method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="migration_apply">'
        . '<input type="hidden" name="projeto_uuid" value="' . h($access['project']['uuid']) . '">'
        . '<input type="hidden" name="_return" value="/migrations?uuid=' . h($access['project']['uuid']) . '">'
        . '<button' . (!$databaseActive || $manifest === [] || $busy ? ' disabled' : '')
        . '>Aplicar no Sandbox</button></form></main></body></html>';
    exit;
}

if ($path === '/projeto') {
    $access = sandbox_editor_access((string) ($_GET['uuid'] ?? ''));
    $project = $access['project'];
    $environment = $access['environment'];
    $route = db('control')->prepare(
        'SELECT hostname FROM rotas_web
         WHERE ambiente_id=(SELECT id FROM ambientes WHERE uuid=:environment)
           AND ativo=1
         ORDER BY id LIMIT 1'
    );
    $route->execute(['environment' => $environment['uuid']]);
    $hostname = $route->fetchColumn();

    sandbox_page_start('Editor Threeebs — ' . (string) $project['nome'], true);
    echo '<header class="sandbox-header"><p class="sandbox-kicker">Threeebs :3 / Monaco edita</p>'
        . '<div class="sandbox-nav"><h1>' . h($project['nome']) . '</h1>'
        . '<a href="/">Projetos</a>';
    if (project_user_can_manage_database($project, $user)) {
        echo '<a href="/migrations?uuid=' . h($project['uuid']) . '">Migrations</a>';
    }
    if (is_string($hostname) && $hostname !== '') {
        echo '<a href="' . h(public_route_url($hostname)) . '" target="_blank" rel="noopener">'
            . 'Abrir preview Sandbox</a>';
    }
    echo '<form method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="logout"><button>Sair</button></form>'
        . '</div></header>';
    show_flash();
    echo '<main class="sandbox-editor" data-threeebs-editor'
        . ' data-project-uuid="' . h($project['uuid']) . '"'
        . ' data-csrf="' . h(csrf_token()) . '"'
        . ' data-realtime="enabled">'
        . '<aside class="explorer"><div class="explorer-toolbar">'
        . '<button type="button" id="create-file">+ Arquivo</button>'
        . '<button type="button" id="create-directory">+ Pasta</button>'
        . '<button type="button" id="rename-item" disabled>Renomear</button>'
        . '<button type="button" id="delete-item" class="danger" disabled>Excluir</button>'
        . '</div><nav id="project-tree" class="tree" aria-label="Arquivos do projeto"></nav></aside>'
        . '<section class="editor-pane"><div class="editor-toolbar">'
        . '<span id="editor-path" class="editor-path">Nenhum arquivo aberto</span>'
        . '<button type="button" id="save-file" disabled>Salvar</button></div>'
        . '<div id="editor-status" class="editor-status">Carregando editor…</div>'
        . '<div id="monaco-editor" aria-label="Editor de código"></div></section></main>'
        . '</body></html>';
    exit;
}

http_response_code(404);
sandbox_page_start('Página não encontrada');
echo '<main class="sandbox-auth"><h1>Página não encontrada</h1><p><a href="/">Voltar</a></p></main>'
    . '</body></html>';
