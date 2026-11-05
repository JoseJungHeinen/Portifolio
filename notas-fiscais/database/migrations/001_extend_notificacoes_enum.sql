-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 001 — Estender ENUM da tabela `notificacoes`
--
-- Adiciona os novos tipos de notificação do módulo Workflow NF.
-- Em MariaDB, ALTER TABLE MODIFY COLUMN funciona para alterar ENUM.
--
-- Banco: compras
-- Tabela: notificacoes
-- Executar: mysql -u root compras < database/migrations/001_extend_notificacoes_enum.sql
-- ═══════════════════════════════════════════════════════════════════════════

USE `compras`;

-- Tipos existentes (não remover nenhum — apenas acrescentar os nf_*)
ALTER TABLE `notificacoes`
  MODIFY COLUMN `tipo` ENUM(
    -- Tipos existentes do sistema maior (manter sem alteração)
    'aprovacao_pendente',
    'oc_aprovada',
    'nf_divergente',
    'contrato_vencendo',
    'estoque_critico',
    'budget_critico',
    'transferencia_pendente',
    -- Novos tipos do módulo Workflow NF
    'nf_tarefa_atribuida',     -- nova tarefa na fila do usuário/grupo
    'nf_processo_devolvido',   -- processo devolvido para o solicitante
    'nf_processo_aprovado',    -- processo aprovado em todas as etapas
    'nf_uat_convite',          -- usuário convidado para teste UAT de template
    'nf_parcela_vencendo'      -- parcela com vencimento próximo (≤ 7 dias)
  ) NOT NULL;
