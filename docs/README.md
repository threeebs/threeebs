# Documentação do Threeebs :3

Este diretório reúne os artigos necessários para planejar, instalar, publicar e manter uma instância do Threeebs.

~~~mermaid
flowchart TD
    A[Planejar domínio] --> B[Preparar servidor]
    B --> C[Instalar containers]
    C --> D[Configurar túnel]
    D --> E[Validar e operar]
~~~

## Ordem recomendada

| Etapa | Artigo | Resultado esperado |
| --- | --- | --- |
| 1 | [Visão geral](00-visao-geral.md) | Entender o modelo da plataforma |
| 2 | [Requisitos](01-requisitos.md) | Separar domínio, VPS e acessos |
| 3 | [Domínio e subdomínios](02-dominio-e-subdominios.md) | Definir a árvore de endereços |
| 4 | [Servidor e Docker](03-servidor-e-docker.md) | Preparar a máquina e os containers |
| 5 | [Cloudflare Tunnel](04-cloudflare-tunnel.md) | Publicar serviços sem abrir portas da aplicação |
| 6 | [Portas e segurança](05-portas-e-seguranca.md) | Revisar rede, firewall e superfícies expostas |
| 7 | [Instalação e atualização](06-instalacao-e-atualizacao.md) | Instalar, atualizar e diagnosticar |
| 8 | [Recursos em desenvolvimento](07-recursos-em-desenvolvimento.md) | Entender o canal Edge e o que ainda não é estável |

## Arquitetura resumida

~~~mermaid
flowchart TD
    U[Usuário] --> CF[Cloudflare]
    CF --> T[cloudflared]
    T --> A[Portal / Admin / Sandbox]
    T --> H[Host]
    H --> DB[(MySQL e Redis)]
    H --> RT[Ambientes dos projetos]
~~~

O tráfego público termina no Cloudflare e segue por um túnel de saída até o container `cloudflared`. Dentro da rede Compose, os destinos são nomes de serviços, como `portal:80` e `host:80`.

> A documentação usa `seudominio.com` apenas como exemplo. Verifique disponibilidade, marca e regras do registrador antes de comprar um nome.
