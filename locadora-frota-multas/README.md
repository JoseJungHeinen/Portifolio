# Locadora: gestão de frota e multas

> Versão para portfólio. Nomes, hosts, bancos e credenciais são **fictícios** (`exemplo.local`, `app_user`, `TROQUE_NO_SERVIDOR`). Nenhum dado real foi incluído; configure o seu `.env` antes de rodar.

Dois módulos:

- `fleet-service-manager/`: gestão de frota e solicitações de serviço (TypeScript, React, Drizzle ORM, Vitest).
- `multas-teste/`: controle de multas em PHP que substitui planilha e robô RPA, com coletor agendado e importação de histórico.

Os dados importados (planilhas, capturas) **não** foram incluídos.

## Como rodar

```bash
cd fleet-service-manager
pnpm install
pnpm dev
```

Para o controle de multas, veja `multas-teste/README.md`.

