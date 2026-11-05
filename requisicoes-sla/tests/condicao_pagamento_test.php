<?php
// Condição de pagamento em parcelas (OC). Uso: C:\xampp\php\php.exe C:\Projetos\Requisicoes\tests\condicao_pagamento_test.php
declare(strict_types=1);
// O autoload do Composer já carrega src/helpers.php (files).
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CondicaoPagamento as C;

$falhas = 0;
function confere(string $nome, bool $ok): void {
    global $falhas;
    echo ($ok ? 'ok    ' : 'FALHA ') . $nome . PHP_EOL;
    if (!$ok) $falhas++;
}

// ── parcelas() ──
confere('"30" = [30]',                    C::parcelas('30') === [30]);
confere('"30/60" = [30,60]',              C::parcelas('30/60') === [30, 60]);
confere('"30/60/90" = [30,60,90]',        C::parcelas('30/60/90') === [30, 60, 90]);
confere('espaços " 30 / 60 / 90 "',       C::parcelas(' 30 / 60 / 90 ') === [30, 60, 90]);
confere('"30, 60 e 90 dias"',             C::parcelas('30, 60 e 90 dias') === [30, 60, 90]);
confere('"30 dias"',                      C::parcelas('30 dias') === [30]);
confere('"28 DDL"',                       C::parcelas('28 DDL') === [28]);
confere('"28/35/42 ddl"',                 C::parcelas('28/35/42 ddl') === [28, 35, 42]);
confere('"à vista" = [0]',                C::parcelas('à vista') === [0]);
confere('"A VISTA" = [0]',                C::parcelas('A VISTA') === [0]);
confere('"Antecipado" = [0]',             C::parcelas('Antecipado') === [0]);
confere('"0" = [0]',                      C::parcelas('0') === [0]);
confere('"" = null',                      C::parcelas('') === null);
confere('"   " = null',                   C::parcelas('   ') === null);
confere('"a combinar" = null',            C::parcelas('a combinar') === null);
confere('"boleto" = null',                C::parcelas('boleto') === null);
confere('"3x" sozinho = null',            C::parcelas('3x') === null);
confere('"3x sem juros" = null',          C::parcelas('3x sem juros') === null);
confere('"3x 30/60/90" = 3 parcelas',     C::parcelas('3x 30/60/90') === [30, 60, 90]);
confere('"2x 30/60/90" contradiz = null', C::parcelas('2x 30/60/90') === null);
confere('percentual ignorado',            C::parcelas('50% 30/60') === [30, 60]);
confere('percentual com vírgula',         C::parcelas('30/60 (12,5% entrada)') === [30, 60]);
confere('prefixo do portal',              C::parcelas('Condição de pagamento: 30/60/90') === [30, 60, 90]);
confere('prefixo sem acento',             C::parcelas('condicao de pagamento: 28 ddl') === [28]);

// ── prazoMedio() / normalizar() ──
confere('média 30/60/90 = 60',           C::prazoMedio([30, 60, 90]) === 60.0);
confere('média 30/60 = 45',              C::prazoMedio([30, 60]) === 45.0);
confere('média 30/45 = 37,5',            C::prazoMedio([30, 45]) === 37.5);
confere('normalizar = "30/60/90"',       C::normalizar([30, 60, 90]) === '30/60/90');

// ── validar(): obrigatório, reconhecido, 0–365 por parcela, até 12 parcelas ──
$v = C::validar('30/60/90');
confere('válida: texto',                 is_array($v) && $v['condicao_pagamento'] === '30/60/90');
confere('válida: parcelas_dias',         is_array($v) && $v['parcelas_dias'] === '30/60/90');
confere('válida: prazo médio 60',        is_array($v) && $v['prazo_pagamento_dias'] === 60);
$v = C::validar('  30, 60 e 90 dias  ');
confere('texto livre guardado aparado',  is_array($v) && $v['condicao_pagamento'] === '30, 60 e 90 dias' && $v['parcelas_dias'] === '30/60/90');
$v = C::validar('30/45');
confere('prazo médio arredonda (37,5→38)', is_array($v) && $v['prazo_pagamento_dias'] === 38);
$v = C::validar('à vista');
confere('à vista: 0 dias',               is_array($v) && $v['parcelas_dias'] === '0' && $v['prazo_pagamento_dias'] === 0);
confere('vazio = erro',                  is_string(C::validar('')));
confere('não reconhecido = erro',        is_string(C::validar('a combinar')));
confere('parcela > 365 = erro',          is_string(C::validar('30/400')));
confere('365 aceito',                    is_array(C::validar('365')));
confere('13 parcelas = erro',            is_string(C::validar(implode('/', range(10, 130, 10)))));
confere('12 parcelas aceito',            is_array(C::validar(implode('/', range(10, 120, 10)))));
confere('texto > 100 = erro',            is_string(C::validar('30/60 ' . str_repeat('a', 100))));

// ── descricao() (dica ao lado do campo) e rotulo() (detalhe/PDF) ──
confere('descrição 3 parcelas',          C::descricao([30, 60, 90]) === '3 parcelas: 30, 60 e 90 dias (prazo médio 60)');
confere('descrição 1 parcela',           C::descricao([28]) === '1 parcela: 28 dias');
confere('descrição à vista',             C::descricao([0]) === 'À vista');
confere('descrição 30/45 média 37,5',    C::descricao([30, 45]) === '2 parcelas: 30 e 45 dias (prazo médio 37,5)');
confere('rótulo com parcelas',           C::rotulo('30/60/90', '30/60/90', 60) === '30/60/90 (3 parcelas)');
confere('rótulo texto livre',            C::rotulo('30, 60 e 90 dias', '30/60/90', 60) === '30, 60 e 90 dias (3 parcelas: 30/60/90)');
confere('rótulo 1 parcela',              C::rotulo('28', '28', 28) === '28 dias (1 parcela)');
confere('rótulo à vista',                C::rotulo('à vista', '0', 0) === 'à vista');
confere('rótulo OC antiga (só dias)',    C::rotulo(null, null, 30) === '30 dias');
confere('rótulo sem nada = null',        C::rotulo(null, null, null) === null);

// ── compatibilidade: diasPagamento do painel usa o mesmo parser ──
confere('painel "30/60/90" = 60',        \App\Services\PainelComprasService::diasPagamento('30/60/90') === 60.0);

// ── RequisicaoCotacao: JSON na observação da vencedora ──
use App\Models\RequisicaoCotacao as RC;
$obs = RC::observacaoComPrazos('2026-11-20', '30/60/90');
$p   = RC::prazosOc($obs);
confere('JSON ida e volta: condição',    $p['condicao_pagamento'] === '30/60/90');
confere('JSON ida e volta: entrega',     $p['entrega_prevista'] === '2026-11-20');
confere('JSON ida e volta: prazo médio', $p['prazo_pagamento_dias'] === 60);
confere('JSON cabe em 255',              strlen((string)RC::observacaoComPrazos('2026-11-20', str_repeat('30/', 33) . '3')) <= 255);
$antigo = RC::prazosOc('{"oc_entrega_prevista":"2026-11-20","oc_prazo_pagamento_dias":45}');
confere('JSON antigo: condição = "45"',  $antigo['condicao_pagamento'] === '45' && $antigo['prazo_pagamento_dias'] === 45);
confere('nada a guardar = null',         RC::observacaoComPrazos(null, null) === null && RC::observacaoComPrazos('', '') === null);
confere('observação vazia',              RC::prazosOc(null)['condicao_pagamento'] === null);

echo PHP_EOL . ($falhas ? "$falhas falha(s)" : 'tudo ok') . PHP_EOL;
exit($falhas ? 1 : 0);
