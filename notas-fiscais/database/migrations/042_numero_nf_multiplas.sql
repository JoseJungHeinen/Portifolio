-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 042 — Número da NF com várias notas no mesmo processo
--
-- `nf_processos.numero_nf` era VARCHAR(50), tamanho pensado para UM número.
-- Na prática o solicitante abre um processo cobrindo um bordero inteiro e
-- digita a lista: "187/188/189/190/194/191/192/..." — passa fácil de 50
-- caracteres e o INSERT morre com
--
--   SQLSTATE[22001]: String data, right truncated: 1406 Data too long for
--   column 'numero_nf' at row 1
--
-- e a tela devolve "Erro ao criar processo" depois de o usuário preencher o
-- wizard inteiro. Ocorrido em 12/08/2026 (Bordero - Locadora, 22 notas).
--
-- VARCHAR(255) cobre ~40 números separados por barra. O campo continua NULL
-- quando sem_nota = 1 (migration 039). A checagem de duplicidade
-- (Process::buscarPorNumeroNf) compara a string inteira — dois borderos com
-- listas diferentes seguem sendo processos distintos, como já eram.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/042_numero_nf_multiplas.sql
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE nf_processos
  MODIFY COLUMN `numero_nf` VARCHAR(255) NULL
    COMMENT 'Numero da NF - aceita lista quando o processo cobre varias notas. NULL somente quando sem_nota = 1';
