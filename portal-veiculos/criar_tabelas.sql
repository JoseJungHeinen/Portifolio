-- Executar no SQL Server Management Studio

USE [NOME_DO_SEU_BANCO];
GO

-- Tabela tipos_transferencia
CREATE TABLE tipos_transferencia (
    id                   INT IDENTITY(1,1) PRIMARY KEY,
    nome                 VARCHAR(100)      NOT NULL,
    valor_placa          DECIMAL(12,2)     NOT NULL,
    valor_intermunicipal DECIMAL(12,2)     NOT NULL,
    valor_transferencia  DECIMAL(12,2)     NOT NULL,
    ativo                BIT               NOT NULL CONSTRAINT DF_tipos_transferencia_ativo DEFAULT (1),
    created_at           DATETIME2         NULL,
    updated_at           DATETIME2         NULL
);
GO

INSERT INTO tipos_transferencia (nome, valor_placa, valor_intermunicipal, valor_transferencia, ativo)
VALUES
    ('Moto',                  180.00, 330.00,  990.00, 1),
    ('Carro -= 2021',         350.00, 330.00, 1490.00, 1),
    ('Carro += 2022',         350.00, 330.00, 1590.00, 1),
    ('Transferência Lojista', 350.00, 330.00, 1190.00, 1);
GO

-- Tabela tipos_emplacamento
CREATE TABLE tipos_emplacamento (
    id                  INT IDENTITY(1,1) PRIMARY KEY,
    nome                VARCHAR(150)   NOT NULL,
    taxas               DECIMAL(12,2)  NOT NULL,
    ipva_percentual     DECIMAL(5,4)   NOT NULL,
    valor_placa         DECIMAL(12,2)  NOT NULL,
    valor_escolha_placa DECIMAL(12,2)  NOT NULL,
    margem              DECIMAL(12,2)  NOT NULL,
    ativo               BIT            NOT NULL CONSTRAINT DF_tipos_emplacamento_ativo DEFAULT (1),
    created_at          DATETIME2      NULL,
    updated_at          DATETIME2      NULL
);
GO

INSERT INTO tipos_emplacamento (nome, taxas, ipva_percentual, valor_placa, valor_escolha_placa, margem, ativo)
VALUES
    ('Normal',                              423.92, 0.0300, 350.00, 750.00, 815.00, 1),
    ('Veículos Leves',                      423.92, 0.0300, 350.00, 750.00, 415.00, 1),
    ('Ducato 15L (Placa Vermelha e Cinza)', 423.92, 0.0300, 350.00, 750.00, 630.00, 1),
    ('Taxi/PCD com Isenção',                423.92, 0.0000, 350.00, 750.00, 630.00, 1);
GO

-- Tabela orcamentos
CREATE TABLE orcamentos (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    tipo          VARCHAR(30)   NOT NULL,
    subtipo_id    INT           NOT NULL,
    texto_detalhe NVARCHAR(MAX) NOT NULL,
    total         DECIMAL(12,2) NOT NULL,
    dados_entrada NVARCHAR(MAX) NULL,
    criado_em     DATETIME2     NOT NULL CONSTRAINT DF_orcamentos_criado_em DEFAULT (SYSDATETIME()),
    deletar_em    DATETIME2     NULL
);
GO
