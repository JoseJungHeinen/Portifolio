<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'contagem_2_lib.php';
require __DIR__ . '/assert.php';

echo "test_rodada\n";

$INV = 999999;
$EMP = 1;
$REV = 1;

$novoInventario = function (string $status = 'aberto') use ($pdo, $INV, $EMP, $REV) {
    $pdo->prepare("DELETE FROM inv_inventarios WHERE id = ?")->execute([$INV]);
    $pdo->prepare("
        INSERT INTO inv_inventarios (id, empresa, revenda, descricao, data_inicio, data_fim, status)
        VALUES (?, ?, ?, 'TESTE ENCERRAR RODADA', NOW(), NULL, ?)
    ")->execute([$INV, $EMP, $REV, $status]);
};

// ── Rodada corrente ────────────────────────────────────────────────────────
$novoInventario();
t_eq('inventario novo comeca na rodada 1', 1, contagem2_rodada_atual($pdo, $INV, $EMP, $REV));

// Inventario que nao existe nao pode virar "rodada 0" nem estourar: 1 e o
// estado seguro, porque nenhuma gravacao de recontagem passa por ele.
t_eq('inventario inexistente devolve 1', 1, contagem2_rodada_atual($pdo, 999998, $EMP, $REV));

// ── Encerrar ───────────────────────────────────────────────────────────────
t_true('encerrar a 1a devolve true', contagem2_encerrar_rodada($pdo, $INV, $EMP, $REV, 1, 'gestor.teste'));
t_eq('a rodada corrente virou 2', 2, contagem2_rodada_atual($pdo, $INV, $EMP, $REV));

$enc = contagem2_encerramento($pdo, $INV, $EMP, $REV);
t_eq('guardou quem encerrou', 'gestor.teste', $enc['por']);
t_true('guardou quando encerrou', $enc['em'] !== null && $enc['em'] !== '');

// Duplo clique: encerrar a rodada 1 de novo nao pode empurrar para 3 nem
// apagar o nome de quem encerrou primeiro.
t_eq('encerrar a mesma rodada de novo nao faz nada', false,
     contagem2_encerrar_rodada($pdo, $INV, $EMP, $REV, 1, 'outro.gestor'));
t_eq('a rodada continua 2', 2, contagem2_rodada_atual($pdo, $INV, $EMP, $REV));
t_eq('quem encerrou continua o primeiro', 'gestor.teste',
     contagem2_encerramento($pdo, $INV, $EMP, $REV)['por']);

// ── Teto: a rodada 3 nao existe enquanto nao houver cont_3 ─────────────────
t_eq('encerrar a 2a e recusado por enquanto', false,
     contagem2_encerrar_rodada($pdo, $INV, $EMP, $REV, 2, 'gestor.teste'));
t_eq('e a rodada continua 2', 2, contagem2_rodada_atual($pdo, $INV, $EMP, $REV));

// ── Inventario encerrado ───────────────────────────────────────────────────
$novoInventario('concluido');
t_eq('inventario concluido nao encerra rodada', false,
     contagem2_encerrar_rodada($pdo, $INV, $EMP, $REV, 1, 'gestor.teste'));
t_eq('e continua na rodada 1', 1, contagem2_rodada_atual($pdo, $INV, $EMP, $REV));

// ── Loja errada ────────────────────────────────────────────────────────────
$novoInventario();
t_eq('revenda errada nao encerra', false, contagem2_encerrar_rodada($pdo, $INV, $EMP, 5, 1, 'gestor.teste'));
t_eq('e o inventario continua na rodada 1', 1, contagem2_rodada_atual($pdo, $INV, $EMP, $REV));

// ── Quem pode gravar em qual rodada ────────────────────────────────────────
t_true('na rodada 1, a 1a contagem grava',  contagem2_pode_gravar($pdo, $INV, $EMP, $REV, 1));
t_eq('na rodada 1, a 2a nao grava', false,  contagem2_pode_gravar($pdo, $INV, $EMP, $REV, 2));

contagem2_encerrar_rodada($pdo, $INV, $EMP, $REV, 1, 'gestor.teste');
t_eq('na rodada 2, a 1a nao grava mais', false, contagem2_pode_gravar($pdo, $INV, $EMP, $REV, 1));
t_true('na rodada 2, a 2a grava',              contagem2_pode_gravar($pdo, $INV, $EMP, $REV, 2));

// A conferencia nao e rodada de contagem: passa sempre.
t_true('conferencia passa na rodada 1', contagem2_pode_gravar($pdo, $INV, $EMP, $REV, 3));

// ── Encerrar a 2ª (a ultima): tranca sem abrir outra ───────────────────────
t_eq('a 1a nao se encerra por aqui', false,
     contagem2_fechar_ultima_rodada($pdo, $INV, $EMP, $REV, 1, 'gestor.teste'));
t_null('2a aberta nao tem fechamento', contagem2_fechamento($pdo, $INV, $EMP, $REV));

t_true('encerrar a 2a devolve true', contagem2_fechar_ultima_rodada($pdo, $INV, $EMP, $REV, 2, 'Gestor Teste'));
t_eq('guardou quem encerrou a 2a', 'Gestor Teste', contagem2_fechamento($pdo, $INV, $EMP, $REV)['por']);
t_eq('a rodada continua 2', 2, contagem2_rodada_atual($pdo, $INV, $EMP, $REV));
t_eq('2a encerrada nao grava mais', false, contagem2_pode_gravar($pdo, $INV, $EMP, $REV, 2));
t_eq('nem a 1a', false, contagem2_pode_gravar($pdo, $INV, $EMP, $REV, 1));
t_true('conferencia continua passando', contagem2_pode_gravar($pdo, $INV, $EMP, $REV, 3));
t_true('com a 2a encerrada, a divisao fecha', contagem2_designacao_fechada($pdo, $INV, $EMP, $REV));

t_eq('encerrar de novo nao faz nada', false,
     contagem2_fechar_ultima_rodada($pdo, $INV, $EMP, $REV, 2, 'outro.gestor'));
t_eq('quem encerrou continua o primeiro', 'Gestor Teste', contagem2_fechamento($pdo, $INV, $EMP, $REV)['por']);

// ── Terminei ───────────────────────────────────────────────────────────────
$pdo->prepare("DELETE FROM inv_coletor_terminou WHERE id_inventario = ?")->execute([$INV]);
t_eq('ninguem terminou ainda', [], contagem2_terminaram($pdo, $INV, $EMP, $REV, 2));

contagem2_registrar_terminou($pdo, $INV, $EMP, $REV, 2, 3, 'Joao e Maria', 5);
$fim = contagem2_terminaram($pdo, $INV, $EMP, $REV, 2);
t_eq('registrou o coletor 3 com 5 pendentes', 5, $fim[3]['pendentes'] ?? null);
t_eq('guardou a dupla', 'Joao e Maria', $fim[3]['dupla'] ?? null);
t_eq('aviso e por rodada', [], contagem2_terminaram($pdo, $INV, $EMP, $REV, 1));

contagem2_registrar_terminou($pdo, $INV, $EMP, $REV, 2, 3, 'Joao e Maria', 2);
t_eq('avisar de novo sobrescreve', 2, contagem2_terminaram($pdo, $INV, $EMP, $REV, 2)[3]['pendentes'] ?? null);

contagem2_desfazer_terminou($pdo, $INV, $EMP, $REV, 2, 3);
t_eq('voltar a gravar desfaz o aviso', [], contagem2_terminaram($pdo, $INV, $EMP, $REV, 2));

$pdo->prepare("DELETE FROM inv_inventarios WHERE id = ?")->execute([$INV]);

t_fim();
