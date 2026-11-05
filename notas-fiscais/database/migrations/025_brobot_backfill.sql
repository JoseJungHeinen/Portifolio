-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 025 — Backfill histórico do Brobot por filial
--
-- Problema: o poller (BrobotSync) só anda para frente a partir de um cursor
-- global (brobot.cursor_issued_at). Ao LIGAR uma filial nova, as notas dela
-- que já estavam no Brobot ficam ATRÁS do cursor e nunca seriam capturadas —
-- o sync já as viu (destino de filial então desligada) e passou o cursor.
--
-- Solução: ao ligar a integração de uma filial, enfileira-se um "backfill":
-- uma varredura da janela [desde, ate] importando SÓ as notas daquela filial
-- (dedup por chave protege contra duplicar). O cursor incremental segue
-- intacto cobrindo [ate, ∞). Depois do backfill, tudo volta ao fluxo normal.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/025_brobot_backfill.sql
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `nf_brobot_backfill` (
  `id`              char(36)     NOT NULL,
  `filial_id`       char(36)     NOT NULL,
  `cnpj`            char(14)     NOT NULL COMMENT 'CNPJ destino filtrado no backfill (só digitos)',
  `desde`           datetime     NOT NULL COMMENT 'Início da janela varrida no Brobot',
  `ate`             datetime     NOT NULL COMMENT 'Fim da janela (momento em que a filial foi ligada)',
  `cursor_ate`      datetime     DEFAULT NULL COMMENT 'Progresso: created_at do último doc varrido',
  `status`          enum('pendente','rodando','concluido','erro') NOT NULL DEFAULT 'pendente',
  `docs_importadas` int(10) unsigned NOT NULL DEFAULT 0,
  `docs_varridos`   int(10) unsigned NOT NULL DEFAULT 0,
  `detalhe`         varchar(500) DEFAULT NULL,
  `criado_em`       timestamp    NOT NULL DEFAULT current_timestamp(),
  `atualizado_em`   timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_bbf_status` (`status`),
  KEY `idx_bbf_filial` (`filial_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Backfill histórico do Brobot por filial (disparado ao ligar a integração)';

-- Janela padrão (em dias) que o backfill varre ao ligar uma filial.
INSERT IGNORE INTO `nf_configuracoes` (`chave`, `valor`, `grupo`) VALUES
  ('brobot.backfill_ligar_dias', '90', 'brobot');
