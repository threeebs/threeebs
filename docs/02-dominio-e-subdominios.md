# Domínio e subdomínios

Use uma árvore previsível. Isso facilita suporte, certificados, regras de acesso e comunicação com o cliente.

## Padrão recomendado

| Endereço | Destino | Público |
| --- | --- | --- |
| `painel.seudominio.com` | Portal | Sim |
| `admin.seudominio.com` | Administração | Somente equipe; proteja com Cloudflare Access |
| `editor.seudominio.com` | Sandbox/editor | Somente usuários autorizados |
| `<cliente>.seudominio.com` | Host e ambiente do cliente | Sim |
| `cliente.com` | Host, usando domínio próprio | Sim |

~~~mermaid
flowchart TD
    D[seudominio.com] --> P[painel]
    D --> A[admin]
    D --> E[editor]
    D --> W["*.seudominio.com"]
    W --> C[Projetos de clientes]
~~~

## Como escolher o domínio

Um bom nome para subdomínios de clientes:

- é curto e fácil de soletrar;
- funciona depois do nome do cliente;
- não limita o negócio a um único tipo de projeto;
- não parece um endereço técnico;
- possui uma identidade diferente do domínio usado para e-mail corporativo, quando possível.

Teste o nome em voz alta: “seu site está em `maria.seudominio.com`”. Se a frase ficar longa ou confusa, procure uma opção mais simples.

## Variáveis de produção

O `.env.example` usa `localhost` para manter a instalação pública segura por padrão. Em produção, adapte:

~~~dotenv
BASE_DOMAIN=seudominio.com
PORTAL_URL=https://painel.seudominio.com
ADMIN_URL=https://admin.seudominio.com
SANDBOX_URL=https://editor.seudominio.com
HOST_URL=https://seudominio.com
PREVIEW_BASE_DOMAIN=seudominio.com
PUBLIC_ROUTE_SCHEME=https
BIND_ADDRESS=127.0.0.1
~~~

Mantenha `BIND_ADDRESS=127.0.0.1` quando o `cloudflared` roda no mesmo Compose. Isso evita publicar diretamente as portas locais da aplicação.

## Wildcard e ordem das rotas

O registro wildcard `*.seudominio.com` recebe os subdomínios de clientes. No arquivo de ingress do Cloudflare, coloque `painel`, `admin` e `editor` antes do wildcard; a primeira regra compatível é usada.

Um registro DNS não carrega número de porta. O caminho é:

~~~mermaid
flowchart LR
    A[HTTPS :443] --> B[Cloudflare]
    B --> C[Túnel]
    C --> D[host:80]
~~~

As portas `601x` são mapeamentos locais do Docker, não endereços para DNS.

## Domínio próprio de um cliente

Cadastre o hostname do cliente no Cloudflare e faça-o chegar ao mesmo túnel/Host. Depois, associe esse hostname ao ambiente correto no Threeebs. Confirme propriedade e autorização do domínio antes da ativação.

[Próximo: servidor e Docker](03-servidor-e-docker.md)
