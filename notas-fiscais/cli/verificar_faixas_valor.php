<?php
// @versao Tpl_08 V_01_27/08/2026
declare(strict_types=1);

/**
 * Verificador de faixa de valor dos fluxos — CLI (rodar sob demanda).
 *
 * `Process::exigirValorNaFaixa()` só consegue barrar um valor fora da faixa
 * quando a versão DECLARA a faixa. Versão com `valor_min` e `valor_max` nulos
 * aceita qualquer valor — e isso não aparece em lugar nenhum da tela.
 *
 * Foi exatamente o que o levantamento de 27/08/2026 encontrou no Server-APP:
 * pares de fluxo "(até R$ 500)" / "(acima de R$ 500)" publicados com as duas
 * colunas nulas. A separação existia só no nome. Este script torna essa lacuna
 * visível para quem cadastra.
 *
 * Uso:
 *   php cli/verificar_faixas_valor.php            # só o que precisa de ação
 *   php cli/verificar_faixas_valor.php --todos    # inclui os sem indício no nome
 *
 * Saída: 0 = nada a corrigir · 1 = há fluxo com faixa faltando
 *
 * Nada é alterado — o script só lê.
 */

define('ROOT', dirname(__DIR__));
require ROOT . '/config/database.php';

$listarTodos = in_array('--todos', $argv, true);
$pdo = Database::getInstance();

/** O nome do fluxo promete uma faixa que as colunas não cumprem? */
function nomePrometeFaixa(string $nome): bool
{
    $n = mb_strtolower($nome, 'UTF-8');
    // "até R$ 500", "acima de $ 1000,00", "abaixo de 200"
    if (preg_match('/\b(at[eé]|acima|abaixo|maior|menor)\b/u', $n) === 1) {
        return true;
    }
    // Qualquer cifrão seguido de número — "(R$500)", "($ 1000,00)"
    return preg_match('/\$\s*[\d\.,]+/u', $n) === 1;
}

function reais(?float $v): string
{
    return $v === null ? '—' : 'R$ ' . number_format($v, 2, ',', '.');
}

// ── Versões que valem hoje: publicadas, de template ativo ────────────
$rows = $pdo->query(
    "SELECT tv.id, tv.numero_versao, tv.valor_min, tv.valor_max,
            tv.filial_id, tv.departamento_id, tv.setor_id, tv.tipo_nota_id,
            t.nome,
            (SELECT COUNT(*) FROM nf_processos p
              WHERE p.template_versao_id = tv.id
                AND p.is_teste = 0 AND p.deletado_em IS NULL) AS processos
       FROM nf_template_versoes tv
       JOIN nf_templates t ON t.id = tv.template_id
      WHERE tv.status = 'publicado' AND t.ativo = 1
      ORDER BY t.nome, tv.numero_versao"
)->fetchAll();

$semFaixa   = [];   // nem min nem max
$comFaixa   = [];
foreach ($rows as $r) {
    if ($r['valor_min'] === null && $r['valor_max'] === null) {
        $semFaixa[] = $r;
    } else {
        $comFaixa[] = $r;
    }
}

$criticos = array_values(array_filter($semFaixa, static fn($r) => nomePrometeFaixa((string) $r['nome'])));
$outros   = array_values(array_filter($semFaixa, static fn($r) => !nomePrometeFaixa((string) $r['nome'])));

echo str_repeat('=', 78), "\n";
echo "FAIXA DE VALOR DOS FLUXOS — ", date('d/m/Y H:i'), "\n";
echo str_repeat('=', 78), "\n\n";
printf("Versões publicadas de template ativo : %d\n",  count($rows));
printf("  declaram faixa                     : %d\n",  count($comFaixa));
printf("  NÃO declaram faixa                 : %d\n",  count($semFaixa));
printf("    destas, com faixa prometida no nome: %d\n\n", count($criticos));

// ── 1. O que precisa de ação ─────────────────────────────────────────
echo str_repeat('-', 78), "\n";
echo "1. O NOME PROMETE UMA FAIXA E AS COLUNAS ESTÃO VAZIAS\n";
echo "   Estes fluxos aceitam QUALQUER valor. A alçada existe só no nome.\n";
echo str_repeat('-', 78), "\n";

if (!$criticos) {
    echo "   Nenhum. \n\n";
} else {
    printf("   %-58s %5s %8s\n", 'FLUXO', 'VER', 'PROCS');
    foreach ($criticos as $r) {
        printf("   %-58s v%-4d %8d\n",
            mb_strimwidth((string) $r['nome'], 0, 58, '…', 'UTF-8'),
            (int) $r['numero_versao'],
            (int) $r['processos']
        );
    }
    echo "\n   Corrigir em: Templates → abrir o fluxo → Critérios → Valor mínimo / máximo.\n";
    echo "   Preencher a faixa NÃO altera processo já aberto — vale da próxima abertura.\n\n";
}

// ── 2. Sem faixa e sem indício no nome ───────────────────────────────
if ($listarTodos) {
    echo str_repeat('-', 78), "\n";
    echo "2. SEM FAIXA DECLARADA (sem indício no nome)\n";
    echo "   Pode estar certo: nem todo fluxo tem alçada por valor.\n";
    echo str_repeat('-', 78), "\n";
    foreach ($outros as $r) {
        printf("   %-58s v%-4d %8d\n",
            mb_strimwidth((string) $r['nome'], 0, 58, '…', 'UTF-8'),
            (int) $r['numero_versao'],
            (int) $r['processos']
        );
    }
    echo "\n";
} else {
    printf("2. Sem faixa e sem indício no nome: %d fluxo(s). Use --todos para listar.\n\n", count($outros));
}

// ── 3. Faixas declaradas: sobreposição e lacuna no mesmo recorte ─────
echo str_repeat('-', 78), "\n";
echo "3. FLUXOS QUE DECLARAM FAIXA — SOBREPOSIÇÃO E LACUNA\n";
echo "   Mesmo recorte (filial + departamento + setor + tipo de nota).\n";
echo str_repeat('-', 78), "\n";

$porRecorte = [];
foreach ($comFaixa as $r) {
    $chave = implode('|', [
        $r['filial_id'] ?? '*', $r['departamento_id'] ?? '*',
        $r['setor_id'] ?? '*',  $r['tipo_nota_id'] ?? '*',
    ]);
    $porRecorte[$chave][] = $r;
}

$achados = 0;
foreach ($porRecorte as $grupo) {
    if (count($grupo) < 2) {
        continue;
    }
    usort($grupo, static function ($a, $b) {
        return ($a['valor_min'] ?? -INF) <=> ($b['valor_min'] ?? -INF);
    });

    for ($i = 1; $i < count($grupo); $i++) {
        $ant = $grupo[$i - 1];
        $atu = $grupo[$i];
        $antMax = $ant['valor_max'] !== null ? (float) $ant['valor_max'] : null;
        $atuMin = $atu['valor_min'] !== null ? (float) $atu['valor_min'] : null;

        if ($antMax === null || $atuMin === null) {
            continue;   // faixa aberta de um lado: não dá para afirmar nada
        }

        if ($atuMin <= $antMax) {
            $achados++;
            echo "   SOBREPOSIÇÃO\n";
            printf("     %s  (%s a %s)\n", $ant['nome'], reais((float) $ant['valor_min']), reais($antMax));
            printf("     %s  (%s a %s)\n", $atu['nome'], reais($atuMin), reais($atu['valor_max'] !== null ? (float) $atu['valor_max'] : null));
            echo "     → um valor na interseção casa com os dois: quem abre escolhe a alçada.\n\n";
        } elseif ($atuMin > $antMax + 0.01) {
            $achados++;
            echo "   LACUNA\n";
            printf("     entre %s (termina em %s) e %s (começa em %s)\n",
                $ant['nome'], reais($antMax), $atu['nome'], reais($atuMin));
            echo "     → valor nesse intervalo não encontra fluxo e trava a abertura.\n\n";
        }
    }
}

if ($achados === 0) {
    echo "   Nenhuma sobreposição ou lacuna entre faixas declaradas.\n\n";
}

echo str_repeat('=', 78), "\n";
if ($criticos) {
    printf("AÇÃO NECESSÁRIA: %d fluxo(s) com faixa prometida no nome e não declarada.\n", count($criticos));
    exit(1);
}
echo "Nada pendente.\n";
exit(0);
