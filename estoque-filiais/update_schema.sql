USE sistema_estoque;

-- Tabela de Fornecedores
CREATE TABLE IF NOT EXISTS fornecedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome_fantasia VARCHAR(150) NOT NULL,
    razao_social VARCHAR(150),
    cnpj VARCHAR(20) UNIQUE,
    contato VARCHAR(100),
    email VARCHAR(100),
    telefone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Vínculo entre Produto e Fornecedor (Muitos para Muitos)
CREATE TABLE IF NOT EXISTS produto_fornecedor (
    produto_id INT NOT NULL,
    fornecedor_id INT NOT NULL,
    preco_referencia DECIMAL(10,2),
    PRIMARY KEY (produto_id, fornecedor_id),
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
    FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE CASCADE
);

-- Inserir alguns fornecedores de teste
INSERT IGNORE INTO fornecedores (nome_fantasia, contato, email) VALUES 
('Papelaria Central', 'João Silva', 'contato@papelariacentral.com'),
('Distribuidora Office', 'Maria Souza', 'vendas@office.com');

-- Vincular produtos aos fornecedores
INSERT IGNORE INTO produto_fornecedor (produto_id, fornecedor_id, preco_referencia) VALUES 
(1, 1, 25.50), -- Papel A4 no Fornecedor 1
(1, 2, 24.90), -- Papel A4 no Fornecedor 2
(2, 1, 45.00); -- Caneta Azul no Fornecedor 1
