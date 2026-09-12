<?php

declare(strict_types=1);

function portal_public_is_current(string $currentPath, string $href): string
{
    return $currentPath === $href ? ' aria-current="page"' : '';
}

function portal_theme_toggle(): string
{
    return '<button class="icon-button marketing-theme-toggle" type="button" data-theme-toggle aria-label="Ativar tema claro">'
        . '<span class="theme-icon theme-icon--sun"><svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg></span>'
        . '<span class="theme-icon theme-icon--moon"><svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg></span>'
        . '</button>';
}

function portal_public_start(
    string $title,
    string $description,
    string $currentPath,
    string $bodyClass = ''
): void {
    $portalHref = auth_user() ? '/projetos' : '/login';
    $portalLabel = auth_user() ? 'Abrir Portal' : 'Entrar';

    echo '<!doctype html><html lang="pt-BR" data-theme="dark" data-product="portal"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="theme-color" content="#181818">'
        . '<meta name="description" content="' . h($description) . '">'
        . '<title>' . h($title) . ' · Threeebs</title>'
        . '<script src="/assets/js/public-theme.js"></script>'
        . '<link rel="stylesheet" href="/assets/identity/css/threeebs.css">'
        . '<link rel="stylesheet" href="/assets/identity/css/pages.css">'
        . '<link rel="stylesheet" href="/assets/identity/css/experiences.css">'
        . '<link rel="stylesheet" href="/assets/css/main.css">'
        . '<link rel="stylesheet" href="/assets/css/public-marketing.css">'
        . '</head><body class="public-page marketing-page ' . h($bodyClass) . '">'
        . '<a class="skip-link" href="#main-content">Pular para o conteúdo</a>'
        . '<header class="marketing-header"><div class="marketing-header__inner">'
        . '<a class="marketing-brand" href="/" aria-label="Threeebs — página inicial">'
        . '<span aria-hidden="true">:3</span><strong>Threeebs</strong></a>'
        . '<div class="marketing-header__actions">' . portal_theme_toggle()
        . '<details class="marketing-nav" data-public-nav open>'
        . '<summary aria-label="Abrir navegação"><span class="nav-menu-label">Menu</span>'
        . '<span class="nav-menu-icon" aria-hidden="true"><i></i><i></i><i></i></span></summary>'
        . '<nav aria-label="Navegação pública"><a href="/"' . portal_public_is_current($currentPath, '/') . '>Home</a>'
        . '<a href="/precos"' . portal_public_is_current($currentPath, '/precos') . '>Preços</a>'
        . '<a href="/sobre"' . portal_public_is_current($currentPath, '/sobre') . '>Sobre</a>'
        . '<details class="more-menu" data-more-menu><summary>Mais <span aria-hidden="true">⌄</span></summary>'
        . '<div class="more-menu__panel"><a href="https://docs.3eb.site/" target="_blank" rel="noopener noreferrer" aria-label="Docs (abre em nova aba)">Docs <span aria-hidden="true">↗</span></a>'
        . '<a href="https://identidade.3eb.site/" target="_blank" rel="noopener noreferrer" aria-label="Identidade (abre em nova aba)">Identidade <span aria-hidden="true">↗</span></a>'
        . '<a href="/parceiro/candidatar">Parceria <span aria-hidden="true">→</span></a>'
        . '</div></details><a class="button button--secondary portal-entry" href="' . h($portalHref) . '">'
        . h($portalLabel) . '</a></nav></details></div></div></header>'
        . '<main id="main-content" tabindex="-1">';
}

function portal_public_end(): void
{
    echo '</main><footer class="marketing-footer"><div><a class="marketing-brand" href="/">'
        . '<span aria-hidden="true">:3</span><strong>Threeebs</strong></a>'
        . '<p>Seu ambiente de desenvolvimento.</p></div><nav aria-label="Navegação do rodapé">'
        . '<a href="/precos">Preços</a><a href="/sobre">Sobre</a>'
        . '<a href="https://docs.3eb.site/" target="_blank" rel="noopener noreferrer" aria-label="Documentação (abre em nova aba)">Documentação</a>'
        . '<a href="https://identidade.3eb.site/" target="_blank" rel="noopener noreferrer" aria-label="Identidade (abre em nova aba)">Identidade</a>'
        . '</nav></footer>'
        . '<script src="/assets/js/public-navigation.js" defer></script>'
        . '<script type="module" src="/assets/js/public-scene.js"></script>'
        . '</body></html>';
}

function portal_login_start(string $title, string $description): void
{
    echo '<!doctype html><html lang="pt-BR" data-theme="dark" data-product="portal"><head>'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="theme-color" content="#181818">'
        . '<meta name="description" content="' . h($description) . '">'
        . '<title>' . h($title) . ' · Threeebs</title>'
        . '<script src="/assets/js/public-theme.js"></script>'
        . '<link rel="stylesheet" href="/assets/identity/css/threeebs.css">'
        . '<link rel="stylesheet" href="/assets/identity/css/pages.css">'
        . '<link rel="stylesheet" href="/assets/identity/css/experiences.css">'
        . '<link rel="stylesheet" href="/assets/css/main.css">'
        . '<link rel="stylesheet" href="/assets/css/public-marketing.css">'
        . '</head><body class="public-page portal-login-page">'
        . '<a class="skip-link" href="#main-content">Pular para o conteúdo</a>'
        . '<header class="login-header"><div class="login-header__inner">'
        . '<a class="marketing-brand" href="/" aria-label="Threeebs — página inicial">'
        . '<span aria-hidden="true">:3</span><strong>Threeebs</strong></a>'
        . '<div class="login-header__actions">' . portal_theme_toggle()
        . '<a class="login-home-link" href="/"><span aria-hidden="true">←</span> Voltar para Home</a></div>'
        . '</div></header><main id="main-content" tabindex="-1">';
}

function portal_login_end(): void
{
    echo '</main><script src="/assets/js/password.js" defer></script>'
        . '<script src="/assets/js/public-navigation.js" defer></script></body></html>';
}

function portal_public_login(): void
{
    portal_login_start(
        'Entrar',
        'Entre no Portal Threeebs para acessar seus projetos, ambientes, colaboradores e tarefas.'
    );
    ?>
    <section class="login-experience">
        <div class="login-story">
            <p class="eyebrow"><span></span>Portal Threeebs</p>
            <h1>Seu projeto continua daqui.</h1>
            <p>Acesse o ambiente onde projetos, pessoas, decisões e próximos passos permanecem conectados.</p>
            <ul>
                <li><span aria-hidden="true">01</span><div><strong>Projetos e ambientes</strong><small>Sandbox, produção e acessos organizados.</small></div></li>
                <li><span aria-hidden="true">02</span><div><strong>Trabalho compartilhado</strong><small>Tarefas e colaboradores no mesmo contexto.</small></div></li>
                <li><span aria-hidden="true">03</span><div><strong>Evolução visível</strong><small>Uma base para acompanhar o que vem depois.</small></div></li>
            </ul>
        </div>
        <div class="login-panel">
            <div class="login-panel__heading">
                <span class="login-panel__mark" aria-hidden="true">:3</span>
                <div><p class="eyebrow">Acesso seguro</p><h2>Entrar no Portal</h2></div>
            </div>
            <p>Use o acesso fornecido pela equipe responsável pelo seu projeto.</p>
            <?php show_flash(); ?>
            <form class="experience-form login-form" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="login">
                <label class="field">E-mail<input type="email" name="email" autocomplete="username" inputmode="email" required autofocus></label>
                <?= ui_password_input('senha', 'Senha') ?>
                <div class="login-form__meta"><a class="text-link" href="/esqueci-senha">Esqueci minha senha</a></div>
                <button class="button button--primary button--wide" type="submit">Entrar</button>
            </form>
            <p class="login-panel__support">Ainda não possui acesso? <a href="/precos#interesse">Conte sobre seu projeto</a>.</p>
        </div>
    </section>
    <?php
    portal_login_end();
}

function portal_public_home(): void
{
    portal_public_start(
        'Seu ambiente de desenvolvimento',
        'A Threeebs ajuda pessoas a transformar ideias em sistemas próprios.',
        '/',
        'marketing-home'
    );
    ?>
    <section class="marketing-hero">
        <div class="marketing-hero__copy">
            <p class="eyebrow"><span></span>Seu ambiente de desenvolvimento</p>
            <h1>Ideias ganham forma. Sistemas ganham <em>futuro.</em></h1>
            <p class="hero-lead">A Threeebs ajuda pessoas a transformar ideias em sistemas próprios, unindo desenvolvimento, infraestrutura e acompanhamento para começar com clareza e evoluir com autonomia.</p>
            <div class="hero-actions">
                <a class="button button--primary" href="/precos">Conhecer os planos</a>
                <a class="button button--secondary" href="/sobre">Entender a Threeebs</a>
            </div>
            <a class="hero-docs-link" href="https://docs.3eb.site/" target="_blank" rel="noopener noreferrer" aria-label="Explorar a documentação (abre em nova aba)">Explorar a documentação <span aria-hidden="true">↗</span></a>
        </div>
        <div class="system-scene system-scene--hero" data-three-scene="hero" role="img" aria-label="Uma ideia evolui em quatro etapas até se tornar um sistema capaz de continuar crescendo">
            <canvas aria-hidden="true"></canvas>
            <div class="system-scene__flow" aria-hidden="true">
                <div class="system-stage"><small>01</small><span>Ideia</span><b>ponto de partida</b></div>
                <i></i>
                <div class="system-stage"><small>02</small><span>Projeto</span><b>direção e forma</b></div>
                <i></i>
                <div class="system-stage"><small>03</small><span>Sistema</span><b>base em operação</b></div>
                <i></i>
                <div class="system-stage"><small>04</small><span>Evolução</span><b>próximos ciclos</b></div>
            </div>
            <p class="system-scene__caption"><span>Do primeiro contexto</span><span>à autonomia para evoluir</span></p>
        </div>
    </section>

    <section class="marketing-section statement-section" id="como-funciona">
        <div class="section-heading">
            <div><p class="eyebrow"><span></span>O que é a Threeebs</p>
            <h2>Uma base para construir junto.</h2></div>
            <p>A gente assume a complexidade técnica sem tirar de você o contexto, as decisões e a possibilidade de seguir seu próprio caminho.</p>
        </div>
        <ol class="journey-line" aria-label="Jornada de um projeto">
            <li><strong>Ideia</strong><span>O ponto de partida.</span></li>
            <li><strong>Entendimento</strong><span>Contexto e direção.</span></li>
            <li><strong>MVP</strong><span>A primeira entrega útil.</span></li>
            <li><strong>Hospedagem</strong><span>Uma base em operação.</span></li>
            <li><strong>Evolução</strong><span>Melhorias em etapas.</span></li>
        </ol>
        <a class="text-arrow" href="https://docs.3eb.site/" target="_blank" rel="noopener noreferrer" aria-label="Como funciona (abre em nova aba)">Como funciona <span aria-hidden="true">↗</span></a>
    </section>

    <section class="marketing-section start-small-section">
        <div class="section-kicker"><span>Comece pequeno</span><span>cresça no seu ritmo</span></div>
        <div class="start-small-grid">
            <div>
                <h2>O primeiro passo não precisa carregar o projeto inteiro.</h2>
                <p>Uma presença simples pode validar a ideia. Depois, conteúdo, dados, usuários e processos podem entrar quando fizerem sentido.</p>
                <a class="button button--secondary" href="/precos">Ver possibilidades</a>
            </div>
            <ol class="growth-stack">
                <li><span>01</span><strong>Site simples</strong></li>
                <li><span>02</span><strong>Site completo</strong></li>
                <li><span>03</span><strong>Sistema dinâmico</strong></li>
            </ol>
        </div>
    </section>

    <section class="marketing-section evolution-section">
        <div class="ambient-scene" data-three-scene="ambient"><canvas aria-hidden="true"></canvas><span aria-hidden="true">:3</span></div>
        <div class="evolution-copy">
            <p class="eyebrow"><span></span>Construído para evoluir</p>
            <h2>Crescer não deveria significar começar tudo de novo.</h2>
            <p>O sistema, a documentação e as decisões técnicas evoluem juntos. Assim, cada nova etapa parte de uma base que já conhece o projeto.</p>
        </div>
        <ul class="evolution-list">
            <li><strong>Desenvolvimento</strong><span>Entregas pequenas, visíveis e testáveis.</span></li>
            <li><strong>Banco de dados</strong><span>Quando a necessidade pede estrutura dinâmica.</span></li>
            <li><strong>Infraestrutura</strong><span>Ambientes para construir e operar.</span></li>
            <li><strong>Documentação</strong><span>Contexto preservado junto do sistema.</span></li>
            <li><strong>Acompanhamento</strong><span>Feedback e próximos passos em conjunto.</span></li>
        </ul>
    </section>

    <section class="marketing-section ecosystem-section">
        <div class="section-heading"><div><p class="eyebrow"><span></span>Conheça o ecossistema</p>
            <h2>Uma linguagem, vários pontos de apoio.</h2></div>
            <p>Produto, princípios e identidade ficam acessíveis para que a relação com a Threeebs seja clara.</p>
        </div>
        <div class="ecosystem-grid">
            <a href="https://docs.3eb.site/" target="_blank" rel="noopener noreferrer" aria-label="Documentação Threeebs (abre em nova aba)"><span>01</span><h3>Documentação</h3><p>Entenda produto, processo e fundamentos.</p><b aria-hidden="true">↗</b></a>
            <a href="https://identidade.3eb.site/" target="_blank" rel="noopener noreferrer" aria-label="Identidade Threeebs (abre em nova aba)"><span>02</span><h3>Identidade</h3><p>Conheça o sistema visual que conecta o ecossistema.</p><b aria-hidden="true">↗</b></a>
            <a href="/sobre"><span>03</span><h3>Sobre a Threeebs</h3><p>Saiba por que construímos dessa forma.</p><b aria-hidden="true">→</b></a>
        </div>
    </section>

    <section class="marketing-cta">
        <p class="eyebrow"><span></span>Próximo passo</p>
        <h2>Vamos entender o que seu projeto precisa.</h2>
        <p>Comece contando a ideia. O caminho pode ser simples agora e crescer depois.</p>
        <a class="button button--primary" href="/precos">Conhecer as possibilidades</a>
    </section>
    <?php
    portal_public_end();
}

function portal_interest_form(): void
{
    show_flash();
    ?>
    <form class="interest-form" method="post" action="/precos#interesse">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="register_interest">
        <div class="honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <label class="field">Seu nome<input name="nome" maxlength="150" autocomplete="name" required></label>
        <label class="field">E-mail<input type="email" name="email" maxlength="254" autocomplete="email" inputmode="email" required></label>
        <label class="field">Empresa ou projeto <small>(opcional)</small><input name="empresa" maxlength="180" autocomplete="organization"></label>
        <div class="form-grid">
            <label class="field">Tipo de projeto<select name="tipo_projeto" required><option value="" disabled selected>Selecione uma opção</option><option value="site">Site institucional</option><option value="sistema">Sistema ou aplicação web</option><option value="loja">Loja virtual</option><option value="outro">Outro tipo de projeto</option></select></label>
            <label class="field">Momento atual<select name="momento" required><option value="" disabled selected>Selecione uma opção</option><option value="ideia">Tenho uma ideia</option><option value="planejamento">Estou planejando</option><option value="em_andamento">Já está em andamento</option><option value="evolucao">Quero evoluir algo existente</option></select></label>
        </div>
        <label class="field">O que você quer transformar?<textarea name="mensagem" maxlength="4000" rows="6" required></textarea></label>
        <label class="check-consent"><input type="checkbox" name="consentimento" value="1" required><span>Autorizo o contato da equipe Threeebs sobre este interesse.</span></label>
        <button class="button button--primary" type="submit">Enviar interesse</button>
    </form>
    <?php
}

function portal_public_pricing(): void
{
    portal_public_start(
        'Preços',
        'Conheça os níveis iniciais de projeto da Threeebs e conte o que você precisa construir.',
        '/precos',
        'marketing-pricing'
    );
    ?>
    <section class="internal-hero">
        <div><p class="eyebrow"><span></span>Preços</p>
        <h1>Um ponto de partida para cada momento.</h1>
        <p>Os valores dependem do projeto, período e necessidade. Primeiro entendemos o escopo; depois construímos um orçamento coerente com ele.</p></div>
        <div class="visual-panel visual-panel--plans" data-three-scene="ambient" role="img" aria-label="Três estruturas de projeto, do site simples à plataforma dinâmica">
            <canvas aria-hidden="true"></canvas>
            <div class="visual-panel__content" aria-hidden="true"><span>Site simples</span><span>Site completo</span><span>Plataforma</span></div>
        </div>
    </section>

    <section class="plans-section" aria-labelledby="plans-title">
        <div class="section-heading"><div><p class="eyebrow"><span></span>Catálogo inicial</p><h2 id="plans-title">Comece com a estrutura certa para agora.</h2></div>
        <p>Os três níveis mostram bases possíveis. O escopo final nasce da conversa sobre o projeto.</p></div>
        <div class="plans-grid">
            <article class="plan-card">
                <p class="plan-number">01</p><h3>Site estático simples</h3><p>Uma presença digital pequena, clara e personalizada.</p>
                <ul><li>Até 3 páginas estáticas</li><li>Layout personalizado</li><li>Hospedagem Threeebs</li><li>Endereço publicado</li></ul>
                <div class="plan-boundary"><strong>Sem como padrão</strong><span>Banco de dados, painel e área restrita.</span></div>
                <a class="button button--secondary" href="/precos#interesse">Solicitar orçamento</a>
            </article>
            <article class="plan-card">
                <p class="plan-number">02</p><h3>Site estático completo</h3><p>Mais páginas, navegação e profundidade de conteúdo.</p>
                <ul><li>Até 10 páginas estáticas</li><li>Navegação mais completa</li><li>Layout personalizado</li><li>Hospedagem Threeebs</li><li>Formulário simples quando aplicável</li></ul>
                <div class="plan-boundary"><strong>Sem como padrão</strong><span>Banco de dados.</span></div>
                <a class="button button--secondary" href="/precos#interesse">Solicitar orçamento</a>
            </article>
            <article class="plan-card plan-card--featured">
                <p class="plan-number">03</p><h3>Projeto dinâmico / plataforma</h3><p>Uma base para sistemas com dados, pessoas e processos.</p>
                <ul><li>Base de até 15 páginas</li><li>Banco de dados e estrutura dinâmica</li><li>Possibilidade de usuários, login e painel</li><li>Conteúdo dinâmico</li><li>Integrações e automações</li><li>Expansão por escopo</li></ul>
                <div class="plan-boundary"><strong>Antes de construir</strong><span>Pode exigir diagnóstico técnico-estratégico.</span></div>
                <a class="button button--primary" href="/precos#interesse">Conversar sobre o projeto</a>
            </article>
        </div>
    </section>

    <section class="interest-section marketing-interest" id="interesse">
        <div><p class="eyebrow"><span></span>Conte sobre seu projeto</p>
        <h2>A conversa começa pelo que você precisa.</h2>
        <p>Este formulário registra seu interesse para a equipe Threeebs. Ele não cria conta, não fecha contratação e não define preço automaticamente.</p>
        <ol><li>Você compartilha contexto e momento.</li><li>A equipe avalia a necessidade.</li><li>O próximo passo é combinado com clareza.</li></ol></div>
        <?php portal_interest_form(); ?>
    </section>
    <?php
    portal_public_end();
}

function portal_public_about(): void
{
    portal_public_start(
        'Sobre',
        'A Threeebs existe para ajudar pessoas a transformar ideias em sistemas próprios.',
        '/sobre',
        'marketing-about'
    );
    ?>
    <section class="internal-hero about-hero">
        <div><p class="eyebrow"><span></span>Sobre a Threeebs</p>
        <h1>Tecnologia também é relação.</h1>
        <p>A Threeebs existe para ajudar pessoas a transformar ideias em sistemas próprios — sem exigir que elas dominem toda a complexidade técnica para começar.</p></div>
        <div class="visual-panel visual-panel--partnership" data-three-scene="ambient" role="img" aria-label="Pessoa e Threeebs colaboram ao redor de um sistema próprio">
            <canvas aria-hidden="true"></canvas>
            <div class="visual-panel__content" aria-hidden="true"><span>Pessoa</span><strong>Sistema próprio</strong><span>Threeebs</span></div>
        </div>
    </section>

    <section class="about-story">
        <article><p class="story-number">01</p><div><h2>O que é a Threeebs</h2><p>Uma empresa de tecnologia que apoia a criação e a evolução de sistemas próprios. A gente combina trabalho humano, inteligência artificial e infraestrutura para transformar ideias em sistemas reais e sustentáveis.</p></div></article>
        <article><p class="story-number">02</p><div><h2>Por que ela existe</h2><p>Porque facilidade não deveria significar dependência. Uma pessoa pode começar com uma presença simples ou um MVP e ainda assim construir uma base que preserve código, contexto e possibilidades de crescimento.</p></div></article>
        <article><p class="story-number">03</p><div><h2>Como trabalhamos</h2><p>Primeiro entendemos. Depois organizamos o contexto, definimos uma primeira entrega útil e construímos em pequenas etapas. Mostrar, validar, ajustar e documentar fazem parte do mesmo ciclo.</p></div></article>
        <article><p class="story-number">04</p><div><h2>Autonomia e evolução</h2><p>A gente não quer que ninguém permaneça por falta de saída, mas por escolha. Projetos podem ser versionados, preservar seu código e seguir outro caminho quando fizer sentido.</p></div></article>
        <article><p class="story-number">05</p><div><h2>Segurança e transparência</h2><p>Crescimento não acontece às custas de confiança. Segurança, privacidade, clareza sobre limites e acesso pelo menor privilégio fazem parte da base — não do extra.</p></div></article>
    </section>

    <blockquote class="about-quote"><p>“A gente não promete mágica. Promete parceria.”</p><cite>Manifesto Threeebs</cite></blockquote>

    <section class="marketing-cta">
        <p class="eyebrow"><span></span>Uma ideia pode começar pequena</p>
        <h2>Vamos construir o primeiro passo.</h2>
        <p>Conte o que você quer transformar e descubra qual base faz sentido para agora.</p>
        <a class="button button--primary" href="/precos#interesse">Conversar sobre o projeto</a>
    </section>
    <?php
    portal_public_end();
}
