-- R002_classificacao_fornecedores.sql — Exemplo Requisições (banco próprio `requisicoes`)
-- Etapa 3: classificação de fornecedores (situação, cadastro fiscal/trabalhista/contratual)
-- e avaliação periódica ponderada (prazo 20% · qualidade 20% · preço 30% · conformidade 30%).
-- Spec: docs/2026-10-30-sistema-compras-proprio.md
-- Só colunas novas com DEFAULT; a avaliação deixa de ser "uma por usuário" e passa a ser "uma por período".
-- Produção: rodar à mão ANTES do código. COLLATE/charset herdados das tabelas.

ALTER TABLE fornecedores
  ADD COLUMN situacao enum('pendente','homologado','bloqueado') NOT NULL DEFAULT 'pendente'
      COMMENT 'Bloqueado não recebe cotação nem OC' AFTER ativo,
  -- Coluna comum + triggers (abaixo): o MariaDB 10.11 de produção recusa LEFT() sobre CHAR em coluna
  -- GENERATED (erro 1901, depende de PAD_CHAR_TO_FULL_LENGTH); o 10.4 local aceitava.
  ADD COLUMN cnpj_raiz char(8) NULL COMMENT 'Agrupa matriz e filiais (preenchido por trigger)' AFTER cnpj,
  ADD COLUMN contato_fiscal varchar(150) NULL AFTER telefone,
  ADD COLUMN banco_proprio tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Conta em nome do próprio CNPJ' AFTER regime_confirmado_em,
  ADD COLUMN mei tinyint(1) NOT NULL DEFAULT 0 AFTER banco_proprio,
  ADD COLUMN mao_de_obra tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Presta serviço com mão de obra no local' AFTER mei,
  ADD COLUMN doc_trabalhista tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Documentação trabalhista/segurança entregue' AFTER mao_de_obra,
  ADD COLUMN tem_contrato tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Contrato formal ou OC anual aberta' AFTER doc_trabalhista,
  ADD COLUMN clausula_reequilibrio tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Cláusula de reequilíbrio tributário (14.1)' AFTER tem_contrato,
  ADD COLUMN curva_a tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Avaliado trimestralmente' AFTER clausula_reequilibrio,
  ADD KEY idx_forn_cnpj_raiz (cnpj_raiz),
  ADD KEY idx_forn_situacao (situacao);

CREATE TRIGGER trg_forn_cnpj_raiz_ins BEFORE INSERT ON fornecedores FOR EACH ROW SET NEW.cnpj_raiz = LEFT(NEW.cnpj, 8);
CREATE TRIGGER trg_forn_cnpj_raiz_upd BEFORE UPDATE ON fornecedores FOR EACH ROW SET NEW.cnpj_raiz = LEFT(NEW.cnpj, 8);
UPDATE fornecedores SET cnpj_raiz = LEFT(cnpj, 8);

-- Inativo vira bloqueado; os demais começam pendentes (homologar um a um).
UPDATE fornecedores SET situacao = 'bloqueado' WHERE ativo = 0;
UPDATE fornecedores SET mei = 1 WHERE regime_tributario = 'mei';

ALTER TABLE avaliacoes_fornecedor
  ADD COLUMN periodo char(7) NULL COMMENT 'Trimestre avaliado, ex.: 2026-T4' AFTER usuario_id,
  ADD COLUMN prazo decimal(3,1) NULL COMMENT '0–5, peso 20%' AFTER periodo,
  ADD COLUMN qualidade decimal(3,1) NULL COMMENT '0–5, peso 20%' AFTER prazo,
  ADD COLUMN preco decimal(3,1) NULL COMMENT '0–5, peso 30%' AFTER qualidade,
  ADD COLUMN conformidade decimal(3,1) NULL COMMENT '0–5, peso 30%' AFTER preco,
  MODIFY COLUMN nota decimal(4,2) NOT NULL COMMENT 'Nota ponderada 0–5',
  DROP INDEX uq_fornecedor_usuario,
  ADD UNIQUE KEY uq_fornecedor_periodo (fornecedor_id, periodo);

-- fornecedores.avaliacao passa a ser a nota do período mais recente (atualizada pelo código).
