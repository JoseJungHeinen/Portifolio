-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 037 — Etapa de Observação
--
-- Terceiro atributo de comportamento do catálogo de etapas (nf_etapas), ao lado
-- de `encerra_automaticamente` e `visualizacao_restrita`.
--
-- Etapa marcada como observação dá VISIBILIDADE ao responsável (usuário ou
-- grupo) sem nunca pedir ação dele: o processo aparece em Processos, não aparece
-- em Minhas Tarefas, e o fluxo passa direto por ela.
--
-- Como funciona, sem coluna nova em nf_processo_etapas: a etapa é criada já com
-- status 'pulado' (valor que o ENUM sempre teve e ninguém usava) e com o
-- aprovador preenchido normalmente.
--
--   · visibilidade  — Process::isAprovadorDeAlgumaEtapa() olha QUALQUER etapa,
--                     independente de status, então o responsável enxerga o
--                     processo desde a abertura;
--   · Minhas Tarefas — Task::listar() exige status 'pendente' NA etapa atual,
--                     então a etapa de observação nunca vira tarefa;
--   · fluxo         — Task::aprovarEtapa() procura a próxima etapa 'pendente',
--                     e por isso já pula sozinha. Os demais caminhos
--                     (Process::aprovar em cadeia, devolução para trás) foram
--                     ajustados para não parar nem reabrir uma etapa 'pulado'.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/037_etapa_observacao.sql
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE nf_etapas
  ADD COLUMN `somente_observacao` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Etapa de observacao - da visibilidade ao responsavel sem exigir acao'
    AFTER `visualizacao_restrita`;
