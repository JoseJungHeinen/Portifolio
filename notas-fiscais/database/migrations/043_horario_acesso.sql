-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 043 — Controle de horário de acesso
--
-- Limita o acesso ao sistema à jornada de cada pessoa. Desenho completo e as
-- 10 decisões em docs/IDEIA-CONTROLE-HORARIO-ACESSO.md.
--
-- Modelo: horário é CADASTRO REUTILIZÁVEL, não campo por pessoa nem por perfil.
-- Perfil é o eixo de permissão — se o horário fosse propriedade dele, dar
-- jornada diferente a alguém obrigaria a mexer nas permissões dessa pessoa.
--
-- NADA é pendurado na tabela `usuarios`: ela é do sistema maior (compras), e o
-- padrão do módulo é prefixo `nf_`. Em 40+ migrations só a 030 mexeu nela.
-- Daí a tabela de ligação nf_usuario_horario.
--
-- Ausência de linha em nf_usuario_horario = SEM LIMITE. É o caso dos Diretores:
-- ausência de regra, e não uma regra "24h" que alguém possa editar por engano.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/043_horario_acesso.sql
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `nf_horarios_acesso` (
  `id`                  char(36)     NOT NULL,
  `nome`                varchar(60)  NOT NULL,
  `descricao`           varchar(255)     NULL,
  `tolerancia_minutos`  smallint(5) unsigned NOT NULL DEFAULT 0
      COMMENT 'Minutos de folga depois do fim do turno - evita perder formulario preenchido',
  `ativo`               tinyint(1)   NOT NULL DEFAULT 1,
  `criado_em`           timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em`       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_horario_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma linha por dia da semana. dia_semana segue o date('w') do PHP: 0=domingo.
-- Turno com hora NULL = fechado. Dia sem faixa nenhuma = fechado (não é
-- "não configurado" — sábado e domingo fechados são o caso comum).
CREATE TABLE IF NOT EXISTS `nf_horario_faixas` (
  `horario_id`  char(36)   NOT NULL,
  `dia_semana`  tinyint(1) NOT NULL COMMENT '0=dom 1=seg 2=ter 3=qua 4=qui 5=sex 6=sab',
  `t1_inicio`   time           NULL,
  `t1_fim`      time           NULL,
  `t2_inicio`   time           NULL,
  `t2_fim`      time           NULL,
  PRIMARY KEY (`horario_id`, `dia_semana`),
  CONSTRAINT `fk_faixa_horario` FOREIGN KEY (`horario_id`)
    REFERENCES `nf_horarios_acesso` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nf_usuario_horario` (
  `usuario_id`  char(36) NOT NULL,
  `horario_id`  char(36) NOT NULL,
  `criado_em`   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `criado_por`  char(36)     NULL,
  PRIMARY KEY (`usuario_id`),
  KEY `idx_uh_horario` (`horario_id`),
  CONSTRAINT `fk_uh_horario` FOREIGN KEY (`horario_id`)
    REFERENCES `nf_horarios_acesso` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Só entra aqui o que JÁ foi aprovado. A tramitação da solicitação acontece no
-- motor de processos (template "Solicitação de Acesso Fora do Horário"), e o
-- processo_id guarda a origem — quem pediu, quem aprovou e quando já vivem no
-- histórico do processo, não se duplica nada.
CREATE TABLE IF NOT EXISTS `nf_horario_excecoes` (
  `id`           char(36) NOT NULL,
  `usuario_id`   char(36) NOT NULL,
  `data`         date     NOT NULL,
  `hora_inicio`  time     NOT NULL,
  `hora_fim`     time     NOT NULL,
  `processo_id`  char(36)     NULL COMMENT 'Processo que autorizou - NULL so em liberacao manual',
  `criado_em`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exc_usuario_data` (`usuario_id`, `data`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liga o template de solicitação à gravação automática da exceção. Mesmo lugar
-- de tem_financeiro / tem_rateio / permite_sem_nota (precedente: migration 039).
ALTER TABLE `nf_template_versoes`
  ADD COLUMN `libera_excecao_horario` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Fluxo de solicitacao de acesso - ao aprovar, grava a excecao de horario'
    AFTER `permite_sem_nota`;
