-- ════════════════════════════════════════════════════════════════════
-- 050 — Fiscal: situação cadastral do fornecedor (Ativa / Simples / MEI)
--
-- POR QUE
--   A tela Fiscal lista o emitente do XML, que nem sempre é um fornecedor
--   cadastrado. Para saber se a empresa está ativa e se é Simples ou MEI,
--   o sistema já tem duas coisas prontas:
--     1. as colunas `situacao_cadastral`, `optante_simples` e `optante_mei`
--        na tabela `fornecedores` (compartilhada com o compras);
--     2. a consulta ao cnpj.ws, com trava global de 20s.
--
-- O QUE MUDA
--   nf_fiscal_cnpj — cache SÓ para os CNPJ que a tela Fiscal encontrou nos
--     XML e que NÃO existem em `fornecedores`. São emitentes de nota, não
--     necessariamente fornecedores do grupo: criar um cadastro para cada um
--     poluiria a tabela compartilhada com o sistema de compras.
--
--     Quem ESTÁ em `fornecedores` continua sendo atualizado lá mesmo — é o
--     que a consulta da tela de Fornecedores já faz hoje. A tela Fiscal lê
--     as duas fontes, com o cadastro tendo precedência.
--
--   `consultado_em` + `erro` existem para a consulta não ficar repetindo
--   eternamente um CNPJ que a Receita não devolve (baixado antigo, inválido
--   no XML). Com 20s por consulta, repetir à toa custa caro.
--
-- Idempotente: rodar duas vezes não quebra.
-- ════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `nf_fiscal_cnpj` (
  `cnpj`               char(14)     NOT NULL COMMENT 'Só dígitos — mesmo formato de fornecedores.cnpj',
  `razao_social`       varchar(200) DEFAULT NULL,
  `situacao_cadastral` varchar(30)  DEFAULT NULL COMMENT 'Ativa, Baixada, Suspensa, Inapta...',
  `optante_simples`    tinyint(1)   DEFAULT NULL COMMENT '1=sim, 0=nao, NULL=desconhecido',
  `optante_mei`        tinyint(1)   DEFAULT NULL COMMENT '1=sim, 0=nao, NULL=desconhecido',
  `consultado_em`      datetime     DEFAULT NULL COMMENT 'Última tentativa — sucesso ou erro',
  `erro`               varchar(160) DEFAULT NULL COMMENT 'Motivo da última falha. NULL = consulta ok',
  PRIMARY KEY (`cnpj`),
  KEY `idx_nffc_situacao` (`situacao_cadastral`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Situacao na Receita dos emitentes que NAO estao em fornecedores';

-- ── Conferência ──────────────────────────────────────────────────────
SELECT
  (SELECT COUNT(*) FROM nf_fiscal_cnpj)                                   AS cache_cnpj,
  (SELECT COUNT(*) FROM fornecedores
    WHERE situacao_cadastral IS NOT NULL AND situacao_cadastral <> '')     AS cadastro_com_situacao;
