<?php
declare(strict_types=1);

use App\Models\Ofp;
use App\Services\ConciliacaoService;
use App\Services\PainelService;

fx_limpar();
$_SESSION['unidades'] = null;
$dia = fn(int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
$jeep = fx_unidade('Eurovale - Jeep');
$hoje = date('Y-m-d');

$A = fx_ofp(['status' => 'aguardando_credito', 'valor_nf_devolucao' => 100, 'valor_creditado_peca' => 40, 'data_abertura' => $dia(-10), 'data_decisao' => $dia(-8), 'prazo_data' => $dia(-1)]);
$B = fx_ofp(['status' => 'em_analise', 'valor_reclamado' => 200, 'data_abertura' => $dia(-45), 'prazo_data' => $dia(5)]);
$C = fx_ofp(['status' => 'aguardando_credito', 'unidade_id' => $jeep, 'valor_nf_devolucao' => 300, 'valor_creditado_peca' => 100, 'valor_icms_st' => 20, 'valor_creditado_st' => 5, 'data_abertura' => $dia(-70), 'data_decisao' => $dia(-60), 'prazo_data' => $dia(1)]);
$D = fx_ofp(['status' => 'creditada', 'unidade_id' => $jeep, 'valor_nf_devolucao' => 300, 'valor_creditado_peca' => 300, 'data_credito' => $hoje, 'data_abertura' => $dia(-20), 'data_decisao' => $dia(-15)]);
$E = fx_ofp(['status' => 'estornada', 'unidade_id' => $jeep, 'valor_nf_devolucao' => 500, 'valor_creditado_peca' => 500, 'data_credito' => $hoje, 'data_abertura' => $dia(-20), 'data_decisao' => $dia(-15)]);
$F = fx_ofp(['status' => 'rejeitada_final', 'valor_reclamado' => 50, 'data_abertura' => $dia(-100), 'data_decisao' => $dia(-90)]);
$G = fx_ofp(['status' => 'cancelada', 'valor_reclamado' => 70, 'data_abertura' => $dia(-3)]);
fx_db()->prepare("INSERT INTO ofp_eventos (id, ofp_id, status_de, status_para) VALUES (?, ?, 'em_analise', 'rejeitada')")->execute([uuid(), $C]);

$p = new PainelService();

T::teste('cards: abertos, ativas, vencidas e creditado do mês (sem estornada)', function () use ($p) {
    $c = $p->cards([]);
    T::igual(460.0, $c['aberto_peca']);
    T::igual(15.0, $c['aberto_st']);
    T::igual(3, $c['ativas']);
    T::igual(1, $c['vencidas']);
    T::igual(300.0, $c['creditado_mes']);
});

T::teste('matriz total bate com os cards', function () use ($p) {
    $c = $p->cards([]);
    $m = $p->matriz([]);
    T::igual(round($c['aberto_peca'] + $c['aberto_st'], 2), round($m['total'], 2));
    T::igual(round($m['total'], 2), round(array_sum($m['totalColunas']), 2));
});

T::teste('aging por faixa', function () use ($p) {
    $q = array_column($p->aging([]), 'qtd', 'faixa');
    T::igual(['0-30' => 1, '31-60' => 1, '61-90' => 1, '90+' => 0], $q);
});

T::teste('evolução: 12 meses, último é o atual, sem estornada', function () use ($p) {
    $e = $p->evolucao([]);
    T::igual(12, count($e));
    T::igual(date('m/Y'), $e[11]['mes']);
    T::igual(300.0, $e[11]['creditado']);
});

T::teste('causais: taxa de rejeição sobre decididas', function () use ($p) {
    $l = array_values(array_filter($p->topCausais([]), fn($r) => $r['codigo'] === 'AP'));
    T::igual(6, (int)$l[0]['total']);
    T::igual(5, (int)$l[0]['decididas']);
    T::igual(2, (int)$l[0]['rejeitadas']);
    T::igual(40.0, (float)$l[0]['taxa_rejeicao']);
});

T::teste('aberto do SQL bate com ConciliacaoService::emAberto nos status ativos', function () use ($A, $B, $C) {
    foreach ([$A, $B, $C] as $id) {
        $st = fx_db()->prepare('SELECT o.*, ' . Ofp::sqlAbertoPeca() . ' AS sp, ' . Ofp::sqlAbertoSt() . ' AS ss FROM ofps o WHERE o.id = ?');
        $st->execute([$id]);
        $o = $st->fetch();
        $php = ConciliacaoService::emAberto($o);
        T::igual($php['peca'], (float)$o['sp']);
        T::igual($php['st'], (float)$o['ss']);
    }
});
