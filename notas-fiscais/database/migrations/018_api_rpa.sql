-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 018 — Configurações da API do RPA (lançamento de notas no ERP)
--
-- O RPA consulta as notas paradas na etapa dele (etapa do processo atribuída
-- ao usuário robô), baixa o XML via api/nfe.php, lança no ERP e confirma o
-- lançamento via api/rpa.php — o que aprova a etapa e o fluxo segue.
--
-- Executar: mysql -u notas_app -p Notas < database/migrations/018_api_rpa.sql
-- ═══════════════════════════════════════════════════════════════════════════

INSERT IGNORE INTO `nf_configuracoes` (`chave`, `valor`, `grupo`) VALUES
  ('api.rpa_key',           '', 'api'),
  ('api.rpa_allow_ips',     '', 'api'),
  ('api.rpa_usuario_email', '', 'api');
