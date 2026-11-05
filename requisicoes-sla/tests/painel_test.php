<?php
// Checagem das funções puras do Painel de compras. Uso: C:\xampp\php\php.exe C:\Projetos\Requisicoes\tests\painel_test.php
declare(strict_types=1);
// O autoload do Composer já carrega src/helpers.php (files).
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\PainelComprasService as P;

$falhas = 0;
function confere(string $nome, bool $ok): void {
    global $falhas;
    echo ($ok ? 'ok    ' : 'FALHA ') . $nome . PHP_EOL;
    if (!$ok) $falhas++;
}

// ── diasPagamento ──
confere('"30 dias" = 30',             P::diasPagamento('30 dias') === 30.0);
confere('"30/60/90" = 60',            P::diasPagamento('30/60/90') === 60.0);
confere('"30/60" = 45',               P::diasPagamento('30/60') === 45.0);
confere('"30, 60 e 90 dias" = 60',    P::diasPagamento('30, 60 e 90 dias') === 60.0);
confere('"à vista" = 0',              P::diasPagamento('à vista') === 0.0);
confere('"A VISTA" = 0',              P::diasPagamento('A VISTA') === 0.0);
confere('"antecipado" = 0',           P::diasPagamento('antecipado') === 0.0);
confere('"28 DDL" = 28',              P::diasPagamento('28 DDL') === 28.0);
confere('prefixo da OC direta',       P::diasPagamento('Condição de pagamento: 30 dias') === 30.0);
confere('"" = null',                  P::diasPagamento('') === null);
confere('"a combinar" = null',        P::diasPagamento('a combinar') === null);
confere('"boleto" = null',            P::diasPagamento('boleto') === null);

// ── avaliarMeta ──
confere('sem valor = null',           P::avaliarMeta(null, '<=', 5) === null);
confere('4 <= 5 dentro',              P::avaliarMeta(4.0, '<=', 5) === true);
confere('5 <= 5 dentro (limite)',     P::avaliarMeta(5.0, '<=', 5) === true);
confere('6 <= 5 fora',                P::avaliarMeta(6.0, '<=', 5) === false);
confere('30 >= 30 dentro (limite)',   P::avaliarMeta(30.0, '>=', 30) === true);
confere('29,9 >= 30 fora',            P::avaliarMeta(29.9, '>=', 30) === false);
confere('0 <= 0,05 dentro',           P::avaliarMeta(0.0, '<=', 0.05) === true);

// ── periodoInicio ──
$hoje = new DateTimeImmutable('2026-10-29 14:30');
confere('mês corrente',               P::periodoInicio('mes', $hoje) === '2026-10-01');
confere('últimos 3 meses',            P::periodoInicio('3m', $hoje) === '2026-07-29');
confere('3 meses sem estourar o mês', P::periodoInicio('3m', new DateTimeImmutable('2026-05-31')) === '2026-02-28');
confere('ano corrente',               P::periodoInicio('ano', $hoje) === '2026-01-01');
confere('tudo = null',                P::periodoInicio('tudo', $hoje) === null);
confere('desconhecido = mês',         P::periodoInicio('xyz', $hoje) === '2026-10-01');

// ── entregaNoPrazo (fase 2) ──
confere('NF antes da previsão',       P::entregaNoPrazo('2026-10-20 10:00:00', '2026-10-25') === true);
confere('NF no dia previsto (limite)', P::entregaNoPrazo('2026-10-25 23:59:00', '2026-10-25') === true);
confere('NF depois da previsão',      P::entregaNoPrazo('2026-10-26', '2026-10-25') === false);

// ── diasPagamentoOc: campo da OC tem prioridade; texto é o fallback aproximado ──
confere('campo 28 → 28, exato',       P::diasPagamentoOc(28, '30/60/90') === [28.0, false]);
confere('campo 0 (à vista) → 0, exato', P::diasPagamentoOc(0, '') === [0.0, false]);
confere('sem campo → texto, aprox.',  P::diasPagamentoOc(null, '30/60/90') === [60.0, true]);
confere('sem campo e sem texto',      P::diasPagamentoOc(null, 'a combinar') === [null, false]);

// ── alertasCnd ──
$h = new DateTimeImmutable('2026-10-29');
$sevs = fn(array $a) => array_column($a, 'sev');
confere('CND vencida = bad',          $sevs(P::alertasCnd('2026-10-28', 'negativa', $h)) === ['bad']);
confere('CND vence hoje = warn',      $sevs(P::alertasCnd('2026-10-29', 'negativa', $h)) === ['warn']);
confere('CND vence em 15 dias = warn', $sevs(P::alertasCnd('2026-11-13', 'negativa', $h)) === ['warn']);
confere('CND vence em 16 dias = nada', P::alertasCnd('2026-11-14', 'negativa', $h) === []);
confere('CND positiva = warn',        $sevs(P::alertasCnd('2027-01-01', 'positiva', $h)) === ['warn']);
confere('positiva c/ efeito negativa = nada', P::alertasCnd('2027-01-01', 'positiva_efeitos_negativa', $h) === []);
confere('vencida e positiva = 2',     $sevs(P::alertasCnd('2026-01-01', 'positiva', $h)) === ['bad', 'warn']);
confere('sem CND = nada',             P::alertasCnd(null, null, $h) === []);
confere('texto cita os dias',         str_contains(P::alertasCnd('2026-11-08', null, $h)[0]['texto'], '10 dias'));

// ── regimeDesatualizado ──
confere('regime nunca confirmado',    P::regimeDesatualizado(null, $h) === true);
confere('regime há 5 meses = ok',     P::regimeDesatualizado('2026-05-30', $h) === false);
confere('regime há 6 meses (limite) = ok', P::regimeDesatualizado('2026-04-29', $h) === false);
confere('regime há mais de 6 meses',  P::regimeDesatualizado('2026-04-28', $h) === true);

// ── confirmacaoAtrasada: recebido e não confirmado há mais de 1 dia útil ──
confere('recebido hoje = ok',         P::confirmacaoAtrasada('2026-10-29 08:00:00', new DateTimeImmutable('2026-10-29 17:00')) === false);
confere('recebido ontem = ok (1 d.u.)', P::confirmacaoAtrasada('2026-10-28 08:00:00', new DateTimeImmutable('2026-10-29 17:00')) === false);
confere('recebido há 2 d.u. = atraso', P::confirmacaoAtrasada('2026-10-27 08:00:00', new DateTimeImmutable('2026-10-29 17:00')) === true);
confere('sexta → segunda = ok',       P::confirmacaoAtrasada('2026-10-23 16:00:00', new DateTimeImmutable('2026-10-26 09:00')) === false);
confere('sexta → terça = atraso',     P::confirmacaoAtrasada('2026-10-23 16:00:00', new DateTimeImmutable('2026-10-27 09:00')) === true);

// ── Etapa 4: pedidos abertos pelas 11 etapas do fluxograma ──
$and = fn(?int $atual) => ['atual' => $atual];
$c = P::contarPorEtapa([$and(2), $and(2), $and(6), $and(null), $and(11)]);
confere('contarPorEtapa: 11 chaves 1..11',  array_keys($c) === range(1, 11));
confere('contarPorEtapa: conta a etapa atual', $c[2] === 2 && $c[6] === 1 && $c[11] === 1);
confere('contarPorEtapa: sem atual fica fora', array_sum($c) === 4);
confere('contarPorEtapa: vazio = tudo zero', array_sum(P::contarPorEtapa([])) === 0);

$pl = P::etapasPipeline(P::contarPorEtapa([$and(2), $and(4)]), P::contarPorEtapa([$and(4), $and(10)]));
confere('pipeline: 11 etapas',              count($pl) === 11 && array_column($pl, 'numero') === range(1, 11));
confere('pipeline: rótulo do fluxograma',   $pl[5]['rotulo'] === 'Envio da OC');
confere('pipeline: soma SOL + requisição',  $pl[3]['n'] === 2 && $pl[3]['sol'] === 1 && $pl[3]['req'] === 1);
confere('pipeline: só requisição',          $pl[9]['n'] === 1 && $pl[9]['sol'] === 0);
confere('pipeline: 02 tem link (filtro da lista)', $pl[1]['link'] === 'solicitacoes?aba=todas&status=aguardando_aprovacao');
confere('pipeline: 06 sem link',            $pl[5]['link'] === null);
confere('pipeline: grupos 1-3 / 4-6 / 7-11', $pl[2]['fase'] === 1 && $pl[3]['fase'] === 2 && $pl[5]['fase'] === 2 && $pl[6]['fase'] === 3);

// ── Etapa 4: data de entrega da OC (recebimento físico, senão NF aprovada) ──
confere('entrega = recebimento físico',     P::dataEntregaOc('2026-10-20', '2026-10-25 10:00:00') === '2026-10-20');
confere('sem físico = data da NF aprovada', P::dataEntregaOc(null, '2026-10-25 10:00:00') === '2026-10-25');
confere('físico vazio = NF',                P::dataEntregaOc('', '2026-10-25') === '2026-10-25');
confere('nenhum = null (não entregue)',     P::dataEntregaOc(null, null) === null);
confere('físico no prazo, NF depois = no prazo',
        P::entregaNoPrazo(P::dataEntregaOc('2026-10-24', '2026-10-28'), '2026-10-25') === true);

// ── Etapa 4: OC com entrega atrasada ──
$hj = new DateTimeImmutable('2026-10-30');
$oc = ['entrega_prevista' => '2026-10-27', 'recebido_fisico_em' => null, 'nf_aprovada' => null,
       'transito_em' => null, 'cte_numero' => null, 'enviada_em' => '2026-10-20 10:00:00', 'fornecedor' => 'ACME'];
$at = P::entregaAtrasada($oc, $hj);
confere('atrasada sem trânsito = warn',     $at !== null && $at['sev'] === 'warn' && $at['dias'] === 3);
confere('texto cita a previsão',            $at !== null && str_contains($at['texto'], '27/10/2026') && str_contains($at['texto'], 'ACME'));
$ti = P::entregaAtrasada(['transito_em' => '2026-10-28 09:00:00', 'cte_numero' => '123'] + $oc, $hj);
confere('atrasada em trânsito = info',      $ti !== null && $ti['sev'] === 'info' && str_contains($ti['texto'], 'CT-e 123'));
confere('recebida (físico) = sem alerta',   P::entregaAtrasada(['recebido_fisico_em' => '2026-10-29'] + $oc, $hj) === null);
confere('NF aprovada = sem alerta',         P::entregaAtrasada(['nf_aprovada' => '2026-10-29'] + $oc, $hj) === null);
confere('prevista hoje = sem alerta',       P::entregaAtrasada(['entrega_prevista' => '2026-10-30'] + $oc, $hj) === null);
confere('sem previsão = sem alerta',        P::entregaAtrasada(['entrega_prevista' => null] + $oc, $hj) === null);
$ne = P::entregaAtrasada(['enviada_em' => null] + $oc, $hj);
confere('não enviada e atrasada: avisa',    $ne !== null && $ne['sev'] === 'warn' && str_contains($ne['texto'], 'não foi marcada como enviada'));

// ── Etapa 4: OC gerada/aprovada e não enviada há mais de 1 dia útil ──
confere('gerada hoje = ok',                 P::envioAtrasado('2026-10-29 08:00:00', new DateTimeImmutable('2026-10-29 17:00')) === false);
confere('gerada ontem = ok (1 d.u.)',       P::envioAtrasado('2026-10-28 08:00:00', new DateTimeImmutable('2026-10-29 17:00')) === false);
confere('gerada há 2 d.u. = atraso',        P::envioAtrasado('2026-10-27 08:00:00', new DateTimeImmutable('2026-10-29 17:00')) === true);
confere('sexta → segunda = ok',             P::envioAtrasado('2026-10-23 16:00:00', new DateTimeImmutable('2026-10-26 09:00')) === false);
confere('sexta → terça = atraso',           P::envioAtrasado('2026-10-23 16:00:00', new DateTimeImmutable('2026-10-27 09:00')) === true);

// ── Etapa 4: sugestão de bloqueio pelos dois últimos PERÍODOS (Fornecedor::sugereBloqueio) ──
$av = fn(string $f, string $per, float $nota, string $criado) => ['fornecedor_id' => $f, 'periodo' => $per, 'nota' => $nota, 'criado_em' => $criado];
$linhas = [
    // A: dois últimos períodos < 2 → sugere (mesmo lançados fora de ordem)
    $av('A', '2026-T2', 1.5, '2026-10-30 10:00:00'), $av('A', '2026-T3', 1.8, '2026-10-01 10:00:00'),
    // B: período mais recente ok, lançado antes → não sugere (por criado_em sugeriria)
    $av('B', '2026-T3', 3.5, '2026-09-01 10:00:00'), $av('B', '2026-T2', 1.0, '2026-10-30 10:00:00'), $av('B', '2026-T1', 1.0, '2026-10-29 10:00:00'),
    // C: só uma avaliação → não sugere
    $av('C', '2026-T3', 1.0, '2026-10-01 10:00:00'),
];
confere('bloqueio: só A',                   P::fornecedoresSugeridosBloqueio($linhas) === ['A']);
confere('bloqueio: vazio',                  P::fornecedoresSugeridosBloqueio([]) === []);

echo PHP_EOL . ($falhas ? "$falhas falha(s)" : 'tudo ok') . PHP_EOL;
exit($falhas ? 1 : 0);
