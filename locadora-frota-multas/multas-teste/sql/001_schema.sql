-- Módulo de multas (teste) — substitui a planilha CONTROLE DE MULTAS.
--   Controle Geral → multas | aba Detran → capturas_detran + captura_itens
--   Veículos → veiculos_frota | Configuração → orgaos_autuadores | Planilha1 → infracoes_ctb
-- O status é derivado (lib/status.php) e guardado em etapa/status só para filtrar.

CREATE TABLE IF NOT EXISTS lojas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL UNIQUE,
  cnpj VARCHAR(14) NULL COMMENT 'CNPJ usado na consulta do PCSDetran',
  ativa TINYINT(1) NOT NULL DEFAULT 1,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS veiculos_frota (
  id INT AUTO_INCREMENT PRIMARY KEY,
  placa VARCHAR(7) NOT NULL UNIQUE,
  modelo VARCHAR(120) NULL,
  renavam VARCHAR(20) NULL,
  loja_id INT NULL,
  empresa VARCHAR(40) NOT NULL DEFAULT 'EXEMPLO',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_loja (loja_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orgaos_autuadores (
  codigo VARCHAR(10) PRIMARY KEY,
  nome VARCHAR(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS infracoes_ctb (
  codigo VARCHAR(10) PRIMARY KEY,
  descricao TEXT NOT NULL,
  amparo_legal VARCHAR(60) NULL,
  infrator VARCHAR(40) NULL,
  gravidade VARCHAR(40) NULL,
  orgao_competente VARCHAR(60) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  serie VARCHAR(20) NOT NULL UNIQUE COMMENT 'Nº do auto de infração',
  placa VARCHAR(7) NOT NULL,
  veiculo_id INT NULL,
  loja_id INT NULL,
  -- dados do DETRAN
  orgao_codigo VARCHAR(10) NULL,
  autuador VARCHAR(200) NULL,
  tipo ENUM('multa','remulta') NULL,
  codigo_infracao VARCHAR(10) NULL,
  descricao TEXT NULL,
  local_infracao TEXT NULL,
  valor DECIMAL(10,2) NULL,
  data_infracao DATETIME NULL,
  data_infracao_texto VARCHAR(30) NULL,
  situacao_detran ENUM('a_vencer','vencida') NULL,
  vencimento_detran DATE NULL,
  vencimento_pendente TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'DETRAN mostra "--"',
  presente_no_detran TINYINT(1) NOT NULL DEFAULT 1,
  primeira_captura_em DATE NULL,
  ultima_captura_id INT NULL,
  saiu_do_detran_em DATE NULL,
  -- checklist (NULL = não preenchido)
  prazo_identificacao DATE NULL,
  prazo_identificacao_nota VARCHAR(60) NULL COMMENT '"Não se aplica", "recurso"... conta como preenchido',
  email_enviado VARCHAR(30) NULL,
  identificacao_condutor VARCHAR(60) NULL,
  condutor_nome VARCHAR(120) NULL,
  fatura_numero VARCHAR(30) NULL,
  pagamento_situacao VARCHAR(60) NULL,
  pagamento_data DATE NULL,
  ocorrencia_especial ENUM('fraude') NULL,
  observacoes TEXT NULL,
  -- derivados
  etapa ENUM('falta_data','falta_email','falta_identificar','falta_faturar','aguardando_vcto',
             'falta_pagar','aguardando_baixar','finalizado_detran','fraude') NOT NULL,
  status ENUM('nova','em_processo','a_faturar','aguardando_vcto','a_pagar','finalizado') NOT NULL,
  origem ENUM('planilha','captura','manual') NOT NULL,
  import_raw JSON NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_etapa (etapa),
  KEY idx_status (status),
  KEY idx_loja (loja_id),
  KEY idx_placa (placa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS capturas_detran (
  id INT AUTO_INCREMENT PRIMARY KEY,
  origem ENUM('robo_excel','portal','planilha') NOT NULL,
  arquivo VARCHAR(255) NULL,
  lojas_cobertas JSON NOT NULL COMMENT 'só multas destas lojas podem "sair do DETRAN"',
  total_lidas INT NOT NULL DEFAULT 0,
  novas INT NOT NULL DEFAULT 0,
  atualizadas INT NOT NULL DEFAULT 0,
  sairam_do_detran INT NOT NULL DEFAULT 0,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS captura_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  captura_id INT NOT NULL,
  serie VARCHAR(20) NOT NULL,
  loja VARCHAR(80) NULL,
  situacao_detran ENUM('a_vencer','vencida') NULL,
  vencimento_detran DATE NULL,
  valor DECIMAL(10,2) NULL,
  bruto JSON NULL,
  KEY idx_captura (captura_id),
  KEY idx_serie (serie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multas_historico (
  id INT AUTO_INCREMENT PRIMARY KEY,
  multa_id INT NOT NULL,
  campo VARCHAR(40) NOT NULL,
  de TEXT NULL,
  para TEXT NULL,
  origem VARCHAR(20) NOT NULL DEFAULT 'manual',
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_multa (multa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
