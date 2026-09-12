# Visão geral

Hoje você pode comprar um pequeno pedaço de terra na internet.

Você contrata uma VPS, registra um domínio e passa a ter um espaço onde pode construir projetos, hospedar aplicações e oferecer serviços. Assim como em um terreno, esse espaço pode ser dividido em pequenos lotes para diferentes clientes.

O Threeebs organiza:

- clientes e usuários;
- projetos e ambientes;
- endereços e rotas;
- serviços e infraestrutura.

~~~mermaid
flowchart TD
    V[VPS] --> T[Threeebs]
    T --> C1[Cliente A]
    T --> C2[Cliente B]
    C1 --> P1[Projeto e ambiente]
    C2 --> P2[Projeto e ambiente]
~~~

## Como um endereço encontra um projeto

Imagine o domínio `seudominio.com` e o projeto `loja-maria`. O endereço público pode ser:

~~~text
loja-maria.seudominio.com
~~~

Quando alguém acessa esse endereço, o Host identifica a rota e entrega o ambiente correspondente.

~~~mermaid
flowchart LR
    A[Endereço] --> B[Host]
    B --> C[(Rotas web)]
    C --> D[Ambiente]
    D --> E[Projeto]
~~~

A estrutura central é:

> Clientes possuem usuários e projetos. Projetos possuem ambientes. Ambientes possuem endereços e serviços. O Host recebe um endereço e descobre qual ambiente deve servir.

## Domínio próprio do cliente

O mesmo projeto também pode responder em `cliente.com`. O domínio personalizado é encaminhado pelo Cloudflare ao Host, e o Threeebs continua entregando o mesmo ambiente de produção.

~~~mermaid
flowchart TD
    S[loja-maria.seudominio.com] --> H[Host Threeebs]
    D[cliente.com] --> H
    H --> P[Projeto Maria / Production]
~~~

O endereço muda; o projeto e sua operação permanecem centralizados.

## Produto e recorrência

O cliente geralmente quer o resultado, não a infraestrutura. Uma oferta pode combinar site ou sistema, hospedagem, manutenção, suporte e automações. O Threeebs fornece a base técnica para organizar essa relação recorrente.

[Próximo: requisitos](01-requisitos.md)
