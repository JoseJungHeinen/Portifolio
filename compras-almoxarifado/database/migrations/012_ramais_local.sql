-- Migration 012: Replicar tabelas de ramais do banco veiculos para o banco Compras
-- Motivo: banco veiculos fica em servidor separado na producao

CREATE TABLE IF NOT EXISTS ramais_cidades (
    id       INT UNSIGNED NOT NULL,
    nome     VARCHAR(100) NOT NULL,
    telefone VARCHAR(30)  DEFAULT NULL,
    ordem    INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ramais (
    id           INT UNSIGNED NOT NULL,
    id_cidade    INT UNSIGNED NOT NULL,
    setor        VARCHAR(150) NOT NULL,
    ramal        VARCHAR(30)  NOT NULL,
    ativo        TINYINT(1)   NOT NULL DEFAULT 1,
    ordem        INT          NOT NULL DEFAULT 0,
    ordem_cidade INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT fk_ramais_cidade FOREIGN KEY (id_cidade) REFERENCES ramais_cidades(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── ramais_cidades ───────────────────────────────────────────────────────────
INSERT INTO ramais_cidades (id, nome, ordem, telefone) VALUES
(1,  'Cidade A - Fiat',   1, '(00) 0000-0000'),
(3,  'Cidade C',       4, '(00) 0000-0000'),
(4,  'Porto Alegre',     7, '(00) 0000-0000'),
(5,  'Sta. Cruz do Sul', 6, '(00) 0000-0000'),
(6,  'Cidade D',    9, '(00) 0000-0000'),
(7,  'Cidade A - Jeep',   5, '(00) 0000-0000'),
(9,  'Cidade B',          2, '(51) 220-0180'),
(10, 'Canoas',           3, '(00) 0000-0000');

-- ─── ramais (42 registros completos) ─────────────────────────────────────────
INSERT INTO ramais (id, id_cidade, setor, ramal, ativo, ordem, ordem_cidade) VALUES
(3,  1,  'Financeiro',           '1880',      1,  3,  1),
(6,  1,  'Recepcao',             '1800',      1,  1,  1),
(8,  3,  'Recepcao',             '4800',      1,  1,  5),
(12, 1,  'Caixa',                '1802',      1,  2,  1),
(17, 1,  'T.I',                  '1810',      1,  4,  0),
(18, 1,  'Pecas',                '1820',      1,  5,  0),
(19, 1,  'RH',                   '1804',      1,  6,  0),
(20, 1,  'Locadora',             '1870',      1,  7,  0),
(21, 1,  'CRM',                  '1861',      1,  8,  0),
(22, 1,  'Documental',           '1855',      1,  9,  0),
(24, 3,  'Caixa',                '4802',      1,  2,  0),
(25, 10, 'Caixa',                '3802',      1,  1,  0),
(28, 3,  'Oficina',              '4841',      1,  3,  0),
(29, 3,  'Pecas',                '4820',      1,  4,  0),
(33, 9,  'Recepcao',             '2800',      1,  1,  0),
(34, 9,  'Caixa',                '2802',      1,  2,  0),
(35, 7,  'Recepcao',             '5800',      1,  1,  0),
(36, 7,  'Caixa',                '5802',      1,  2,  0),
(37, 4,  'Recepcao',             '7800',      1,  1,  0),
(38, 4,  'Caixa',                '7802',      1,  2,  0),
(40, 5,  'Recepcao',             '6800',      1,  1,  0),
(42, 5,  'Caixa - Paula',        '6802',      1,  2,  0),
(43, 5,  'Vendas - Alexandre',   '6815',      1,  3,  0),
(44, 5,  'Vendas - Makeli',      '6830',      1,  4,  0),
(45, 5,  'Vendas - Marcelo',     '6831',      1,  5,  0),
(46, 5,  'Vendas - Stive',       '6833',      1,  6,  0),
(47, 5,  'Vendas - Ciro',        '6835',      1,  7,  0),
(48, 5,  'Pecas - Henricke',     '6820',      1,  8,  0),
(49, 5,  'Pos-Vendas - Silvia',  '6841',      1,  9,  0),
(50, 5,  'Pos-Vendas - Joice',   '6842',      1, 10,  0),
(51, 10, 'Recepcao - Jocenara',  '3800/3801', 1,  2,  0),
(52, 10, 'Rochele',              '3808',      1,  3,  0),
(53, 10, 'Lucas',                '3802',      1,  4,  0),
(55, 10, 'Elaine',               '3804',      1,  5,  0),
(56, 10, 'Ismail',               '3805',      1,  6,  0),
(57, 10, 'Cristian',             '3807',      1,  7,  0),
(58, 6,  'Recepcao - Paulo',     '9800',      1,  1,  0),
(59, 6,  'Recepcao - Andressa',  '9802',      1,  2,  0),
(60, 6,  'Cristoffer',           '9834',      1,  3,  0),
(61, 6,  'Gabriel',              '9835',      1,  4,  0),
(63, 6,  'Felipe',               '9839',      1,  5,  0),
(64, 6,  'Diego',                '9837',      1,  6,  0);
