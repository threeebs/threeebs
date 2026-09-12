# Requisitos

Antes de instalar, separe os acessos e recursos abaixo.

## Checklist

| Item | Necessário | Observação |
| --- | --- | --- |
| Domínio | Sim, para publicação | Deve usar DNS gerenciado pelo Cloudflare |
| Conta Cloudflare | Sim, para o túnel | O domínio precisa estar ativo na conta |
| Servidor Linux | Sim | Use uma distribuição suportada pelo Docker Engine |
| Docker Engine | Sim | Executa os containers |
| Docker Compose v2 | Sim | Use o comando `docker compose` |
| Git e Bash | Sim | Clone, instalação e atualização |
| OpenSSL | Sim | Geração de segredos |
| `systemd` e `sudo` | Opcional | Necessários para o operador automático de ambientes |
| Acesso SSH | Recomendado | Restrinja por chave e origem sempre que possível |

## Domínio recomendado

Prefira um domínio curto, neutro, fácil de ouvir e digitar. Evite acentos, hífens, números e palavras de infraestrutura como `vps`, `server` ou `interno`.

Uma estrutura amigável para os clientes é:

~~~text
painel.seudominio.com
admin.seudominio.com
editor.seudominio.com
cliente.seudominio.com
~~~

Se a empresa já possui um domínio institucional e usa e-mail nele, considere um segundo domínio curto para entrega dos projetos. Isso reduz o acoplamento entre site institucional, e-mail e infraestrutura de clientes.

Exemplos conceituais, sem indicação de disponibilidade:

- `cliente.seusites.com`;
- `cliente.webbase.com.br`;
- `cliente.3eb.site`.

## Servidor

Como ponto de partida para uma instalação pequena, considere:

| Perfil | CPU | Memória | Armazenamento |
| --- | ---: | ---: | ---: |
| Avaliação e poucos projetos | 2 vCPU | 4 GB | 40 GB SSD |
| Operação com vários clientes | 4 vCPU | 8 GB ou mais | 80 GB SSD ou mais |

Esses valores são uma estimativa inicial, não um mínimo garantido. PHP, banco de dados, arquivos enviados, backups, logs e quantidade de acessos alteram o consumo. Meça a instância real e aumente recursos antes de atingir os limites.

Planeje também:

- backups fora da VPS;
- monitoramento de disco e memória;
- atualizações de segurança do sistema;
- uma conta administrativa separada;
- política de retenção para logs e uploads;
- janela de manutenção para atualizações.

## Rede

O Cloudflare Tunnel funciona com conexões iniciadas pelo servidor. Não é necessário abrir as portas `6010–6017` para a internet. Se o firewall de saída for restritivo, permita a comunicação do `cloudflared` com o Cloudflare na porta `7844`.

Veja o [mapa de portas e segurança](05-portas-e-seguranca.md) antes de alterar o firewall.

## Referências oficiais

- [Instalar Docker Engine](https://docs.docker.com/engine/install/)
- [Instalar o plugin Docker Compose](https://docs.docker.com/compose/install/linux/)
- [Pré-requisitos de um túnel gerenciado localmente](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/do-more-with-tunnels/local-management/create-local-tunnel/)

[Próximo: domínio e subdomínios](02-dominio-e-subdominios.md)
