<?php
declare(strict_types=1);
ob_start();
session_start();
require 'conexao_inventario.php';

if (empty($_SESSION['inventario_login'])) { header('Location: index.php'); exit; }

// ── helpers ──
function qAll(PDO $pdo, string $sql, array $p = []): array {
    $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── Lojas com inventário ──
$lojas = qAll($pdo, "
    SELECT DISTINCT l.codigo, l.empresa AS nome_empresa, l.revenda AS nome_revenda
      FROM inv_inventarios i
     INNER JOIN inv_lojas l ON l.ativo = 1
       AND CAST(SUBSTRING_INDEX(l.codigo,'.',1) AS UNSIGNED) = i.empresa
       AND CAST(SUBSTRING_INDEX(l.codigo,'.',-1) AS UNSIGNED) = i.revenda
     ORDER BY l.codigo
");

// Mapa codigo → nome para usar nos labels
$nomeLoja = [];
foreach ($lojas as $lj) {
    $nomeLoja[$lj['codigo']] = trim($lj['nome_empresa'] . ' ' . $lj['nome_revenda']);
}

// ── Mapa de inventários por loja (para JS) ──
$invMap = [];
foreach ($lojas as $lj) {
    $cod = $lj['codigo'];
    [$e, $r] = array_map('intval', explode('.', $cod));
    $invMap[$cod] = qAll($pdo,
        "SELECT id, DATE_FORMAT(data_inicio,'%d/%m/%Y') AS dt
           FROM inv_inventarios WHERE empresa = :e AND revenda = :r
          ORDER BY data_inicio DESC",
        [':e' => $e, ':r' => $r]
    );
}

// ── Parâmetros da URL ──
$cod1  = (string)($_GET['cod1'] ?? '');
$cod2  = (string)($_GET['cod2'] ?? '');
$inv1  = ($_GET['inv1'] ?? '') !== '' ? (int)$_GET['inv1'] : null;
$inv2  = ($_GET['inv2'] ?? '') !== '' ? (int)$_GET['inv2'] : null;
$filtro = (string)($_GET['filtro'] ?? 'faltam_ambos');
$busca  = trim((string)($_GET['busca'] ?? ''));

function parseEmpRev(string $cod): ?array {
    if ($cod === '' || strpos($cod, '.') === false) return null;
    [$e, $r] = array_map('intval', explode('.', $cod));
    return ($e > 0 && $r > 0) ? ['e' => $e, 'r' => $r] : null;
}

$l1 = parseEmpRev($cod1);
$l2 = parseEmpRev($cod2);
$nome1 = $nomeLoja[$cod1] ?? $cod1;
$nome2 = $nomeLoja[$cod2] ?? $cod2;

// ── Comparação ──
$rows      = [];
$totais    = ['total'=>0,'faltam_ambos'=>0,'faltam_inv1'=>0,'faltam_inv2'=>0,'sobrando_inv1'=>0,'sobrando_inv2'=>0,'divergentes'=>0,'ok'=>0];
$comparado = false;
$erroMsg   = '';

if ($l1 && $l2 && $inv1 !== null && $inv2 !== null) {
    if ($cod1 === $cod2 && $inv1 === $inv2) {
        $erroMsg = 'mesmo';
    } else {
        $comparado = true;
        $sql = "SELECT item_estoque_pub AS ref, descricao_item_estoque AS descr, locacao_geral AS loc,
                       quantidade AS saldo, custo_entrada AS custo, class_abc AS abc,
                       COALESCE(cont_1,0) AS c1
                  FROM estoque WHERE empresa=:e AND revenda=:r AND id_inventario=:inv";
        $d1 = qAll($pdo, $sql, [':e'=>$l1['e'],':r'=>$l1['r'],':inv'=>$inv1]);
        $d2 = qAll($pdo, $sql, [':e'=>$l2['e'],':r'=>$l2['r'],':inv'=>$inv2]);

        $m1 = []; foreach ($d1 as $i) $m1[(string)$i['ref']] = $i;
        $m2 = []; foreach ($d2 as $i) $m2[(string)$i['ref']] = $i;

        $refs = array_unique(array_merge(array_keys($m1), array_keys($m2)));
        sort($refs);

        foreach ($refs as $ref) {
            $a = $m1[$ref] ?? null;
            $b = $m2[$ref] ?? null;
            $ca = $a ? (int)$a['c1'] : 0;
            $cb = $b ? (int)$b['c1'] : 0;
            $sa = $a ? (int)$a['saldo'] : 0;
            $sb = $b ? (int)$b['saldo'] : 0;
            $fa = $a && $ca === 0;
            $fb = $b && $cb === 0;
            // Sobrando: contagem > saldo em pelo menos um inventário
            $sobA = $a && $ca > 0 && $ca > $sa;
            $sobB = $b && $cb > 0 && $cb > $sb;

            if     ($fa && $fb)         $st = 'faltam_ambos';
            elseif ($fa)                $st = 'faltam_inv1';
            elseif ($fb)                $st = 'faltam_inv2';
            elseif ($sobA)              $st = 'sobrando_inv1';
            elseif ($sobB)              $st = 'sobrando_inv2';
            elseif ($ca !== $cb)        $st = 'divergentes';
            else                        $st = 'ok';

            $totais[$st]++;
            $totais['total']++;

            if ($filtro !== 'todos' && $st !== $filtro) continue;

            $desc = (string)($a['descr'] ?? ($b['descr'] ?? ''));
            if ($busca !== '') {
                $bu = mb_strtoupper($busca);
                if (mb_stripos((string)$ref, $bu) === false && mb_stripos($desc, $bu) === false) continue;
            }

            $rows[] = [
                'ref'=>(string)$ref, 'desc'=>$desc,
                'locA'=>(string)($a['loc'] ?? ''), 'locB'=>(string)($b['loc'] ?? ''),
                'sA'=>$a ? $sa : null, 'sB'=>$b ? $sb : null,
                'cA'=>$a ? $ca : null, 'cB'=>$b ? $cb : null,
                'abc'=>(string)($a['abc'] ?? ($b['abc'] ?? '')),
                'custo'=>(float)($a['custo'] ?? ($b['custo'] ?? 0)),
                'st'=>$st,
            ];
        }
    }
}

// ── CSV ──
if (($comparado) && ($_GET['export'] ?? '') === 'csv' && $rows) {
    ob_end_clean();
    header('Content-Type:text/csv;charset=utf-8');
    header('Content-Disposition:attachment;filename="comparar_'.$cod1.'_vs_'.$cod2.'.csv"');
    $o = fopen('php://output','w');
    fwrite($o, "\xEF\xBB\xBF");
    $stL = ['faltam_ambos'=>'Falta ambos','faltam_inv1'=>'Falta '.$nome1,'faltam_inv2'=>'Falta '.$nome2,
            'sobrando_inv1'=>'Sobra '.$nome1,'sobrando_inv2'=>'Sobra '.$nome2,
            'divergentes'=>'Divergente','ok'=>'OK'];
    fputcsv($o,['Referência','Descrição','ABC','Loc '.$nome1,'Saldo '.$nome1,'Cont '.$nome1,'Loc '.$nome2,'Saldo '.$nome2,'Cont '.$nome2,'Custo','Status'],';');
    foreach ($rows as $r) {
        fputcsv($o,[$r['ref'],$r['desc'],$r['abc'],$r['locA'],
            $r['sA']??'',$r['cA']??'',$r['locB'],
            $r['sB']??'',$r['cB']??'',
            number_format($r['custo'],2,',','.'),$stL[$r['st']]],';');
    }
    fclose($o); exit;
}

// ── Paginação ──
$pp   = 50;
$totR = count($rows);
$pag  = max(1,(int)($_GET['pagina']??1));
$tPag = max(1,(int)ceil($totR/$pp));
$pag  = min($pag,$tPag);
$off  = ($pag-1)*$pp;
$slice = array_slice($rows,$off,$pp);

// ── Valor em risco ──
$valorRisco = 0;
if ($filtro === 'faltam_ambos') {
    foreach ($rows as $r) $valorRisco += $r['custo'] * max((int)($r['sA']??0),(int)($r['sB']??0));
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Comparar Inventários</title>
<style>
:root{--red:#2347C8;--green:#16a34a;--amber:#d97706;--bg:#f3f4f6;--g100:#f9fafb;--g200:#e5e7eb;--g400:#9ca3af;--g600:#4b5563;--g800:#111827;--sh:0 2px 8px rgba(15,23,42,.07);--r:10px}
*{box-sizing:border-box;margin:0}
body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--g800)}
a{color:inherit;text-decoration:none}

/* header */
.hd{background:#fff;border-bottom:1px solid var(--g200);padding:10px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:20;box-shadow:0 1px 4px rgba(0,0,0,.04)}
.hd h1{font-size:16px;font-weight:700}
.hd .bk{font-size:13px;padding:5px 12px;border:1px solid var(--g200);border-radius:99px;background:#fff;color:var(--g600)}
.hd .bk:hover{border-color:var(--red);color:var(--red)}

main{padding:16px 20px 40px;max-width:1200px;margin:0 auto;display:flex;flex-direction:column;gap:14px}

/* cards */
.cd{background:#fff;border-radius:var(--r);padding:14px 16px;box-shadow:var(--sh);border:1px solid rgba(0,0,0,.04)}
.ct{font-size:13px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.ct::before{content:"";width:3px;height:14px;border-radius:99px;background:var(--red)}

/* seleção */
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
.lado{display:flex;flex-direction:column;gap:6px}
.lado lbl{font-size:11px;font-weight:700;color:var(--g600);text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:4px}
.tag{display:inline-block;padding:1px 8px;border-radius:99px;font-size:10px;font-weight:700}
.tag-a{background:#dbeafe;color:#1e40af}
.tag-b{background:#fce7f3;color:#be185d}
.lado select{height:36px;border-radius:99px;border:1px solid var(--g200);padding:0 14px;font-size:13px;background:var(--g100);width:100%;transition:border-color .3s}
.barra{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.barra input{height:36px;border-radius:99px;border:1px solid var(--g200);padding:0 14px;font-size:13px;background:var(--g100);flex:1;min-width:160px}
.barra .btn{height:36px;border-radius:99px;border:none;padding:0 22px;font-size:13px;font-weight:700;cursor:pointer;background:linear-gradient(135deg,var(--red),#FF1744);color:#fff}
.barra .btn:hover{filter:brightness(1.08)}

/* KPIs */
.kpis{display:flex;flex-wrap:wrap;gap:10px}
.kp{flex:1;min-width:130px;background:#fff;border-radius:var(--r);padding:12px 14px;box-shadow:var(--sh);border:2px solid transparent;cursor:pointer;transition:all .15s}
.kp:hover{border-color:var(--g200);box-shadow:0 0 0 3px rgba(0,0,0,.04)}
.kp.on{border-color:var(--red);box-shadow:0 0 0 3px rgba(35,71,200,.1)}
.kp .n{font-size:26px;font-weight:800;line-height:1}
.kp .l{font-size:11px;color:var(--g600);margin-top:4px;font-weight:500}
.kp.on .l{color:var(--red);font-weight:700}
.kp-r .n{color:var(--red)} .kp-y .n{color:var(--amber)} .kp-p .n{color:#6366f1} .kp-s .n{color:#0891b2} .kp-g .n{color:var(--g800)}

/* tabela */
table{width:100%;border-collapse:collapse;font-size:12px}
thead{background:var(--g100)}
th,td{border-bottom:1px solid var(--g200);padding:7px 6px;text-align:left}
th{font-weight:600;color:var(--g600);font-size:11px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
tbody tr:hover{background:#FFF3F4}
.tc{text-align:center}.tr{text-align:right}
.mn{font-family:ui-monospace,SFMono-Regular,monospace;font-size:11px}
.badge{padding:2px 7px;border-radius:99px;font-size:10px;font-weight:700;display:inline-block}
.b-a{background:#dcfce7;color:#166534}.b-b{background:#dbeafe;color:#1e40af}.b-c{background:#fef9c3;color:#854d0e}
.st{padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;white-space:nowrap;display:inline-block}
.st-faltam_ambos{background:#fef2f2;color:#b91c1c}
.st-faltam_inv1,.st-faltam_inv2{background:#fff7ed;color:#c2410c}
.st-divergentes{background:#eef2ff;color:#4338ca}
.st-sobrando_ambos,.st-sobrando_inv1,.st-sobrando_inv2{background:#ecfeff;color:#0e7490}
.st-ok{background:#f0fdf4;color:#166534}
.z{color:var(--red);font-weight:700}.v{color:var(--green)}.sob{color:#0891b2;font-weight:700}
.ha{color:#1e40af}.hb{color:#be185d}
.risco{color:var(--red);font-weight:600;font-size:13px}

/* paginação */
.pg{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:8px 0;font-size:12px;color:var(--g600)}
.pg .ps{display:flex;gap:4px}
.pg .ps a,.pg .ps span{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;border-radius:6px;border:1px solid var(--g200);background:#fff;color:var(--g600);font-size:12px}
.pg .ps a:hover{border-color:var(--red);color:var(--red)}
.pg .ps .cur{background:var(--red);color:#fff;border-color:var(--red);font-weight:700}

.empty{text-align:center;padding:40px 20px;color:var(--g400)}
.empty .ic{font-size:40px;margin-bottom:10px}
.csv-btn{font-size:12px;padding:5px 14px;border:1px solid var(--g200);border-radius:99px;background:#fff;color:var(--g600);display:inline-block;margin-top:10px}
.csv-btn:hover{border-color:var(--red);color:var(--red)}

@media(max-width:768px){
  .grid2{grid-template-columns:1fr}
  .kpis{gap:6px}.kp{min-width:calc(50% - 6px);padding:10px}.kp .n{font-size:20px}
  table{font-size:11px}th,td{padding:5px 4px}.hm{display:none}
}
</style>
</head>
<body>

<div class="hd">
  <h1>Comparar Inventários</h1>
  <a href="dashboard_inventario.php" class="bk">← Voltar</a>
</div>

<main>

<!-- ═══ FORMULÁRIO ═══ -->
<div class="cd">
  <div class="ct">Selecionar lojas e inventários</div>
  <form method="get" id="F">
    <input type="hidden" name="filtro" id="hFiltro" value="<?= htmlspecialchars($filtro) ?>">
    <div class="grid2">
      <div class="lado">
        <lbl><span class="tag tag-a">A</span> Lado A</lbl>
        <select name="cod1" id="c1">
          <option value="">Loja…</option>
          <?php foreach ($lojas as $lj): $nm = trim($lj['nome_empresa'].' '.$lj['nome_revenda']); ?>
            <option value="<?= $lj['codigo'] ?>" <?= $cod1===$lj['codigo']?'selected':'' ?>><?= htmlspecialchars($nm) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="inv1" id="i1">
          <option value="">Inventário…</option>
          <?php if ($l1): foreach (($invMap[$cod1]??[]) as $iv): ?>
            <option value="<?= $iv['id'] ?>" <?= $inv1===(int)$iv['id']?'selected':'' ?>>#<?= $iv['id'] ?> — <?= $iv['dt'] ?></option>
          <?php endforeach; endif; ?>
        </select>
      </div>
      <div class="lado">
        <lbl><span class="tag tag-b">B</span> Lado B</lbl>
        <select name="cod2" id="c2">
          <option value="">Loja…</option>
          <?php foreach ($lojas as $lj): $nm = trim($lj['nome_empresa'].' '.$lj['nome_revenda']); ?>
            <option value="<?= $lj['codigo'] ?>" <?= $cod2===$lj['codigo']?'selected':'' ?>><?= htmlspecialchars($nm) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="inv2" id="i2">
          <option value="">Inventário…</option>
          <?php if ($l2): foreach (($invMap[$cod2]??[]) as $iv): ?>
            <option value="<?= $iv['id'] ?>" <?= $inv2===(int)$iv['id']?'selected':'' ?>>#<?= $iv['id'] ?> — <?= $iv['dt'] ?></option>
          <?php endforeach; endif; ?>
        </select>
      </div>
    </div>
    <div class="barra">
      <input type="text" name="busca" placeholder="Buscar referência ou descrição…" value="<?= htmlspecialchars($busca) ?>">
      <button type="submit" class="btn">Comparar</button>
    </div>
  </form>
</div>

<?php if ($comparado): ?>

<!-- ═══ KPIs ═══ -->
<div class="kpis">
  <?php
    $kpis = [
      ['faltam_ambos','Faltam em ambos','kp-r'],
      ['faltam_inv1','Faltam só '.$nome1,'kp-y'],
      ['faltam_inv2','Faltam só '.$nome2,'kp-y'],
      ['sobrando_inv1','Sobrando '.$nome1,'kp-s'],
      ['sobrando_inv2','Sobrando '.$nome2,'kp-s'],
      ['divergentes','Qtd divergente','kp-p'],
      ['todos','Total de itens','kp-g'],
    ];
    foreach ($kpis as [$k,$lab,$cls]):
      $val = $k === 'todos' ? $totais['total'] : $totais[$k];
      $on  = $filtro === $k ? ' on' : '';
  ?>
    <div class="kp <?= $cls . $on ?>" onclick="filtrar('<?= $k ?>')">
      <div class="n"><?= $val ?></div>
      <div class="l"><?= $lab ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($filtro === 'faltam_ambos' && $valorRisco > 0): ?>
  <div class="cd"><div class="risco">Valor estimado em risco: R$ <?= number_format($valorRisco,2,',','.') ?></div></div>
<?php endif; ?>

<!-- ═══ TABELA ═══ -->
<div class="cd" style="padding:10px 12px;overflow-x:auto">
  <div class="ct">
    <?php
      $titulos = ['faltam_ambos'=>'Não contados em nenhum','faltam_inv1'=>'Não contados em '.$nome1,
                  'faltam_inv2'=>'Não contados em '.$nome2,
                  'sobrando_inv1'=>'Sobrando em '.$nome1.' (contagem > saldo)',
                  'sobrando_inv2'=>'Sobrando em '.$nome2.' (contagem > saldo)',
                  'divergentes'=>'Contagem divergente','todos'=>'Todos os itens'];
    ?>
    <?= $titulos[$filtro] ?? 'Resultado' ?>
    <span style="font-weight:400;color:var(--g400);margin-left:6px">(<?= $totR ?>)</span>
  </div>

  <?php if (!$slice): ?>
    <div class="empty"><div class="ic">✅</div><p>Nenhum item neste filtro.</p></div>
  <?php else: ?>
    <table>
      <thead><tr>
        <th>Ref</th><th>Descrição</th><th class="hm">ABC</th>
        <th class="tc ha">Loc <?= htmlspecialchars($nome1) ?></th>
        <th class="tr ha">Saldo <?= htmlspecialchars($nome1) ?></th>
        <th class="tr ha">Cont <?= htmlspecialchars($nome1) ?></th>
        <th class="tc hb">Loc <?= htmlspecialchars($nome2) ?></th>
        <th class="tr hb">Saldo <?= htmlspecialchars($nome2) ?></th>
        <th class="tr hb">Cont <?= htmlspecialchars($nome2) ?></th>
        <th class="tr hm">Custo</th><th class="tc">Status</th>
      </tr></thead>
      <tbody>
      <?php
        $stNome = ['faltam_ambos'=>'Falta ambos','faltam_inv1'=>'Falta '.$nome1,'faltam_inv2'=>'Falta '.$nome2,
                   'sobrando_inv1'=>'Sobra '.$nome1,'sobrando_inv2'=>'Sobra '.$nome2,
                   'divergentes'=>'Diverge','ok'=>'OK'];
        foreach ($slice as $r):
      ?>
        <tr>
          <td class="mn"><?= htmlspecialchars((string)$r['ref']) ?></td>
          <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars((string)$r['desc']) ?>"><?= htmlspecialchars((string)$r['desc']) ?></td>
          <td class="hm tc"><?php if($r['abc']):?><span class="badge b-<?=strtolower($r['abc'])?>"><?=$r['abc']?></span><?php endif;?></td>
          <td class="tc mn"><?= htmlspecialchars((string)$r['locA']) ?: '—' ?></td>
          <td class="tr"><?= $r['sA']!==null ? $r['sA'] : '—' ?></td>
          <td class="tr <?= $r['cA']!==null?($r['cA']===0?'z':($r['cA']>($r['sA']??0)?'sob':'v')):'' ?>"><?= $r['cA']!==null ? $r['cA'] : '—' ?></td>
          <td class="tc mn"><?= htmlspecialchars((string)$r['locB']) ?: '—' ?></td>
          <td class="tr"><?= $r['sB']!==null ? $r['sB'] : '—' ?></td>
          <td class="tr <?= $r['cB']!==null?($r['cB']===0?'z':($r['cB']>($r['sB']??0)?'sob':'v')):'' ?>"><?= $r['cB']!==null ? $r['cB'] : '—' ?></td>
          <td class="tr hm"><?= $r['custo']>0 ? number_format($r['custo'],2,',','.') : '—' ?></td>
          <td class="tc"><span class="st st-<?=$r['st']?>"><?=$stNome[$r['st']]?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($tPag > 1): ?>
      <div class="pg">
        <span>Mostrando <?=$off+1?>–<?=min($off+$pp,$totR)?> de <?=$totR?></span>
        <div class="ps">
        <?php
          $gp=$_GET;
          if($pag>1){$gp['pagina']=$pag-1;echo'<a href="?'.http_build_query($gp).'">‹</a>';}
          $s=max(1,$pag-3);$e=min($tPag,$pag+3);
          if($s>1){$gp['pagina']=1;echo'<a href="?'.http_build_query($gp).'">1</a>';if($s>2)echo'<span>…</span>';}
          for($p=$s;$p<=$e;$p++){$gp['pagina']=$p;echo $p===$pag?'<span class="cur">'.$p.'</span>':'<a href="?'.http_build_query($gp).'">'.$p.'</a>';}
          if($e<$tPag){if($e<$tPag-1)echo'<span>…</span>';$gp['pagina']=$tPag;echo'<a href="?'.http_build_query($gp).'">'.$tPag.'</a>';}
          if($pag<$tPag){$gp['pagina']=$pag+1;echo'<a href="?'.http_build_query($gp).'">›</a>';}
        ?>
        </div>
      </div>
    <?php endif; ?>

    <div style="text-align:right">
      <?php $cp=$_GET;$cp['export']='csv'; ?>
      <a href="?<?=http_build_query($cp)?>" class="csv-btn">📥 Exportar CSV</a>
    </div>
  <?php endif; ?>
</div>

<?php elseif ($erroMsg === 'mesmo'): ?>
  <div class="cd"><div class="empty"><div class="ic">⚠️</div><p>Selecione inventários ou lojas <b>diferentes</b>.</p></div></div>
<?php else: ?>
  <div class="cd"><div class="empty"><div class="ic">🔍</div>
    <p>Selecione as lojas e inventários dos dois lados.</p>
    <p style="font-size:12px;color:var(--g400)">Mesma loja com inventários diferentes ou lojas diferentes.</p>
  </div></div>
<?php endif; ?>

</main>

<script>
var M = <?= json_encode($invMap, JSON_UNESCAPED_UNICODE) ?>;
var form = document.getElementById('F');

// Ao trocar loja → popula inventários e auto-submete
document.getElementById('c1').addEventListener('change', function(){ carregarInvs('c1','i1'); });
document.getElementById('c2').addEventListener('change', function(){ carregarInvs('c2','i2'); });

// Ao trocar inventário manualmente → auto-submete
document.getElementById('i1').addEventListener('change', autoSubmit);
document.getElementById('i2').addEventListener('change', autoSubmit);

function carregarInvs(codId, invId) {
    var sel = document.getElementById(invId);
    var cod = document.getElementById(codId).value;
    sel.innerHTML = '<option value="">Inventário…</option>';

    if (cod && M[cod]) {
        for (var x = 0; x < M[cod].length; x++) {
            var iv = M[cod][x];
            var o = document.createElement('option');
            o.value = iv.id;
            o.textContent = '#' + iv.id + ' — ' + iv.dt;
            if (x === 0) o.selected = true;
            sel.appendChild(o);
        }
        sel.style.borderColor = '#16a34a';
        setTimeout(function(){ sel.style.borderColor = ''; }, 500);
    }
    autoSubmit();
}

function autoSubmit() {
    if (document.getElementById('c1').value &&
        document.getElementById('c2').value &&
        document.getElementById('i1').value &&
        document.getElementById('i2').value) {
        form.submit();
    }
}

function filtrar(f) {
    document.getElementById('hFiltro').value = f;
    form.submit();
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>