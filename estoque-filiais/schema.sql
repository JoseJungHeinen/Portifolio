CREATE DATABASE IF NOT EXISTS sistema_estoque;
USE sistema_estoque;

-- Tabela de Filiais
CREATE TABLE IF NOT EXISTS filiais (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cidade VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de Usuários
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    senha VARCHAR(255) NOT NULL,
    cargo ENUM('admin', 'comprador', 'filial') NOT NULL,
    setor VARCHAR(50),
    filial_id INT,
    FOREIGN KEY (filial_id) REFERENCES filiais(id) ON DELETE SET NULL
);

-- Tabela de Produtos
CREATE TABLE IF NOT EXISTS produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    unidade_medida VARCHAR(20) DEFAULT 'un',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de Estoque
CREATE TABLE IF NOT EXISTS estoque (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    filial_id INT NOT NULL, -- 0 pode ser central ou usar uma filial específica como central
    quantidade INT DEFAULT 0,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
    UNIQUE KEY (produto_id, filial_id)
);

-- Validação Mensal de Estoque
CREATE TABLE IF NOT EXISTS validacoes_estoque (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filial_id INT NOT NULL,
    mes_referencia INT NOT NULL,
    ano_referencia INT NOT NULL,
    data_validacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (filial_id) REFERENCES filiais(id) ON DELETE CASCADE
);

-- Solicitações de Compra
CREATE TABLE IF NOT EXISTS solicitacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filial_id INT NOT NULL,
    usuario_id INT NOT NULL,
    status ENUM('pendente', 'aprovado', 'rejeitado', 'em_compra') DEFAULT 'pendente',
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (filial_id) REFERENCES filiais(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- Itens da Solicitação
CREATE TABLE IF NOT EXISTS itens_solicitacao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    solicitacao_id INT NOT NULL,
    produto_id INT NOT NULL,
    quantidade INT NOT NULL,
    FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id)
);

-- Ordens de Compra
CREATE TABLE IF NOT EXISTS ordens_compra (
    id INT AUTO_INCREMENT PRIMARY KEY,
    solicitacao_id INT,
    comprador_id INT NOT NULL,
    fornecedor VARCHAR(150),
    valor_total DECIMAL(10,2),
    frete DECIMAL(10,2),
    status ENUM('aberta', 'concluida', 'cancelada') DEFAULT 'aberta',
    data_emissao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes(id),
    FOREIGN KEY (comprador_id) REFERENCES usuarios(id)
);

-- Dados iniciais para teste
INSERT INTO filiais (nome, cidade) VALUES ('Estoque Central', 'Matriz'), ('Filial Porto Alegre', 'Porto Alegre'), ('Filial Caxias', 'Cidade D');
INSERT INTO usuarios (nome, email, senha, cargo, setor, filial_id) VALUES 
('Admin', 'admin@sistema.com', '123456', 'admin', 'Diretoria', 1),
('Comprador 1', 'comprador@sistema.com', '123456', 'comprador', 'Suprimentos', 1),
('Gerente Filial 1', 'filial1@sistema.com', '123456', 'filial', 'Vendas', 2);
INSERT INTO produtos (nome, descricao) VALUES ('Papel A4', 'Pacote 500 folhas'), ('Caneta Azul', 'Caixa com 50 unidades'), ('Grampeador', 'Grampeador de mesa');
