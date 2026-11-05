-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 020 — Índices compostos para performance do Dashboard
--
-- Sistema vai operar com volume médio de 5.000–10.000 notas/CT-e por mês.
-- As queries do dashboard (Visão Geral, Processos, Aprovações, Financeiro,
-- Desempenho, Meus Processos) filtram quase sempre por COMBINAÇÕES de colunas
-- (ex: deletado_em + is_teste + status; processo_id + numero_etapa + status),
-- mas as tabelas só tinham índices de coluna única — o MySQL/MariaDB só
-- aproveita um índice por vez nesse cenário, then faz table scan pro resto do
-- filtro. Índices compostos alinhados aos padrões de WHERE reais do código
-- resolvem isso na raiz, sem precisar reescrever nenhuma query.
--
-- Achados mais críticos:
--   - nf_processos.criado_por NÃO TINHA ÍNDICE NENHUM, apesar de ser usado
--     em Meus Processos, filtro "Responsável" e ranking de usuários.
--   - nf_processo_etapas não tinha um composto (processo_id, numero_etapa,
--     status) — o padrão de filtro mais repetido no código inteiro, além de
--     ser usado em subqueries CORRELACIONADAS (uma execução por linha da
--     query externa) em sqlEntradaEtapaAtual().
--   - nf_processo_historico não tinha (processo_id, acao, numero_etapa) —
--     mesma situação, usado correlacionado em sqlEntradaEtapaAtual().
--
-- Todos os índices abaixo são aditivos (CREATE INDEX) — não alteram dado
-- nem comportamento, só aceleram leitura. Custo: espaço em disco + leve
-- overhead de escrita, aceitável frente ao ganho de leitura no volume
-- esperado.
--
-- Banco: compras
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/020_indices_performance_dashboard.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

-- ─── nf_processos ────────────────────────────────────────────────────────

-- Filtro base universal: praticamente toda query do dashboard começa com
-- "deletado_em IS NULL AND is_teste = 0 AND status ...".
CREATE INDEX `idx_nfp_ativo_status` ON `nf_processos` (`deletado_em`, `is_teste`, `status`);

-- Sem isso, QUALQUER filtro por "quem criou" (Meus Processos, Responsável,
-- Ranking) faz table scan completo.
CREATE INDEX `idx_nfp_criado_por` ON `nf_processos` (`criado_por`);

-- Usado no JOIN com nf_processo_etapas para achar a etapa atual do processo
-- (pe.numero_etapa = pr.etapa_atual) — repetido em quase toda query de lista.
CREATE INDEX `idx_nfp_etapa_atual` ON `nf_processos` (`etapa_atual`);

-- "Concluídos no período" filtra por concluido_em em vários KPIs.
CREATE INDEX `idx_nfp_concluido_em` ON `nf_processos` (`concluido_em`);

-- ─── nf_processo_etapas ──────────────────────────────────────────────────

-- O padrão de filtro mais repetido no código inteiro:
-- "WHERE processo_id = ? AND numero_etapa = ? AND status = ?"
CREATE INDEX `idx_nfpe_processo_numero_status` ON `nf_processo_etapas` (`processo_id`, `numero_etapa`, `status`);

-- Fila por aprovador/grupo (filaPorAprovador/filaPorGrupo) e checagem de
-- participação (isAprovadorDeAlgumaEtapa) filtram por aprovador_id + tipo.
CREATE INDEX `idx_nfpe_aprovador` ON `nf_processo_etapas` (`aprovador_id`, `tipo_aprovador`, `status`);

-- ─── nf_processo_historico ───────────────────────────────────────────────

-- sqlEntradaEtapaAtual() roda essa condição como subquery CORRELACIONADA —
-- uma execução por linha da query externa. Sem índice composto, cada
-- execução varre o histórico inteiro do processo.
CREATE INDEX `idx_nfph_processo_acao_etapa` ON `nf_processo_historico` (`processo_id`, `acao`, `numero_etapa`);

-- processosDevolvidos()/kpisAprovacoes() filtram "acao = 'processo_devolvido'
-- AND criado_em BETWEEN ? AND ?" — sem esse composto, cai no índice de
-- criado_em sozinho e ainda filtra acao linha a linha.
CREATE INDEX `idx_nfph_acao_criado` ON `nf_processo_historico` (`acao`, `criado_em`);

-- ─── nf_parcelas ─────────────────────────────────────────────────────────

-- pendentesPagamento()/kpisFinanceiro()/volumePor*() filtram
-- "is_teste = 0 AND status IN (...) AND vencimento ..." juntos.
CREATE INDEX `idx_nfpar_teste_status_venc` ON `nf_parcelas` (`is_teste`, `status`, `vencimento`);
