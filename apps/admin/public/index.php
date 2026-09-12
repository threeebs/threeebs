<?php

declare(strict_types=1);

require '/var/www/shared/bootstrap.php';
require '/var/www/shared/ui.php';
require '/var/www/shared/environment_operations.php';

$path = request_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function admin_navigation(): array
{
    return [
        ['href' => '/', 'label' => 'Visão geral', 'icon' => 'home'],
        ['href' => '/usuarios', 'label' => 'Usuários', 'icon' => 'users'],
        ['href' => '/clientes', 'label' => 'Clientes', 'icon' => 'clients'],
        ['href' => '/projetos', 'label' => 'Projetos', 'icon' => 'projects'],
        ['href' => '/planos', 'label' => 'Planos', 'icon' => 'server'],
        ['href' => '/parceiros', 'label' => 'Parceiros', 'icon' => 'users'],
        ['href' => '/jornadas', 'label' => 'Jornadas', 'icon' => 'journey'],
        ['href' => '/tarefas', 'label' => 'Tarefas', 'icon' => 'tasks'],
        ['href' => '/servidores', 'label' => 'Servidores', 'icon' => 'server'],
    ];
}

function admin_page_start(string $title): void
{
    global $path;
    if (auth_user()) {
        $GLOBALS['admin_ui_mode'] = 'app';
        ui_app_start($title, 'Admin', admin_navigation(), $path, auth_user(), true);
        return;
    }
    $GLOBALS['admin_ui_mode'] = 'public';
    ui_public_start($title, 'Admin', $path === '/login' ? 'admin-login-page' : 'admin-auth');
}

function admin_page_end(): void
{
    if (($GLOBALS['admin_ui_mode'] ?? 'public') === 'app') {
        ui_app_end();
        return;
    }
    ui_public_end();
}

function admin_nav(): void
{
    // A navegação agora é renderizada pelo shell compartilhado.
}

function admin_role_select(string $name, array $roles, string $selected = 'membro'): void
{
    echo '<p><label>Papel<br><select name="' . h($name) . '" required>';
    foreach ($roles as $value => $label) {
        echo '<option value="' . h($value) . '"' . ($value === $selected ? ' selected' : '') . '>'
            . h($label) . '</option>';
    }
    echo '</select></label></p>';
}

function admin_allowed_role(string $role, array $roles): string
{
    if (!array_key_exists($role, $roles)) {
        throw new ValidationException('Selecione um papel válido.');
    }
    return $role;
}

function input(string $name, string $label, string $type = 'text', string $value = '', bool $required = true): void
{
    echo '<p><label>' . h($label) . '<br><input name="' . h($name) . '" type="' . h($type) . '" value="' . h($value) . '"' . ($required ? ' required' : '') . '></label></p>';
}

function route_type_label(string $type): string
{
    return match ($type) {
        'subdominio' => 'Subdomínio Threeebs',
        'dominio_personalizado' => 'Domínio personalizado',
        default => $type,
    };
}

function admin_partner_status_label(string $status): string
{
    return match ($status) {
        'email_pendente' => 'Confirmação de e-mail pendente',
        'email_confirmado' => 'Perfil ainda não enviado',
        'aguardando_analise' => 'Aguardando análise',
        'aprovado' => 'Aprovado',
        'recusado' => 'Não aprovado',
        default => $status,
    };
}

function admin_project(string $uuid): array
{
    $stmt = db('control')->prepare(
        'SELECT p.*,c.nome cliente_nome,c.uuid cliente_uuid,
                pc.nome parceiro_nome,pc.email parceiro_email
         FROM projetos p
         JOIN clientes c ON c.id=p.cliente_id
         LEFT JOIN parceiros partner
           ON partner.usuario_uuid=c.criado_por_usuario_uuid
         LEFT JOIN parceiro_candidaturas pc ON pc.id=partner.candidatura_id
         WHERE p.uuid=:uuid LIMIT 1'
    );
    $stmt->execute(['uuid' => $uuid]);
    $project = $stmt->fetch();
    if (!$project) {
        http_response_code(404);
        exit('Projeto não encontrado.');
    }
    return $project;
}

if ($method === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');
    try {
        if ($action === 'setup') {
            $identity = db('identity');
            $identity->beginTransaction();
            $state = $identity->query('SELECT status FROM threeebs_control.configuracao_instalacao WHERE id=1 FOR UPDATE')->fetchColumn();
            if ($state !== 'pendente') {
                throw new ValidationException('A instalação já foi inicializada.');
            }
            $name = trim((string) ($_POST['nome'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['senha'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ValidationException('Confira o nome e o e-mail.');
            }
            require_strong_password($password, (string) ($_POST['confirmacao'] ?? ''));
            if (!hash_equals(env_required('THREEEBS_SETUP_KEY'), (string) ($_POST['setup_key'] ?? ''))) {
                throw new ValidationException('Chave de instalação inválida.');
            }
            $userUuid = uuid_v4();
            $stmt = $identity->prepare('INSERT INTO usuarios (uuid,nome,email,status) VALUES (:uuid,:nome,:email,\'ativo\')');
            $stmt->execute(['uuid' => $userUuid, 'nome' => $name, 'email' => $email]);
            $userId = (int) $identity->lastInsertId();
            $stmt = $identity->prepare("INSERT INTO credenciais (uuid,usuario_id,tipo,identificador,segredo_hash,ativa) VALUES (:uuid,:user,'senha','',:hash,1)");
            $stmt->execute(['uuid' => uuid_v4(), 'user' => $userId, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $stmt = $identity->prepare("UPDATE threeebs_control.configuracao_instalacao SET primeiro_administrador_usuario_uuid=:uuid,status='inicializada',inicializada_em=UTC_TIMESTAMP(6) WHERE id=1 AND status='pendente'");
            $stmt->execute(['uuid' => $userUuid]);
            $stmt = $identity->prepare(
                "INSERT INTO threeebs_control.plataforma_usuarios
                    (uuid,usuario_uuid,papel,ativo,concedido_por_usuario_uuid)
                 VALUES (:membership,:user,'owner',1,:granted_by)"
            );
            $stmt->execute([
                'membership' => uuid_v4(),
                'user' => $userUuid,
                'granted_by' => $userUuid,
            ]);
            $identity->commit();
            login_user($email, $password);
            audit_event('instalacao.inicializada', 'instalacao');
            flash('Primeiro administrador criado como owner da plataforma.');
            redirect('/');
        }

        if ($action === 'login') {
            if (!login_user((string) ($_POST['email'] ?? ''), (string) ($_POST['senha'] ?? ''))) {
                throw new ValidationException('E-mail ou senha inválidos.');
            }
            if (!is_admin()) {
                logout_user();
                throw new ValidationException('Este usuário não é o administrador inicial.');
            }
            redirect('/');
        }

        if ($action === 'logout') {
            logout_user();
            redirect('/login');
        }

        $admin = require_admin();

        if ($action === 'create_storage_plan') {
            $plan = create_storage_plan(
                (string) ($_POST['nome'] ?? ''),
                (string) ($_POST['codigo'] ?? ''),
                (string) ($_POST['descricao'] ?? ''),
                (int) ($_POST['armazenamento_mib'] ?? 0),
                (int) ($_POST['arquivos_max'] ?? 0),
                (int) ($_POST['pastas_max'] ?? 0),
                (int) ($_POST['arquivo_mib'] ?? 0),
                (string) ($_POST['preco'] ?? ''),
                (string) ($_POST['moeda'] ?? '')
            );
            audit_event(
                'catalogo.plano_storage_criado',
                'catalogo_item',
                (string) $plan['uuid'],
                null,
                null,
                null,
                [
                    'codigo' => $plan['codigo'],
                    'valor' => $plan['valor'],
                    'moeda' => $plan['moeda'],
                    'limites' => [
                        'armazenamento_bytes_max' => $plan['armazenamento_bytes_max'],
                        'arquivos_max' => $plan['arquivos_max'],
                        'pastas_max' => $plan['pastas_max'],
                        'arquivo_bytes_max' => $plan['arquivo_bytes_max'],
                    ],
                ]
            );
            flash('Plano de storage criado no Catálogo.');
            redirect('/planos');
        }

        if ($action === 'assign_storage_plan') {
            $project = admin_project((string) ($_POST['projeto_uuid'] ?? ''));
            $assignment = assign_project_storage_plan(
                (int) $project['id'],
                (string) ($_POST['catalog_item_uuid'] ?? ''),
                (string) $admin['uuid']
            );
            audit_event(
                'projeto.plano_storage_atribuido',
                'projeto',
                (string) $project['uuid'],
                (string) $project['cliente_uuid'],
                (string) $project['uuid'],
                null,
                [
                    'atribuicao_uuid' => $assignment['assignment_uuid'],
                    'catalog_item_uuid' => $assignment['catalog_item_uuid'],
                    'catalog_item_codigo' => $assignment['catalog_item_codigo'],
                    'limites' => [
                        'armazenamento_bytes_max' => $assignment['armazenamento_bytes_max'],
                        'arquivos_max' => $assignment['arquivos_max'],
                        'pastas_max' => $assignment['pastas_max'],
                        'arquivo_bytes_max' => $assignment['arquivo_bytes_max'],
                    ],
                ]
            );
            flash('Plano de storage atribuído e limites do projeto atualizados.');
            redirect('/projetos?uuid=' . rawurlencode((string) $project['uuid']));
        }

        if ($action === 'approve_partner') {
            $applicationUuid = (string) ($_POST['candidatura_uuid'] ?? '');
            $control = db('control');
            $control->beginTransaction();
            try {
                $stmt = $control->prepare(
                    "SELECT * FROM parceiro_candidaturas
                     WHERE uuid=:uuid LIMIT 1 FOR UPDATE"
                );
                $stmt->execute(['uuid' => $applicationUuid]);
                $application = $stmt->fetch();
                if (!is_array($application)
                    || (string) $application['status'] !== 'aguardando_analise'
                    || empty($application['usuario_uuid'])) {
                    throw new ValidationException('Candidatura pendente válida não encontrada.');
                }
                if (!current_active_user(['uuid' => (string) $application['usuario_uuid']])) {
                    throw new ValidationException('O usuário da candidatura não existe ou não está ativo.');
                }
                $stmt = $control->prepare(
                    "INSERT INTO parceiros
                        (uuid,candidatura_id,usuario_uuid,status,aprovado_por_usuario_uuid,aprovado_em)
                     VALUES (:uuid,:application,:user,'ativo',:approved_by,UTC_TIMESTAMP(6))
                     ON DUPLICATE KEY UPDATE
                        status='ativo',
                        aprovado_por_usuario_uuid=VALUES(aprovado_por_usuario_uuid),
                        aprovado_em=VALUES(aprovado_em)"
                );
                $stmt->execute([
                    'uuid' => uuid_v4(),
                    'application' => $application['id'],
                    'user' => $application['usuario_uuid'],
                    'approved_by' => $admin['uuid'],
                ]);
                $stmt = $control->prepare(
                    "UPDATE parceiro_candidaturas
                     SET status='aprovado',aprovado_em=UTC_TIMESTAMP(6),
                         aprovado_por_usuario_uuid=:approved_by,
                         aprovacao_webhook_status='pendente',
                         aprovacao_webhook_ultimo_erro=NULL
                     WHERE id=:id"
                );
                $stmt->execute(['approved_by' => $admin['uuid'], 'id' => $application['id']]);
                $control->commit();
            } catch (Throwable $error) {
                if ($control->inTransaction()) {
                    $control->rollBack();
                }
                throw $error;
            }
            $application = partner_application_by_uuid($applicationUuid);
            $webhook = n8n_webhook_event('parceiro.aprovado', [
                'application_uuid' => $applicationUuid,
                'partner_user_uuid' => (string) $application['usuario_uuid'],
                'email' => (string) $application['email'],
                'name' => (string) $application['nome'],
                'portal_url' => rtrim((string) (getenv('PORTAL_URL') ?: ''), '/') . '/parceiro',
                'approved_at' => (string) $application['aprovado_em'],
            ], 'partner-approved:' . $applicationUuid . ':' . (string) $application['aprovado_em']);
            update_partner_webhook_delivery($applicationUuid, 'aprovacao', $webhook);
            audit_event('parceiro.aprovado', 'parceiro_candidatura', $applicationUuid, null, null, null, [
                'usuario_uuid' => $application['usuario_uuid'],
                'webhook_status' => $webhook['status'],
            ]);
            flash(($webhook['success'] ?? false)
                ? 'Parceiro aprovado e comunicação enviada.'
                : 'Parceiro aprovado. A comunicação por e-mail ficou pendente; a aprovação foi preservada.');
            redirect('/parceiros?uuid=' . rawurlencode($applicationUuid));
        }

        if ($action === 'retry_partner_approval_webhook') {
            $applicationUuid = (string) ($_POST['candidatura_uuid'] ?? '');
            $application = partner_application_by_uuid($applicationUuid);
            if (!$application || (string) $application['status'] !== 'aprovado') {
                throw new ValidationException('Candidatura aprovada não encontrada.');
            }
            $webhook = n8n_webhook_event('parceiro.aprovado', [
                'application_uuid' => $applicationUuid,
                'partner_user_uuid' => (string) $application['usuario_uuid'],
                'email' => (string) $application['email'],
                'name' => (string) $application['nome'],
                'portal_url' => rtrim((string) (getenv('PORTAL_URL') ?: ''), '/') . '/parceiro',
                'approved_at' => (string) $application['aprovado_em'],
            ], 'partner-approved:' . $applicationUuid . ':' . (string) $application['aprovado_em']);
            update_partner_webhook_delivery($applicationUuid, 'aprovacao', $webhook);
            flash(($webhook['success'] ?? false)
                ? 'Comunicação reenviada.'
                : 'O reenvio falhou. A aprovação continua válida.');
            redirect('/parceiros?uuid=' . rawurlencode($applicationUuid));
        }

        if ($action === 'create_journey') {
            $name = trim((string) ($_POST['nome'] ?? ''));
            $code = slug((string) ($_POST['codigo'] ?? ''));
            $context = slug((string) ($_POST['contexto'] ?? ''));
            if ($name === '' || $code === '' || $context === '') {
                throw new ValidationException('Nome, código e contexto são obrigatórios.');
            }
            $stmt = db('control')->prepare(
                "INSERT INTO jornadas (uuid,codigo,nome,descricao,contexto,status)
                 VALUES (:uuid,:code,:name,:description,:context,:status)"
            );
            $uuid = uuid_v4();
            $stmt->execute([
                'uuid' => $uuid,
                'code' => $code,
                'name' => $name,
                'description' => trim((string) ($_POST['descricao'] ?? '')),
                'context' => $context,
                'status' => isset($_POST['ativa']) ? 'ativa' : 'inativa',
            ]);
            flash('Jornada criada.');
            redirect('/jornadas?uuid=' . rawurlencode($uuid));
        }

        if ($action === 'create_journey_stage') {
            $journeyUuid = (string) ($_POST['jornada_uuid'] ?? '');
            $title = trim((string) ($_POST['titulo'] ?? ''));
            $code = slug((string) ($_POST['codigo'] ?? ''));
            $order = (int) ($_POST['ordem'] ?? 0);
            if ($journeyUuid === '' || $title === '' || $code === '' || $order < 1) {
                throw new ValidationException('Jornada, título, código e ordem positiva são obrigatórios.');
            }
            $stmt = db('control')->prepare(
                "INSERT INTO jornada_etapas
                    (uuid,jornada_id,codigo,titulo,descricao,ordem,status)
                 SELECT :uuid,id,:code,:title,:description,:position,:status
                 FROM jornadas WHERE uuid=:journey"
            );
            $stmt->execute([
                'uuid' => uuid_v4(),
                'code' => $code,
                'title' => $title,
                'description' => trim((string) ($_POST['descricao'] ?? '')),
                'position' => $order,
                'status' => isset($_POST['ativa']) ? 'ativa' : 'inativa',
                'journey' => $journeyUuid,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new ValidationException('Jornada não encontrada.');
            }
            flash('Etapa criada.');
            redirect('/jornadas?uuid=' . rawurlencode($journeyUuid));
        }

        if ($action === 'assign_journey') {
            $journeyUuid = (string) ($_POST['jornada_uuid'] ?? '');
            $userUuid = (string) ($_POST['usuario_uuid'] ?? '');
            $user = db('identity')->prepare('SELECT 1 FROM usuarios WHERE uuid=:uuid AND status=\'ativo\'');
            $user->execute(['uuid' => $userUuid]);
            if (!$user->fetchColumn()) {
                throw new ValidationException('Usuário ativo não encontrado.');
            }
            $journey = db('control')->prepare('SELECT 1 FROM jornadas WHERE uuid=:uuid');
            $journey->execute(['uuid' => $journeyUuid]);
            if (!$journey->fetchColumn()) {
                throw new ValidationException('Jornada não encontrada.');
            }
            $stmt = db('control')->prepare(
                "INSERT INTO usuario_jornadas (uuid,jornada_id,usuario_uuid,status)
                 SELECT :uuid,id,:user,'pendente' FROM jornadas WHERE uuid=:journey
                 ON DUPLICATE KEY UPDATE updated_at=CURRENT_TIMESTAMP(6)"
            );
            $stmt->execute([
                'uuid' => uuid_v4(),
                'user' => $userUuid,
                'journey' => $journeyUuid,
            ]);
            flash('Jornada associada ao usuário.');
            redirect('/jornadas?uuid=' . rawurlencode($journeyUuid));
        }

        if ($action === 'toggle_journey') {
            $journeyUuid = (string) ($_POST['jornada_uuid'] ?? '');
            $stmt = db('control')->prepare(
                "UPDATE jornadas
                 SET status=IF(status='ativa','inativa','ativa')
                 WHERE uuid=:uuid"
            );
            $stmt->execute(['uuid' => $journeyUuid]);
            flash('Status da jornada alterado.');
            redirect('/jornadas?uuid=' . rawurlencode($journeyUuid));
        }

        if ($action === 'toggle_journey_stage') {
            $journeyUuid = (string) ($_POST['jornada_uuid'] ?? '');
            $stmt = db('control')->prepare(
                "UPDATE jornada_etapas e
                 JOIN jornadas j ON j.id=e.jornada_id
                 SET e.status=IF(e.status='ativa','inativa','ativa')
                 WHERE e.uuid=:stage AND j.uuid=:journey"
            );
            $stmt->execute([
                'stage' => (string) ($_POST['etapa_uuid'] ?? ''),
                'journey' => $journeyUuid,
            ]);
            flash('Status da etapa alterado.');
            redirect('/jornadas?uuid=' . rawurlencode($journeyUuid));
        }

        if ($action === 'advance_journey') {
            $journeyUuid = (string) ($_POST['jornada_uuid'] ?? '');
            flash(advance_user_journey((string) ($_POST['usuario_jornada_uuid'] ?? '')));
            redirect('/jornadas?uuid=' . rawurlencode($journeyUuid));
        }

        if ($action === 'create_user') {
            $result = create_user_with_first_access(
                (string) ($_POST['nome'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                $admin
            );
            $uuid = (string) $result['user']['uuid'];
            audit_event('usuario.criado', 'usuario', $uuid);
            flash(($result['webhook']['success'] ?? false)
                ? 'Usuário criado e convite de primeiro acesso enviado.'
                : 'Usuário criado, mas o webhook de primeiro acesso não foi entregue. Confira a integração n8n.');
            redirect('/usuarios?uuid=' . rawurlencode($uuid));
        }

        if ($action === 'create_client') {
            $name = trim((string) ($_POST['nome'] ?? ''));
            $clientSlug = slug((string) ($_POST['slug'] ?? ''));
            if ($name === '' || $clientSlug === '') {
                throw new ValidationException('Nome e slug são obrigatórios.');
            }
            $stmt = db('control')->prepare('INSERT INTO clientes (uuid,nome,slug,status,criado_por_usuario_uuid) VALUES (:uuid,:nome,:slug,:status,:by)');
            $uuid = uuid_v4();
            $stmt->execute(['uuid' => $uuid, 'nome' => $name, 'slug' => $clientSlug, 'status' => (string) ($_POST['status'] ?? 'ativo'), 'by' => $admin['uuid']]);
            audit_event('cliente.criado', 'cliente', $uuid, $uuid);
            flash('Cliente criado.');
            redirect('/clientes?uuid=' . rawurlencode($uuid));
        }

        if ($action === 'link_client_user') {
            $clientUuid = (string) ($_POST['cliente_uuid'] ?? '');
            $clientUserUuid = (string) ($_POST['usuario_uuid'] ?? '');
            if (!current_active_user(['uuid' => $clientUserUuid])) {
                throw new ValidationException('O usuário selecionado não existe ou não está ativo.');
            }
            $clientLookup = db('control')->prepare(
                "SELECT id FROM clientes WHERE uuid=:client AND status='ativo' LIMIT 1"
            );
            $clientLookup->execute(['client' => $clientUuid]);
            $clientId = $clientLookup->fetchColumn();
            if (!$clientId) {
                throw new ValidationException('Cliente ativo não encontrado.');
            }

            $stmt = db('control')->prepare(
                "INSERT INTO cliente_usuarios
                    (cliente_id,usuario_uuid,papel,ativo,concedido_por_usuario_uuid)
                 VALUES (:client,:user,:role,:active,:by)
                 ON DUPLICATE KEY UPDATE
                    papel=VALUES(papel),ativo=VALUES(ativo),
                    concedido_por_usuario_uuid=VALUES(concedido_por_usuario_uuid)"
            );
            $clientRoles = ['proprietario' => 'Proprietário', 'gestor' => 'Gestor', 'membro' => 'Membro'];
            $clientRole = admin_allowed_role(trim((string) ($_POST['papel'] ?? '')), $clientRoles);
            $clientUserActive = isset($_POST['ativo']) ? 1 : 0;
            $stmt->execute([
                'client' => $clientId,
                'user' => $clientUserUuid,
                'role' => $clientRole,
                'active' => $clientUserActive,
                'by' => $admin['uuid'],
            ]);
            audit_event(
                'cliente.usuario_vinculado',
                'cliente',
                $clientUuid,
                $clientUuid,
                null,
                null,
                ['usuario_uuid' => $clientUserUuid, 'papel' => $clientRole, 'ativo' => $clientUserActive]
            );
            flash('Vínculo do cliente salvo.');
            redirect('/clientes?uuid=' . rawurlencode($clientUuid));
        }

        if ($action === 'create_project') {
            $clientUuid = (string) $_POST['cliente_uuid'];
            $name = trim((string) ($_POST['nome'] ?? ''));
            $projectSlug = slug((string) ($_POST['slug'] ?? ''));
            if ($name === '' || $projectSlug === '') {
                throw new ValidationException('Nome e slug são obrigatórios.');
            }
            $stmt = db('control')->prepare('INSERT INTO projetos (uuid,cliente_id,nome,slug,descricao,status) SELECT :uuid,id,:nome,:slug,:description,:status FROM clientes WHERE uuid=:client');
            $uuid = uuid_v4();
            $stmt->execute(['uuid' => $uuid, 'nome' => $name, 'slug' => $projectSlug, 'description' => trim((string) ($_POST['descricao'] ?? '')), 'status' => (string) ($_POST['status'] ?? 'ativo'), 'client' => $clientUuid]);
            if ($stmt->rowCount() !== 1) {
                throw new ValidationException('Cliente não encontrado.');
            }
            audit_event('projeto.criado', 'projeto', $uuid, $clientUuid, $uuid);
            flash('Projeto criado.');
            redirect('/projetos?uuid=' . rawurlencode($uuid));
        }

        if ($action === 'link_project_user') {
            $projectUuid = (string) ($_POST['projeto_uuid'] ?? '');
            $projectUserUuid = (string) ($_POST['usuario_uuid'] ?? '');
            $project = admin_project($projectUuid);
            if (!current_active_user(['uuid' => $projectUserUuid])) {
                throw new ValidationException('O usuário selecionado não existe ou não está ativo.');
            }
            $membership = db('control')->prepare(
                'SELECT 1
                 FROM cliente_usuarios
                 WHERE cliente_id=:client AND usuario_uuid=:user AND ativo=1
                 LIMIT 1'
            );
            $membership->execute([
                'client' => $project['cliente_id'],
                'user' => $projectUserUuid,
            ]);
            if (!$membership->fetchColumn()) {
                throw new ValidationException(
                    'O usuário precisa pertencer ativamente ao cliente dono deste projeto.'
                );
            }

            $stmt = db('control')->prepare(
                "INSERT INTO projeto_usuarios
                    (projeto_id,usuario_uuid,papel,ativo,concedido_por_usuario_uuid)
                 VALUES (:project,:user,:role,:active,:by)
                 ON DUPLICATE KEY UPDATE
                    papel=VALUES(papel),ativo=VALUES(ativo),
                    concedido_por_usuario_uuid=VALUES(concedido_por_usuario_uuid)"
            );
            $projectRoles = [
                'gestor' => 'Gestor',
                'desenvolvedor' => 'Desenvolvedor',
                'colaborador' => 'Colaborador',
                'membro' => 'Membro',
                'visualizador' => 'Visualizador',
            ];
            $projectRole = admin_allowed_role(trim((string) ($_POST['papel'] ?? '')), $projectRoles);
            $projectUserActive = isset($_POST['ativo']) ? 1 : 0;
            $stmt->execute([
                'project' => $project['id'],
                'user' => $projectUserUuid,
                'role' => $projectRole,
                'active' => $projectUserActive,
                'by' => $admin['uuid'],
            ]);
            audit_event(
                'projeto.usuario_vinculado',
                'projeto',
                $projectUuid,
                $project['cliente_uuid'],
                $projectUuid,
                null,
                ['usuario_uuid' => $projectUserUuid, 'papel' => $projectRole, 'ativo' => $projectUserActive]
            );
            flash('Vínculo do projeto salvo.');
            redirect('/projetos?uuid=' . rawurlencode($projectUuid));
        }

        if (in_array($action, ['queue_database_provision', 'queue_php_activation'], true)) {
            $project = admin_project((string) ($_POST['projeto_uuid'] ?? ''));
            $environment = environment_for_project(
                (string) $project['uuid'],
                (string) ($_POST['ambiente_uuid'] ?? '')
            );
            if ((string) $environment['tipo'] === 'production'
                && (string) ($_POST['confirm_production'] ?? '') !== '1') {
                throw new ValidationException(
                    'Confirme explicitamente a operação no ambiente de Produção.'
                );
            }
            $operationType = $action === 'queue_database_provision'
                ? 'database.provision'
                : 'runtime.php.activate';
            queue_environment_operation(
                $environment,
                $operationType,
                (string) $admin['uuid'],
                ['production_confirmed' => (string) $environment['tipo'] === 'production']
            );
            flash(
                $operationType === 'database.provision'
                    ? 'Criação do banco adicionada à fila.'
                    : 'Ativação do PHP adicionada à fila.'
            );
            redirect('/projetos?uuid=' . rawurlencode((string) $project['uuid']));
        }

        if ($action === 'provision_environments') {
            $project = admin_project((string) $_POST['projeto_uuid']);
            $serverId = db('control')->query("SELECT id FROM servidores WHERE padrao=1 AND status='ativo' ORDER BY id LIMIT 1")->fetchColumn();
            if (!$serverId) {
                throw new ValidationException('Servidor padrão ativo não encontrado.');
            }
            $stmt = db('control')->prepare("INSERT INTO ambientes (uuid,projeto_id,servidor_id,tipo,nome,slug,diretorio,status)
                VALUES (:uuid,:project,:server,:type,:name,:slug,:directory,'ativo')
                ON DUPLICATE KEY UPDATE servidor_id=VALUES(servidor_id),nome=VALUES(nome),slug=VALUES(slug),diretorio=VALUES(diretorio)");
            $runtimeStmt = db('control')->prepare(
                "INSERT INTO ambiente_runtimes
                    (uuid,ambiente_id,tipo,execucao_habilitada,mount_target,status,configuracao)
                 SELECT :uuid,a.id,'runtime.static',0,'/var/www/project','planejado',
                        JSON_OBJECT('document_root','/var/www/project')
                   FROM ambientes a
                  WHERE a.projeto_id=:project AND a.tipo=:type
                 ON DUPLICATE KEY UPDATE ambiente_id=VALUES(ambiente_id)"
            );
            foreach (['sandbox' => ['Sandbox', 'sandbox'], 'production' => ['Produção', 'production']] as $type => [$name, $folder]) {
                $relative = $project['uuid'] . '/' . $folder;
                $stmt->execute(['uuid' => uuid_v4(), 'project' => $project['id'], 'server' => $serverId, 'type' => $type, 'name' => $name, 'slug' => $folder, 'directory' => $relative]);
                $runtimeStmt->execute([
                    'uuid' => uuid_v4(),
                    'project' => $project['id'],
                    'type' => $type,
                ]);
                $directory = safe_environment_path($relative, true);
                $index = $directory . '/index.html';
                if (!is_file($index)) {
                    $label = $type === 'sandbox' ? 'Sandbox' : 'Production';
                    atomic_write($index, '<h1>Threeebs ' . $label . ' :3</h1>' . "\n" . '<p>Projeto: ' . h($project['nome']) . '</p>' . "\n");
                }
            }
            audit_event(
                'ambientes.criados',
                'projeto',
                $project['uuid'],
                null,
                $project['uuid'],
                null,
                ['tipos' => ['sandbox', 'production']]
            );
            flash('Ambientes padrão provisionados sem sobrescrever conteúdo existente.');
            redirect('/projetos?uuid=' . rawurlencode($project['uuid']));
        }

        if ($action === 'create_route') {
            $projectUuid = (string) $_POST['projeto_uuid'];
            $hostname = normalize_hostname((string) ($_POST['hostname'] ?? ''));
            $routeType = (string) ($_POST['tipo'] ?? '');
            if (!in_array($routeType, ['subdominio', 'dominio_personalizado'], true)) {
                throw new ValidationException('Tipo de rota inválido.');
            }

            $existing = db('control')->prepare(
                'SELECT r.hostname,a.nome ambiente,p.nome projeto
                 FROM rotas_web r
                 JOIN ambientes a ON a.id=r.ambiente_id
                 JOIN projetos p ON p.id=a.projeto_id
                 WHERE r.hostname=:hostname LIMIT 1'
            );
            $existing->execute(['hostname' => $hostname]);
            if ($currentRoute = $existing->fetch()) {
                throw new ValidationException(
                    'Hostname já cadastrado para o projeto '
                    . $currentRoute['projeto'] . ' / ' . $currentRoute['ambiente'] . '.'
                );
            }

            $stmt = db('control')->prepare(
                'INSERT INTO rotas_web (uuid,ambiente_id,hostname,tipo,ativo)
                 SELECT :uuid,a.id,:hostname,:type,:active
                 FROM ambientes a
                 JOIN projetos p ON p.id=a.projeto_id
                 WHERE a.uuid=:environment AND p.uuid=:project'
            );
            $routeUuid = uuid_v4();
            $stmt->execute([
                'uuid' => $routeUuid,
                'hostname' => $hostname,
                'type' => $routeType,
                'active' => isset($_POST['ativo']) ? 1 : 0,
                'environment' => (string) $_POST['ambiente_uuid'],
                'project' => $projectUuid,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new ValidationException('Ambiente não encontrado neste projeto.');
            }
            audit_event(
                'rota.criada',
                'rota_web',
                $routeUuid,
                null,
                $projectUuid,
                (string) $_POST['ambiente_uuid'],
                ['hostname' => $hostname, 'tipo' => $routeType]
            );
            flash('Rota criada. Configure o mesmo hostname na Cloudflare para publicar o acesso.');
            redirect('/projetos?uuid=' . rawurlencode($projectUuid));
        }

        if ($action === 'create_default_board') {
            $project = admin_project((string) $_POST['projeto_uuid']);
            $work = db('work');
            $work->beginTransaction();
            $stmt = $work->prepare("INSERT INTO quadros (uuid,projeto_uuid,nome,slug,status) VALUES (:uuid,:project,'Fluxo padrão','fluxo-padrao','ativo') ON DUPLICATE KEY UPDATE nome=VALUES(nome)");
            $stmt->execute(['uuid' => uuid_v4(), 'project' => $project['uuid']]);
            $stmt = $work->prepare("SELECT id FROM quadros WHERE projeto_uuid=:project AND slug='fluxo-padrao'");
            $stmt->execute(['project' => $project['uuid']]);
            $boardId = (int) $stmt->fetchColumn();
            $column = $work->prepare("INSERT INTO colunas (uuid,quadro_id,nome,slug,ordem,status) VALUES (:uuid,:board,:name,:slug,:position,'ativa') ON DUPLICATE KEY UPDATE nome=VALUES(nome)");
            foreach (['Backlog', 'Fazer', 'Fazendo', 'Revisão', 'Concluído'] as $position => $name) {
                $column->execute(['uuid' => uuid_v4(), 'board' => $boardId, 'name' => $name, 'slug' => slug($name), 'position' => $position + 1]);
            }
            $work->commit();
            flash('Quadro padrão criado.');
            redirect('/projetos?uuid=' . rawurlencode($project['uuid']));
        }

        if ($action === 'create_board') {
            $project = admin_project((string) $_POST['projeto_uuid']);
            $name = trim((string) $_POST['nome']);
            if ($name === '') throw new ValidationException('Informe o nome do quadro.');
            $stmt = db('work')->prepare("INSERT INTO quadros (uuid,projeto_uuid,nome,slug,status) VALUES (:uuid,:project,:name,:slug,'ativo')");
            $stmt->execute(['uuid' => uuid_v4(), 'project' => $project['uuid'], 'name' => $name, 'slug' => slug($name)]);
            redirect('/projetos?uuid=' . rawurlencode($project['uuid']));
        }

        if ($action === 'create_column') {
            $project = admin_project((string) $_POST['projeto_uuid']);
            $name = trim((string) $_POST['nome']);
            $stmt = db('work')->prepare("INSERT INTO colunas (uuid,quadro_id,nome,slug,ordem,status) VALUES (:uuid,:board,:name,:slug,:position,'ativa')");
            $stmt->execute(['uuid' => uuid_v4(), 'board' => (int) $_POST['quadro_id'], 'name' => $name, 'slug' => slug($name), 'position' => max(1, (int) $_POST['ordem'])]);
            redirect('/projetos?uuid=' . rawurlencode($project['uuid']));
        }

        if ($action === 'create_task') {
            $project = admin_project((string) $_POST['projeto_uuid']);
            $title = trim((string) $_POST['titulo']);
            if ($title === '') throw new ValidationException('Informe o título da tarefa.');
            $work = db('work');
            $work->beginTransaction();
            $stmt = $work->prepare("INSERT INTO tarefas (uuid,coluna_id,titulo,descricao,status,prioridade,criado_por_usuario_uuid) VALUES (:uuid,:column,:title,:description,'aberta',:priority,:by)");
            $taskUuid = uuid_v4();
            $stmt->execute(['uuid' => $taskUuid, 'column' => (int) $_POST['coluna_id'], 'title' => $title, 'description' => trim((string) ($_POST['descricao'] ?? '')), 'priority' => trim((string) ($_POST['prioridade'] ?? 'normal')) ?: 'normal', 'by' => $admin['uuid']]);
            $taskId = (int) $work->lastInsertId();
            if (!empty($_POST['responsavel_uuid'])) {
                $stmt = $work->prepare("INSERT INTO tarefa_responsaveis (tarefa_id,usuario_uuid,papel) VALUES (:task,:user,'responsavel')");
                $stmt->execute(['task' => $taskId, 'user' => (string) $_POST['responsavel_uuid']]);
            }
            $work->commit();
            audit_event(
                'tarefa.criada',
                'tarefa',
                $taskUuid,
                $project['cliente_uuid'] ?? null,
                $project['uuid']
            );
            flash('Tarefa criada.');
            redirect('/projetos?uuid=' . rawurlencode($project['uuid']));
        }
    } catch (Throwable $error) {
        if (isset($identity) && $identity instanceof PDO && $identity->inTransaction()) $identity->rollBack();
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        if (isset($work) && $work instanceof PDO && $work->inTransaction()) $work->rollBack();
        error_log($error->getMessage());
        $message = $error instanceof PDOException && $error->getCode() === '23000'
            ? 'Registro duplicado ou vínculo inválido.'
            : public_error_message($error);
        flash($message);
        $return = (string) ($_POST['_return'] ?? '/');
        redirect(str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : '/');
    }
}

$state = installation();
if (($state['status'] ?? 'pendente') === 'pendente') {
    admin_page_start('Threeebs Admin :3 — Primeiro acesso');
    show_flash();
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="_action" value="setup">';
    input('nome', 'Nome'); input('email', 'E-mail', 'email'); echo ui_new_password_fields('confirmacao'); input('setup_key', 'THREEEBS_SETUP_KEY', 'password');
    echo '<button>Criar primeiro administrador</button></form>';
    admin_page_end(); exit;
}

if ($path === '/login' && !auth_user()) {
    admin_page_start('Acesso administrativo');
    $passwordResetUrl = rtrim((string) (getenv('PORTAL_URL') ?: ''), '/') . '/esqueci-senha';
    echo '<section class="admin-login-shell" aria-labelledby="admin-login-title">'
        . '<div class="admin-login-story"><a class="admin-login-brand" href="https://www.3eb.site" aria-label="Threeebs — voltar ao início">'
        . '<strong>Threeebs <span>:3</span></strong><small>Admin</small></a>'
        . '<div class="admin-login-copy"><p class="eyebrow"><span></span>Operação central</p>'
        . '<h2>Onde projetos ganham direção.</h2><p>Um espaço reservado para organizar pessoas, ambientes e toda a operação Threeebs.</p></div>'
        . '<div class="admin-login-orbit" aria-hidden="true"><span>:3</span><i></i><i></i><i></i></div>'
        . '<p class="admin-login-status"><span></span>Ambiente administrativo protegido</p></div>'
        . '<div class="admin-login-panel"><a class="button button--secondary admin-login-back" href="https://www.3eb.site">'
        . ui_icon('home', 18) . 'Voltar ao início</a><div class="admin-login-card">'
        . '<p class="eyebrow"><span></span>Acesso restrito</p><h1 id="admin-login-title">Bem-vindo de volta.</h1>'
        . '<p class="admin-login-intro">Use suas credenciais administrativas para continuar.</p>'
        . '<div class="admin-login-flash">';
    show_flash();
    echo '</div><form class="admin-login-form" method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="login">'
        . '<label class="field">E-mail<input type="email" name="email" autocomplete="username" inputmode="email" autofocus required></label>'
        . ui_password_input('senha', 'Senha')
        . '<div class="admin-login-options"><a href="' . h($passwordResetUrl) . '">Esqueci minha senha</a></div>'
        . '<button class="button button--primary admin-login-submit" type="submit">Entrar no Admin</button></form>'
        . '<p class="admin-login-note">Acesso monitorado e exclusivo para pessoas autorizadas.</p></div></div></section>';
    admin_page_end(); exit;
}

$admin = require_admin();
admin_page_start('Threeebs Admin :3');
admin_nav(); show_flash();

if ($path === '/') {
    echo '<section class="admin-overview"><p class="eyebrow"><span></span>Administração da plataforma</p><h1>Olá, ' . h($admin['nome']) . '.</h1><p>Gerencie pessoas, clientes, projetos e operação a partir de um único painel.</p></section>'
        . '<div class="admin-grid">'
        . '<a href="/usuarios"><span>Acessos</span><strong>Usuários</strong></a>'
        . '<a href="/clientes"><span>Organizações</span><strong>Clientes</strong></a>'
        . '<a href="/projetos"><span>Ambientes e rotas</span><strong>Projetos</strong></a>'
        . '<a href="/jornadas"><span>Onboarding</span><strong>Jornadas</strong></a>'
        . '<a href="/tarefas"><span>Planejamento</span><strong>Tarefas</strong></a>'
        . '<a href="/servidores"><span>Infraestrutura</span><strong>Servidores</strong></a>'
        . '</div>';
} elseif ($path === '/usuarios') {
    $uuid = (string) ($_GET['uuid'] ?? '');
    if ($uuid !== '') {
        $stmt = db('identity')->prepare("SELECT u.uuid,u.nome,u.email,u.status,u.created_at,
            EXISTS(SELECT 1 FROM credenciais c WHERE c.usuario_id=u.id AND c.tipo='senha' AND c.ativa=1) possui_senha
            FROM usuarios u WHERE u.uuid=:uuid");
        $stmt->execute(['uuid' => $uuid]); $item = $stmt->fetch();
        echo $item ? '<h2>' . h($item['nome']) . '</h2><dl><dt>E-mail</dt><dd>' . h($item['email']) . '</dd><dt>Status</dt><dd>' . h($item['status']) . '</dd><dt>Acesso</dt><dd>' . ((int) $item['possui_senha'] === 1 ? 'Senha definida' : 'Aguardando primeiro acesso') . '</dd><dt>UUID</dt><dd>' . h($item['uuid']) . '</dd></dl>' : '<p>Usuário não encontrado.</p>';
    }
    echo '<h2>Criar usuário</h2><p class="admin-intro">Informe apenas nome e e-mail. O usuário receberá um link de primeiro acesso para criar e confirmar uma senha forte.</p><form method="post">' . csrf_field() . '<input type="hidden" name="_action" value="create_user"><input type="hidden" name="_return" value="/usuarios">';
    input('nome','Nome'); input('email','E-mail','email'); echo '<button>Criar e enviar primeiro acesso</button></form><h2>Usuários</h2><ul>';
    foreach (db('identity')->query('SELECT uuid,nome,email,status FROM usuarios ORDER BY nome,email') as $user) echo '<li><a href="/usuarios?uuid=' . h($user['uuid']) . '">' . h($user['nome'] ?: $user['email']) . '</a> — ' . h($user['status']) . '</li>';
    echo '</ul>';
} elseif ($path === '/jornadas') {
    $uuid = (string) ($_GET['uuid'] ?? '');
    if ($uuid === '') {
        echo '<h2>Criar jornada</h2><form method="post">' . csrf_field()
            . '<input type="hidden" name="_action" value="create_journey">'
            . '<input type="hidden" name="_return" value="/jornadas">';
        input('nome', 'Nome');
        input('codigo', 'Código');
        input('contexto', 'Contexto', 'text', 'cliente');
        echo '<p><label>Descrição<br><textarea name="descricao"></textarea></label></p>'
            . '<label><input type="checkbox" name="ativa" checked> ativa</label> '
            . '<button>Criar jornada</button></form><h2>Jornadas</h2><ul>';
        foreach (db('control')->query('SELECT uuid,nome,contexto,status FROM jornadas ORDER BY nome') as $journey) {
            echo '<li><a href="/jornadas?uuid=' . h($journey['uuid']) . '">'
                . h($journey['nome']) . '</a> — ' . h($journey['contexto'])
                . ' — ' . h($journey['status']) . '</li>';
        }
        echo '</ul>';
    } else {
        $stmt = db('control')->prepare('SELECT * FROM jornadas WHERE uuid=:uuid');
        $stmt->execute(['uuid' => $uuid]);
        $journey = $stmt->fetch();
        if (!$journey) {
            http_response_code(404);
            echo '<p>Jornada não encontrada.</p>';
        } else {
            echo '<h2>' . h($journey['nome']) . '</h2><p>Código: '
                . h($journey['codigo']) . ' | Contexto: ' . h($journey['contexto'])
                . ' | Status: ' . h($journey['status']) . '</p><p>'
                . nl2br(h($journey['descricao'])) . '</p>'
                . '<form method="post">' . csrf_field()
                . '<input type="hidden" name="_action" value="toggle_journey">'
                . '<input type="hidden" name="jornada_uuid" value="' . h($uuid) . '">'
                . '<button>Alternar status da jornada</button></form>';

            $stmt = db('control')->prepare(
                'SELECT * FROM jornada_etapas WHERE jornada_id=:id ORDER BY ordem,id'
            );
            $stmt->execute(['id' => $journey['id']]);
            $stages = $stmt->fetchAll();
            $activeStageCount = 0;
            echo '<h3>Etapas</h3><ol>';
            foreach ($stages as $stage) {
                if ($stage['status'] === 'ativa') {
                    $activeStageCount++;
                }
                echo '<li>' . h($stage['titulo']) . ' — ordem ' . h($stage['ordem'])
                    . ' — ' . h($stage['status'])
                    . '<form method="post">' . csrf_field()
                    . '<input type="hidden" name="_action" value="toggle_journey_stage">'
                    . '<input type="hidden" name="jornada_uuid" value="' . h($uuid) . '">'
                    . '<input type="hidden" name="etapa_uuid" value="' . h($stage['uuid']) . '">'
                    . '<button>Alternar status</button></form></li>';
            }
            echo '</ol><h3>Adicionar etapa</h3><form method="post">' . csrf_field()
                . '<input type="hidden" name="_action" value="create_journey_stage">'
                . '<input type="hidden" name="jornada_uuid" value="' . h($uuid) . '">';
            input('titulo', 'Título');
            input('codigo', 'Código');
            input('ordem', 'Ordem', 'number', (string) (count($stages) + 1));
            echo '<p><label>Descrição<br><textarea name="descricao"></textarea></label></p>'
                . '<label><input type="checkbox" name="ativa" checked> ativa</label> '
                . '<button>Adicionar etapa</button></form>';

            $users = db('identity')->query(
                "SELECT uuid,nome,email FROM usuarios WHERE status='ativo' ORDER BY nome,email"
            )->fetchAll();
            $usersByUuid = [];
            foreach ($users as $user) {
                $usersByUuid[$user['uuid']] = $user['nome'] ?: $user['email'];
            }
            echo '<h3>Associar usuário</h3><form method="post">' . csrf_field()
                . '<input type="hidden" name="_action" value="assign_journey">'
                . '<input type="hidden" name="jornada_uuid" value="' . h($uuid) . '">'
                . '<select name="usuario_uuid">';
            foreach ($users as $user) {
                echo '<option value="' . h($user['uuid']) . '">'
                    . h(($user['nome'] ?: $user['email']) . ' — ' . $user['email'])
                    . '</option>';
            }
            echo '</select> <button>Associar jornada</button></form>';

            $stmt = db('control')->prepare(
                "SELECT uj.uuid,uj.usuario_uuid,uj.status,uj.iniciada_em,uj.concluida_em,
                        e.titulo etapa_atual,
                        (SELECT COUNT(*) FROM usuario_jornada_etapas uje
                         WHERE uje.usuario_jornada_id=uj.id
                           AND uje.status='concluida') etapas_concluidas
                 FROM usuario_jornadas uj
                 LEFT JOIN jornada_etapas e ON e.id=uj.etapa_atual_id
                 WHERE uj.jornada_id=:journey
                 ORDER BY uj.created_at,uj.id"
            );
            $stmt->execute(['journey' => $journey['id']]);
            echo '<h3>Progresso dos usuários</h3><ul>';
            foreach ($stmt as $progress) {
                echo '<li><strong>'
                    . h($usersByUuid[$progress['usuario_uuid']] ?? $progress['usuario_uuid'])
                    . '</strong> — ' . h($progress['status'])
                    . ' — progresso ' . h($progress['etapas_concluidas']) . '/'
                    . h($activeStageCount)
                    . ' — etapa atual: ' . h($progress['etapa_atual'] ?: 'nenhuma');
                if ($progress['status'] !== 'concluida') {
                    echo '<form method="post">' . csrf_field()
                        . '<input type="hidden" name="_action" value="advance_journey">'
                        . '<input type="hidden" name="jornada_uuid" value="' . h($uuid) . '">'
                        . '<input type="hidden" name="usuario_jornada_uuid" value="'
                        . h($progress['uuid']) . '"><button>'
                        . ($progress['status'] === 'pendente'
                            ? 'Iniciar jornada'
                            : 'Concluir etapa e avançar')
                        . '</button></form>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
    }
} elseif ($path === '/parceiros') {
    $uuid = (string) ($_GET['uuid'] ?? '');
    echo '<div class="page-heading compact-heading"><p class="eyebrow"><span></span>Rede Threeebs</p>'
        . '<h1>Candidaturas de parceiros</h1><p>Confirmação de e-mail, análise administrativa e situação das comunicações.</p></div>';
    if ($uuid !== '') {
        $stmt = db('control')->prepare(
            'SELECT pc.*,u.status usuario_status,p.uuid parceiro_uuid,p.status parceiro_status
             FROM parceiro_candidaturas pc
             LEFT JOIN threeebs_identity.usuarios u ON u.uuid=pc.usuario_uuid
             LEFT JOIN parceiros p ON p.candidatura_id=pc.id
             WHERE pc.uuid=:uuid LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        $application = $stmt->fetch();
        if (!$application) {
            http_response_code(404);
            echo '<p class="empty-state">Candidatura não encontrada.</p>';
        } else {
            echo '<section class="admin-card partner-review"><div class="section-title"><h2>'
                . h($application['nome'] ?: $application['email']) . '</h2><span>'
                . h(admin_partner_status_label((string) $application['status'])) . '</span></div>'
                . '<dl class="detail-list"><div><dt>E-mail</dt><dd>' . h($application['email']) . '</dd></div>'
                . '<div><dt>Confirmado em</dt><dd>' . h($application['email_confirmado_em'] ?: 'pendente') . '</dd></div>'
                . '<div><dt>GitHub</dt><dd>' . ($application['github_url'] ? '<a href="' . h($application['github_url']) . '" target="_blank" rel="noopener">Abrir perfil</a>' : 'não informado') . '</dd></div>'
                . '<div><dt>LinkedIn</dt><dd>' . ($application['linkedin_url'] ? '<a href="' . h($application['linkedin_url']) . '" target="_blank" rel="noopener">Abrir perfil</a>' : 'não informado') . '</dd></div>'
                . '<div><dt>Portfólio</dt><dd>' . ($application['portfolio_url'] ? '<a href="' . h($application['portfolio_url']) . '" target="_blank" rel="noopener">Abrir site</a>' : 'não informado') . '</dd></div>'
                . '<div><dt>Tecnologias</dt><dd>' . nl2br(h($application['tecnologias'] ?: 'não informado')) . '</dd></div>'
                . '<div><dt>Experiência</dt><dd>' . nl2br(h($application['experiencia'] ?: 'não informado')) . '</dd></div>'
                . '<div><dt>Enviada em</dt><dd>' . h($application['candidatura_enviada_em'] ?: 'pendente') . '</dd></div>'
                . '<div><dt>Aprovada por</dt><dd>' . h($application['aprovado_por_usuario_uuid'] ?: 'pendente') . '</dd></div>'
                . '<div><dt>Aprovada em</dt><dd>' . h($application['aprovado_em'] ?: 'pendente') . '</dd></div></dl>'
                . '<h3>Comunicações n8n</h3><table><thead><tr><th>Evento</th><th>Status</th><th>Tentativas</th><th>Último envio</th><th>Erro</th></tr></thead><tbody>'
                . '<tr><td>Confirmação</td><td>' . h($application['confirmacao_webhook_status']) . '</td><td>' . h($application['confirmacao_webhook_tentativas']) . '</td><td>' . h($application['confirmacao_webhook_ultimo_envio_em'] ?: '—') . '</td><td>' . h($application['confirmacao_webhook_ultimo_erro'] ?: '—') . '</td></tr>'
                . '<tr><td>Aprovação</td><td>' . h($application['aprovacao_webhook_status']) . '</td><td>' . h($application['aprovacao_webhook_tentativas']) . '</td><td>' . h($application['aprovacao_webhook_ultimo_envio_em'] ?: '—') . '</td><td>' . h($application['aprovacao_webhook_ultimo_erro'] ?: '—') . '</td></tr>'
                . '</tbody></table>';
            if ((string) $application['status'] === 'aguardando_analise') {
                echo '<form method="post">' . csrf_field()
                    . '<input type="hidden" name="_action" value="approve_partner">'
                    . '<input type="hidden" name="candidatura_uuid" value="' . h($uuid) . '">'
                    . '<input type="hidden" name="_return" value="/parceiros?uuid=' . h($uuid) . '">'
                    . '<button class="button button--primary">Aprovar parceiro</button></form>';
            }
            if ((string) $application['status'] === 'aprovado'
                && (string) $application['aprovacao_webhook_status'] !== 'enviado') {
                echo '<form method="post">' . csrf_field()
                    . '<input type="hidden" name="_action" value="retry_partner_approval_webhook">'
                    . '<input type="hidden" name="candidatura_uuid" value="' . h($uuid) . '">'
                    . '<input type="hidden" name="_return" value="/parceiros?uuid=' . h($uuid) . '">'
                    . '<button class="button button--secondary">Reenviar comunicação</button></form>';
            }
            echo '</section>';
        }
    }
    $applications = db('control')->query(
        'SELECT uuid,nome,email,status,email_confirmado_em,candidatura_enviada_em,
                confirmacao_webhook_status,aprovacao_webhook_status
         FROM parceiro_candidaturas ORDER BY created_at DESC,id DESC'
    );
    echo '<table><thead><tr><th>Candidato</th><th>Situação</th><th>E-mail confirmado</th><th>Comunicação</th></tr></thead><tbody>';
    foreach ($applications as $application) {
        echo '<tr><td><a href="/parceiros?uuid=' . h($application['uuid']) . '">'
            . h($application['nome'] ?: $application['email']) . '</a><br><small>' . h($application['email']) . '</small></td>'
            . '<td>' . h(admin_partner_status_label((string) $application['status'])) . '</td>'
            . '<td>' . h($application['email_confirmado_em'] ?: 'pendente') . '</td>'
            . '<td>confirmação: ' . h($application['confirmacao_webhook_status'])
            . '<br>aprovação: ' . h($application['aprovacao_webhook_status']) . '</td></tr>';
    }
    echo '</tbody></table>';
} elseif ($path === '/clientes') {
    $uuid = (string) ($_GET['uuid'] ?? '');
    if ($uuid !== '') {
        $stmt = db('control')->prepare(
            'SELECT c.*,pc.nome parceiro_nome,pc.email parceiro_email
             FROM clientes c
             LEFT JOIN parceiros partner ON partner.usuario_uuid=c.criado_por_usuario_uuid
             LEFT JOIN parceiro_candidaturas pc ON pc.id=partner.candidatura_id
             WHERE c.uuid=:uuid'
        ); $stmt->execute(['uuid'=>$uuid]); $client=$stmt->fetch();
        if (!$client) { http_response_code(404); echo '<p>Cliente não encontrado.</p>'; }
        else {
            echo '<h2>' . h($client['nome']) . '</h2><p>Slug: ' . h($client['slug']) . ' | Status: ' . h($client['status'])
                . ' | Parceiro responsável: ' . h($client['parceiro_nome'] ?: $client['parceiro_email'] ?: 'cadastro administrativo') . '</p>';
            $users=db('identity')->query('SELECT uuid,nome,email FROM usuarios ORDER BY nome,email')->fetchAll();
            echo '<h3>Associar usuário</h3><form method="post">'.csrf_field().'<input type="hidden" name="_action" value="link_client_user"><input type="hidden" name="cliente_uuid" value="'.h($uuid).'"><input type="hidden" name="_return" value="/clientes?uuid='.h($uuid).'"><select name="usuario_uuid">';
            foreach($users as $u) echo '<option value="'.h($u['uuid']).'">'.h($u['nome'].' — '.$u['email']).'</option>';
            echo '</select>';
            admin_role_select('papel', ['proprietario' => 'Proprietário', 'gestor' => 'Gestor', 'membro' => 'Membro']);
            echo '<p class="role-help">O papel descreve a atuação da pessoa dentro do cliente.</p><label><input type="checkbox" name="ativo" checked> vínculo ativo</label> <button>Salvar vínculo</button></form><ul>';
            $stmt=db('control')->prepare('SELECT cu.usuario_uuid,cu.papel,cu.ativo FROM cliente_usuarios cu WHERE cu.cliente_id=:id'); $stmt->execute(['id'=>$client['id']]);
            foreach($stmt as $link) echo '<li>'.h($link['usuario_uuid']).' — '.h($link['papel']).' — '.($link['ativo']?'ativo':'inativo').'</li>'; echo '</ul>';
            echo '<h3>Novo projeto</h3><form method="post">'.csrf_field().'<input type="hidden" name="_action" value="create_project"><input type="hidden" name="cliente_uuid" value="'.h($uuid).'"><input type="hidden" name="_return" value="/clientes?uuid='.h($uuid).'">';
            input('nome','Nome'); input('slug','Slug'); echo '<p><label>Descrição<br><textarea name="descricao"></textarea></label></p>'; input('status','Status','text','ativo'); echo '<button>Criar projeto</button></form><h3>Projetos</h3><ul>';
            $stmt=db('control')->prepare('SELECT uuid,nome,status FROM projetos WHERE cliente_id=:id ORDER BY nome'); $stmt->execute(['id'=>$client['id']]); foreach($stmt as $p) echo '<li><a href="/projetos?uuid='.h($p['uuid']).'">'.h($p['nome']).'</a> — '.h($p['status']).'</li>'; echo '</ul>';
        }
    }
    echo '<h2>Criar cliente</h2><p class="admin-intro">O cliente agrupa colaboradores e projetos. Use um slug curto, estável e sem espaços.</p><form method="post">'.csrf_field().'<input type="hidden" name="_action" value="create_client"><input type="hidden" name="_return" value="/clientes">'; input('nome','Nome'); input('slug','Slug'); input('status','Status','text','ativo'); echo '<button>Criar</button></form><h2>Clientes</h2><ul>';
    foreach(db('control')->query(
        'SELECT c.uuid,c.nome,c.status,pc.nome parceiro_nome,pc.email parceiro_email
         FROM clientes c
         LEFT JOIN parceiros partner ON partner.usuario_uuid=c.criado_por_usuario_uuid
         LEFT JOIN parceiro_candidaturas pc ON pc.id=partner.candidatura_id
         ORDER BY c.nome'
    ) as $c) echo '<li><a href="/clientes?uuid='.h($c['uuid']).'">'.h($c['nome']).'</a> — '.h($c['status'])
        .' — parceiro: '.h($c['parceiro_nome'] ?: $c['parceiro_email'] ?: 'cadastro administrativo').'</li>'; echo '</ul>';
} elseif ($path === '/planos') {
    echo '<h2>Planos de storage</h2>'
        . '<p class="admin-intro">Defina a oferta comercial e seus limites técnicos. Criar um plano não o atribui automaticamente a nenhum projeto.</p>'
        . '<form method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="create_storage_plan">'
        . '<input type="hidden" name="_return" value="/planos">';
    input('nome', 'Nome do plano');
    input('codigo', 'Código estável');
    echo '<p><label>Descrição<br><textarea name="descricao" maxlength="4000"></textarea></label></p>';
    input('armazenamento_mib', 'Armazenamento por projeto (MiB)', 'number');
    input('arquivos_max', 'Quantidade máxima de arquivos', 'number');
    input('pastas_max', 'Quantidade máxima de pastas', 'number');
    input('arquivo_mib', 'Tamanho máximo por arquivo (MiB)', 'number');
    input('preco', 'Preço por projeto/mês', 'text');
    input('moeda', 'Moeda ISO');
    echo '<button>Criar plano</button></form>'
        . '<h3>Planos disponíveis</h3>';
    $plans = storage_plan_catalog_items();
    if ($plans === []) {
        echo '<p>Nenhum plano de storage cadastrado.</p>';
    } else {
        echo '<table><thead><tr><th>Plano</th><th>Preço vigente</th><th>Storage</th><th>Arquivos</th><th>Pastas</th><th>Arquivo</th></tr></thead><tbody>';
        foreach ($plans as $plan) {
            $price = $plan['valor'] !== null
                ? number_format((float) $plan['valor'], 2, ',', '.') . ' ' . $plan['moeda']
                : 'sem preço vigente';
            echo '<tr><td><strong>' . h($plan['nome']) . '</strong><br><code>' . h($plan['codigo']) . '</code></td>'
                . '<td>' . h($price) . '</td>'
                . '<td>' . h(storage_bytes_label((int) $plan['armazenamento_bytes_max'])) . '</td>'
                . '<td>' . h($plan['arquivos_max']) . '</td>'
                . '<td>' . h($plan['pastas_max']) . '</td>'
                . '<td>' . h(storage_bytes_label((int) $plan['arquivo_bytes_max'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
} elseif ($path === '/projetos') {
    $uuid=(string)($_GET['uuid']??'');
    if($uuid===''){ echo '<h2>Projetos</h2><ul>'; foreach(db('control')->query(
        'SELECT p.uuid,p.nome,p.status,c.nome cliente,pc.nome parceiro_nome,pc.email parceiro_email
         FROM projetos p
         JOIN clientes c ON c.id=p.cliente_id
         LEFT JOIN parceiros partner ON partner.usuario_uuid=c.criado_por_usuario_uuid
         LEFT JOIN parceiro_candidaturas pc ON pc.id=partner.candidatura_id
         ORDER BY p.nome'
    ) as $p) echo '<li><a href="/projetos?uuid='.h($p['uuid']).'">'.h($p['nome']).'</a> — '.h($p['cliente']).' — '.h($p['status'])
        .' — parceiro: '.h($p['parceiro_nome'] ?: $p['parceiro_email'] ?: 'cadastro administrativo').'</li>'; echo '</ul>'; }
    else {
        $project=admin_project($uuid); echo '<h2>'.h($project['nome']).'</h2><p>Cliente: '.h($project['cliente_nome']).' | Slug: '.h($project['slug']).' | Status: '.h($project['status'])
            .' | Parceiro responsável: '.h($project['parceiro_nome'] ?: $project['parceiro_email'] ?: 'cadastro administrativo').'</p><p>'.nl2br(h($project['descricao'])).'</p>';
        $storage = project_storage_summary((int) $project['id']);
        $storagePercent = (int) $storage['armazenamento_bytes_max'] > 0
            ? min(100, round(100 * (int) $storage['armazenamento_bytes_usados'] / (int) $storage['armazenamento_bytes_max'], 2))
            : 0;
        echo '<h3>Plano e uso de storage</h3><dl>'
            . '<dt>Plano atual</dt><dd>' . h($storage['catalog_item_nome'] ?: 'Configuração padrão ou manual') . '</dd>'
            . '<dt>Armazenamento</dt><dd>' . h(storage_bytes_label((int) $storage['armazenamento_bytes_usados']))
            . ' de ' . h(storage_bytes_label((int) $storage['armazenamento_bytes_max']))
            . ' (' . h($storagePercent) . '%)</dd>'
            . '<dt>Arquivos</dt><dd>' . h($storage['arquivos_usados']) . ' de ' . h($storage['arquivos_max']) . '</dd>'
            . '<dt>Pastas</dt><dd>' . h($storage['pastas_usadas']) . ' de ' . h($storage['pastas_max']) . '</dd>'
            . '<dt>Tamanho por arquivo</dt><dd>' . h(storage_bytes_label((int) $storage['arquivo_bytes_max'])) . '</dd>'
            . '<dt>Última reconciliação</dt><dd>' . h($storage['reconciliado_em'] ?: 'ainda não executada') . '</dd>'
            . '</dl>';
        $storagePlans = storage_plan_catalog_items();
        if ($storagePlans === []) {
            echo '<p>Nenhum item ativo do Catálogo possui limites de storage configurados.</p>';
        } else {
            echo '<form method="post">' . csrf_field()
                . '<input type="hidden" name="_action" value="assign_storage_plan">'
                . '<input type="hidden" name="projeto_uuid" value="' . h($uuid) . '">'
                . '<input type="hidden" name="_return" value="/projetos?uuid=' . h($uuid) . '">'
                . '<p><label>Plano de storage<br><select name="catalog_item_uuid" required>';
            foreach ($storagePlans as $storagePlan) {
                $price = $storagePlan['valor'] !== null
                    ? ' — ' . number_format((float) $storagePlan['valor'], 2, ',', '.')
                        . ' ' . h($storagePlan['moeda'])
                    : ' — sem preço vigente';
                echo '<option value="' . h($storagePlan['uuid']) . '">'
                    . h($storagePlan['nome']) . ' — '
                    . h(storage_bytes_label((int) $storagePlan['armazenamento_bytes_max']))
                    . $price . '</option>';
            }
            echo '</select></label></p>'
                . '<p class="role-help">A atribuição cria um snapshot dos limites. Reduzir o plano não apaga arquivos existentes.</p>'
                . '<button>Atribuir plano e aplicar limites</button></form>';
        }
        $users = db('control')->prepare(
            "SELECT u.uuid,u.nome,u.email
             FROM threeebs_identity.usuarios u
             JOIN cliente_usuarios cu ON cu.usuario_uuid=u.uuid
             WHERE cu.cliente_id=:client AND cu.ativo=1 AND u.status='ativo'
             ORDER BY u.nome,u.email"
        );
        $users->execute(['client' => $project['cliente_id']]);
        echo '<h3>Usuários do projeto</h3><form method="post">'.csrf_field().'<input type="hidden" name="_action" value="link_project_user"><input type="hidden" name="projeto_uuid" value="'.h($uuid).'"><input type="hidden" name="_return" value="/projetos?uuid='.h($uuid).'"><select name="usuario_uuid">'; foreach($users as $u) echo '<option value="'.h($u['uuid']).'">'.h($u['nome'].' — '.$u['email']).'</option>';
        echo '</select>';
        admin_role_select('papel', [
            'gestor' => 'Gestor',
            'desenvolvedor' => 'Desenvolvedor',
            'colaborador' => 'Colaborador',
            'membro' => 'Membro',
            'visualizador' => 'Visualizador',
        ]);
        echo '<p class="role-help">O usuário precisa ser membro ativo do cliente antes de receber acesso ao projeto.</p><label><input type="checkbox" name="ativo" checked> vínculo ativo</label> <button>Salvar vínculo</button></form>';
        $stmt=db('control')->prepare('SELECT usuario_uuid,papel,ativo FROM projeto_usuarios WHERE projeto_id=:id');$stmt->execute(['id'=>$project['id']]);echo '<ul>';foreach($stmt as $link)echo '<li>'.h($link['usuario_uuid']).' — '.h($link['papel']).' — '.($link['ativo']?'ativo':'inativo').'</li>';echo '</ul>';
        echo '<h3>Ambientes</h3><form method="post">'.csrf_field().'<input type="hidden" name="_action" value="provision_environments"><input type="hidden" name="projeto_uuid" value="'.h($uuid).'"><input type="hidden" name="_return" value="/projetos?uuid='.h($uuid).'"><button>Criar ambientes padrão</button></form>';
        $environments = project_environment_operation_rows((int) $project['id']);
        if (!$environments) {
            echo '<p>Crie os ambientes antes de configurar PHP ou banco de dados.</p>';
        } else {
            echo '<table><thead><tr><th>Ambiente</th><th>Banco de dados</th><th>PHP</th><th>Ações</th></tr></thead><tbody>';
            foreach ($environments as $environment) {
                $databaseActive = (int) $environment['database_ativo'] === 1;
                $phpActive = (string) $environment['runtime_tipo'] === 'runtime.php'
                    && (int) $environment['execucao_habilitada'] === 1
                    && (string) $environment['runtime_status'] === 'ativo';
                $databaseBusy = in_array((string) $environment['database_operacao_status'], ['pendente', 'executando'], true);
                $phpBusy = in_array((string) $environment['php_operacao_status'], ['pendente', 'executando'], true);
                echo '<tr><td><strong>' . h($environment['nome']) . '</strong><br><code>'
                    . h($environment['diretorio']) . '</code><br>' . h($environment['status']) . '</td>'
                    . '<td>' . ($databaseActive ? 'ativo' : environment_operation_status_label($environment['database_operacao_status']))
                    . '</td><td>' . ($phpActive ? 'ativo' : environment_operation_status_label($environment['php_operacao_status']))
                    . '</td><td>';
                foreach ([
                    'queue_database_provision' => ['Criar banco de dados', $databaseActive || $databaseBusy],
                    'queue_php_activation' => ['Ativar PHP', $phpActive || $phpBusy],
                ] as $operationAction => [$label, $disabled]) {
                    echo '<form method="post">'
                        . csrf_field()
                        . '<input type="hidden" name="_action" value="' . h($operationAction) . '">'
                        . '<input type="hidden" name="projeto_uuid" value="' . h($uuid) . '">'
                        . '<input type="hidden" name="ambiente_uuid" value="' . h($environment['uuid']) . '">'
                        . '<input type="hidden" name="_return" value="/projetos?uuid=' . h($uuid) . '">';
                    if ((string) $environment['tipo'] === 'production') {
                        echo '<label><input type="checkbox" name="confirm_production" value="1" required> '
                            . 'confirmar Produção</label><br>';
                    }
                    echo '<button' . ($disabled ? ' disabled' : '') . '>' . h($label) . '</button></form>';
                }
                if (!empty($environment['ultima_operacao_erro'])) {
                    echo '<br><small>Última falha: <code>'
                        . h($environment['ultima_operacao_erro']) . '</code></small>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        $navigation=project_environment_navigation((int)$project['id']);
        $production=$navigation['production']??null;
        $sandbox=$navigation['sandbox']??null;
        echo '<h3>Acessos do projeto</h3><ul><li><strong>Produção:</strong> ';
        echo is_array($production)&&is_string($production['url'])
            ? '<a href="'.h($production['url']).'" target="_blank" rel="noopener">Abrir produção</a>'
            : 'sem rota ativa';
        echo '</li><li><strong>Sandbox:</strong> ';
        echo is_array($sandbox)&&is_string($sandbox['url'])
            ? '<a href="'.h($sandbox['url']).'" target="_blank" rel="noopener">Abrir preview Sandbox</a>'
            : 'sem rota ativa';
        echo '</li><li><strong>Editor Threeebs:</strong> ';
        echo is_array($sandbox)&&(string)$sandbox['status']==='ativo'
            ? '<a href="'.h(editor_project_url($uuid)).'">Abrir Monaco edita</a>'
            : 'Sandbox ativo indisponível';
        echo '</li></ul>';
        echo '<h3>Rotas web</h3>'
            . '<p>Um ambiente pode ter vários hostnames. Cadastre somente o domínio, sem protocolo, caminho ou barra final.</p>'
            . '<p>Sugestões: Produção <code>' . h($project['slug'] . '.3eb.site')
            . '</code> | Sandbox <code>' . h('sandbox-' . $project['slug'] . '.3eb.site') . '</code></p>';
        if (!$environments) {
            echo '<p>Crie os ambientes primeiro.</p>';
        } else {
            echo '<form method="post">' . csrf_field()
                . '<input type="hidden" name="_action" value="create_route">'
                . '<input type="hidden" name="projeto_uuid" value="' . h($uuid) . '">'
                . '<input type="hidden" name="_return" value="/projetos?uuid=' . h($uuid) . '">'
                . '<p><label>Ambiente<br><select name="ambiente_uuid">';
            foreach ($environments as $environment) {
                echo '<option value="' . h($environment['uuid']) . '">' . h($environment['nome']) . '</option>';
            }
            echo '</select></label></p>';
            input('hostname', 'Hostname', 'text', $project['slug'] . '.3eb.site');
            echo '<p><label>Tipo da rota<br><select name="tipo" required>'
                . '<option value="subdominio">Subdomínio Threeebs</option>'
                . '<option value="dominio_personalizado">Domínio personalizado</option>'
                . '</select></label></p>'
                . '<label><input type="checkbox" name="ativo" checked> ativa</label> '
                . '<button>Criar rota</button></form>';
        }
        $stmt = db('control')->prepare(
            'SELECT r.hostname,r.tipo,r.ativo,a.nome ambiente
             FROM rotas_web r
             JOIN ambientes a ON a.id=r.ambiente_id
             WHERE a.projeto_id=:id
             ORDER BY a.nome,r.tipo,r.hostname'
        );
        $stmt->execute(['id' => $project['id']]);
        echo '<table><thead><tr><th>Hostname</th><th>Ambiente</th><th>Tipo</th><th>Status</th></tr></thead><tbody>';
        foreach ($stmt as $route) {
            echo '<tr><td><code>' . h($route['hostname']) . '</code></td>'
                . '<td>' . h($route['ambiente']) . '</td>'
                . '<td>' . h(route_type_label((string) $route['tipo'])) . '</td>'
                . '<td>' . ($route['ativo'] ? 'ativa' : 'inativa') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Work</h3><form method="post">'.csrf_field().'<input type="hidden" name="_action" value="create_default_board"><input type="hidden" name="projeto_uuid" value="'.h($uuid).'"><input type="hidden" name="_return" value="/projetos?uuid='.h($uuid).'"><button>Criar quadro padrão</button></form>';
        $stmt=db('work')->prepare('SELECT * FROM quadros WHERE projeto_uuid=:uuid ORDER BY id');$stmt->execute(['uuid'=>$uuid]);$boards=$stmt->fetchAll();
        echo '<form method="post">'.csrf_field().'<input type="hidden" name="_action" value="create_board"><input type="hidden" name="projeto_uuid" value="'.h($uuid).'"><input type="hidden" name="_return" value="/projetos?uuid='.h($uuid).'">';input('nome','Novo quadro');echo '<button>Criar quadro</button></form>';
        foreach($boards as $board){echo '<h4>'.h($board['nome']).'</h4>'; $stmt=db('work')->prepare('SELECT * FROM colunas WHERE quadro_id=:id ORDER BY ordem');$stmt->execute(['id'=>$board['id']]);$columns=$stmt->fetchAll();foreach($columns as $column){echo '<h5>'.h($column['nome']).'</h5><ul>'; $task=db('work')->prepare('SELECT t.*,GROUP_CONCAT(tr.usuario_uuid) responsaveis FROM tarefas t LEFT JOIN tarefa_responsaveis tr ON tr.tarefa_id=t.id WHERE t.coluna_id=:id GROUP BY t.id ORDER BY t.ordem,t.id');$task->execute(['id'=>$column['id']]);foreach($task as $t)echo '<li>'.h($t['titulo']).($t['responsaveis']?' — '.h($t['responsaveis']):'').'</li>';echo '</ul>';}
            echo '<form method="post">'.csrf_field().'<input type="hidden" name="_action" value="create_column"><input type="hidden" name="projeto_uuid" value="'.h($uuid).'"><input type="hidden" name="quadro_id" value="'.h($board['id']).'"><input name="nome" placeholder="Nova coluna" required> <input name="ordem" type="number" min="1" value="'.h(count($columns)+1).'" required> <button>Criar coluna</button></form>';}
        $allColumns=[];foreach($boards as $board){$stmt=db('work')->prepare('SELECT id,nome FROM colunas WHERE quadro_id=:id ORDER BY ordem');$stmt->execute(['id'=>$board['id']]);$allColumns=array_merge($allColumns,$stmt->fetchAll());}
        if($allColumns){echo '<h4>Nova tarefa</h4><form method="post">'.csrf_field().'<input type="hidden" name="_action" value="create_task"><input type="hidden" name="projeto_uuid" value="'.h($uuid).'"><input type="hidden" name="_return" value="/projetos?uuid='.h($uuid).'">';input('titulo','Título');echo '<p><textarea name="descricao" placeholder="Descrição"></textarea></p><select name="coluna_id">';foreach($allColumns as $c)echo '<option value="'.h($c['id']).'">'.h($c['nome']).'</option>';echo '</select> <select name="responsavel_uuid"><option value="">Sem responsável</option>';foreach($users as $u)echo '<option value="'.h($u['uuid']).'">'.h($u['nome'].' — '.$u['email']).'</option>';echo '</select> <input name="prioridade" value="normal" required> <button>Criar tarefa</button></form>';}
    }
} elseif($path==='/tarefas'){echo '<h2>Tarefas por projeto</h2><ul>';foreach(db('control')->query('SELECT uuid,nome FROM projetos ORDER BY nome') as $p)echo '<li><a href="/projetos?uuid='.h($p['uuid']).'">'.h($p['nome']).'</a></li>';echo '</ul>';}
elseif($path==='/servidores'){echo '<h2>Servidores</h2><table><tr><th>Nome</th><th>Driver</th><th>Hostname</th><th>Status</th></tr>';foreach(db('control')->query('SELECT nome,driver,hostname,status FROM servidores ORDER BY nome') as $s)echo '<tr><td>'.h($s['nome']).'</td><td>'.h($s['driver']).'</td><td>'.h($s['hostname']).'</td><td>'.h($s['status']).'</td></tr>';echo '</table>';}
else{http_response_code(404);echo '<p>Página não encontrada.</p>';}

admin_page_end();
