<?php
// Checagem da classificação de fornecedores (etapa 3). Uso: C:\xampp\php\php.exe C:\Projetos\Requisicoes\tests\fornecedor_test.php
declare(strict_types=1);
// O autoload do Composer já carrega src/helpers.php (files).
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Models\Fornecedor as F;
use App\Services\FornecedorListaService as L;

$falhas = 0;
function confere(string $nome, bool $ok): void {
    global $falhas;
    echo ($ok ? 'ok    ' : 'FALHA ') . $nome . PHP_EOL;
    if (!$ok) $falhas++;
}

// ── Nota ponderada: prazo 20% · qualidade 20% · preço 30% · conformidade 30% ──
confere('nota tudo 5 = 5',             F::notaPonderada(['prazo' => 5, 'qualidade' => 5, 'preco' => 5, 'conformidade' => 5]) === 5.0);
confere('nota 5/5/0/0 = 2,0',          F::notaPonderada(['prazo' => 5, 'qualidade' => 5, 'preco' => 0, 'conformidade' => 0]) === 2.0);
confere('nota 0/0/5/5 = 3,0',          F::notaPonderada(['prazo' => 0, 'qualidade' => 0, 'preco' => 5, 'conformidade' => 5]) === 3.0);
confere('nota 4/3/2/1 = 2,3',          F::notaPonderada(['prazo' => 4, 'qualidade' => 3, 'preco' => 2, 'conformidade' => 1]) === 2.3);
confere('nota 3/3/3/2,5 = 2,85',       F::notaPonderada(['prazo' => 3, 'qualidade' => 3, 'preco' => 3, 'conformidade' => 2.5]) === 2.85);
confere('critério fora de 0–5',        F::validarCriterios(['prazo' => 6, 'qualidade' => 3, 'preco' => 3, 'conformidade' => 3]) !== null);
confere('critério faltando',           F::validarCriterios(['prazo' => 3, 'qualidade' => 3, 'preco' => 3]) !== null);
confere('critérios válidos',           F::validarCriterios(['prazo' => 0, 'qualidade' => 5, 'preco' => 2.5, 'conformidade' => '4']) === null);
confere('formatar 2,85',               F::formatarNota(2.85) === '2,85');
confere('formatar 3,0',                F::formatarNota(3.0) === '3,0');
confere('formatar 2,3',                F::formatarNota(2.3) === '2,3');

// ── Plano de ação (< 3,0) e sugestão de bloqueio (< 2,0 nos dois últimos períodos) ──
confere('2,99 → plano de ação',        F::precisaPlanoAcao(2.99));
confere('3,0 → sem plano',             !F::precisaPlanoAcao(3.0));
$av = fn(string $p, float $n, string $em = '2026-10-01 10:00:00') => ['periodo' => $p, 'nota' => $n, 'criado_em' => $em];
confere('bloqueio: dois últimos < 2',  F::sugereBloqueio([$av('2026-T3', 1.5), $av('2026-T4', 1.9)]));
confere('bloqueio: um só período',     !F::sugereBloqueio([$av('2026-T4', 1.0)]));
confere('bloqueio: último ≥ 2',        !F::sugereBloqueio([$av('2026-T3', 1.0), $av('2026-T4', 2.0)]));
confere('bloqueio: antigo < 2 não conta', !F::sugereBloqueio([$av('2026-T1', 1.0), $av('2026-T2', 1.0), $av('2026-T3', 3.5), $av('2026-T4', 1.0)]));
confere('bloqueio: ordem por período, não por entrada', F::sugereBloqueio([$av('2026-T4', 1.0), $av('2025-T4', 4.0), $av('2026-T3', 1.5)]));
confere('bloqueio: vazio',             !F::sugereBloqueio([]));
confere('período de 30/10/2026',       F::periodoDe('2026-10-30') === '2026-T4');
confere('período de 01/04/2026',       F::periodoDe('2026-04-01') === '2026-T2');
confere('período válido',              F::periodoValido('2026-T4') && !F::periodoValido('2026-T5') && !F::periodoValido('26-T1'));

// ── Raiz do CNPJ / matriz ──
confere('raiz com máscara',            F::cnpjRaiz('11.222.333/0001-81') === '05570714');
confere('raiz vazia',                  F::cnpjRaiz(null) === '' && F::cnpjRaiz('123') === '');
confere('matriz 0001',                 F::ehMatriz('05570714000159'));
confere('filial 0002',                 !F::ehMatriz('05570714000230'));

// ── podeCotar (cotação/OC) ──
confere('pendente pode cotar',         F::podeCotar(['situacao' => 'pendente', 'ativo' => 1]));
confere('homologado pode cotar',       F::podeCotar(['situacao' => 'homologado', 'ativo' => 1]));
confere('bloqueado não cota',          !F::podeCotar(['situacao' => 'bloqueado', 'ativo' => 1]));
confere('inativo não cota',            !F::podeCotar(['situacao' => 'homologado', 'ativo' => 0]));
confere('sem situação (legado) cota',  F::podeCotar(['ativo' => 1]));

// ── Pendências ──
$hoje = '2026-10-30';
$ok = [
    'cnpj' => '11222333000181', 'regime_tributario' => 'lucro_real', 'regime_confirmado_em' => '2026-09-01',
    'cnd_federal_validade' => '2027-01-01', 'cnd_federal_situacao' => 'negativa', 'banco_proprio' => 1,
    'contato_fiscal' => 'Ana, fiscal@x.com', 'email' => 'x@x.com', 'mei' => 0, 'mao_de_obra' => 0,
    'doc_trabalhista' => 0, 'tem_contrato' => 0, 'clausula_reequilibrio' => 0,
];
confere('cadastro completo: sem pendências', F::pendencias($ok, $hoje) === []);
$p = F::pendencias(['regime_tributario' => null] + $ok, $hoje);
confere('sem regime',                  isset($p['sem_regime']) && !isset($p['regime_reconfirmar']));
$p = F::pendencias(['regime_confirmado_em' => '2026-04-01'] + $ok, $hoje);
confere('regime > 6 meses',            isset($p['regime_reconfirmar']));
$p = F::pendencias(['regime_confirmado_em' => '2026-05-01'] + $ok, $hoje);
confere('regime há 182 dias: ok',      !isset($p['regime_reconfirmar']));
$p = F::pendencias(['regime_confirmado_em' => null] + $ok, $hoje);
confere('regime nunca confirmado',     isset($p['regime_nao_confirmado']));
$p = F::pendencias(['cnd_federal_validade' => '2026-10-29'] + $ok, $hoje);
confere('CND vencida',                 isset($p['cnd_vencida']) && str_contains($p['cnd_vencida'], '29/10/2026'));
$p = F::pendencias(['cnd_federal_validade' => '2026-11-14'] + $ok, $hoje);
confere('CND vence em 15 dias',        isset($p['cnd_vence']) && !isset($p['cnd_vencida']));
$p = F::pendencias(['cnd_federal_validade' => '2026-11-15'] + $ok, $hoje);
confere('CND em 16 dias: ok',          !isset($p['cnd_vence']));
$p = F::pendencias(['cnd_federal_validade' => null] + $ok, $hoje);
confere('CND sem validade',            isset($p['cnd_sem_validade']));
$p = F::pendencias(['cnd_federal_situacao' => 'positiva'] + $ok, $hoje);
confere('CND positiva',                isset($p['cnd_positiva']));
$p = F::pendencias(['cnd_federal_situacao' => 'positiva_efeitos_negativa'] + $ok, $hoje);
confere('positiva c/ efeito neg.: ok', !isset($p['cnd_positiva']));
$p = F::pendencias(['banco_proprio' => 0, 'contato_fiscal' => ' ', 'email' => ''] + $ok, $hoje);
confere('sem banco/fiscal/e-mail',     isset($p['sem_banco'], $p['sem_fiscal'], $p['sem_email']));
$p = F::pendencias(['mei' => 1] + $ok, $hoje);
confere('MEI (marcado)',               isset($p['mei']));
$p = F::pendencias(['regime_tributario' => 'mei'] + $ok, $hoje);
confere('MEI (regime)',                isset($p['mei']));
$p = F::pendencias(['mao_de_obra' => 1] + $ok, $hoje);
confere('mão de obra sem doc',         isset($p['mao_obra_sem_doc']));
$p = F::pendencias(['mao_de_obra' => 1, 'doc_trabalhista' => 1] + $ok, $hoje);
confere('mão de obra com doc: ok',     !isset($p['mao_obra_sem_doc']));
$p = F::pendencias(['tem_contrato' => 1] + $ok, $hoje);
confere('contrato sem cláusula',       isset($p['contrato_sem_clausula']));
$p = F::pendencias(['tem_contrato' => 1, 'clausula_reequilibrio' => 1] + $ok, $hoje);
confere('contrato com cláusula: ok',   !isset($p['contrato_sem_clausula']));
confere('toda pendência tem rótulo de filtro', array_diff(array_keys(F::pendencias([], $hoje)), array_keys(F::PENDENCIAS)) === []);

// ── Lista: filtros, contagens, ordenação e agrupamento matriz/filiais ──
$lin = fn(string $id, string $nome, string $cnpj, array $x = []) => $x + [
    'id' => $id, 'razao_social' => $nome, 'nome_fantasia' => '', 'cnpj' => $cnpj, 'cidade' => 'Cidade A', 'estado' => 'RS',
    'email' => 'a@a', 'situacao' => 'pendente', 'regime_tributario' => null, 'cnd_federal_situacao' => null,
    'oc_12m' => 0, 'criado_em' => '2026-01-01 00:00:00',
] + $ok;
$rows = [
    $lin('a', 'Alfa Filial', '11111111000290', ['oc_12m' => 50]),
    $lin('b', 'Beta',        '22222222000100', ['cidade' => 'Cidade B', 'oc_12m' => 900, 'situacao' => 'homologado', 'regime_tributario' => 'lucro_real']),
    $lin('c', 'Alfa Matriz', '11111111000100', ['oc_12m' => 10]),
    $lin('d', 'Delta',       '33333333000100', ['estado' => 'SC', 'cidade' => 'Joinville', 'situacao' => 'bloqueado']),
];
$ids = fn(array $l) => implode(',', array_column($l, 'id'));
confere('ordem nome + matriz antes da filial', $ids(L::ordenarAgrupar($rows, 'nome')) === 'c,a,b,d');
confere('ordem OC: grupo na posição do 1º', $ids(L::ordenarAgrupar($rows, 'oc')) === 'b,c,a,d');
confere('ordem cidade',                $ids(L::ordenarAgrupar($rows, 'cidade')) === 'b,d,c,a');
confere('filtro situação',             $ids(L::filtrar($rows, ['situacao' => 'bloqueado'], $hoje)) === 'd');
confere('filtro UF',                   $ids(L::filtrar($rows, ['uf' => 'SC'], $hoje)) === 'd');
confere('filtro regime vazio',         count(L::filtrar($rows, ['regime' => L::VAZIO], $hoje)) === 3);
confere('filtro pendência sem regime', count(L::filtrar($rows, ['pend' => 'sem_regime'], $hoje)) === 3);
confere('filtro grupo filiais',        $ids(L::filtrar($rows, ['pend' => 'filial'], $hoje)) === 'a');
confere('filtro grupo com OC',         count(L::filtrar($rows, ['pend' => 'com_oc'], $hoje)) === 3);
confere('busca por CNPJ com máscara',  $ids(L::filtrar($rows, ['q' => '22.222.222'], $hoje)) === 'b');
confere('busca sem acento',            $ids(L::filtrar($rows, ['q' => 'joinvílle'], $hoje)) === 'd');
$op = L::opcoes($rows, ['uf' => 'RS'], 'situacao', fn($f) => $f['situacao'], $hoje);
confere('contagem considera outros filtros', ($op['pendente'] ?? 0) === 2 && ($op['homologado'] ?? 0) === 1 && !isset($op['bloqueado']));
$op = L::opcoes($rows, ['uf' => 'RS'], 'uf', fn($f) => $f['estado'], $hoje);
confere('contagem ignora o próprio filtro', ($op['SC'] ?? 0) === 1);

echo PHP_EOL . ($falhas ? "$falhas falha(s)" : 'tudo ok') . PHP_EOL;
exit($falhas ? 1 : 0);
