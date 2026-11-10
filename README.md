<div align="center">

# José Henrique Jung Heinen

**Assistente de TI · Desenvolvimento de sistemas web**

Desenvolvo os sistemas internos de uma rede de concessionárias no Rio Grande do Sul: compras, estoque, inventário, notas fiscais e portais de acesso.

[![Portfólio](https://img.shields.io/badge/Portf%C3%B3lio-ver_site-2347C8?style=for-the-badge)](https://josejungheinen.github.io/Portifolio/)
[![E-mail](https://img.shields.io/badge/E--mail-jose.heinen%40universo.univates.br-141922?style=for-the-badge)](mailto:jose.heinen@universo.univates.br)

![PHP](https://img.shields.io/badge/PHP-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-003545?style=flat-square&logo=mariadb&logoColor=white)
![Oracle](https://img.shields.io/badge/Oracle-F80000?style=flat-square&logo=oracle&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=flat-square&logo=javascript&logoColor=black)
![TypeScript](https://img.shields.io/badge/TypeScript-3178C6?style=flat-square&logo=typescript&logoColor=white)
![React](https://img.shields.io/badge/React-20232A?style=flat-square&logo=react&logoColor=61DAFB)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)
![Python](https://img.shields.io/badge/Python-3776AB?style=flat-square&logo=python&logoColor=white)
![Linux](https://img.shields.io/badge/Linux-FCC624?style=flat-square&logo=linux&logoColor=black)

</div>

---

## Sistemas

| Sistema | O que faz | Tecnologias |
|---|---|---|
| [**Compras e Almoxarifado**](compras-almoxarifado/) | Requisição, cotação com fornecedores, comparativo, aprovação por alçada, ordem de compra em PDF e estoque por filial. | PHP (MVC), MySQL, PHPMailer |
| [**Requisições com SLA**](requisicoes-sla/) | Fluxo só de compras, da solicitação à entrega, com urgência por cor e prazo de resposta. | PHP (MVC), MySQL |
| [**Inventário de peças**](inventario-pecas/) | Contagem com coletor, segunda contagem por dupla e tela de decisão para divergências, consultando o ERP. | PHP, Oracle (OCI8), MySQL |
| [**Notas fiscais**](notas-fiscais/) | Processos do financeiro: aprovação em lote, devolução, anexos e vínculo de NFS-e a um processo de NF-e. | PHP (MVC), MySQL |
| [**Portal único de acesso**](portal-unico-acesso/) | Login único (SSO) para vários sistemas, com permissões por sistema e por tela. | PHP, MySQL |
| [**Controle de OFP**](controle-ofp/) | Operações de OFP do estoque de peças, com visão para gerente, comprador, financeiro e direção. | PHP (MVC), MySQL |
| [**Portal de veículos**](portal-veiculos/) | Catálogo de acessórios, orçamentos e documentos em PDF para os vendedores. | PHP, MySQL, TCPDF |
| [**Gestão de projetos**](gestao-projetos/) | Kanban com registro de horas e cronograma em Gantt feito em JavaScript puro. | PHP, Tailwind CSS |
| [**Locadora: frota e multas**](locadora-frota-multas/) | Gestão de frota e serviços, e consulta de multas que antes rodava num robô. | TypeScript, React, PHP |
| [**Estoque por filial**](estoque-filiais/) | Primeira versão do controle de estoque, compras e fornecedores. | PHP, MySQL, Bootstrap |

Cada pasta tem um README com a estrutura e como rodar.

## Como eu trabalho

1. Levanto a necessidade com o setor e reúno o que eles já usam: planilhas, manuais e fluxogramas.
2. Escrevo ou aprovo a especificação antes do código.
3. Desenvolvo com o Claude Code em ambiente local, com banco de teste separado.
4. Testo com usuários por perfil e só então publico no servidor.
5. Verifico a data e o histórico de segurança de cada dependência antes de instalar.

## Sobre o código

Esta é uma versão preparada para portfólio. Nomes de empresa, endereços de servidor, bancos e credenciais foram substituídos por valores fictícios (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real de clientes, fornecedores ou colaboradores foi incluído.
