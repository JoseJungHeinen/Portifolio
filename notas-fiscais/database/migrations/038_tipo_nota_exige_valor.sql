-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 038 — "Exige valor" no tipo de nota
--
-- Hoje o campo "Valor Total" da Etapa 1 da abertura é obrigatório para todo
-- tipo de nota. Isso não faz sentido em tipos que não têm valor a informar —
-- Cancelamento, Devolução, Remessa, Transferência — e obriga o solicitante a
-- inventar um número só para o formulário deixar passar.
--
-- A coluna é genérica em nf_lista_itens porque é lá que moram os itens de todas
-- as listas, mas só tem significado na lista `tipos-nota`. Nas demais fica no
-- default e ninguém lê.
--
-- DEFAULT 1 preserva o comportamento atual: todo tipo de nota já cadastrado
-- continua exigindo valor até alguém desmarcar em Tabelas → Tipos de nota.
--
-- Efeito na busca de template: tipo que não exige valor passa a IGNORAR a faixa
-- valor_min/valor_max do template (decisão do usuário em 05/08/2026) — sem isso,
-- valor 0 casaria só com template de faixa aberta ou iniciada em zero.
--
-- A coluna entra logo depois de `valor` porque `dados` só existe no banco do
-- servidor — a base local está atrás nesse ponto, e um AFTER `dados` quebraria
-- a migration aqui.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/038_tipo_nota_exige_valor.sql
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE nf_lista_itens
  ADD COLUMN `exige_valor` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Lista tipos-nota - 0 = abertura de processo nao exige o Valor Total'
    AFTER `valor`;
