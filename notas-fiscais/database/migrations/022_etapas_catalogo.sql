-- 022_etapas_catalogo.sql
-- Catálogo de Etapas (aba Etapas em Listas) + suporte a etapa de arquivamento.
--
--  • nf_etapas: etapas padronizadas criadas ANTES de usar nos templates.
--    Atributos herdados por todos os fluxos que usam a etapa:
--      - encerra_automaticamente: a etapa se conclui sozinha ao ser atingida
--        (etapa "Arquivo" — processo recebe os dados e arquiva no vínculo);
--      - sla_horas: SLA padrão — vale para todos os templates que usam a etapa
--        (sobrepõe o SLA definido no template);
--      - tipo_aprovador: o que a etapa aceita (grupo | usuario | ambos);
--      - visualizacao_restrita: enquanto o processo está nesta etapa, apenas
--        os aprovadores da etapa (e admins) podem visualizá-lo.
--  • nf_processo_etapas: passa a aceitar etapa sem aprovador (arquivamento)
--    e guarda a referência ao catálogo (etapa_catalogo_id).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS nf_etapas (
  id                      CHAR(36)      NOT NULL,
  nome                    VARCHAR(100)  NOT NULL,
  descricao               VARCHAR(255)  NULL,
  encerra_automaticamente TINYINT(1)    NOT NULL DEFAULT 0,
  sla_horas               DECIMAL(8,2)  NULL,
  tipo_aprovador          ENUM('grupo','usuario','ambos') NOT NULL DEFAULT 'ambos',
  visualizacao_restrita   TINYINT(1)    NOT NULL DEFAULT 0,
  ativo                   TINYINT(1)    NOT NULL DEFAULT 1,
  criado_por              CHAR(36)      NULL,
  criado_em               TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nf_etapas_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Etapas de processo: arquivamento não tem aprovador
ALTER TABLE nf_processo_etapas
  MODIFY tipo_aprovador ENUM('grupo','usuario','arquivo') NOT NULL,
  MODIFY aprovador_id CHAR(36) NULL,
  ADD COLUMN etapa_catalogo_id CHAR(36) NULL AFTER nome_etapa,
  ADD KEY idx_pe_etapa_catalogo (etapa_catalogo_id);

-- Seeds: etapa terminal "Arquivo" + etapa obrigatória do financeiro
INSERT INTO nf_etapas (id, nome, descricao, encerra_automaticamente, tipo_aprovador)
VALUES (UUID(), 'Arquivo',
        'Etapa terminal: o processo recebe os dados da nota e é arquivado automaticamente, registrando quem executou o vínculo.',
        1, 'ambos')
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), encerra_automaticamente = 1;

INSERT INTO nf_etapas (id, nome, descricao, tipo_aprovador)
VALUES (UUID(), 'Aprovação Financeiro',
        'Etapa obrigatória dos templates com controle financeiro habilitado.',
        'ambos')
ON DUPLICATE KEY UPDATE nome = nome;

-- Importa para o catálogo os nomes de etapa já usados em processos existentes
-- (padroniza sem quebrar os fluxos legados — atributos ficam nos padrões)
INSERT IGNORE INTO nf_etapas (id, nome, tipo_aprovador)
SELECT UUID(), x.nome_etapa, 'ambos'
FROM (SELECT DISTINCT nome_etapa FROM nf_processo_etapas
      WHERE nome_etapa IS NOT NULL AND nome_etapa <> '') x;
