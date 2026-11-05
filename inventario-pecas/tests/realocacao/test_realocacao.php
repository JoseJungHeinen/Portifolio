<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_oracle.php';
require 'realocacao_lib.php';
require __DIR__ . '/../conferencia/assert.php';

echo "test_realocacao\n";

// ── Regras puras ────────────────────────────────────────────────────────────
$zz = ['zona' => 'ZZ', 'rua' => 99, 'estante' => 99, 'prateleira' => 'ZZ', 'numero' => 99];
t_true('ZZ-99-99-ZZ-99 e sem locacao', realocacao_eh_sem_locacao($zz));
t_true('minuscula e espaco tambem', realocacao_eh_sem_locacao(['zona' => ' zz', 'rua' => '99', 'estante' => 99, 'prateleira' => 'zz ', 'numero' => 99]));
// Zona ZZ com qualquer resto: as variacoes que existem no Apollo.
t_true('ZZ-99-999-ZZZ-999 e indefinida', realocacao_eh_sem_locacao(['zona' => 'ZZ', 'rua' => 99, 'estante' => 999, 'prateleira' => 'ZZZ', 'numero' => 999]));
t_true('ZZ-1-1-1-1 e indefinida', realocacao_eh_sem_locacao(['zona' => 'ZZ', 'rua' => 1, 'estante' => 1, 'prateleira' => '1', 'numero' => 1]));
t_true('ZZ com uma parte diferente continua indefinida', realocacao_eh_sem_locacao(array_merge($zz, ['numero' => 98])));
$a1 = ['zona' => 'A', 'rua' => 1, 'estante' => 1, 'prateleira' => 'A', 'numero' => 1];
t_true('A-1-1-A-1 e indefinida', realocacao_eh_sem_locacao($a1));
t_true('a-01-001-a-1 tambem', realocacao_eh_sem_locacao(['zona' => 'a', 'rua' => '01', 'estante' => '001', 'prateleira' => 'a ', 'numero' => 1]));
t_eq('A-1-1-A-2 e locacao', false, realocacao_eh_sem_locacao(array_merge($a1, ['numero' => 2])));
t_eq('B-1-1-A-1 e locacao', false, realocacao_eh_sem_locacao(array_merge($a1, ['zona' => 'B'])));
t_eq('ZZ so na prateleira e locacao', false, realocacao_eh_sem_locacao(['zona' => '1', 'rua' => 21, 'estante' => 21, 'prateleira' => 'ZZ', 'numero' => 2]));
t_eq('Z sozinho na zona e locacao', false, realocacao_eh_sem_locacao(array_merge($a1, ['zona' => 'Z'])));

$v = realocacao_validar_locacao(['zona' => 'A', 'rua' => '1', 'estante' => '1', 'prateleira' => 'A', 'numero' => '1']);
t_true('gravar A-1-1-A-1 e recusado', isset($v['erros']['zona']));
$v = realocacao_validar_locacao(['zona' => 'ZZ', 'rua' => '1', 'estante' => '1', 'prateleira' => '1', 'numero' => '1']);
t_true('gravar zona ZZ e recusado', isset($v['erros']['zona']));

$v = realocacao_validar_locacao(['zona' => 'zz', 'rua' => '99', 'estante' => '99', 'prateleira' => 'ZZ', 'numero' => '99']);
t_true('gravar o proprio ZZ e recusado', isset($v['erros']['zona']));
$v = realocacao_validar_locacao(['zona' => '1', 'rua' => '22', 'estante' => '13', 'prateleira' => 'a', 'numero' => '1']);
t_eq('locacao normal passa', [], $v['erros']);
$v = realocacao_validar_locacao(['zona' => '1', 'rua' => '100', 'estante' => '13', 'prateleira' => 'A', 'numero' => '1']);
t_true('regras do Apollo continuam valendo', isset($v['erros']['rua']));

t_eq('bipado + bipado', 'bipado', realocacao_entrada('bipado', 'bipado'));
t_eq('item digitado', 'digitado', realocacao_entrada('digitado', 'bipado'));
t_eq('locacao digitada', 'digitado', realocacao_entrada('bipado', 'digitado'));
$lancou = false;
try { realocacao_entrada('', 'bipado'); } catch (InvalidArgumentException $e) { $lancou = true; }
t_true('entrada vazia lanca', $lancou);

// ── Apollo (leitura; gravacao so com rollback) ──────────────────────────────
$ora = oracle_conectar();
if (!$ora) {
    echo "  (Apollo indisponivel: pulando a parte de banco)\n";
    t_fim();
    exit;
}

$achar = function (string $cond) use ($ora): ?string {
    $r = conferencia_ora_consultar($ora, "
        SELECT e.item_estoque_pub
          FROM pec_item_revenda r
          JOIN pec_item_estoque e ON e.empresa = r.empresa AND e.item_estoque = r.item_estoque
         WHERE r.empresa = 1 AND r.revenda = 1 AND $cond
           AND e.item_estoque_pub IS NOT NULL AND ROWNUM = 1", []);
    return $r === [] ? null : (string)$r[0]['ITEM_ESTOQUE_PUB'];
};
$semLoc = $achar("r.locacao_zona = 'ZZ' AND r.locacao_rua = 99 AND r.locacao_estante = 99 AND r.locacao_prateleira = 'ZZ' AND r.locacao_numero = 99");
$comLoc = $achar("r.locacao_zona NOT IN ('ZZ', 'A') AND r.locacao_rua <> 99");
$a111   = $achar("r.locacao_zona = 'A' AND r.locacao_rua = 1 AND r.locacao_estante = 1 AND r.locacao_prateleira = 'A' AND r.locacao_numero = 1");
$zzVar  = $achar("r.locacao_zona = 'ZZ' AND NOT (r.locacao_rua = 99 AND r.locacao_estante = 99 AND r.locacao_prateleira = 'ZZ' AND r.locacao_numero = 99)");

$c = realocacao_checar_item($ora, 1, 1, (string)$semLoc);
t_true("item sem locacao ($semLoc) e aceito", $c['ok']);
t_true('devolve o codigo interno', is_int($c['item_estoque']));

$c2 = realocacao_checar_item($ora, 1, 1, (string)$comLoc);
t_eq("item com locacao ($comLoc) e recusado", false, $c2['ok']);
t_true('o motivo mostra a locacao atual', strpos($c2['erro'], 'já tem locação') !== false);

$cA = realocacao_checar_item($ora, 1, 1, (string)$a111);
t_true("item em A-1-1-A-1 ($a111) e aceito", $cA['ok']);
$cZ = realocacao_checar_item($ora, 1, 1, (string)$zzVar);
t_true("item em ZZ fora do padrao ($zzVar, " . ($cZ['atual'] ? realocacao_juntar($cZ['atual']) : '?') . ") e aceito", $cZ['ok']);

$c3 = realocacao_checar_item($ora, 1, 1, '1-22-13-A-1');
t_true('etiqueta bipada no lugar do item avisa', strpos($c3['erro'], 'etiqueta de locação') !== false);

$c4 = realocacao_checar_item($ora, 1, 1, 'NAOEXISTE999XYZ');
t_true('item inexistente', strpos($c4['erro'], 'não encontrado') !== false);

// Gravacao: dentro da transacao, sem commit — e confere que voltou.
$val = realocacao_validar_locacao(['zona' => '1', 'rua' => '22', 'estante' => '13', 'prateleira' => 'A', 'numero' => '1']);
conferencia_gravar_locacao($ora, 1, 1, $c['item_estoque'], $val['valores'], false);
t_eq('dentro da transacao, gravou', '1-22-13-A-1', realocacao_juntar(conferencia_locacao_da_loja($ora, 1, 1, $c['item_estoque'])));
oci_rollback($ora);
t_true('depois do rollback, sem locacao de novo', realocacao_eh_sem_locacao(conferencia_locacao_da_loja($ora, 1, 1, $c['item_estoque'])));

oci_close($ora);
t_fim();
