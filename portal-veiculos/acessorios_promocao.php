<?php
/**
 * Módulo compartilhado de promoção de acessórios.
 * Garante o schema (colunas + tabela de créditos) e centraliza as regras
 * de "item em promoção" / "valor efetivo", usadas por acessorios.php,
 * acessorios_gestor.php e acessorios_orcamento_impressao.php.
 */

/**
 * Garante que a tabela acessorios_marcas tenha as colunas de promoção
 * e que a tabela de créditos de vendedor exista, criando o que faltar
 * automaticamente (mesmo padrão de self-healing usado para 'criado_em'
 * em acessorios_gestor.php).
 */
function garantirSchemaPromocaoAcessorios(mysqli $mysqli): void {
    $colunas = [
        'valor_promocional' => 'DECIMAL(10,2) NULL',
        'valor_vendedor'    => 'DECIMAL(10,2) NULL',
        'promocao_inicio'   => 'DATE NULL',
        'promocao_fim'      => 'DATE NULL',
    ];
    foreach ($colunas as $nome => $definicao) {
        $chk = $mysqli->query("SHOW COLUMNS FROM acessorios_marcas LIKE '$nome'");
        if (!$chk || !$chk->num_rows) {
            // Tenta criar a coluna automaticamente se existir permissão.
            $mysqli->query("ALTER TABLE acessorios_marcas ADD COLUMN $nome $definicao");
            // Reconfirma o resultado em vez de assumir que o ALTER funcionou
            // (ex.: usuário do banco sem permissão ALTER falharia em silêncio).
            $chk = $mysqli->query("SHOW COLUMNS FROM acessorios_marcas LIKE '$nome'");
            if (!$chk || !$chk->num_rows) {
                continue;
            }
        }
    }

    $mysqli->query(
        "CREATE TABLE IF NOT EXISTS acessorios_promocao_creditos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            orcamento_id INT NOT NULL,
            user_id INT NOT NULL,
            valor_vendedor DECIMAL(10,2) NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

/**
 * Um item está em promoção quando tem valor_promocional definido
 * e a data de hoje cai dentro do período configurado.
 */
function acessorioEstaEmPromocao(array $item): bool {
    if (($item['valor_promocional'] ?? null) === null || $item['valor_promocional'] === '') {
        return false;
    }
    if (empty($item['promocao_inicio']) || empty($item['promocao_fim'])) {
        return false;
    }
    $hoje = date('Y-m-d');
    return $hoje >= substr($item['promocao_inicio'], 0, 10) && $hoje <= substr($item['promocao_fim'], 0, 10);
}

/**
 * Recebe uma linha de acessorios_marcas (com as colunas de promoção
 * já selecionadas) e substitui 'valor' pelo valor promocional quando
 * a promoção estiver ativa. Adiciona a flag 'em_promocao' e, quando ativa,
 * preserva o valor original em 'valor_original' (já que 'valor' é sobrescrito).
 */
function aplicarValorEfetivo(array $item): array {
    $ativa = acessorioEstaEmPromocao($item);
    $item['em_promocao'] = $ativa;
    if ($ativa) {
        $item['valor_original'] = $item['valor'];
        $item['valor'] = $item['valor_promocional'];
    }
    return $item;
}
