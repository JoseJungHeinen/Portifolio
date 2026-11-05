-- Migration 011: ensure Compras sector exists

INSERT INTO setores (id, nome, tipo, ativo)
VALUES ('22000015-0000-0000-0000-000000000015', 'Compras', 'administrativo', 1)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    tipo = VALUES(tipo),
    ativo = VALUES(ativo);
