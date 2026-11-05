<?php
// @versao Ver.001.261026
require_once __DIR__ . '/config_database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        ['error' => 'Não autorizado'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

$host = $_SERVER['HTTP_HOST'] ?? '';

$ehLocal = (
    $host === 'veiculos.test' ||
    $host === 'localhost' ||
    $host === '127.0.0.1' ||
    substr($host, -5) === '.test'
);

/**
 * Resolve diretório físico e URL base do BI.
 *
 * O diretório físico é sempre relativo a __DIR__ (onde bi_menu.php mora).
 * A URL base é derivada da URL real do próprio bi_menu.php, garantindo
 * que funcione independente de o document root apontar para /public ou não.
 */

// URL base = diretório do próprio bi_menu.php no servidor web
// Ex.: se acessado em /Veiculos/public/bi_menu.php → scriptBase = /Veiculos/public
$scriptBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

// Procura a pasta BI (case-insensitive) dentro de __DIR__
$candidatos_bi = ['BI', 'bi'];
$BI_DIR = null;
$BI_URL = null;

foreach ($candidatos_bi as $nome) {
    $tentativa = __DIR__ . DIRECTORY_SEPARATOR . $nome;
    if (is_dir($tentativa)) {
        $BI_DIR = $tentativa;
        $BI_URL = $scriptBase . '/' . $nome;
        break;
    }
}

// Fallback
if ($BI_DIR === null) {
    $BI_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'BI';
    $BI_URL = $scriptBase . '/BI';
}

/**
 * Normaliza nome de pasta para chave de permissão
 * Ex.: "BI LOCADORA" -> "locadora"
 */
function limparNomePermissao(string $nome): string
{
    $nome = trim($nome);
    $nome = preg_replace('/^BI[\s_]+/i', '', $nome);
    $nome = strtolower($nome);
    $nome = preg_replace('/[^a-z0-9]+/i', '', $nome);
    return $nome;
}

/**
 * Mapeia pasta -> coluna em usuarios_permissoes
 * Sempre usando padrão menu_bi_*
 */
function permChaveDaPasta(string $nome): string
{
    $slug = limparNomePermissao($nome);

    // mapeamento direto para bater com listarSidebarPermissoes (grupo BI)
    $mapa = [
        'locadora'      => 'menu_bi_locadora',
        'veiculos'      => 'menu_bi_veiculo',
        'veiculo'       => 'menu_bi_veiculo',
        'oficina'       => 'menu_bi_oficina',
        'oficinamix'    => 'menu_bi_oficina',   // Mix por Modelo usa a permissão BI Oficina
        'estoque'       => 'menu_bi_veiculo',   // Estoque de Veículos usa a permissão BI Veículos
        'pedidos'       => 'menu_bi_veiculo',   // Pedidos em Aberto usa a permissão BI Veículos
        'pecas'         => 'menu_bi_pecas',
        'peca'          => 'menu_bi_pecas',
        'financeiro'    => 'menu_bi_financeiro',
        'contabilidade' => 'menu_bi_contabilidade',
        'metas'         => 'menu_bi_metas',
    ];

    if (isset($mapa[$slug])) {
        return $mapa[$slug];
    }

    // fallback: BI QUALQUER -> menu_bi_qualquer
    return 'menu_bi_' . $slug;
}

/**
 * Gera label a partir do nome da pasta
 * Ex.: "BI LOCADORA" -> "Locadora"
 */
function labelDaPasta(string $nome): string
{
    $nome = preg_replace('/^BI[\s_]+/i', '', trim($nome));
    $nome = str_replace('_', ' ', $nome);
    $nome = strtolower($nome);
    return ucwords($nome);
}

$is_admin = !empty($_SESSION['is_admin']);
$perms    = (isset($_SESSION['perms']) && is_array($_SESSION['perms'])) ? $_SESSION['perms'] : [];
$uid      = 0;

/**
 * Se não for admin e ainda não tiver perms na sessão,
 * carrega de usuarios_permissoes
 */
if (empty($perms)) { // carrega perms p/ TODOS (inclui admin) — necessário p/ itens restritos
    global $mysqli;

   if (!empty($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
} elseif (!empty($_SESSION['usuario_id'])) {
    $uid = (int) $_SESSION['usuario_id'];
} elseif (!empty($_SESSION['id_usuario'])) {
    $uid = (int) $_SESSION['id_usuario'];
} elseif (!empty($_SESSION['id'])) {           // <-- acrescentar aqui
    $uid = (int) $_SESSION['id'];
}

    if ($uid > 0) {
        $sql = "SELECT * FROM usuarios_permissoes WHERE usuario_id = ? LIMIT 1";
        $st  = $mysqli->prepare($sql);

        if ($st) {
            $st->bind_param('i', $uid);
            $st->execute();

            $row = [];

            if (method_exists($st, 'get_result')) {
                $res = $st->get_result();
                if ($res) {
                    $tmp = $res->fetch_assoc();
                    if (is_array($tmp)) {
                        $row = $tmp;
                    }
                }
            } else {
                $meta = $st->result_metadata();
                if ($meta) {
                    $data  = [];
                    $binds = [];

                    while ($field = $meta->fetch_field()) {
                        $binds[] = &$data[$field->name];
                    }

                    call_user_func_array([$st, 'bind_result'], $binds);

                    if ($st->fetch()) {
                        foreach ($data as $k => $v) {
                            $row[$k] = $v;
                        }
                    }
                }
            }

            $st->close();

            if (!empty($row)) {
                $perms              = $row;
                $_SESSION['perms']  = $row;
            }
        }
    }
}

$itens = [];

if (!is_dir($BI_DIR)) {
    echo json_encode(
        [
            'itens' => [],
            'erro'  => 'Diretório BI/ não encontrado',
            'debug' => [
                'host'    => $host,
                'eh_local'=> $ehLocal,
                'bi_dir'  => $BI_DIR,
                'bi_url'  => $BI_URL,
            ],
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Escaneia pastas dentro de BI_DIR e monta itens conforme permissão.
 * Agora aceita HTML (BIs estáticos) E PHP (BIs dinâmicos como o de Metas).
 */
$pastas = scandir($BI_DIR);

foreach ($pastas as $pasta) {
    if ($pasta === '.' || $pasta === '..') {
        continue;
    }

    $caminhoPasta = $BI_DIR . '/' . $pasta;
    if (!is_dir($caminhoPasta)) {
        continue;
    }

    // Pastas que viraram análises dentro de uma página-grupo (bi_grupo.php):
    // continuam existindo (links antigos funcionam), mas não aparecem soltas no menu.
    $pastasAgrupadas = ['oficinamix', 'estoque', 'pedidos', 'metas'];
    if (in_array(limparNomePermissao($pasta), $pastasAgrupadas, true)) {
        continue;
    }

    $chavePermissao = permChaveDaPasta($pasta);

    // Grupos que também abrem com outra permissão (Veículos contém Metas)
    $permsAlternativas = [
        'menu_bi_veiculo' => ['menu_bi_metas', 'bi_metas_admin'],
    ];

    // Não-admin: só mostra com a coluna = 1.
    // Itens restritos (ex.: Controle Financeiro) exigem a permissão MESMO sendo admin.
    $itensRestritos = ['menu_bi_controlefinanceiro'];
    $exigePerm = !$is_admin || in_array($chavePermissao, $itensRestritos, true);
    if ($exigePerm) {
        $temPerm = false;
        foreach (array_merge([$chavePermissao], $permsAlternativas[$chavePermissao] ?? []) as $p) {
            if ((int)($perms[$p] ?? 0) === 1) {
                $temPerm = true;
            }
        }
        if (!$temPerm) {
            continue;
        }
    }

    // ── RESOLUÇÃO DO ARQUIVO DE ENTRADA ────────────────────────────────────
    // Ordem de preferência:
    //   1) index.php / dashboard.php  (BI dinâmico em PHP, ex.: Metas)
    //   2) index.html / dashboard.html (BI estático)
    //   3) qualquer .php da pasta (ordenado)
    //   4) qualquer .html/.htm da pasta (ordenado)
    // ───────────────────────────────────────────────────────────────────────
    $preferidos = [
        'index.php',
        'dashboard.php',
        'index.html',
        'dashboard.html',
    ];

    $entryFile = null;

    foreach ($preferidos as $arquivoPreferido) {
        if (is_file($caminhoPasta . '/' . $arquivoPreferido)) {
            $entryFile = $arquivoPreferido;
            break;
        }
    }

    // fallback: pega o primeiro .php ou .html/.htm (ordenado)
    if ($entryFile === null) {
        $candidatos = array_merge(
            glob($caminhoPasta . '/*.php')  ?: [],
            glob($caminhoPasta . '/*.html') ?: [],
            glob($caminhoPasta . '/*.htm')  ?: [],
            glob($caminhoPasta . '/*.HTML') ?: [],
            glob($caminhoPasta . '/*.HTM')  ?: []
        );

        // remove arquivos que começam com "_" (ex.: _guard.php) — são internos
        $candidatos = array_filter($candidatos, function ($f) {
            return substr(basename($f), 0, 1) !== '_';
        });

        if (empty($candidatos)) {
            continue;
        }

        sort($candidatos);
        $entryFile = basename($candidatos[0]);
    }

    $itens[] = [
        'pasta' => $pasta,
        'label' => ['menu_bi_pecas' => 'Peças', 'menu_bi_veiculo' => 'Veículos'][$chavePermissao] ?? labelDaPasta($pasta),
        'url'   => $BI_URL . '/' . rawurlencode($pasta) . '/' . rawurlencode($entryFile) . '?v=' . filemtime($caminhoPasta . '/' . $entryFile),
        'chave' => $chavePermissao,
    ];
}

// Ordem alfabética, exceto "Oficina", que fica entre Locadora e Metas.
usort($itens, function (array $a, array $b): int {
    $chave = function (array $i): string {
        return $i['chave'] === 'menu_bi_oficina' ? 'Locadora ~' . $i['label'] : $i['label'];
    };
    return strcmp($chave($a), $chave($b));
});

echo json_encode(
    [
        'itens' => $itens,
        'debug' => [
            'usuario_id'   => $uid,
            'is_admin'     => $is_admin ? 1 : 0,
            'bi_dir_usado' => $BI_DIR,
            'bi_url_usado' => $BI_URL,
            'total_itens'  => count($itens),
        ],
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);