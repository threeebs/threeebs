# Changelog

Mudanças públicas relevantes serão registradas neste arquivo.

O formato segue princípios de [Keep a Changelog](https://keepachangelog.com/), e as versões públicas usam versionamento semântico.

## Unreleased

### Added

- Serviço realtime para edição colaborativa.
- Fundação de runtime PHP isolado, desabilitada por padrão até provisionamento explícito.
- Operações assíncronas de ambiente e operador opcional via `systemd`.
- Limites de armazenamento, planos de projeto e bancos isolados por ambiente.
- Fluxos de parceiros, redefinição de senha e primeiro acesso.
- Assets públicos de marketing, identidade visual e licença do Three.js.
- Instalação e atualização automatizadas com sincronização segura do `.env` e backup prévio.
- Artigos sobre arquitetura, requisitos, domínio, Docker, Cloudflare Tunnel, portas, segurança e operação.
- Exemplo público e sanitizado de ingress do Cloudflare Tunnel.

### Changed

- Portal, Admin, Host e Sandbox atualizados para os novos fluxos.
- Docker Compose, Apache e inicialização do MySQL ampliados para os novos serviços.
- Documentação de instalação atualizada para instalações locais e servidores.
- README reduzido a uma página de entrada, com a documentação detalhada organizada em `docs/`.

### Security

- Redes internas, limites de runtime e diretórios protegidos de segredos adicionados.
- Migrations e permissões passam a ser aplicadas de forma idempotente durante instalação e atualização.
- Documentação agora diferencia portas locais, portas internas dos containers e exposição pelo túnel.

## [0.1.0] - 2026-09-03

### Added

- Primeiro snapshot executável público do Threeebs :3.
- Aplicações Portal, Admin, Sandbox e Host com instalação via Docker Compose.
- Design System Threeebs compartilhado entre Portal e Admin.
- Navegação responsiva e alternância de tema.
- Landing pública e cadastro de interessados.
- Migration `014_create_interessados.sql`.

### Changed

- Interface e navegação do Portal e do Admin atualizadas.
- Bootstrap compartilhado e inicialização do MySQL atualizados para a nova versão.

### Known limitations

- Os logotipos PNG oficiais ainda não acompanham este snapshot.
- Não existe execução de código arbitrário, terminal ou upload arbitrário no Sandbox.
- O projeto permanece em fase Alpha e pode receber mudanças incompatíveis.

## [0.1.0-alpha.1] - 2026-09-02

### Added

- Fundação documental inicial do repositório público.
- Primeiro snapshot operacional Alpha.
