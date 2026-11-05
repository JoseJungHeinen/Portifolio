<?php
declare(strict_types=1);

chdir(__DIR__ . '/../..');
require 'conexao_inventario.php';
require 'conexao_oracle.php';
require 'conferencia_lib.php';
require __DIR__ . '/assert.php';

echo "test_buscar_oracle\n";

$ora = oracle_conectar();
if (!$ora) {
    echo "  SKIP: Apollo indisponivel\n";
    exit(0);
}

/**
 * Saldo e locacao lidos por um caminho independente, direto na tabela.
 *
 * O OCI8 devolve as chaves em MAIUSCULAS, e conferencia_montar_locacao()
 * procura as minusculas — sem normalizar aqui, o valor esperado do teste sairia
 * sempre vazio e a assercao falharia por bug do teste, nao do codigo.
 */
function fixture_revenda($ora, int $item, int $revenda): array
{
    $sql = "SELECT (qtd_contabil - qtd_alocada) AS saldo,
                   locacao_zona, locacao_rua, locacao_estante,
                   locacao_prateleira, locacao_numero
              FROM pec_item_revenda
             WHERE empresa = 1 AND item_estoque = :item AND revenda = :rev";
    $stmt = oci_parse($ora, $sql);
    oci_bind_by_name($stmt, ':item', $item);
    oci_bind_by_name($stmt, ':rev', $revenda);
    oci_execute($stmt, OCI_DEFAULT);
    $row = oci_fetch_assoc($stmt) ?: [];
    oci_free_statement($stmt);
    return array_change_key_case($row, CASE_LOWER);
}

$lojas = conferencia_lojas_da_cidade($pdo, 1, 1); // Cidade A, entrando pela Fiat

// ── Item presente nas duas lojas ────────────────────────────────────────────
$r = conferencia_buscar_oracle($ora, 1, 1, $lojas, '063806');

t_eq('origem e oracle', 'oracle', $r['origem']);
t_eq('codigo publico', '063806', $r['item']['item_estoque_pub']);
t_eq('codigo interno', '322208', $r['item']['cod_item_estoque']);
t_eq('descricao', 'BATERIA ELETR 72AH TORO 2.4 START STOP', $r['item']['descricao']);

$fiat = fixture_revenda($ora, 322208, 1);
$jeep = fixture_revenda($ora, 322208, 5);

t_eq('locacao exibida e a da loja atual (Fiat)', conferencia_montar_locacao($fiat), $r['item']['locacao_geral']);

// Cada loja traz a SUA locacao: a tela mostra uma por linha, porque a mesma peca
// fica em lugares diferentes em cada loja.
t_eq('Fiat traz a locacao dela', conferencia_montar_locacao($fiat), $r['lojas'][0]['locacao']);
t_eq('Jeep traz a locacao dela', conferencia_montar_locacao($jeep), $r['lojas'][1]['locacao']);
t_true('as duas locacoes sao mesmo diferentes', $r['lojas'][0]['locacao'] !== $r['lojas'][1]['locacao']);

t_eq('duas lojas na resposta', 2, count($r['lojas']));
t_eq('loja 1 e a revenda 1', 1, $r['lojas'][0]['revenda']);
t_eq('loja 1 e Fiat', 'Fiat', $r['lojas'][0]['nome']);
t_true('loja 1 e a atual', $r['lojas'][0]['atual']);
t_eq('saldo da Fiat vem do Apollo', (int)$fiat['saldo'], $r['lojas'][0]['saldo']);
t_eq('loja 2 e a revenda 5', 5, $r['lojas'][1]['revenda']);
t_eq('saldo da Jeep vem do Apollo', (int)$jeep['saldo'], $r['lojas'][1]['saldo']);

// ── A mesma busca, entrando pela Jeep: muda a locacao exibida ───────────────
$lojasJeep = conferencia_lojas_da_cidade($pdo, 1, 5);
$rj = conferencia_buscar_oracle($ora, 1, 5, $lojasJeep, '063806');
t_eq('entrando pela Jeep, locacao e a da Jeep', conferencia_montar_locacao($jeep), $rj['item']['locacao_geral']);
t_eq('saldos nao mudam de lugar', (int)$fiat['saldo'], $rj['lojas'][0]['saldo']);

// ── Bipar o codigo de um item equivalente traz esse item, nao o principal ───
$ra = conferencia_buscar_oracle($ora, 1, 1, $lojas, '7091115');
t_eq('codigo equivalente traz o proprio item', '7091115', $ra['item']['item_estoque_pub']);

// ── Uma loja com linha, a outra sem: a sem linha vira zero e nao some ───────
// O fixture antigo ('002405') tinha saldo 0 na unica loja que o tem, entao as
// duas saiam 0 e o teste passaria ate se a funcao copiasse o saldo da irma.
$c54594 = fixture_revenda($ora, 54594, 1);
$r1 = conferencia_buscar_oracle($ora, 1, 1, $lojas, '5945181');
t_eq('item so na Fiat: duas lojas listadas', 2, count($r1['lojas']));
t_eq('Fiat traz o saldo do Apollo', (int)$c54594['saldo'], $r1['lojas'][0]['saldo']);
t_eq('Jeep, sem linha, vira zero', 0, $r1['lojas'][1]['saldo']);
// Sem linha de estoque nao ha locacao: string vazia, e a tela mostra um traco.
t_eq('Jeep, sem linha, fica sem locacao', '', $r1['lojas'][1]['locacao']);
t_true('Fiat, com linha, tem locacao', $r1['lojas'][0]['locacao'] !== '');

// O espelho, para travar tambem a ordem: agora e a Fiat que nao tem linha.
$c171325 = fixture_revenda($ora, 171325, 5);
$r2 = conferencia_buscar_oracle($ora, 1, 1, $lojas, 'K68197867AB');
t_eq('Fiat, sem linha, vira zero', 0, $r2['lojas'][0]['saldo']);
t_eq('Jeep traz o saldo do Apollo', (int)$c171325['saldo'], $r2['lojas'][1]['saldo']);

// ── Item sem linha de estoque em loja NENHUMA da cidade ainda e encontrado ──
// Esta e a prova de que cabecalho e saldos sao duas consultas: um INNER JOIN
// devolveria zero linhas e a tela diria "item nao encontrado" para um item que
// existe. Sem este teste, trocar as duas consultas por um JOIN passaria batido.
$r0 = conferencia_buscar_oracle($ora, 1, 1, $lojas, 'K04746989');
t_eq('item sem estoque em loja nenhuma e encontrado', '82677', $r0['item']['cod_item_estoque']);
t_eq('e ainda lista as duas lojas', 2, count($r0['lojas']));
t_eq('as duas com zero', 0, $r0['lojas'][0]['saldo'] + $r0['lojas'][1]['saldo']);
t_eq('sem linha, sem locacao', '', $r0['item']['locacao_geral']);

// ── Sessao cuja loja nao esta em inv_lojas ──────────────────────────────────
$rv = conferencia_buscar_oracle($ora, 1, 1, [], '063806');
t_eq('sem lista de lojas, monta a da sessao', 1, count($rv['lojas']));
t_eq('e ela traz o saldo da loja da sessao', (int)$fiat['saldo'], $rv['lojas'][0]['saldo']);

// ── Consulta que falha tem que estourar, nao devolver vazio ─────────────────
// Se devolvesse vazio, a tela mostraria zero em todas as lojas com cara de
// resposta boa, e o operador contaria contra um numero inventado.
$estourou = false;
try {
    conferencia_ora_consultar($ora, "SELECT coluna_que_nao_existe FROM dual", []);
} catch (RuntimeException $e) {
    $estourou = true;
}
t_true('consulta invalida estoura em vez de devolver vazio', $estourou);

// ── Codigo inexistente ──────────────────────────────────────────────────────
t_null('codigo inexistente devolve null', conferencia_buscar_oracle($ora, 1, 1, $lojas, 'ZZZNAOEXISTE'));

t_fim();
