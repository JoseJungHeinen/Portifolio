# Portal de Veículos

> Versão para portfólio. Nomes, hosts, bancos e credenciais são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real foi incluído; configure o seu `.env` antes de rodar.

Portal usado pelos vendedores: catálogo de acessórios com filtros, orçamentos e documentos em PDF (transferência, emplacamento, cartão), links úteis, ramais, políticas comerciais e treinamentos.

- PHP + MySQL (mysqli), geração de PDF com TCPDF (instale a biblioteca; não foi incluída).
- Menu configurável e ordenável por usuário.
- Os módulos de BI e a automação de rede não foram incluídos nesta versão.

## Como rodar

```bash
# instale o TCPDF em ./tcpdf, crie o banco (criar_tabelas.sql) e ajuste config_database.php
php -S localhost:8000
```

