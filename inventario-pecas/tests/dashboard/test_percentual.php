<?php
declare(strict_types=1);

/**
 * Percentual "Concluído do estoque" do dashboard: uma casa decimal, a
 * resposta ao vivo (?ajax_percentual=1) bate com a página e com o cálculo
 * direto no banco, e sobe quando um item é contado.
 *
 * O teste de subida mexe numa linha do inventário aberto e a devolve como
 * estava no finally — mesmo se uma asserção falhar no meio.
 */

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'recontagem_lib.php';
require __DIR__ . '/../conferencia/assert.php';

echo "test_percentual\n";

const GESTOR = ['inventario_login' => ['usuario' => 'teste', 'perfil' => 'gestor']];

/** Roda o dashboard com a sessão e o GET dados; devolve a saída crua. */
function dash(array $session, array $get): string
{
    $arquivo = tempnam(sys_get_temp_dir(), 'dash');
    file_put_contents($arquivo, json_encode(['session' => $session, 'get' => $get]));
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 '
         . escapeshellarg('tests/dashboard/run_dash.php') . ' ' . escapeshellarg($arquivo);
    $saida = (string)(shell_exec($cmd) ?? '');
    @unlink($arquivo);
    return trim($saida);
}

function ajax(array $get): ?array
{
    return json_decode(dash(GESTOR, $get + ['ajax_percentual' => '1']), true);
}

/** O mesmo cálculo, feito aqui direto no banco. */
function percentual_banco(PDO $pdo, int $inv, int $emp, int $rev): float
{
    $cf  = recontagem_sql_contagem_final(recontagem_tem_decisoes($pdo));
    $row = $pdo->prepare("
        SELECT COALESCE(SUM(quantidade * custo_entrada), 0)              AS total,
               COALESCE(SUM(COALESCE($cf, 0) * custo_entrada), 0)       AS contado
          FROM estoque
         WHERE empresa = ? AND revenda = ? AND id_inventario = ?
    ");
    $row->execute([$emp, $rev, $inv]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    return (float)$r['total'] > 0 ? min(100.0, round((float)$r['contado'] / (float)$r['total'] * 100, 1)) : 0.0;
}

// Inventário aberto com mais linhas, para o teste valer com dado real.
$inv = $pdo->query("
    SELECT i.id, i.empresa, i.revenda
      FROM inv_inventarios i
     WHERE i.status = 'aberto'
     ORDER BY (SELECT COUNT(*) FROM estoque e WHERE e.id_inventario = i.id) DESC
     LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$inv) {
    echo "  nenhum inventário aberto para testar\n";
    t_fim();
}

$idInv = (int)$inv['id'];
$emp   = (int)$inv['empresa'];
$rev   = (int)$inv['revenda'];
$get   = ['codigo' => $emp . '.' . $rev, 'inv' => (string)$idInv];
echo "  (inventário $idInv, loja $emp.$rev)\n";

// --- formato ---------------------------------------------------------------
$r = ajax($get);
t_true('ajax responde JSON ok', is_array($r) && ($r['ok'] ?? false) === true);
t_true('percentual é número', is_int($r['percentual'] ?? null) || is_float($r['percentual'] ?? null));
t_true('no máximo uma casa decimal', round((float)$r['percentual'], 1) == (float)$r['percentual']);
t_true('texto com vírgula e uma casa (ex.: 1,3%)', (bool)preg_match('/^\d{1,3},\d%$/', (string)($r['texto'] ?? '')));
t_eq('texto corresponde ao número', number_format((float)$r['percentual'], 1, ',', '.') . '%', $r['texto']);

// --- consistência ----------------------------------------------------------
t_eq('ajax = cálculo direto no banco', percentual_banco($pdo, $idInv, $emp, $rev), (float)$r['percentual']);

$pagina = dash(GESTOR, $get);
preg_match('/id="perc-text">([^<]*)</', $pagina, $mTexto);
preg_match('/id="perc-circle" style="--perc: ([^;]*);"/', $pagina, $mCss);
t_eq('página mostra o mesmo texto do ajax', $r['texto'], $mTexto[1] ?? null);
t_eq('círculo usa ponto decimal no CSS', number_format((float)$r['percentual'], 1, '.', ''), $mCss[1] ?? null);
t_true('página tem o script ao vivo', strpos($pagina, "searchParams.set('ajax_percentual', '1')") !== false);
t_true('página sem aviso/erro de PHP', !preg_match('/(Warning|Fatal error|Notice|Deprecated):/', $pagina));

// Com filtro de locação: ajax e página continuam iguais entre si.
$loc = $pdo->prepare("
    SELECT locacao_zona FROM estoque
     WHERE id_inventario = ? AND locacao_zona <> ''
     GROUP BY locacao_zona ORDER BY COUNT(*) DESC LIMIT 1
");
$loc->execute([$idInv]);
$zona = (string)$loc->fetchColumn();
if ($zona !== '') {
    $getLoc = $get + ['zi' => $zona, 'zf' => $zona];
    $rLoc   = ajax($getLoc);
    preg_match('/id="perc-text">([^<]*)</', dash(GESTOR, $getLoc), $mLoc);
    t_eq("filtro de locação (zona $zona): página = ajax", $rLoc['texto'] ?? 'x', $mLoc[1] ?? 'y');
}

// --- segurança -------------------------------------------------------------
$semLogin = dash([], $get + ['ajax_percentual' => '1']);
t_true('sem login não devolve percentual', strpos($semLogin, 'percentual') === false);

$semInv = dash(GESTOR, ['codigo' => $get['codigo']]);
t_true('sem inventário selecionado não liga o script', strpos($semInv, "searchParams.set('ajax_percentual'") === false);

// --- sobe quando um item é contado -----------------------------------------
// Linha ainda não contada (situacao A, cont_1 zerado) e de valor alto o bastante para mexer 0,1%.
$cand = $pdo->prepare("
    SELECT id_linha, cont_1, situacao, quantidade * custo_entrada AS valor
      FROM estoque
     WHERE empresa = ? AND revenda = ? AND id_inventario = ?
       AND situacao = 'A' AND COALESCE(cont_1, 0) = 0 AND cont2_em IS NULL AND cont3_em IS NULL AND cont4_em IS NULL
       AND quantidade > 0 AND custo_entrada > 0
     ORDER BY quantidade * custo_entrada DESC
     LIMIT 1
");
$cand->execute([$emp, $rev, $idInv]);
$linha = $cand->fetch(PDO::FETCH_ASSOC);

if (!$linha) {
    echo "  (sem linha não contada para o teste de subida)\n";
} else {
    $antes = (float)$r['percentual'];
    try {
        // O mesmo que a 1ª contagem grava quando a peça bate com o sistema.
        $pdo->prepare("UPDATE estoque SET cont_1 = quantidade, situacao = 'C' WHERE id_linha = ?")
            ->execute([$linha['id_linha']]);

        $depois = ajax($get);
        t_true("sobe depois de contar um item ($antes% → " . ($depois['texto'] ?? '?') . ')',
               (float)($depois['percentual'] ?? 0) > $antes);
        t_eq('subida = cálculo direto no banco', percentual_banco($pdo, $idInv, $emp, $rev), (float)($depois['percentual'] ?? -1));
    } finally {
        $pdo->prepare("UPDATE estoque SET cont_1 = ?, situacao = ? WHERE id_linha = ?")
            ->execute([$linha['cont_1'], $linha['situacao'], $linha['id_linha']]);
    }

    t_eq('linha devolvida como estava: volta ao percentual inicial', $antes, (float)(ajax($get)['percentual'] ?? -1));
}

t_fim();
