-- Migration 016: Cria registros de aprovação para requisições travadas em 'em_aprovacao'
-- Compatível com MySQL 8+ e MariaDB 10+
-- Executar UMA VEZ no servidor de produção

INSERT INTO aprovacoes (id, entidade_tipo, entidade_id, nivel, status, atribuido_para, criado_em)
SELECT
    UUID()         AS id,
    'requisicao'   AS entidade_tipo,
    r.id           AS entidade_id,
    1              AS nivel,
    'pendente'     AS status,
    NULL           AS atribuido_para,
    NOW()          AS criado_em
FROM requisicoes r
WHERE r.status = 'em_aprovacao'
  AND NOT EXISTS (
      SELECT 1 FROM aprovacoes a
      WHERE a.entidade_id = r.id
        AND a.entidade_tipo = 'requisicao'
  );
