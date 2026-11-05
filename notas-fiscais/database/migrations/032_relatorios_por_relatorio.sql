-- ============================================================
-- 032 — Permissão de Relatórios por RELATÓRIO
--
-- O módulo `relatorios` deixa de ter só ['ver','exportar'] e passa a ter
-- uma ação por relatório (rel_fluxo_caixa, rel_pagamentos, ...). Ver
-- src/Helpers/ReportAccess.php — a lista canônica mora lá.
--
-- Acesso é tudo ou nada por relatório: quem vê também exporta. A ação
-- `exportar` some (nunca foi checada em lugar nenhum do código).
--
-- Esta migration preserva o comportamento atual: todo perfil/usuário que
-- HOJE tem relatorios.ver = true continua enxergando os 9 relatórios.
-- Quem tinha ver = false não ganha nada.
--
-- Sem ela, quem tem override gravado (nf_perfil_permissoes /
-- nf_usuario_permissoes) abriria a tela de Relatórios vazia — as chaves
-- novas não existem no JSON e a negação é por padrão.
-- ============================================================

-- ─── Mapas de PERFIL customizados ───────────────────────────
UPDATE nf_perfil_permissoes
   SET permissoes = JSON_REMOVE(
                      JSON_SET(permissoes,
                        '$.relatorios.rel_fluxo_caixa',         TRUE,
                        '$.relatorios.rel_pagamentos',          TRUE,
                        '$.relatorios.rel_contas_pagar',        TRUE,
                        '$.relatorios.rel_gastos_grupo',        TRUE,
                        '$.relatorios.rel_volume_processos',    TRUE,
                        '$.relatorios.rel_sla',                 TRUE,
                        '$.relatorios.rel_devolucoes',          TRUE,
                        '$.relatorios.rel_desempenho_usuarios', TRUE,
                        '$.relatorios.rel_meus_processos',      TRUE),
                      '$.relatorios.exportar')
 WHERE JSON_UNQUOTE(JSON_EXTRACT(permissoes, '$.relatorios.ver')) IN ('true', '1');

-- ─── Overrides granulares por USUÁRIO ───────────────────────
UPDATE nf_usuario_permissoes
   SET permissoes = JSON_REMOVE(
                      JSON_SET(permissoes,
                        '$.relatorios.rel_fluxo_caixa',         TRUE,
                        '$.relatorios.rel_pagamentos',          TRUE,
                        '$.relatorios.rel_contas_pagar',        TRUE,
                        '$.relatorios.rel_gastos_grupo',        TRUE,
                        '$.relatorios.rel_volume_processos',    TRUE,
                        '$.relatorios.rel_sla',                 TRUE,
                        '$.relatorios.rel_devolucoes',          TRUE,
                        '$.relatorios.rel_desempenho_usuarios', TRUE,
                        '$.relatorios.rel_meus_processos',      TRUE),
                      '$.relatorios.exportar')
 WHERE JSON_UNQUOTE(JSON_EXTRACT(permissoes, '$.relatorios.ver')) IN ('true', '1');

-- ─── Limpeza da ação morta em quem NÃO tem acesso ───────────
UPDATE nf_perfil_permissoes
   SET permissoes = JSON_REMOVE(permissoes, '$.relatorios.exportar')
 WHERE JSON_EXTRACT(permissoes, '$.relatorios.exportar') IS NOT NULL;

UPDATE nf_usuario_permissoes
   SET permissoes = JSON_REMOVE(permissoes, '$.relatorios.exportar')
 WHERE JSON_EXTRACT(permissoes, '$.relatorios.exportar') IS NOT NULL;
