-- Coletor pela API do PCSDetran (substitui o robô RPA).

ALTER TABLE multas
  ADD COLUMN cnpj_detran VARCHAR(14) NULL COMMENT 'CNPJ em cuja consulta a multa apareceu' AFTER loja_id,
  ADD COLUMN pontuacao TINYINT NULL AFTER valor;

ALTER TABLE capturas_detran
  MODIFY origem ENUM('robo_excel','portal','planilha','api') NOT NULL,
  ADD COLUMN empresas JSON NULL COMMENT '[{cnpj, ok, total, erro}] da coleta pela API' AFTER lojas_cobertas;

CREATE TABLE IF NOT EXISTS configuracoes (
  chave VARCHAR(60) PRIMARY KEY,
  valor TEXT NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CNPJs consultados pelo coletor → loja usada quando a placa não está no cadastro da frota.
INSERT IGNORE INTO configuracoes (chave, valor) VALUES
  ('coletor_cnpjs', '[{"cnpj":"11222333000181","loja":"Cidade A"},{"cnpj":"11222333000181","loja":""},{"cnpj":"11222333000181","loja":""},{"cnpj":"11222333000181","loja":""}]');
