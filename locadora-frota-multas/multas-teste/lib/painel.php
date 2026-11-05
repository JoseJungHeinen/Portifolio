<?php
/**
 * Números do "Painel Andamento das Multas" (antigo Gráfico - Controle),
 * usados pelo resumo para WhatsApp (imagem e texto).
 */
function dados_painel(): array
{
    $pdo = db();
    $porEtapa = $pdo->query('SELECT etapa, COUNT(*) q, COALESCE(SUM(valor),0) v FROM multas GROUP BY etapa')->fetchAll(PDO::FETCH_UNIQUE);
    $linhas = [];
    $aberto = ['q' => 0, 'v' => 0.0];
    foreach (LINHAS_PAINEL as $l) {
        $q = 0; $v = 0.0;
        foreach ($l['etapas'] as $e) { $q += (int)($porEtapa[$e]['q'] ?? 0); $v += (float)($porEtapa[$e]['v'] ?? 0); }
        $linhas[] = ['chave' => $l['chave'], 'rotulo' => $l['rotulo'], 'q' => $q, 'v' => $v];
        $aberto['q'] += $q; $aberto['v'] += $v;
    }
    $final = ['q' => (int)($porEtapa['finalizado_detran']['q'] ?? 0), 'v' => (float)($porEtapa['finalizado_detran']['v'] ?? 0)];

    $lojas = $pdo->query("SELECT l.nome, COUNT(m.id) total, SUM(m.etapa NOT IN ('finalizado_detran','fraude')) aberto
        FROM lojas l JOIN multas m ON m.loja_id = l.id GROUP BY l.id ORDER BY total DESC")->fetchAll();

    $hoje = date('Y-m-d');
    $st = $pdo->prepare("SELECT
        SUM(origem = 'captura' AND primeira_captura_em = ?) novas_hoje,
        SUM(saiu_do_detran_em = ?) sairam_hoje,
        SUM(situacao_detran = 'vencida' AND etapa NOT IN ('finalizado_detran','fraude')) vencidas_detran
        FROM multas");
    $st->execute([$hoje, $hoje]);
    $dia = array_map('intval', $st->fetch());

    $captura = $pdo->query("SELECT criado_em, empresas FROM capturas_detran WHERE origem <> 'planilha' ORDER BY id DESC LIMIT 1")->fetch();

    return [
        'data' => date('d/m/Y'),
        'linhas' => $linhas,
        'aberto' => $aberto,
        'finalizadas' => $final,
        'totalGeral' => $aberto['q'] + $final['q'],
        'lojas' => array_map(fn($l) => ['nome' => $l['nome'], 'total' => (int)$l['total'], 'aberto' => (int)$l['aberto'],
            'pct' => $l['total'] ? $l['aberto'] / $l['total'] : 0], $lojas),
        'dia' => $dia,
        'ultimaCaptura' => $captura ? date('d/m H:i', strtotime($captura['criado_em'])) : null,
        'capturaHoje' => $captura && date('Y-m-d', strtotime($captura['criado_em'])) === $hoje,
    ];
}

function brl_curto(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }

/** Texto pronto para colar no WhatsApp (*negrito*). */
function resumo_texto(array $d): string
{
    $t = "*Painel Andamento das Multas — {$d['data']}*\n\n";
    foreach ($d['linhas'] as $l) $t .= "• {$l['rotulo']}: *{$l['q']}* (" . brl_curto($l['v']) . ")\n";
    $t .= "\n*Em aberto no DETRAN: {$d['aberto']['q']}* (" . brl_curto($d['aberto']['v']) . ")\n";
    $t .= "Finalizadas: {$d['finalizadas']['q']} · Total geral: {$d['totalGeral']}\n\n*Lojas (em aberto / total)*\n";
    foreach ($d['lojas'] as $l) $t .= "• {$l['nome']}: {$l['aberto']} / {$l['total']} (" . number_format($l['pct'] * 100, 1, ',', '') . "%)\n";
    $t .= "\nHoje: {$d['dia']['novas_hoje']} novas · {$d['dia']['sairam_hoje']} saíram do DETRAN · {$d['dia']['vencidas_detran']} vencidas no DETRAN";
    return $t;
}
