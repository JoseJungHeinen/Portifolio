-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 039 — Processo sem Nota
--
-- Nem todo fluxo nasce de uma nota fiscal: adiantamento, reembolso, solicitação
-- de coleta, abertura antecipada de processo cuja NF só chega depois. Hoje a
-- Etapa 2 obriga Número da NF, Valor Total e Data da NF, e quem não tem nota
-- inventa número e data para o formulário deixar passar — sujando a base e a
-- checagem de duplicidade.
--
-- Duas peças:
--
--   nf_template_versoes.permite_sem_nota  — habilita a opção no fluxo (aba
--        Configuração do template, ao lado de tem_financeiro/tem_rateio).
--        Sem isso marcado, a abertura segue exigindo os três campos.
--
--   nf_processos.sem_nota                 — o solicitante marcou a opção neste
--        processo. É o que a tela lê para escrever "sem nota" em vez de "0,00"
--        e "01/01/1970", e o que a checagem de duplicidade usa para não tratar
--        dois processos sem nota como a mesma NF.
--
-- E os três campos passam a aceitar NULL. NULL é diferente de zero aqui:
-- valor_nf = 0,00 é uma nota de valor zero, valor_nf = NULL é "não há nota".
-- Só o processo com sem_nota = 1 grava NULL — todo o resto continua obrigatório
-- na abertura, validado no servidor.
--
-- "Emitido para" (filial_id / filial_doc) segue NOT NULL de propósito: é o CNPJ
-- que define o escopo de visibilidade por filial, os relatórios por loja e os
-- filtros do dashboard. Decisão do usuário em 05/08/2026.
--
-- Banco local: compras · Banco servidor: Notas (o USE fica a cargo do comando)
-- Executar: mysql -u root --default-character-set=utf8mb4 compras < database/migrations/039_processo_sem_nota.sql
-- ═══════════════════════════════════════════════════════════════════════════

ALTER TABLE nf_template_versoes
  ADD COLUMN `permite_sem_nota` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Fluxo aceita processo sem nota fiscal - libera a opcao na abertura'
    AFTER `tem_financeiro`;

ALTER TABLE nf_processos
  ADD COLUMN `sem_nota` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Processo aberto sem nota fiscal - numero/valor/data ficam nulos'
    AFTER `chave_nf`,
  MODIFY COLUMN `numero_nf` VARCHAR(50) NULL
    COMMENT 'NULL somente quando sem_nota = 1',
  MODIFY COLUMN `valor_nf` DECIMAL(15,2) NULL
    COMMENT 'NULL somente quando sem_nota = 1 - zero e um valor, NULL e ausencia',
  MODIFY COLUMN `data_nf` DATE NULL
    COMMENT 'NULL somente quando sem_nota = 1';
