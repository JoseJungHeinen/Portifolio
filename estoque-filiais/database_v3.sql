USE sistema_estoque;

-- 1. Melhorar Tabela de Produtos
ALTER TABLE produtos 
ADD COLUMN setor_id INT,
ADD COLUMN finalidade VARCHAR(255),
ADD COLUMN recorrencia ENUM('baixa', 'media', 'alta') DEFAULT 'media',
ADD COLUMN preco_medio DECIMAL(10,2) DEFAULT 0.00,
ADD COLUMN ultima_compra_preco DECIMAL(10,2) DEFAULT 0.00,
ADD COLUMN ultima_compra_data DATE;

-- 2. Tabela de Logs de Auditoria
CREATE TABLE IF NOT EXISTS logs_auditoria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tabela_nome VARCHAR(50),
    registro_id INT,
    usuario_id INT,
    acao VARCHAR(50), -- 'CRIOU', 'APROVOU', 'NEGOU', 'EDITOU'
    detalhes TEXT,
    data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- 3. Melhorar Itens da Solicitação (para permitir edição e justificativa por item se necessário)
ALTER TABLE itens_solicitacao 
ADD COLUMN quantidade_aprovada INT,
ADD COLUMN justificativa TEXT;

-- 4. Melhorar Solicitações (Justificativa Geral e Rastreabilidade)
ALTER TABLE solicitacoes 
ADD COLUMN justificativa_comprador TEXT,
ADD COLUMN data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- 5. Histórico de Preços por Fornecedor (Para Inteligência de Compras)
CREATE TABLE IF NOT EXISTS historico_precos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT,
    fornecedor_id INT,
    preco DECIMAL(10,2),
    data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ordem_compra_id INT,
    FOREIGN KEY (produto_id) REFERENCES produtos(id),
    FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id)
);

-- Dados de teste para novos campos
UPDATE produtos SET finalidade = 'Uso Administrativo', recorrencia = 'alta' WHERE id = 1;
UPDATE produtos SET finalidade = 'Material de Escritório', recorrencia = 'media' WHERE id = 2;
