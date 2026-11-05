<?php
/**
 * includes/veiculos_permissoes.php
 *
 * Permissões granulares de menu do Veículos (tabela usuarios_permissoes,
 * uma coluna booleana por item — mesmo modelo que Veiculos/gerenciar_usuarios.php
 * já usa pra ele mesmo).
 */
require_once __DIR__ . '/db_connect.php';

function listar_grupos_permissoes_veiculos(): array
{
    return [
        'geral' => [
            'label' => 'Geral',
            'items' => [
                'menu_buscar'    => 'Buscar',
                'menu_links'     => 'Links úteis',
                'menu_ramais'    => 'Ramais',
                'menu_bancos'    => 'Bancos',
                'menu_politicas' => 'Políticas comerciais',
            ],
        ],
        'ferramentas' => [
            'label' => 'Ferramentas',
            'items' => [
                'menu_ferramentas'              => 'Acesso ao grupo Ferramentas',
                'menu_ferramenta_transferencia' => '→ Transferência',
                'menu_ferramenta_emplacamento'  => '→ Emplacamento',
                'menu_ferramenta_cartao'        => '→ Cartão',
                'menu_ferramenta_documentos'    => '→ Documentos',
                'menu_ferramenta_orcamento'     => '→ Orçamento',
            ],
        ],
        'treinamentos' => [
            'label' => 'Treinamentos',
            'items' => [
                'menu_treinamentos'        => 'Meus treinamentos',
                'menu_treinamentos_gestor' => 'Gestão de equipe',
            ],
        ],
        'bi' => [
            'label' => 'Business Intelligence',
            'items' => [
                'menu_bi_veiculo'       => 'BI Veículos',
                'menu_bi_metas'         => 'BI Metas',
                'bi_metas_admin'        => '→ BI Metas: Administrador (editar metas/exclusões)',
                'menu_bi_locadora'      => 'BI Locadora',
                'menu_bi_oficina'       => 'BI Oficina',
                'menu_bi_pecas'         => 'BI Peças',
                'menu_bi_financeiro'    => 'BI Financeiro',
                'menu_bi_contabilidade' => 'BI Contabilidade',
            ],
        ],
        'setores' => [
            'label' => 'Módulos por setor',
            'items' => [
                'menu_apresentacao_carros' => 'Vídeos dos carros (Vendas)',
                'menu_locadora'            => 'Locadora',
                'menu_acessorios'          => 'Acessórios (Oficina)',
                'menu_inventario'          => 'Inventário',
                'menu_pecas'               => 'Peças',
            ],
        ],
    ];
}

/**
 * @return array<string,bool> chave do item => marcado ou não (vazio se o usuário não tem linha ainda)
 */
function buscar_permissoes_veiculos(array $cfgVeiculos, int $idUsuario): array
{
    $pdo  = conectar_sistema($cfgVeiculos['db']);
    $stmt = $pdo->prepare('SELECT * FROM usuarios_permissoes WHERE usuario_id = ? LIMIT 1');
    $stmt->execute([$idUsuario]);
    $row = $stmt->fetch();

    if (!$row) {
        return [];
    }

    $marcadas = [];
    foreach (listar_grupos_permissoes_veiculos() as $grupo) {
        foreach ($grupo['items'] as $chave => $label) {
            $marcadas[$chave] = !empty($row[$chave]);
        }
    }
    return $marcadas;
}

/**
 * @param string[] $marcadas chaves dos itens que devem ficar marcados (as ausentes viram 0)
 */
/**
 * Colunas que realmente existem em usuarios_permissoes. O código do próprio
 * Veículos (listar_grupos_permissoes_veiculos, espelhado de lá) e a tabela real
 * já ficaram fora de sincronia (ex: 'bi_metas_admin' não tem coluna) — em vez
 * de assumir que toda chave do array vira coluna, confere contra o schema real.
 */
function colunas_reais_permissoes_veiculos(\PDO $pdo): array
{
    static $colunas = null;
    if ($colunas === null) {
        $colunas = $pdo->query('SHOW COLUMNS FROM usuarios_permissoes')->fetchAll(\PDO::FETCH_COLUMN);
    }
    return $colunas;
}

function salvar_permissoes_veiculos(array $cfgVeiculos, int $idUsuario, array $marcadas): void
{
    $pdo = conectar_sistema($cfgVeiculos['db']);
    $colunasReais = colunas_reais_permissoes_veiculos($pdo);

    $colunas = [];
    foreach (listar_grupos_permissoes_veiculos() as $grupo) {
        foreach ($grupo['items'] as $chave => $label) {
            if (!in_array($chave, $colunasReais, true)) {
                continue; // item existe na lista mas não tem coluna real — ignora
            }
            $colunas[$chave] = in_array($chave, $marcadas, true) ? 1 : 0;
        }
    }

    $stmtCheck = $pdo->prepare('SELECT id FROM usuarios_permissoes WHERE usuario_id = ? LIMIT 1');
    $stmtCheck->execute([$idUsuario]);
    $existe = $stmtCheck->fetch();

    if ($existe) {
        $sets    = [];
        $valores = [];
        foreach ($colunas as $col => $val) {
            $sets[]    = "`$col` = ?";
            $valores[] = $val;
        }
        $valores[] = $idUsuario;
        $pdo->prepare('UPDATE usuarios_permissoes SET ' . implode(', ', $sets) . ' WHERE usuario_id = ?')
            ->execute($valores);
    } else {
        $nomesColunas = implode(',', array_map(fn($c) => "`$c`", array_keys($colunas)));
        $marcadores   = implode(',', array_fill(0, count($colunas) + 1, '?'));
        $valores      = array_merge([$idUsuario], array_values($colunas));
        $pdo->prepare("INSERT INTO usuarios_permissoes (usuario_id,$nomesColunas) VALUES ($marcadores)")
            ->execute($valores);
    }
}
