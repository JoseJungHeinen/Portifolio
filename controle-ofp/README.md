# Controle de OFP

> Versão para portfólio. Nomes, hosts, bancos e credenciais são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real foi incluído; configure o seu `.env` antes de rodar.

Operações de OFP do estoque de peças, com visão por perfil (gerente, comprador, financeiro e direção).

- PHP 8.1+ em MVC próprio, MySQL, migrations em `database/migrations/`.
- Regras de prazo e crédito baseadas no manual do fabricante e nas planilhas de controle.
- Testes em `tests/`.

## Como rodar (desenvolvimento)

```bash
composer install
cp .env.example .env        # ajuste banco e e-mail
# aplique os arquivos de database/migrations/ em ordem no seu MySQL/MariaDB
php -S localhost:8000 router.php
```

