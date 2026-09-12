<?php

declare(strict_types=1);

require '/var/www/shared/bootstrap.php';
require '/var/www/shared/ui.php';
require '/var/www/app/views/public-pages.php';

$path = request_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function portal_navigation(): array
{
    return [
        ['href' => '/', 'label' => 'Visão geral', 'icon' => 'home'],
        ['href' => '/projetos', 'label' => 'Projetos', 'icon' => 'projects'],
        ['href' => '/tarefas', 'label' => 'Tarefas', 'icon' => 'tasks'],
        ['href' => '/colaboradores', 'label' => 'Colaboradores', 'icon' => 'users'],
        ['href' => '/parceiro', 'label' => 'Parceiro', 'icon' => 'clients'],
    ];
}

function portal_collaborators(array $user): array
{
    if (is_admin($user)) {
        return db('control')->query(
            "SELECT DISTINCT u.uuid,u.nome,u.email,c.nome cliente_nome,cu.papel
             FROM cliente_usuarios cu
             JOIN clientes c ON c.id=cu.cliente_id AND c.status='ativo'
             JOIN threeebs_identity.usuarios u ON u.uuid=cu.usuario_uuid AND u.status='ativo'
             WHERE cu.ativo=1
             ORDER BY u.nome,u.email,c.nome"
        )->fetchAll();
    }

    $stmt = db('control')->prepare(
        "SELECT DISTINCT u.uuid,u.nome,u.email,c.nome cliente_nome,collaborator.papel
         FROM clientes c
         JOIN cliente_usuarios member
           ON member.cliente_id=c.id AND member.usuario_uuid=:user AND member.ativo=1
         JOIN cliente_usuarios collaborator
           ON collaborator.cliente_id=c.id AND collaborator.ativo=1
         JOIN threeebs_identity.usuarios u
           ON u.uuid=collaborator.usuario_uuid AND u.status='ativo'
         WHERE c.status='ativo' AND collaborator.usuario_uuid<>:other_user
         ORDER BY u.nome,u.email,c.nome"
    );
    $stmt->execute(['user' => $user['uuid'], 'other_user' => $user['uuid']]);
    return $stmt->fetchAll();
}

function portal_projects_by_uuid(array $projects): array
{
    $result = [];
    foreach ($projects as $project) {
        $result[(string) $project['uuid']] = (string) $project['nome'];
    }
    return $result;
}

function portal_tasks(array $projects, array $user): array
{
    $projectsByUuid = portal_projects_by_uuid($projects);
    if ($projectsByUuid === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($projectsByUuid), '?'));
    $stmt = db('work')->prepare(
        "SELECT t.titulo,t.descricao,t.status,t.prioridade,q.projeto_uuid,c.nome coluna,
                EXISTS (SELECT 1 FROM tarefa_responsaveis tr
                        WHERE tr.tarefa_id=t.id AND tr.usuario_uuid=?) responsavel
         FROM tarefas t
         JOIN colunas c ON c.id=t.coluna_id
         JOIN quadros q ON q.id=c.quadro_id
         WHERE q.projeto_uuid IN ({$placeholders})
         ORDER BY t.created_at DESC,t.id DESC"
    );
    $stmt->execute(array_merge([(string) $user['uuid']], array_keys($projectsByUuid)));
    return $stmt->fetchAll();
}

function portal_app_start(string $title, array $user): void
{
    global $path;
    ui_app_start($title, 'Portal', portal_navigation(), $path, $user, true);
    show_flash();
}

function portal_projects_empty_state(): string
{
    $helpUrl = rtrim((string) (getenv('PORTAL_URL') ?: '/'), '/') . '/precos#interesse';
    return '<section class="project-empty-state"><div class="project-empty-mark" aria-hidden="true">:3</div>'
        . '<p class="eyebrow"><span></span>Seu espaço Threeebs</p><h2>Nenhum projeto por aqui ainda.</h2>'
        . '<p>Quando um projeto for associado à sua conta, os ambientes e acessos aparecerão nesta página.</p>'
        . '<div class="project-empty-actions"><a class="button button--secondary" href="/">Ir para a página inicial</a>'
        . '<a class="button button--primary" href="' . h($helpUrl) . '">Preciso de ajuda com meu website</a></div></section>';
}

function portal_partner_application_from_session(): ?array
{
    $uuid = (string) ($_SESSION['partner_application_uuid'] ?? '');
    $email = (string) ($_SESSION['partner_application_email'] ?? '');
    if ($uuid === '' || $email === '') {
        return null;
    }
    $application = partner_application_by_uuid($uuid);
    if (!$application || !hash_equals((string) $application['email'], normalize_email($email))
        || $application['email_confirmado_em'] === null) {
        unset($_SESSION['partner_application_uuid'], $_SESSION['partner_application_email']);
        return null;
    }
    return $application;
}

function portal_optional_profile_url(string $value, string $label): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (strlen($value) > 500 || !filter_var($value, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new ValidationException($label . ' deve ser uma URL completa e válida.');
    }
    return $value;
}

function portal_partner_status_label(string $status): string
{
    return match ($status) {
        'email_pendente' => 'Confirmação de e-mail pendente',
        'email_confirmado' => 'E-mail confirmado — complete seu perfil',
        'aguardando_analise' => 'Aguardando análise',
        'aprovado' => 'Parceiro aprovado',
        'recusado' => 'Candidatura não aprovada',
        default => $status,
    };
}

if ($method === 'POST') {
    require_csrf();
    $action = (string) ($_POST['_action'] ?? '');
    try {
        if ($action === 'request_password_reset') {
            $result = request_password_reset((string) ($_POST['email'] ?? ''));
            $_SESSION['password_reset_requested_at'] = time();
            if (is_array($result['webhook'] ?? null) && !($result['webhook']['success'] ?? false)) {
                error_log('Threeebs password reset webhook delivery failed.');
            }
            flash('Se existir uma conta ativa para este e-mail, enviaremos um link. Aguarde 40 segundos antes de pedir outro.');
            redirect('/esqueci-senha');
        }

        if ($action === 'complete_password_token') {
            $completed = complete_password_token(
                (string) ($_POST['token'] ?? ''),
                (string) ($_POST['senha'] ?? ''),
                (string) ($_POST['confirmacao_senha'] ?? '')
            );
            flash((string) $completed['tipo'] === 'first_access'
                ? 'Primeiro acesso concluído. Entre com a senha que você acabou de criar.'
                : 'Senha redefinida. Entre novamente com sua nova senha.');
            redirect('/login');
        }

        if ($action === 'request_partner_confirmation') {
            $honeypot = trim((string) ($_POST['website'] ?? ''));
            if ($honeypot === '') {
                $lastRequest = (int) ($_SESSION['partner_confirmation_requested_at'] ?? 0);
                if ($lastRequest === 0 || time() - $lastRequest >= 60) {
                    $result = request_partner_email_confirmation((string) ($_POST['email'] ?? ''));
                    $_SESSION['partner_confirmation_requested_at'] = time();
                    if (is_array($result['webhook'] ?? null) && !($result['webhook']['success'] ?? false)) {
                        error_log(
                            'Threeebs partner confirmation webhook pending for application '
                            . (string) (($result['application']['uuid'] ?? 'unknown'))
                        );
                    }
                }
            }
            flash('Se o endereço estiver apto, enviaremos as instruções de confirmação.');
            redirect('/parceiro/candidatar');
        }

        if ($action === 'submit_partner_application') {
            $application = portal_partner_application_from_session();
            if (!$application || (string) $application['status'] !== 'email_confirmado') {
                throw new ValidationException('Confirme novamente seu e-mail para preencher a candidatura.');
            }
            $name = trim((string) ($_POST['nome'] ?? ''));
            $technologies = trim((string) ($_POST['tecnologias'] ?? ''));
            $experience = trim((string) ($_POST['experiencia'] ?? ''));
            if ($name === '' || strlen($name) > 150) {
                throw new ValidationException('Informe seu nome completo.');
            }
            if (strlen($technologies) > 4000 || strlen($experience) > 4000) {
                throw new ValidationException('Os textos de perfil devem ter no máximo 4.000 caracteres.');
            }
            $github = portal_optional_profile_url((string) ($_POST['github_url'] ?? ''), 'GitHub');
            $linkedin = portal_optional_profile_url((string) ($_POST['linkedin_url'] ?? ''), 'LinkedIn');
            $portfolio = portal_optional_profile_url((string) ($_POST['portfolio_url'] ?? ''), 'Portfólio');
            $password = (string) ($_POST['senha'] ?? '');
            $identity = db('identity');
            $identity->beginTransaction();
            $newUser = false;
            try {
                $stmt = $identity->prepare(
                    'SELECT id,uuid,status FROM usuarios WHERE email=:email LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['email' => $application['email']]);
                $candidateUser = $stmt->fetch();
                if (is_array($candidateUser) && (string) $candidateUser['status'] !== 'ativo') {
                    throw new ValidationException(
                        'Existe uma conta indisponível para este e-mail. Entre em contato com a equipe Threeebs.'
                    );
                }
                $sessionUser = auth_user();
                if (is_array($candidateUser) && $sessionUser
                    && (string) $sessionUser['uuid'] !== (string) $candidateUser['uuid']) {
                    throw new ValidationException(
                        'Saia da conta atual antes de concluir uma candidatura para outro e-mail.'
                    );
                }
                if (!is_array($candidateUser)) {
                    require_strong_password($password, (string) ($_POST['confirmacao_senha'] ?? ''));
                    $userUuid = uuid_v4();
                    $stmt = $identity->prepare(
                        "INSERT INTO usuarios
                            (uuid,nome,email,status,email_verificado_em)
                         VALUES (:uuid,:name,:email,'ativo',UTC_TIMESTAMP(6))"
                    );
                    $stmt->execute([
                        'uuid' => $userUuid,
                        'name' => $name,
                        'email' => $application['email'],
                    ]);
                    $userId = (int) $identity->lastInsertId();
                    $stmt = $identity->prepare(
                        "INSERT INTO credenciais
                            (uuid,usuario_id,tipo,identificador,segredo_hash,ativa)
                         VALUES (:uuid,:user,'senha','',:hash,1)"
                    );
                    $stmt->execute([
                        'uuid' => uuid_v4(),
                        'user' => $userId,
                        'hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    $newUser = true;
                } else {
                    $userUuid = (string) $candidateUser['uuid'];
                    $userId = (int) $candidateUser['id'];
                    $stmt = $identity->prepare(
                        'UPDATE usuarios
                         SET email_verificado_em=COALESCE(email_verificado_em,UTC_TIMESTAMP(6))
                         WHERE id=:id'
                    );
                    $stmt->execute(['id' => $userId]);
                }
                $stmt = $identity->prepare(
                    "UPDATE threeebs_control.parceiro_candidaturas
                     SET usuario_uuid=:user,nome=:name,github_url=:github,linkedin_url=:linkedin,
                         portfolio_url=:portfolio,tecnologias=:technologies,experiencia=:experience,
                         status='aguardando_analise',candidatura_enviada_em=UTC_TIMESTAMP(6)
                     WHERE uuid=:uuid AND status='email_confirmado'
                       AND email_confirmado_em IS NOT NULL"
                );
                $stmt->execute([
                    'user' => $userUuid,
                    'name' => $name,
                    'github' => $github,
                    'linkedin' => $linkedin,
                    'portfolio' => $portfolio,
                    'technologies' => $technologies === '' ? null : $technologies,
                    'experience' => $experience === '' ? null : $experience,
                    'uuid' => $application['uuid'],
                ]);
                if ($stmt->rowCount() !== 1) {
                    throw new ValidationException('A candidatura já foi enviada ou não está disponível.');
                }
                $identity->commit();
            } catch (Throwable $error) {
                if ($identity->inTransaction()) {
                    $identity->rollBack();
                }
                throw $error;
            }
            audit_event('parceiro.candidatura_enviada', 'parceiro_candidatura', (string) $application['uuid']);
            unset($_SESSION['partner_application_uuid'], $_SESSION['partner_application_email']);
            if ($newUser) {
                login_user((string) $application['email'], $password);
            }
            flash('Candidatura enviada. Ela está aguardando análise administrativa.');
            redirect(auth_user() ? '/parceiro' : '/login');
        }

        if ($action === 'register_interest') {
            $name = trim((string) ($_POST['nome'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $company = trim((string) ($_POST['empresa'] ?? ''));
            $type = trim((string) ($_POST['tipo_projeto'] ?? ''));
            $stage = trim((string) ($_POST['momento'] ?? ''));
            $message = trim((string) ($_POST['mensagem'] ?? ''));
            $honeypot = trim((string) ($_POST['website'] ?? ''));
            $allowedTypes = ['site', 'sistema', 'loja', 'outro'];
            $allowedStages = ['ideia', 'planejamento', 'em_andamento', 'evolucao'];

            if ($honeypot !== '') {
                flash('Recebemos seu interesse. Em breve entraremos em contato.');
                redirect('/precos#interesse');
            }
            if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || strlen($email) > 254 || strlen($company) > 180
                || !in_array($type, $allowedTypes, true) || !in_array($stage, $allowedStages, true)
                || $message === '' || strlen($message) > 4000 || !isset($_POST['consentimento'])) {
                throw new ValidationException('Confira os campos obrigatórios e tente novamente.');
            }
            $lastSubmission = (int) ($_SESSION['interest_submitted_at'] ?? 0);
            if ($lastSubmission > 0 && time() - $lastSubmission < 60) {
                throw new ValidationException('Aguarde um minuto antes de enviar novamente.');
            }

            $ip = client_ip();
            $ipHash = $ip === '' ? null : hash('sha256', $ip . '|' . (getenv('BASE_DOMAIN') ?: 'threeebs'));
            $stmt = db('control')->prepare(
                "INSERT INTO interessados
                    (uuid,nome,email,empresa,tipo_projeto,momento,mensagem,consentimento,origem,status,ip_hash)
                 VALUES (:uuid,:name,:email,:company,:type,:stage,:message,1,'portal','novo',:ip_hash)"
            );
            $stmt->execute([
                'uuid' => uuid_v4(), 'name' => $name, 'email' => $email,
                'company' => $company === '' ? null : $company, 'type' => $type,
                'stage' => $stage, 'message' => $message, 'ip_hash' => $ipHash,
            ]);
            $_SESSION['interest_submitted_at'] = time();
            flash('Recebemos seu interesse. Em breve entraremos em contato.');
            redirect('/precos#interesse');
        }

        if ($action === 'login') {
            if (!login_user((string) ($_POST['email'] ?? ''), (string) ($_POST['senha'] ?? ''))) {
                throw new ValidationException('E-mail ou senha inválidos.');
            }
            redirect('/');
        }
        if ($action === 'logout') {
            logout_user();
            redirect('/');
        }

        if ($action === 'partner_create_client') {
            $access = require_partner();
            $client = create_partner_client(
                $access['user'],
                (string) ($_POST['nome'] ?? ''),
                (string) ($_POST['slug'] ?? '')
            );
            flash('Cliente criado e associado ao seu perfil.');
            redirect('/parceiro#cliente-' . rawurlencode((string) $client['uuid']));
        }

        if ($action === 'partner_create_project') {
            $access = require_partner();
            $project = create_partner_project(
                $access['user'],
                (string) ($_POST['cliente_uuid'] ?? ''),
                (string) ($_POST['nome'] ?? ''),
                (string) ($_POST['slug'] ?? ''),
                (string) ($_POST['descricao'] ?? '')
            );
            flash('Projeto criado com Sandbox, Produção e endereços Threeebs ativos.');
            redirect('/projeto?uuid=' . rawurlencode((string) $project['uuid']));
        }
    } catch (Throwable $error) {
        error_log($error->getMessage());
        flash(public_error_message($error));
        $returnPath = match ($action) {
            'register_interest' => '/precos#interesse',
            'request_password_reset' => '/esqueci-senha',
            'complete_password_token' => ((string) ($_POST['flow'] ?? '') === 'first_access'
                ? '/primeiro-acesso?token=' : '/redefinir-senha?token=')
                . rawurlencode((string) ($_POST['token'] ?? '')),
            'request_partner_confirmation' => '/parceiro/candidatar',
            'submit_partner_application' => '/parceiro/candidatura',
            'partner_create_client', 'partner_create_project' => '/parceiro',
            default => '/login',
        };
        redirect($returnPath);
    }
}

if ($path === '/esqueci-senha' && !auth_user()) {
    $cooldown = env_int('PASSWORD_RESET_COOLDOWN_SECONDS', 40, 40, 3600);
    $lastResetRequest = (int) ($_SESSION['password_reset_requested_at'] ?? 0);
    $remainingCooldown = max(0, $cooldown - max(0, time() - $lastResetRequest));
    ui_public_start('Recuperar senha', 'Portal', 'auth-public');
    echo '<section class="auth-shell"><div class="auth-copy"><p class="eyebrow"><span></span>Segurança da conta</p>'
        . '<h1>Recupere seu acesso.</h1><p>Informe seu e-mail para receber um link de uso único.</p>'
        . '<a class="text-link" href="/login">Voltar para o login</a></div>'
        . '<div class="auth-card"><h2>Redefinir senha</h2>';
    show_flash();
    echo '<form class="experience-form" method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="request_password_reset">'
        . '<label class="field">E-mail<input type="email" name="email" maxlength="190" autocomplete="email" inputmode="email" required></label>'
        . '<button class="button button--primary button--wide" data-reset-cooldown="' . h($remainingCooldown) . '"'
        . ($remainingCooldown > 0 ? ' disabled' : '') . '>'
        . ($remainingCooldown > 0 ? 'Aguarde ' . h($remainingCooldown) . 's' : 'Enviar link de redefinição') . '</button>'
        . '<p class="form-note">Por segurança, um novo envio para o mesmo e-mail só é permitido após 40 segundos.</p>'
        . '</form></div></section>';
    ui_public_end();
    exit;
}

if (in_array($path, ['/redefinir-senha', '/primeiro-acesso'], true)) {
    $token = (string) ($_GET['token'] ?? '');
    $expectedType = $path === '/primeiro-acesso' ? 'first_access' : 'password_reset';
    $title = $expectedType === 'first_access' ? 'Primeiro acesso' : 'Redefinir senha';
    ui_public_start($title, 'Portal', 'auth-public');
    echo '<section class="auth-shell"><div class="auth-copy"><p class="eyebrow"><span></span>Segurança da conta</p>'
        . '<h1>' . ($expectedType === 'first_access' ? 'Crie seu acesso.' : 'Escolha uma nova senha.') . '</h1>'
        . '<p>A senha deve cumprir todos os requisitos de segurança indicados no formulário.</p>'
        . '<a class="text-link" href="/login">Voltar para o login</a></div><div class="auth-card"><h2>' . h($title) . '</h2>';
    show_flash();
    try {
        $context = password_token_context($token, [$expectedType]);
        echo '<p>Olá, ' . h($context['nome'] ?: $context['email']) . '. Digite e confirme sua nova senha.</p>'
            . '<form class="experience-form" method="post">' . csrf_field()
            . '<input type="hidden" name="_action" value="complete_password_token">'
            . '<input type="hidden" name="flow" value="' . h($expectedType) . '">'
            . '<input type="hidden" name="token" value="' . h($token) . '">'
            . ui_new_password_fields()
            . '<button class="button button--primary button--wide">Salvar nova senha</button></form>';
    } catch (Throwable $error) {
        echo '<p class="form-note">' . h(public_error_message($error)) . '</p>'
            . '<a class="button button--secondary button--wide" href="/esqueci-senha">Solicitar outro link</a>';
    }
    echo '</div></section>';
    ui_public_end();
    exit;
}

if ($path === '/parceiro/confirmar') {
    try {
        $application = confirm_partner_email_token((string) ($_GET['token'] ?? ''));
        $_SESSION['partner_application_uuid'] = $application['uuid'];
        $_SESSION['partner_application_email'] = $application['email'];
        flash('E-mail confirmado. Agora complete seu perfil de parceiro.');
        redirect('/parceiro/candidatura');
    } catch (Throwable $error) {
        error_log($error->getMessage());
        flash(public_error_message($error));
        redirect('/parceiro/candidatar');
    }
}

if ($path === '/parceiro/candidatar') {
    ui_public_start('Ser parceiro', 'Portal', 'partner-public');
    show_flash();
    echo '<section class="auth-shell partner-entry"><div class="auth-copy">'
        . '<p class="eyebrow"><span></span>Rede Threeebs</p>'
        . '<h1>Crie projetos com a Threeebs.</h1>'
        . '<p>Confirme seu e-mail, apresente seu perfil e acompanhe a análise da candidatura.</p>'
        . '<a class="text-link" href="/">Voltar para o início</a></div>'
        . '<div class="auth-card"><h2>Ser parceiro</h2>'
        . '<p>Enviaremos um link de uso único para confirmar seu endereço.</p>'
        . '<form class="experience-form" method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="request_partner_confirmation">'
        . '<div class="honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>'
        . '<label class="field">E-mail<input type="email" name="email" maxlength="190" autocomplete="email" inputmode="email" required></label>'
        . '<button class="button button--primary button--wide">Enviar confirmação</button>'
        . '</form></div></section>';
    ui_public_end();
    exit;
}

if ($path === '/parceiro/candidatura') {
    $application = portal_partner_application_from_session();
    if (!$application) {
        flash('Confirme seu e-mail para acessar o formulário.');
        redirect('/parceiro/candidatar');
    }
    if (in_array((string) $application['status'], ['aguardando_analise', 'aprovado'], true)) {
        redirect(auth_user() ? '/parceiro' : '/login');
    }
    $stmt = db('identity')->prepare(
        "SELECT uuid,status FROM usuarios WHERE email=:email LIMIT 1"
    );
    $stmt->execute(['email' => $application['email']]);
    $existingUser = $stmt->fetch();
    ui_public_start('Candidatura de parceiro', 'Portal', 'partner-public');
    show_flash();
    echo '<section class="application-shell"><div class="page-heading compact-heading">'
        . '<p class="eyebrow"><span></span>E-mail confirmado</p>'
        . '<h1>Conte um pouco sobre você.</h1>'
        . '<p>A candidatura aceita pessoas iniciantes. GitHub, LinkedIn e portfólio são opcionais.</p>'
        . '</div><form class="interest-form partner-application-form" method="post">'
        . csrf_field() . '<input type="hidden" name="_action" value="submit_partner_application">'
        . '<label class="field">E-mail confirmado<input type="email" value="' . h($application['email']) . '" disabled></label>'
        . '<label class="field">Nome completo<input name="nome" maxlength="150" autocomplete="name" required></label>'
        . '<div class="form-grid">'
        . '<label class="field">GitHub <small>(opcional)</small><input type="url" name="github_url" maxlength="500" placeholder="https://github.com/seu-usuario"></label>'
        . '<label class="field">LinkedIn <small>(opcional)</small><input type="url" name="linkedin_url" maxlength="500" placeholder="https://www.linkedin.com/in/seu-perfil"></label></div>'
        . '<label class="field">Portfólio ou site pessoal <small>(opcional)</small><input type="url" name="portfolio_url" maxlength="500" placeholder="https://seusite.com"></label>'
        . '<label class="field">Tecnologias com que trabalha<textarea name="tecnologias" maxlength="4000" rows="4" placeholder="Você pode informar que está começando."></textarea></label>'
        . '<label class="field">Breve descrição da experiência<textarea name="experiencia" maxlength="4000" rows="6" placeholder="Conte sobre estudos, projetos ou experiências profissionais."></textarea></label>';
    if (!is_array($existingUser)) {
        echo ui_new_password_fields();
    } else {
        echo '<p class="form-note">Este e-mail já possui uma conta. Seus acessos atuais serão preservados e nenhuma senha será alterada.</p>';
    }
    echo '<button class="button button--primary">Enviar candidatura</button></form></section>';
    ui_public_end();
    exit;
}

if ($method === 'GET' && $path === '/interesse') {
    redirect('/precos#interesse');
}

if ($method === 'GET' && $path === '/precos') {
    portal_public_pricing();
    exit;
}

if ($method === 'GET' && $path === '/sobre') {
    portal_public_about();
    exit;
}

if ($path === '/login' && !auth_user()) {
    portal_public_login();
    exit;
}

if (!auth_user()) {
    portal_public_home();
    exit;
}

$user = require_auth();
$projects = authorized_projects($user);
$projectsByUuid = portal_projects_by_uuid($projects);

if ($path === '/parceiro') {
    portal_app_start('Parceiro', $user);
    $application = partner_application_for_user($user);
    $partner = active_partner($user);
    echo '<div class="page-heading compact-heading"><p class="eyebrow"><span></span>Rede Threeebs</p>'
        . '<h1>Painel do parceiro</h1><p>Acompanhe sua candidatura e, após a aprovação, crie seus próprios clientes e projetos.</p></div>';
    if (!$application) {
        echo '<section class="content-section"><h2>Ainda não existe uma candidatura</h2>'
            . '<p>Inicie pelo processo de confirmação do seu e-mail.</p>'
            . '<a class="button button--primary" href="/parceiro/candidatar">Ser parceiro</a></section>';
    } else {
        echo '<section class="partner-status"><span>Situação</span><strong>'
            . h(portal_partner_status_label((string) $application['status'])) . '</strong>';
        if ((string) $application['status'] === 'aguardando_analise') {
            echo '<p>Seu perfil está no banco de talentos e aguarda avaliação administrativa.</p>';
        } elseif ((string) $application['status'] === 'aprovado' && !$partner) {
            echo '<p>A aprovação foi registrada, mas o perfil operacional está indisponível. Contate a equipe Threeebs.</p>';
        }
        echo '</section>';
    }
    if ($partner) {
        $stmt = db('control')->prepare(
            "SELECT c.uuid,c.nome,c.slug,c.status,COUNT(p.id) projetos
             FROM clientes c
             LEFT JOIN projetos p ON p.cliente_id=c.id
             WHERE c.criado_por_usuario_uuid=:user
             GROUP BY c.id ORDER BY c.created_at DESC,c.id DESC"
        );
        $stmt->execute(['user' => $user['uuid']]);
        $partnerClients = $stmt->fetchAll();
        echo '<section class="content-section"><div class="section-title"><h2>Novo cliente</h2></div>'
            . '<form class="experience-form compact-form" method="post">' . csrf_field()
            . '<input type="hidden" name="_action" value="partner_create_client">'
            . '<div class="form-grid"><label class="field">Nome do cliente<input name="nome" maxlength="180" required></label>'
            . '<label class="field">Slug do cliente<input name="slug" maxlength="100" pattern="[a-zA-Z0-9_-]+" required></label></div>'
            . '<button class="button button--primary">Criar cliente</button></form></section>'
            . '<section class="content-section"><div class="section-title"><h2>Meus clientes e projetos</h2></div>';
        if ($partnerClients === []) {
            echo '<p class="empty-state">Você ainda não criou clientes.</p>';
        }
        foreach ($partnerClients as $client) {
            echo '<article class="partner-client" id="cliente-' . h($client['uuid']) . '"><header><div><span>Cliente</span><h3>'
                . h($client['nome']) . '</h3></div><strong>' . h($client['projetos']) . ' projeto(s)</strong></header>'
                . '<form class="experience-form compact-form" method="post">' . csrf_field()
                . '<input type="hidden" name="_action" value="partner_create_project">'
                . '<input type="hidden" name="cliente_uuid" value="' . h($client['uuid']) . '">'
                . '<div class="form-grid"><label class="field">Nome do projeto<input name="nome" maxlength="150" required></label>'
                . '<label class="field">Slug do endereço<input name="slug" maxlength="100" pattern="[a-zA-Z0-9_-]+" required></label></div>'
                . '<label class="field">Descrição <small>(opcional)</small><textarea name="descricao" rows="3"></textarea></label>'
                . '<button class="button button--primary">Criar projeto e ambientes</button></form>';
            $projectStmt = db('control')->prepare(
                "SELECT uuid,nome,status FROM projetos
                 WHERE cliente_id=(SELECT id FROM clientes WHERE uuid=:client)
                 ORDER BY created_at DESC,id DESC"
            );
            $projectStmt->execute(['client' => $client['uuid']]);
            echo '<div class="partner-projects">';
            foreach ($projectStmt as $project) {
                echo '<a href="/projeto?uuid=' . h($project['uuid']) . '"><span>'
                    . h($project['nome']) . '</span><small>' . h($project['status']) . '</small></a>';
            }
            echo '</div></article>';
        }
        echo '</section>';
    }
} elseif ($path === '/') {
    portal_app_start('Visão geral', $user);
    echo '<section class="dashboard-hero"><p class="eyebrow"><span></span>Seu ambiente de hospedagem</p><h1>Olá, ' . h((string) $user['nome']) . '.</h1>'
        . '<p>Gerencie seus projetos hospedados, acesse os ambientes e acompanhe o trabalho da sua equipe.</p></section>'
        . '<div class="metric-grid"><article><strong>' . count($projects) . '</strong><span>Projetos hospedados</span></article>'
        . '<article><strong>' . count(portal_collaborators($user)) . '</strong><span>Colaboradores</span></article>'
        . '<article><strong>' . count(portal_tasks($projects, $user)) . '</strong><span>Tarefas visíveis</span></article></div>'
        . '<section class="content-section"><div class="section-title"><h2>Projetos hospedados</h2><a href="/projetos">Ver todos</a></div><div class="project-grid">';
    if ($projects === []) {
        echo portal_projects_empty_state();
    }
    foreach (array_slice($projects, 0, 4) as $project) {
        echo '<article class="project-card"><span>' . h($project['cliente_nome']) . '</span><h3>' . h($project['nome']) . '</h3>'
            . '<a class="button button--secondary" href="/projeto?uuid=' . h($project['uuid']) . '">Abrir projeto</a></article>';
    }
    echo '</div></section>';
} elseif ($path === '/projetos') {
    portal_app_start('Projetos', $user);
    echo '<div class="page-heading compact-heading"><p class="eyebrow"><span></span>Hospedagem</p><h1>Seus projetos hospedados</h1><p>Gerencie seus projetos e acesse os ambientes de teste e produção.</p></div><div class="project-grid">';
    if ($projects === []) echo portal_projects_empty_state();
    foreach ($projects as $project) {
        echo '<article class="project-card"><span>' . h($project['cliente_nome']) . '</span><h2>' . h($project['nome']) . '</h2>'
            . '<a class="button" href="/projeto?uuid=' . h($project['uuid']) . '">Acessar</a></article>';
    }
    echo '</div>';
} elseif ($path === '/colaboradores') {
    portal_app_start('Colaboradores', $user);
    echo '<div class="page-heading compact-heading"><p class="eyebrow"><span></span>Equipe</p><h1>Meus colaboradores</h1><p>Pessoas vinculadas aos mesmos clientes ativos na Threeebs.</p></div><div class="people-grid">';
    $collaborators = portal_collaborators($user);
    if ($collaborators === []) echo '<p class="empty-state">Nenhum colaborador disponível.</p>';
    foreach ($collaborators as $collaborator) {
        echo '<article class="person-card"><div class="avatar">' . h(strtoupper(substr((string) ($collaborator['nome'] ?: $collaborator['email']), 0, 1))) . '</div>'
            . '<div><h3>' . h($collaborator['nome'] ?: $collaborator['email']) . '</h3><p>' . h($collaborator['cliente_nome']) . ' · ' . h($collaborator['papel']) . '</p></div></article>';
    }
    echo '</div>';
} elseif ($path === '/tarefas') {
    portal_app_start('Tarefas', $user);
    echo '<div class="page-heading compact-heading"><p class="eyebrow"><span></span>Acompanhamento</p><h1>Tarefas dos projetos hospedados</h1><p>Acompanhe o trabalho dos projetos aos quais você tem acesso.</p></div><div class="task-list">';
    $tasks = portal_tasks($projects, $user);
    if ($tasks === []) echo '<p class="empty-state">Nenhuma tarefa disponível.</p>';
    foreach ($tasks as $task) {
        echo '<article><div><span>' . h($projectsByUuid[(string) $task['projeto_uuid']] ?? 'Projeto') . ' · ' . h($task['coluna']) . '</span>'
            . '<h3>' . h($task['titulo']) . '</h3></div><div class="task-meta"><span>' . h($task['status']) . '</span>'
            . ((int) $task['responsavel'] === 1 ? '<strong>Responsável: você</strong>' : '') . '</div></article>';
    }
    echo '</div>';
} elseif ($path === '/projeto') {
    $project = require_project((string) ($_GET['uuid'] ?? ''));
    $navigation = project_environment_navigation((int) $project['id']);
    $production = $navigation['production'] ?? null;
    $sandbox = $navigation['sandbox'] ?? null;
    portal_app_start((string) $project['nome'], $user);
    $storage = project_storage_summary((int) $project['id']);
    $storagePercent = (int) $storage['armazenamento_bytes_max'] > 0
        ? min(100, round(100 * (int) $storage['armazenamento_bytes_usados'] / (int) $storage['armazenamento_bytes_max'], 2))
        : 0;
    echo '<div class="project-heading"><div><p class="eyebrow"><span></span>' . h($project['cliente_nome']) . '</p><h1>' . h($project['nome']) . '</h1>'
        . '<p>' . nl2br(h($project['descricao'])) . '</p></div><a class="text-link" href="/projetos">Voltar aos projetos</a></div>'
        . '<section class="content-section"><div class="section-title"><h2>Plano e uso</h2></div>'
        . '<div class="metric-grid"><article><strong>' . h(storage_bytes_label((int) $storage['armazenamento_bytes_usados'])) . '</strong>'
        . '<span>de ' . h(storage_bytes_label((int) $storage['armazenamento_bytes_max'])) . ' · ' . h($storagePercent) . '% usado</span></article>'
        . '<article><strong>' . h($storage['arquivos_usados']) . '</strong><span>de ' . h($storage['arquivos_max']) . ' arquivos</span></article>'
        . '<article><strong>' . h($storage['pastas_usadas']) . '</strong><span>de ' . h($storage['pastas_max']) . ' pastas</span></article></div>'
        . '<p>Plano: <strong>' . h($storage['catalog_item_nome'] ?: 'Configuração padrão') . '</strong>'
        . ' · máximo por arquivo: ' . h(storage_bytes_label((int) $storage['arquivo_bytes_max'])) . '.</p></section>'
        . '<section class="access-grid">';
    $accesses = [
        ['title' => 'Produção', 'description' => 'Conteúdo publicado e acessível pelo endereço de produção.', 'available' => is_array($production) && is_string($production['url']), 'url' => $production['url'] ?? null, 'external' => true],
        ['title' => 'Sandbox', 'description' => 'Ambiente de teste e pré-visualização antes da publicação.', 'available' => is_array($sandbox) && is_string($sandbox['url']), 'url' => $sandbox['url'] ?? null, 'external' => true],
        ['title' => 'Editor Threeebs', 'description' => 'Área de edição do index.html no ambiente Sandbox.', 'available' => is_array($sandbox) && (string) $sandbox['status'] === 'ativo', 'url' => editor_project_url((string) $project['uuid']), 'external' => false],
    ];
    foreach ($accesses as $access) {
        echo '<article><span class="access-state">' . ($access['available'] ? 'Disponível' : 'Indisponível') . '</span><h2>' . h($access['title']) . '</h2><p>' . h($access['description']) . '</p>';
        echo $access['available']
            ? '<a class="button" href="' . h((string) $access['url']) . '"' . ($access['external'] ? ' target="_blank" rel="noopener"' : '') . '>Acessar ' . ui_icon('external', 17) . '</a>'
            : '<span class="button button--disabled" aria-disabled="true">Ainda não disponível</span>';
        echo '</article>';
    }
    echo '</section><section class="content-section"><div class="section-title"><h2>Tarefas</h2></div><div class="task-list">';
    $stmt = db('work')->prepare("SELECT c.nome coluna,t.titulo,t.status FROM quadros q JOIN colunas c ON c.quadro_id=q.id LEFT JOIN tarefas t ON t.coluna_id=c.id WHERE q.projeto_uuid=:project ORDER BY q.id,c.ordem,t.ordem,t.id");
    $stmt->execute(['project' => $project['uuid']]);
    $hasTasks = false;
    foreach ($stmt as $task) {
        if (!$task['titulo']) continue;
        $hasTasks = true;
        echo '<article><div><span>' . h($task['coluna']) . '</span><h3>' . h($task['titulo']) . '</h3></div><div class="task-meta"><span>' . h($task['status']) . '</span></div></article>';
    }
    if (!$hasTasks) echo '<p class="empty-state">Nenhuma tarefa cadastrada neste projeto.</p>';
    echo '</div></section>';
} else {
    http_response_code(404);
    portal_app_start('Página não encontrada', $user);
    echo '<p class="empty-state">A página solicitada não foi encontrada.</p>';
}

ui_app_end();
