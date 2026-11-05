<?php
// Checagem do cálculo de SLA em dias úteis. Uso: C:\xampp\php\php.exe C:\Projetos\Requisicoes\tests\sla_test.php
declare(strict_types=1);
// O autoload do Composer já carrega src/helpers.php (files).
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\SolicitacaoCompraService as Sol;

$falhas = 0;
function confere(string $nome, bool $ok): void {
    global $falhas;
    echo ($ok ? 'ok    ' : 'FALHA ') . $nome . PHP_EOL;
    if (!$ok) $falhas++;
}
$d = fn(string $s) => new DateTimeImmutable($s);
$f = fn(?DateTimeImmutable $x) => $x?->format('Y-m-d H:i');

confere('sexta 15h + 1 útil = segunda 15h',  $f(Sol::vencimentoSla($d('2026-10-30 15:00'), 'emergencial')) === '2026-11-02 15:00');
confere('quarta 10h + 2 úteis = sexta 10h',  $f(Sol::vencimentoSla($d('2026-10-28 10:00'), 'urgente'))     === '2026-10-30 10:00');
confere('quarta 10h + 5 úteis = quarta seg.', $f(Sol::vencimentoSla($d('2026-10-28 10:00'), 'planejado'))   === '2026-11-04 10:00');
confere('sábado + 1 útil = terça 00:00',     $f(Sol::vencimentoSla($d('2026-10-31 11:00'), 'emergencial')) === '2026-11-03 00:00');
confere('domingo + 2 úteis = quarta 00:00',  $f(Sol::vencimentoSla($d('2026-11-01 09:00'), 'urgente'))     === '2026-11-04 00:00');
confere('azul sem SLA',                       Sol::vencimentoSla($d('2026-10-28 10:00'), 'estrategico') === null);

$agora = $d('2026-11-05 12:00');
confere('situação no prazo',  Sol::situacaoSla(['status' => 'em_compra', 'sla_vence_em' => '2026-11-06 10:00', 'concluida_em' => null], $agora)['chave'] === 'no_prazo');
confere('situação vence hoje', Sol::situacaoSla(['status' => 'aprovada', 'sla_vence_em' => '2026-11-05 18:00', 'concluida_em' => null], $agora)['chave'] === 'vence_hoje');
$atr = Sol::situacaoSla(['status' => 'aprovada', 'sla_vence_em' => '2026-11-03 10:00', 'concluida_em' => null], $agora);
confere('situação atrasada 2 úteis', $atr['chave'] === 'atrasada' && $atr['dias'] === 2);
confere('concluída no prazo', Sol::situacaoSla(['status' => 'concluida', 'sla_vence_em' => '2026-11-05 10:00', 'concluida_em' => '2026-11-04 09:00'], $agora)['chave'] === 'concluida_no_prazo');
confere('concluída fora',     Sol::situacaoSla(['status' => 'concluida', 'sla_vence_em' => '2026-11-03 10:00', 'concluida_em' => '2026-11-04 09:00'], $agora)['chave'] === 'concluida_fora');
confere('sem SLA',            Sol::situacaoSla(['status' => 'aprovada', 'sla_vence_em' => null, 'concluida_em' => null], $agora)['chave'] === 'sem_sla');
confere('cancelada: nada',    Sol::situacaoSla(['status' => 'cancelada', 'sla_vence_em' => '2026-11-03 10:00', 'concluida_em' => null], $agora) === null);

echo PHP_EOL . ($falhas ? "$falhas falha(s)" : 'tudo ok') . PHP_EOL;
exit($falhas ? 1 : 0);
