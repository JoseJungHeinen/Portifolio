-- 059_solicitacoes_compra.sql
-- Solicitação de Compra: chamado aberto pelo solicitante, aprovado pelo
-- gestor do setor e convertido em requisição pelo comprador.
-- Spec: docs/superpowers/specs/2026-10-27-solicitacao-compra-design.md
--
-- COLLATE explícito é obrigatório (ver 045): sem ele a tabela herda
-- utf8mb4_general_ci e a FK para usuarios.id falha com errno 150.
-- Deploy NÃO envia migrations: rodar este arquivo à mão no servidor.

CREATE TABLE IF NOT EXISTS solicitacoes_compra (
  id             char(36)     NOT NULL,
  numero         int unsigned NOT NULL,
  titulo         varchar(150) NOT NULL,
  descricao      text         NOT NULL,
  motivo         text         NOT NULL,
  necessario_ate date         NOT NULL COMMENT 'Política §7: quando o item é necessário',
  finalidade     enum('revenda','insumo','uso_consumo','imobilizado','servico') NULL COMMENT 'NULL = solicitante não sabe; o comprador define na requisição',
  solicitante_id char(36)     NOT NULL,
  filial_id      char(36)     NULL,
  setor_id       char(36)     NULL,
  status         enum('aguardando_aprovacao','aprovada','recusada','em_compra','concluida','cancelada') NOT NULL,
  decidido_por   char(36)     NULL,
  decidido_em    timestamp    NULL,
  comprador_id   char(36)     NULL,
  requisicao_id  char(36)     NULL,
  criado_em      timestamp    NOT NULL DEFAULT current_timestamp(),
  atualizado_em  timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uk_sol_numero (numero),
  KEY idx_sol_solicitante (solicitante_id),
  KEY idx_sol_status (status),
  KEY idx_sol_filial_setor (filial_id, setor_id),
  KEY idx_sol_requisicao (requisicao_id),
  CONSTRAINT fk_sol_solicitante FOREIGN KEY (solicitante_id) REFERENCES usuarios(id),
  CONSTRAINT fk_sol_filial      FOREIGN KEY (filial_id)      REFERENCES filiais(id),
  CONSTRAINT fk_sol_setor       FOREIGN KEY (setor_id)       REFERENCES setores(id),
  CONSTRAINT fk_sol_decidido    FOREIGN KEY (decidido_por)   REFERENCES usuarios(id),
  CONSTRAINT fk_sol_comprador   FOREIGN KEY (comprador_id)   REFERENCES usuarios(id),
  -- SET NULL: admin pode excluir requisição (RequisicaoController::excluir)
  CONSTRAINT fk_sol_requisicao  FOREIGN KEY (requisicao_id)  REFERENCES requisicoes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitacao_compra_eventos (
  id             char(36)     NOT NULL,
  solicitacao_id char(36)     NOT NULL,
  usuario_id     char(36)     NOT NULL,
  tipo           enum('comentario','status','andamento') NOT NULL COMMENT 'andamento = etapa da compra (requisição aprovada, OC, recebimento)',
  mensagem       text         NULL,
  status_de      varchar(30)  NULL,
  status_para    varchar(30)  NULL,
  criado_em      timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_solev_sol (solicitacao_id, criado_em),
  CONSTRAINT fk_solev_sol     FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_compra(id) ON DELETE CASCADE,
  CONSTRAINT fk_solev_usuario FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitacao_compra_anexos (
  id             char(36)     NOT NULL,
  solicitacao_id char(36)     NOT NULL,
  evento_id      char(36)     NULL COMMENT 'NULL = foto da abertura',
  arquivo_path   varchar(255) NOT NULL COMMENT 'só o nome do arquivo em storage/solicitacoes/',
  nome_original  varchar(255) NOT NULL,
  tamanho        int unsigned NOT NULL,
  enviado_por    char(36)     NOT NULL,
  criado_em      timestamp    NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY idx_solanx_sol (solicitacao_id),
  KEY idx_solanx_evento (evento_id),
  CONSTRAINT fk_solanx_sol    FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_compra(id) ON DELETE CASCADE,
  CONSTRAINT fk_solanx_evento FOREIGN KEY (evento_id)      REFERENCES solicitacao_compra_eventos(id) ON DELETE CASCADE,
  CONSTRAINT fk_solanx_user   FOREIGN KEY (enviado_por)    REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipo de notificação do módulo. Conferir o enum atual antes (SHOW COLUMNS):
-- MODIFY substitui a lista inteira.
ALTER TABLE notificacoes
  MODIFY COLUMN tipo ENUM('aprovacao_pendente','oc_aprovada','nf_divergente','contrato_vencendo',
                          'estoque_critico','budget_critico','transferencia_pendente',
                          'transferencia_divergente','solicitacao_compra') NOT NULL;
