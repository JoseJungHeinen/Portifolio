# Portal Único

Portal de acesso único (SSO) para vários sistemas internos feitos em PHP/MySQL. O usuário entra uma vez, vê um card por sistema e abre cada um sem novo login.

> Os valores de `config/sistemas/*.json` são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_...`). Troque pelos seus antes de rodar.

## O que faz

- **Login federado:** valida e-mail e senha no cadastro de cada sistema (tabela e coluna de hash definidas no manifesto).
- **SSO:** ao clicar no card, entra direto. Há três modos: formulário automático (POST), token no `localStorage` e link direto, para sistemas em origens diferentes.
- **Manifestos:** cada sistema é um arquivo JSON em `config/sistemas/`. Adicionar um sistema é criar um manifesto.
- **Ponte HTTP:** para bancos que não aceitam conexão remota, um `portal_ponte.php` no servidor do sistema executa a consulta localmente, protegido por token e IP.
- **Administração:** usuários, quais sistemas cada um vê, permissões por tela e cadastro manual de sistemas.
- **Preferências:** cada usuário arrasta os cards e o portal lembra a ordem.

## Estrutura

```
index.php, login.php, logout.php, sso.php   telas e fluxo de SSO
admin/                                      administração de usuários e sistemas
includes/                                   login federado, conexão, ponte, permissões
config/sistemas/                            um manifesto JSON por sistema (exemplos)
docs/portal_ponte_template.php              modelo da ponte (copiar para o servidor do sistema)
docs/deploy/notas_sso_bridge.php            exemplo de ponte de SSO entre origens diferentes
assets/css/design-system.css                tokens e componentes visuais
tests/                                      testes em PHP puro
```

## Como configurar

1. Edite os manifestos em `config/sistemas/` com os hosts, bancos e colunas reais.
2. Nunca versione senhas e tokens reais. Use arquivos `*.local.json` (já ignorados pelo `.gitignore`) ou variáveis do servidor.
3. Para sistemas com ponte, copie `docs/portal_ponte_template.php` para o servidor do sistema, gere um token próprio e longo e informe o IP do portal.

## Testes

```bash
php tests/test_preferencias_usuario.php
php tests/test_federated_auth.php
```

## Segurança

- Quem tem o token da ponte executa SQL no banco daquele sistema: trate-o como senha de banco.
- Os sistemas integrados guardam o hash das senhas (bcrypt) no próprio banco; o portal não armazena senhas.
