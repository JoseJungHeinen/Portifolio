# Sistema de Compras e Almoxarifado

> Versão para portfólio. Nomes, hosts, bancos e credenciais são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real foi incluído; configure o seu `.env` antes de rodar.

Fluxo completo de compras: requisição, cotação com fornecedores, comparativo de preços, aprovação por alçada, ordem de compra em PDF e estoque por filial (entradas, saídas e transferências). Perfis com permissões editáveis e dashboard personalizável por usuário.

- PHP 8.1+ em MVC próprio (`src/Controllers`, `src/Models`, `src/Services`, `views/`), `router.php` para o servidor embutido.
- MySQL/MariaDB com dezenas de migrations em `database/migrations/`.
- PHPMailer para e-mails de cotação, `vlucas/phpdotenv` para configuração.
- Scripts de rotina em `cli/`.

## Como rodar (desenvolvimento)

```bash
composer install
cp .env.example .env        # ajuste banco e e-mail
# aplique os arquivos de database/migrations/ em ordem no seu MySQL/MariaDB
php -S localhost:8000 router.php
```

