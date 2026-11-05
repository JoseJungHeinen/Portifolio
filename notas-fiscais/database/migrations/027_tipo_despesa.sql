-- 027_tipo_despesa.sql
-- Tipo de Despesa por processo — eixo de classificação do gasto, independente do
-- fornecedor (mesmo CNPJ pode vender internet e telefone) e do centro de custo
-- (que diz QUEM consome, não O QUE foi comprado).
--
-- Espelha a estrutura do rateio: N tipos por nota, com percentual e valor.
-- REGRA DE OURO para relatórios: somar `valor` desta tabela, NUNCA nf_processos.valor_nf
-- — senão nota com 2 tipos é contada em dobro.

-- ── 1. Lista de referência "Tipos de despesa" ────────────────────────────
INSERT INTO nf_listas (id, nome, slug, tipo, descricao, ativo, criado_em)
SELECT '00000000-0000-4000-8000-0000000000d5',
       'Tipos de despesa',
       'tipos-despesa',
       'personalizada',
       'Natureza do gasto — usada no bloco Tipo de Despesa do processo e nos relatórios de acompanhamento',
       1,
       NOW()
WHERE NOT EXISTS (SELECT 1 FROM nf_listas WHERE id = '00000000-0000-4000-8000-0000000000d5');

INSERT INTO nf_lista_itens (id, lista_id, valor, dados, ativo, criado_em)
SELECT * FROM (
    SELECT '00000000-0000-4000-8000-00000000d501' AS id, '00000000-0000-4000-8000-0000000000d5' AS lista_id, 'Internet' AS valor, '' AS dados, 1 AS ativo, NOW() AS criado_em
    UNION ALL SELECT '00000000-0000-4000-8000-00000000d502', '00000000-0000-4000-8000-0000000000d5', 'Telefone', '', 1, NOW()
    UNION ALL SELECT '00000000-0000-4000-8000-00000000d503', '00000000-0000-4000-8000-0000000000d5', 'Apollo',   '', 1, NOW()
) AS novos
WHERE NOT EXISTS (
    SELECT 1 FROM nf_lista_itens WHERE lista_id = '00000000-0000-4000-8000-0000000000d5'
);

-- ── 2. Tabela de tipos por processo (espelha nf_processo_rateios) ────────
CREATE TABLE IF NOT EXISTS `nf_processo_tipos_despesa` (
  `id`          char(36)       NOT NULL,
  `processo_id` char(36)       NOT NULL,
  `tipo_id`     char(36)       NOT NULL COMMENT 'FK suave → nf_lista_itens.id (lista Tipos de despesa)',
  `tipo_nome`   varchar(100)   NOT NULL COMMENT 'Denormalizado — imutável no histórico',
  `percentual`  decimal(7,3)   NOT NULL COMMENT 'Percentual do valor da NF (soma = 100)',
  `valor`       decimal(15,2)  NOT NULL COMMENT 'Valor absoluto (soma = valor da NF)',
  `criado_em`   timestamp      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_nftd_processo` (`processo_id`),
  KEY `idx_nftd_tipo` (`tipo_id`),
  CONSTRAINT `fk_nftd_processo` FOREIGN KEY (`processo_id`)
      REFERENCES `nf_processos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Classificação do gasto da NF por tipo de despesa (N por processo, com rateio)';

-- ── 3. Flags na versão do template (espelham tem_rateio) ─────────────────
ALTER TABLE `nf_template_versoes`
  ADD COLUMN `tem_tipo_despesa` tinyint(1) NOT NULL DEFAULT 0
      COMMENT 'Exibe o bloco Tipo de Despesa no step2' AFTER `tem_rateio`,
  ADD COLUMN `tipo_despesa_obrigatorio` tinyint(1) NOT NULL DEFAULT 0
      COMMENT 'Exige ao menos um tipo de despesa para abrir o processo' AFTER `tem_tipo_despesa`;

-- ── 4. Ligar nos templates que já usam rateio ────────────────────────────
-- Decisão do usuário (21/07/2026): começar pelos 4 templates com rateio.
-- Não obrigatório de saída — evita travar quem lança antes dos gestores
-- fecharem a lista de tipos.
UPDATE `nf_template_versoes`
   SET `tem_tipo_despesa` = 1
 WHERE `tem_rateio` = 1;
