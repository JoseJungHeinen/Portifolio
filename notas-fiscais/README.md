# Sistema de Notas Fiscais

> Versão para portfólio. Nomes, hosts, bancos e credenciais são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real foi incluído; configure o seu `.env` antes de rodar.

Processos de notas fiscais do financeiro: aprovação em lote com observações e anexos, devolução, vínculo de NFS-e a um processo de NF-e, manifestação do destinatário e integrações fiscais.

- PHP em MVC próprio (`src/Controllers`, `src/Models`, `src/Helpers`, `views/`).
- MySQL com dezenas de migrations em `database/migrations/`.
- Rotinas e sincronizações em `cli/`.
- Seeds e dumps com dados reais **não** foram incluídos.

## Como rodar (desenvolvimento)

```bash
cp .env.example .env        # ajuste banco e e-mail
# aplique os arquivos de database/migrations/ em ordem no seu MySQL/MariaDB
php -S localhost:8000 router.php
```

