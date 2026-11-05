<?php
// Checagem da barra de andamento (11 etapas do fluxograma). Uso: C:\xampp\php\php.exe C:\Projetos\Requisicoes\tests\andamento_test.php
declare(strict_types=1);
// O autoload do Composer já carrega src/helpers.php (files).
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\AndamentoService as A;

$falhas = 0;
function confere(string $nome, bool $ok): void {
    global $falhas;
    echo ($ok ? 'ok    ' : 'FALHA ') . $nome . PHP_EOL;
    if (!$ok) $falhas++;
}
/** Estados das 11 etapas numa string: c=concluída a=atual p=pendente n=não se aplica. */
function estados(array $r): string {
    $m = ['concluida' => 'c', 'atual' => 'a', 'pendente' => 'p', 'nao_se_aplica' => 'n'];
    return implode('', array_map(fn($e) => $m[$e['estado']] ?? '?', $r['etapas']));
}

$sol   = fn(string $status, array $x = []) => $x + ['status' => $status, 'finalidade' => 'uso_consumo', 'recebido_em' => null, 'entregue_em' => null];
$req   = fn(string $status) => ['status' => $status, 'tipo' => 'compra'];
$oc    = fn(array $x = []) => $x + ['status' => 'aprovado', 'enviada_em' => null, 'transito_em' => null, 'cte_numero' => null,
                                    'recebido_fisico_em' => null, 'recebido_fisico_por' => null, 'nf' => null];
$cheio = $oc(['status' => 'recebido_total', 'enviada_em' => '2026-10-30 10:00:00', 'transito_em' => '2026-10-31 09:00:00',
              'cte_numero' => '123', 'recebido_fisico_em' => '2026-11-02 00:00:00', 'recebido_fisico_por' => 'João', 'nf' => 'aprovada']);

// ── Estrutura ──
$r = A::calcular(['sol' => $sol('aguardando_aprovacao')]);
confere('11 etapas numeradas',              count($r['etapas']) === 11 && $r['etapas'][0]['numero'] === 1 && $r['etapas'][10]['numero'] === 11);
confere('rótulos do fluxograma',            $r['etapas'][6]['rotulo'] === 'NF e XML' && $r['etapas'][1]['rotulo'] === 'Aprovação do gestor');

// ── Solicitação ──
confere('SOL aguardando gestor: 02 atual',  estados($r) === 'cappppppppp' && $r['atual'] === 2);
confere('resumo compacto',                  $r['resumo'] === 'Etapa 2/11 · Aprovação do gestor');

$r = A::calcular(['sol' => $sol('aprovada', ['finalidade' => 'revenda'])]);
confere('revenda: 02 não se aplica, 03 atual', estados($r) === 'cnapppppppp' && $r['atual'] === 3);
confere('revenda: resumo etapa 3',          $r['resumo'] === 'Etapa 3/11 · Solicitação de OC');

$r = A::calcular(['sol' => $sol('aprovada')]);
confere('SOL aprovada sem requisição: 03 atual', estados($r) === 'ccapppppppp');

$r = A::calcular(['sol' => $sol('em_compra'), 'requisicao' => $req('em_aprovacao')]);
confere('requisição em alçada: 03 atual',   estados($r) === 'ccapppppppp' && $r['etapas'][2]['detalhe'] !== null);

$r = A::calcular(['sol' => $sol('em_compra'), 'requisicao' => $req('rejeitado')]);
confere('requisição rejeitada: 03 atual (em revisão)', estados($r) === 'ccapppppppp' && $r['interrompida'] === null);

$r = A::calcular(['sol' => $sol('em_compra'), 'requisicao' => $req('aprovado')]);
confere('requisição aprovada sem cotação: 04 atual', estados($r) === 'cccappppppp');

$r = A::calcular(['sol' => $sol('em_compra'), 'requisicao' => $req('aprovado'), 'cotacoes' => 1, 'respostas' => 0]);
confere('cotação sem resposta: 04 atual com detalhe', estados($r) === 'cccappppppp' && $r['etapas'][3]['detalhe'] === 'Aguardando respostas');

$r = A::calcular(['sol' => $sol('em_compra'), 'requisicao' => $req('aprovado'), 'cotacoes' => 1, 'respostas' => 2]);
confere('cotação respondida: 05 atual',     estados($r) === 'ccccapppppp');

$base = ['sol' => $sol('em_compra'), 'requisicao' => $req('aprovado'), 'cotacoes' => 1, 'respostas' => 2];
$r = A::calcular($base + ['ocs' => [$oc()]]);
confere('OC gerada: 06 atual',              estados($r) === 'cccccappppp');

$r = A::calcular(['sol' => $sol('em_compra'), 'requisicao' => $req('aprovado'), 'ocs' => [$oc()]]);
confere('OC sem cotação registrada: 04/05 concluídas', estados($r) === 'cccccappppp');

$r = A::calcular($base + ['ocs' => [$oc(['status' => 'enviado', 'enviada_em' => '2026-10-30 10:00:00'])]]);
confere('OC enviada: 07 atual',             estados($r) === 'ccccccapppp' && $r['resumo'] === 'Etapa 7/11 · NF e XML');
confere('OC enviada: detalhe com data',     str_contains((string)$r['etapas'][5]['detalhe'], '30/10'));

$r = A::calcular($base + ['ocs' => [$oc(['enviada_em' => '2026-10-30 10:00:00', 'transito_em' => '2026-10-31 09:00:00',
                                         'cte_numero' => '999', 'recebido_fisico_em' => '2026-11-01 00:00:00', 'recebido_fisico_por' => 'Ana'])]]);
confere('marcas 08/09 antes da NF: 07 atual, 08/09 concluídas', estados($r) === 'ccccccaccpp');
confere('CT-e no detalhe',                  str_contains((string)$r['etapas'][7]['detalhe'], '999'));
confere('quem recebeu no detalhe',          str_contains((string)$r['etapas'][8]['detalhe'], 'Ana'));

$r = A::calcular($base + ['ocs' => [$oc(['enviada_em' => '2026-10-30 10:00:00', 'nf' => 'pendente_conferencia'])]]);
confere('NF registrada sem trânsito: 08 atual', estados($r) === 'cccccccappp');

$r = A::calcular($base + ['ocs' => [$oc(['enviada_em' => '2026-10-30 10:00:00', 'transito_em' => '2026-10-31 09:00:00',
                                         'recebido_fisico_em' => '2026-11-01 00:00:00', 'nf' => 'divergente'])]]);
confere('NF divergente: 10 atual',          estados($r) === 'cccccccccap');

$r = A::calcular(['sol' => $sol('em_compra', ['recebido_em' => '2026-11-02 10:00:00'])] + $base + ['ocs' => [$cheio]]);
confere('tudo recebido: 11 atual (solicitante confirma)', estados($r) === 'cccccccccca' && $r['atual'] === 11);

$r = A::calcular(['sol' => $sol('concluida', ['recebido_em' => '2026-11-02 10:00:00', 'entregue_em' => '2026-11-02 11:00:00'])] + $base + ['ocs' => [$cheio]]);
confere('SOL concluída: 11/11',             estados($r) === 'ccccccccccc' && $r['atual'] === null && $r['concluida'] === true);
confere('resumo concluída',                 $r['resumo'] === 'Concluída · 11/11');

$r = A::calcular(['sol' => $sol('concluida', ['finalidade' => 'revenda', 'entregue_em' => '2026-11-02 11:00:00'])] + $base + ['ocs' => [$cheio]]);
confere('revenda concluída também 11/11',   estados($r) === 'cnccccccccc' && $r['concluida'] === true && $r['resumo'] === 'Concluída · 11/11');

// ── Várias OCs: a etapa só conclui quando todas as OCs vivas cumprem ──
$r = A::calcular($base + ['ocs' => [$oc(['enviada_em' => '2026-10-30 10:00:00']), $oc()]]);
confere('2 OCs, 1 enviada: 06 atual',       estados($r) === 'cccccappppp');
$r = A::calcular($base + ['ocs' => [$oc(['enviada_em' => '2026-10-30 10:00:00']), $oc(['status' => 'cancelado'])]]);
confere('OC cancelada é ignorada',          estados($r) === 'ccccccapppp');
$r = A::calcular($base + ['ocs' => [$oc(['status' => 'cancelado'])]]);
confere('só OC cancelada = sem OC: 05 atual', estados($r) === 'ccccapppppp');

// ── Requisição sem SOL ──
$r = A::calcular(['requisicao' => $req('rascunho')]);
confere('sem SOL: 01/02 não se aplicam, 03 atual', estados($r) === 'nnapppppppp' && $r['atual'] === 3);
$r = A::calcular(['requisicao' => $req('aprovado'), 'cotacoes' => 1, 'respostas' => 1, 'ocs' => [$cheio]]);
confere('sem SOL: NF conferida = disponível (11/11)', estados($r) === 'nnccccccccc' && $r['concluida'] === true);

// ── Interrupções ──
$r = A::calcular(['sol' => $sol('recusada')]);
confere('SOL recusada: interrompida, sem atual', $r['interrompida'] === 'Solicitação recusada' && $r['atual'] === null
        && $r['resumo'] === 'Solicitação recusada' && estados($r) === 'cpppppppppp');
$r = A::calcular(['sol' => $sol('cancelada')]);
confere('SOL cancelada: interrompida',      $r['interrompida'] === 'Solicitação cancelada' && $r['atual'] === null);
$r = A::calcular(['requisicao' => $req('cancelado')]);
confere('requisição cancelada: interrompida', $r['interrompida'] === 'Requisição cancelada' && $r['atual'] === null);

// ── Robustez: entrada vazia não quebra ──
$r = A::calcular([]);
confere('sem nada: como requisição sem SOL', count($r['etapas']) === 11 && estados($r) === 'nnapppppppp');

echo PHP_EOL . ($falhas ? "$falhas falha(s)" : 'tudo ok') . PHP_EOL;
exit($falhas ? 1 : 0);
