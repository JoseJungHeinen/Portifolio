-- Encerrar a rodada de contagem.
--
-- Idempotente e portátil: roda no MySQL da Oracle e no MariaDB. Não usa
-- ADD COLUMN IF NOT EXISTS, que é exclusivo do MariaDB.
--
-- rodada_atual = 1 significa 1ª contagem aberta. Encerrar a rodada N é
-- rodada_atual = N + 1. O DEFAULT 1 deixa todo inventário existente no estado
-- certo sem migração de dados.
--
-- cont2_liberada_em e cont2_liberada_por ficam sem uso a partir daqui. Foram
-- criadas em 21/09 e nunca receberam valor; não são removidas aqui para o
-- rollback do código continuar possível sem mexer no schema.

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios'
                AND column_name = 'rodada_atual') = 0,
            'ALTER TABLE inv_inventarios ADD COLUMN rodada_atual TINYINT NOT NULL DEFAULT 1',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios'
                AND column_name = 'rodada_encerrada_em') = 0,
            'ALTER TABLE inv_inventarios ADD COLUMN rodada_encerrada_em DATETIME NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = IF((SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios'
                AND column_name = 'rodada_encerrada_por') = 0,
            'ALTER TABLE inv_inventarios ADD COLUMN rodada_encerrada_por VARCHAR(120) NULL',
            'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Conferência: as três linhas têm que voltar com 1.
SELECT 'inv_inventarios.rodada_atual' AS objeto, COUNT(*) AS existe FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios' AND column_name = 'rodada_atual'
UNION ALL
SELECT 'inv_inventarios.rodada_encerrada_em', COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios' AND column_name = 'rodada_encerrada_em'
UNION ALL
SELECT 'inv_inventarios.rodada_encerrada_por', COUNT(*) FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'inv_inventarios' AND column_name = 'rodada_encerrada_por';
