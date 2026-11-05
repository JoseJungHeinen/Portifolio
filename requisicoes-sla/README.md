# Requisições com SLA

> Versão para portfólio. Nomes, hosts, bancos e credenciais são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real foi incluído; configure o seu `.env` antes de rodar.

Sistema só de compras: Solicitação → Requisição → Aprovação → Cotação → Ordem de compra → NF → entrega, sem estoque.

- Urgência por cor com prazo de resposta (emergencial, urgente, planejado, estratégico).
- Importação da lista de fornecedores por planilha e campos do vencedor/parcelas puxados da cotação.
- Mesma base técnica do sistema de Compras (PHP 8.1+, MVC próprio, MySQL, PHPMailer).

## Como rodar (desenvolvimento)

```bash
composer install
cp .env.example .env        # ajuste banco e e-mail
# aplique os arquivos de database/migrations/ em ordem no seu MySQL/MariaDB
php -S localhost:8000 router.php
```

