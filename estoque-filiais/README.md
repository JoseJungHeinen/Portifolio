# Estoque e Compras (versão inicial)

> Versão para portfólio. Nomes, hosts, bancos e credenciais são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real foi incluído; configure o seu `.env` antes de rodar.

Controle simples de estoque por filial: produtos, solicitações, compras, fornecedores e auditoria.

- PHP + MySQL, Bootstrap, sem framework.
- Esquema em `schema.sql` (e `database_v3.sql`/`update_schema.sql` para evoluções).

## Como rodar

```bash
# crie o banco e importe schema.sql
# ajuste includes/db.php
php -S localhost:8000
```

