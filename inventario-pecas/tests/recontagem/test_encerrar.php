<?php
declare(strict_types=1);

require __DIR__ . '/fixture.php';

echo "test_encerrar\n";

rc_limpar($pdo);
rc_inventario($pdo);

$agora = '2026-09-24 10:00:00';

// Três linhas encerráveis: nao_contado, aguardando, contando.
$idNc = rc_linha($pdo, ['pub' => 'NC', 'qtd' => 5, 'sit' => 'A']);                                      // nao_contado
$idAg = rc_linha($pdo, ['pub' => 'AG', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']);                            // aguardando
$idCt = rc_linha($pdo, ['pub' => 'CT', 'qtd' => 5, 'c1' => 3, 'sit' => 'C', 'rod' => 2, 'col' => 1]);    // contando

// Três linhas que não podem ser encerradas.
$idBa = rc_linha($pdo, ['pub' => 'BA', 'qtd' => 5, 'c1' => 5, 'sit' => 'C']);                            // bateu
$idDe = rc_linha($pdo, ['pub' => 'DE', 'qtd' => 7, 'c1' => 1, 'c2' => 2, 'c2em' => $agora, 'c3' => 3, 'c3em' => $agora,
    'c4' => 4, 'c4em' => $agora, 'sit' => 'C', 'rod' => 4]); // decisao (teto: 4a feita, sem bater)
$idDs = rc_linha($pdo, ['pub' => 'DS', 'qtd' => 5, 'c1' => 3, 'sit' => 'D']);                             // descartado

$r = recontagem_encerrar($pdo, RC_INV, RC_EMP, RC_REV, [$idNc, $idAg, $idCt, $idBa, $idDe, $idDs, 123456789], 'Gestor Teste');

t_eq('encerrou os tres encerraveis', 3, $r['encerrados']);
t_eq('recusou o que bateu', 1, $r['recusados']['bateu']);
t_eq('recusou o de decisao', 1, $r['recusados']['decisao']);
t_eq('recusou o descartado', 1, $r['recusados']['descartado']);
t_eq('recusou id de fora do inventario', 1, $r['recusados']['nao_encontrado']);

// ── Grava encerrada_em / encerrada_por ──────────────────────────────────────
foreach ([$idNc, $idAg, $idCt] as $id) {
    $l = rc_ler($pdo, $id);
    t_true('encerrada_em gravado em ' . $id, $l['recontagem_encerrada_em'] !== null);
    t_eq('encerrada_por gravado em ' . $id, 'Gestor Teste', $l['recontagem_encerrada_por']);
    t_eq('situacao vira decisao em ' . $id, 'decisao', recontagem_situacao($l));
}

// Quem não podia ser encerrado não ganhou as colunas.
t_null('bateu nao ganhou encerrada_em', rc_ler($pdo, $idBa)['recontagem_encerrada_em']);
t_null('decisao nao ganhou encerrada_em', rc_ler($pdo, $idDe)['recontagem_encerrada_em']);
t_null('descartado nao ganhou encerrada_em', rc_ler($pdo, $idDs)['recontagem_encerrada_em']);

// ── Nome cortado em 120 caracteres e sem espaços nas pontas ────────────────
$idAg2 = rc_linha($pdo, ['pub' => 'AG2', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']);
recontagem_encerrar($pdo, RC_INV, RC_EMP, RC_REV, [$idAg2], '  ' . str_repeat('X', 150) . '  ');
t_eq('nome cortado em 120', 120, strlen(rc_ler($pdo, $idAg2)['recontagem_encerrada_por']));

// ── Um closed contando sai da fila do coletor ───────────────────────────────
t_eq('contando encerrado nao aparece mais na fila do coletor', false,
    in_array($idCt, array_column(recontagem_fila($pdo, RC_INV, RC_EMP, RC_REV, 1), 'id'), true));

// ── recontagem_gravar recusa item encerrado, com motivo redistribuido ──────
$base = ['id_inventario' => RC_INV, 'empresa' => RC_EMP, 'revenda' => RC_REV,
         'coletor' => 1, 'dupla' => 'Jose', 'entrada' => 'bipado'];

$gravouEnc = recontagem_gravar($pdo, array_merge($base, ['id' => $idCt, 'quantidade' => 9]));
t_eq('gravar recusa item encerrado', 'redistribuido', $gravouEnc['motivo']);
t_true('gravar nao teve ok', $gravouEnc['ok'] === false);
t_null('nada foi gravado no encerrado', rc_ler($pdo, $idCt)['cont2_em']);

// ── recontagem_mandar recusa item encerrado ─────────────────────────────────
$m = recontagem_mandar($pdo, RC_INV, RC_EMP, RC_REV, [$idAg], 2);
t_eq('mandar recusa o encerrado como decisao', 1, $m['recusados']['decisao']);
t_eq('mandar nao encerrou nenhum', 0, $m['enviados']);
t_eq('rodada do encerrado nao mudou', 1, (int)rc_ler($pdo, $idAg)['rodada_item']);

// ── Segundo clique não sobrescreve nem conta de novo ────────────────────────
$r2 = recontagem_encerrar($pdo, RC_INV, RC_EMP, RC_REV, [$idAg], 'Outro Gestor');
t_eq('segundo encerrar recusa (ja esta em decisao)', 1, $r2['recusados']['decisao']);
t_eq('segundo encerrar nao encerra de novo', 0, $r2['encerrados']);
t_eq('encerrada_por continua o primeiro', 'Gestor Teste', rc_ler($pdo, $idAg)['recontagem_encerrada_por']);

// ── Prévia conta corretamente os encerraveis ────────────────────────────────
$idAg3 = rc_linha($pdo, ['pub' => 'AG3', 'qtd' => 5, 'c1' => 3, 'sit' => 'C']); // aguardando, encerravel
$p = recontagem_previa_envio($pdo, RC_INV, RC_EMP, RC_REV, [$idAg3, $idBa, $idDe, $idDs]);
t_eq('previa: so o aguardando e encerravel', 1, $p['encerraveis']);

rc_limpar($pdo);

t_fim();
