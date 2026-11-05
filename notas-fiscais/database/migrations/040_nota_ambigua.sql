-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 040 — Marcar nota ambígua na inbox de capturas
--
-- Nota "ambígua" é a que casou com MAIS DE UMA regra de pré-fluxo. O motor se
-- recusa a escolher (certo: virar processo pelo fluxo errado é pior que não
-- virar) e deixa a nota parada aguardando decisão humana.
--
-- O problema é que isso não ficava em lugar nenhum: CapturedNote::aplicarRegras()
-- calculava a lista de ambiguidades, devolvia no retorno, e o job de
-- classificação gravava só o TOTAL no detalhe do nf_brobot_log
-- ("0 vinculada(s), 0 sugerida(s), 12 ambígua(s)"). Na inbox a nota ficava
-- idêntica a qualquer outra "Aguardando vínculo" — sem como saber quais eram as
-- 12 nem com que regras colidiram.
--
-- Guardamos as REGRAS que colidiram, não um booleano: saber que a nota é
-- ambígua sem saber entre o que não ajuda a resolver. O selo na tela mostra
-- "AMBÍGUA" e o title lista os nomes.
--
--   NULL      = não ambígua (o normal)
--   JSON      = [{"id": "...", "nome": "..."}, ...] das regras que colidiram
--
-- Sincronizado a cada passagem do motor: deixou de ser ambígua (regra editada,
-- desativada ou excluída), a coluna volta para NULL sozinha.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/040_nota_ambigua.sql
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE nf_notas_capturadas
  ADD COLUMN `ambigua_regras` TEXT NULL
    COMMENT 'JSON das regras de pre-fluxo que colidiram - NULL = nao ambigua'
    AFTER `regra_id`;
