-- Contagem 2 — recontagem guiada dos itens divergentes.
--
-- Idempotente e portátil: roda no MySQL da Oracle e no MariaDB, e pode ser
-- executado mais de uma vez sem erro. Não usa ADD COLUMN IF NOT EXISTS, que é
-- exclusivo do MariaDB — cada coluna é checada no information_schema e o ALTER
-- só é montado se ela faltar.
--
-- cont2_em existe porque o gerador_inventario.php insere cont_2 = 0 junto com a
-- linha: cont_2 sozinho não separa "ainda não recontei" de "recontei e não tem
-- nenhum", e zero é resposta legítima numa recontagem de item faltante.
--
-- cont2_entrada é o mesmo rastro da conferência: o botão de código ilegível
-- libera a digitação sem senha, e o que o torna seguro é ficar registrado.
--
-- dupla_cont2 guarda quem recontou. Em produção ela provavelmente JÁ EXISTE: o
-- contagem_gravar.php roda um ALTER equivalente dentro de um try/catch a cada
-- requisição, então ela nasce na primeira contagem gravada. Está aqui porque
-- uma coluna de que a 2ª contagem depende pertence à migração, não a um
-- try/catch em caminho de requisição.
--
-- Tudo é aditivo: nenhuma linha ou coluna existente é alterada.

-- ── inv_inventarios.cont2_liberada_em ────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios'
                AND column_name = 'cont2_liberada_em') = 0,
            'ALTER TABLE inv_inventarios ADD COLUMN cont2_liberada_em DATETIME NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── inv_inventarios.cont2_liberada_por ───────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios'
                AND column_name = 'cont2_liberada_por') = 0,
            'ALTER TABLE inv_inventarios ADD COLUMN cont2_liberada_por VARCHAR(120) NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── estoque.cont2_em ─────────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'estoque'
                AND column_name = 'cont2_em') = 0,
            'ALTER TABLE estoque ADD COLUMN cont2_em DATETIME NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── estoque.cont2_entrada ────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'estoque'
                AND column_name = 'cont2_entrada') = 0,
            "ALTER TABLE estoque ADD COLUMN cont2_entrada ENUM('bipado','digitado') NULL",
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── estoque.dupla_cont2 (provavelmente já existe em produção) ────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'estoque'
                AND column_name = 'dupla_cont2') = 0,
            'ALTER TABLE estoque ADD COLUMN dupla_cont2 VARCHAR(120) DEFAULT NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── índice da fila ───────────────────────────────────────────────────────────
SET @s = IF((SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'estoque'
                AND index_name = 'idx_estoque_cont2') = 0,
            'CREATE INDEX idx_estoque_cont2 ON estoque (id_inventario, empresa, revenda, cont2_em)',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── Conferência: as seis linhas têm que voltar com 1 ─────────────────────────
SELECT 'inv_inventarios.cont2_liberada_em' AS objeto, COUNT(*) AS existe FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios' AND column_name = 'cont2_liberada_em'
UNION ALL
SELECT 'inv_inventarios.cont2_liberada_por', COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios' AND column_name = 'cont2_liberada_por'
UNION ALL
SELECT 'estoque.cont2_em', COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'estoque' AND column_name = 'cont2_em'
UNION ALL
SELECT 'estoque.cont2_entrada', COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'estoque' AND column_name = 'cont2_entrada'
UNION ALL
SELECT 'estoque.dupla_cont2', COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'estoque' AND column_name = 'dupla_cont2'
UNION ALL
SELECT 'indice idx_estoque_cont2', COUNT(DISTINCT index_name) FROM information_schema.statistics
 WHERE table_schema = DATABASE() AND table_name = 'estoque' AND index_name = 'idx_estoque_cont2';
