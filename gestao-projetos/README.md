# Gestão de Projetos

> Versão para portfólio. Nomes, hosts, bancos e credenciais são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real foi incluído; configure o seu `.env` antes de rodar.

Quadro Kanban com registro de horas e cronograma em Gantt, com datas de início e fim nos projetos. O Gantt é desenhado em JavaScript puro.

- PHP (páginas em `pages/`) + Tailwind CSS.
- Migrations SQL em `migrations/`.

## Como rodar

```bash
npm install
npm run build               # gera o CSS do Tailwind
# aplique migrations/ no MySQL e ajuste config/database.php
php -S localhost:8000
```

