-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 014 — Configurações gerais administráveis (chave-valor)
-- Substitui valores antes fixos em config/*.php. Fallback: se a chave não
-- existir aqui, o código usa o default embutido.
-- ═══════════════════════════════════════════════════════════════════════════
USE `compras`;

CREATE TABLE IF NOT EXISTS `nf_configuracoes` (
  `chave`          varchar(60)  NOT NULL,
  `valor`          text         DEFAULT NULL,
  `grupo`          varchar(30)  NOT NULL DEFAULT 'geral' COMMENT 'geral | email | seguranca | notificacoes',
  `atualizado_por` char(36)     DEFAULT NULL,
  `atualizado_em`  timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configurações gerais do sistema (chave-valor) — editáveis na tela Geral';

INSERT IGNORE INTO `nf_configuracoes` (`chave`, `valor`, `grupo`) VALUES
  ('email.modo',        'log',                          'email'),
  ('email.host',        'exemplo.com',          'email'),
  ('email.porta',       '587',                          'email'),
  ('email.seguranca',   'tls',                          'email'),
  ('email.usuario',     'workflow@exemplo.com',      'email'),
  ('email.senha',       '',                             'email'),
  ('email.remetente_email', 'workflow@exemplo.com',  'email'),
  ('email.remetente_nome',  'Exemplo financeiro — Workflow NF', 'email'),
  ('email.base_url',    'http://localhost:8090',        'email'),
  ('seguranca.session_timeout_min', '30',               'seguranca'),
  ('seguranca.max_login_attempts',  '5',                'seguranca'),
  ('seguranca.lockout_min',         '15',               'seguranca'),
  ('notif.sla_hora_inicio',   '8',                      'notificacoes'),
  ('notif.sla_hora_fim',      '18',                     'notificacoes'),
  ('notif.sla_reenvio_min',   '60',                     'notificacoes'),
  ('notif.sla_primeiro_alerta_min', '5',                'notificacoes'),
  ('geral.itens_por_pagina',  '20',                     'geral');
