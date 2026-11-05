<?php
require_once __DIR__ . '/config_database.php';
session_start();

$logado    = !empty($_SESSION['logado']);
$is_gestor = isset($_SESSION['is_gestor']) && (int)$_SESSION['is_gestor'] === 1;
$setor     = $_SESSION['setor'] ?? null;
$loja      = $_SESSION['loja'] ?? null;

if (!$logado || !$is_gestor || !$setor || !$loja) {
    exit('Acesso negado');
}

$setorUsuario = $setor;
$lojaUsuario  = $loja;

$sql = "
SELECT
    u.id                          AS usuario_id,
    u.nome                        AS usuario_nome,
    u.setor                       AS usuario_setor,
    l.nome                        AS usuario_loja,
    t.id                          AS treinamento_id,
    t.titulo                      AS treinamento_titulo,
    ts.pasta                      AS treinamento_pasta,
    t.subpasta                    AS treinamento_subpasta,
    COALESCE(p.status, 'nao_iniciado') AS status,
    COALESCE(p.progresso, 0)           AS progresso,
    p.data_inicio,
    p.data_conclusao
FROM usuarios u
JOIN lojas l ON l.id = u.id_loja        -- ← JOIN com lojas
JOIN treinamento_setores ts ON ts.setor = u.setor
JOIN treinamentos t ON t.treinamento_setor_id = ts.id
LEFT JOIN treinamento_progresso p
       ON p.usuario_id = u.id
      AND p.treinamento_id = t.id
WHERE u.setor = ?
  AND l.nome = ?          -- ← compara pelo nome da loja
ORDER BY u.nome ASC, t.titulo ASC
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('ss', $setorUsuario, $lojaUsuario);
$stmt->execute();
$res = $stmt->get_result();

$porUsuario = [];
while ($r = $res->fetch_assoc()) {
    $uid = (int)$r['usuario_id'];
    $tid = (int)$r['treinamento_id'];

    if (!isset($porUsuario[$uid])) {
        $porUsuario[$uid] = [
            'nome'         => $r['usuario_nome'],
            'setor'        => $r['usuario_setor'],
            'loja'         => $r['usuario_loja'],
            'treinamentos' => []
        ];
    }

    if (!isset($porUsuario[$uid]['treinamentos'][$tid])) {
        $porUsuario[$uid]['treinamentos'][$tid] = $r;
    }
}
$stmt->close();

foreach ($porUsuario as $uid => &$dados) {
    $treins      = $dados['treinamentos'];
    $total       = count($treins);
    $concluidos  = 0;

    foreach ($treins as $t) {
        if (($t['status'] ?? 'nao_iniciado') === 'concluido') {
            $concluidos++;
        }
    }

    $naoConcluidos           = max(0, $total - $concluidos);
    $dados['total']          = $total;
    $dados['concluidos']     = $concluidos;
    $dados['nao_concluidos'] = $naoConcluidos;
}
unset($dados);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Treinamentos - Gestor</title>
<style>
body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    background:#f4f4f5;
    margin:0;
    padding:12px;
    color:#111827;
}
h1{
    margin:0 0 8px;
    font-size:1.3rem;
}
.subtitulo{
    margin:0 0 16px;
    font-size:.9rem;
    color:#6b7280;
}

.usuario-card{
    background:#fff;
    border-radius:12px;
    border:1px solid #e5e7eb;
    margin-bottom:10px;
    box-shadow:0 2px 8px rgba(15,23,42,0.06);
    overflow:hidden;
    transition:background-color .2s ease,border-color .2s ease;
}
.usuario-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:8px 12px;
}
.usuario-info{
    display:flex;
    flex-direction:column;
}
.usuario-nome{
    font-size:.95rem;
    font-weight:600;
}
.usuario-setor{
    font-size:.8rem;
    color:#6b7280;
}

.usuario-resumo{
    display:flex;
    align-items:center;
    gap:10px;
}

.grafico-pizza{
    width:36px;
    height:36px;
    border-radius:50%;
    background:#e5e7eb;
    position:relative;
}
.grafico-pizza::after{
    content:'';
    position:absolute;
    inset:5px;
    border-radius:50%;
    background:#fff;
}

.usuario-resumo-texto{
    font-size:.75rem;
    color:#4b5563;
    max-width:190px;
}

.btn-detalhes{
    margin-left:8px;
    padding:4px 10px;
    font-size:.75rem;
    border-radius:999px;
    border:1px solid #d1d5db;
    background:#f9fafb;
    color:#111827;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:4px;
    transition:background-color .15s ease,border-color .15s ease,color .15s ease;
}
.btn-detalhes:hover{
    background:#e5e7eb;
    border-color:#9ca3af;
}
.btn-detalhes-icone{
    font-size:.8rem;
}

.usuario-body{
    padding:0 12px 0;
    border-top:1px solid #e5e7eb;
    background:#f9fafb;
}
.usuario-body-inner{
    max-height:0;
    overflow:hidden;
    transition:max-height .3s ease, padding .3s ease;
    padding-top:0;
    padding-bottom:0;
}

.usuario-card.aberto .usuario-body-inner{
    max-height:600px;
    padding-top:8px;
    padding-bottom:10px;
}

.usuario-card.aberto .usuario-header{
    background:#eff6ff;
}

.detalhes-topo{
    display:flex;
    gap:16px;
    align-items:center;
    margin-bottom:10px;
}
.detalhes-grafico{
    position:relative;
    width:90px;
    height:90px;
    border-radius:50%;
}
.detalhes-grafico-centro{
    position:absolute;
    inset:14px;
    border-radius:50%;
    background:#f9fafb;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:.8rem;
    font-weight:600;
    color:#111827;
}
.detalhes-texto{
    font-size:.8rem;
    color:#4b5563;
}

.lista-faltantes-titulo{
    margin-bottom:6px;
    font-size:.8rem;
    font-weight:600;
    color:#111827;
}
.lista-faltantes{
    margin:0 0 10px 0;
    padding-left:16px;
    font-size:.78rem;
    color:#374151;
}

.tabela-trein{
    width:100%;
    border-collapse:collapse;
    font-size:.78rem;
}
.tabela-trein th,
.tabela-trein td{
    padding:4px 3px;
    border-bottom:1px solid #e5e7eb;
    text-align:left;
}
.tabela-trein th{
    background:#f3f4f6;
    font-weight:600;
}
.tabela-trein tr:last-child td{
    border-bottom:none;
}
.status-pill{
    display:inline-block;
    padding:1px 7px;
    border-radius:999px;
    font-size:.7rem;
    font-weight:500;
}
.status-nao_iniciado{
    background:#f3f4f6;
    color:#6b7280;
}
.status-em_andamento{
    background:#eff6ff;
    color:#1d4ed8;
}
.status-concluido{
    background:#dcfce7;
    color:#15803d;
}
</style>
</head>
<body>
    <h1>Treinamentos - Gerenciamento de Equipe</h1>
    <p class="subtitulo">
        Setor: <strong><?= htmlspecialchars($setorUsuario, ENT_QUOTES, 'UTF-8') ?></strong>
        | Loja: <strong><?= htmlspecialchars($lojaUsuario, ENT_QUOTES, 'UTF-8') ?></strong>
    </p>

    <?php if (empty($porUsuario)): ?>
        <p>Nenhum colaborador encontrado para este setor/loja.</p>
    <?php else: ?>
        <?php foreach ($porUsuario as $uid => $dados):
            $treins        = $dados['treinamentos'];
            $total         = $dados['total'];
            $concluidos    = $dados['concluidos'];
            $naoConcluidos = $dados['nao_concluidos'];

            $percConcl   = $total > 0 ? round(($concluidos / $total) * 100) : 0;
            $anguloConcl = $total > 0 ? ($concluidos / $total) * 360 : 0;

            if ($total > 0) {
                $textoResumo = "Fez {$concluidos} de {$total}, faltam {$naoConcluidos} ({$percConcl}%)";
            } else {
                $textoResumo = "Nenhum treinamento cadastrado";
            }

            $faltantes   = [];
            $emAndamento = 0;
            foreach ($treins as $t) {
                $st = $t['status'] ?? 'nao_iniciado';
                if ($st !== 'concluido') {
                    $faltantes[] = $t;
                }
                if ($st === 'em_andamento') {
                    $emAndamento++;
                }
            }
        ?>
            <div class="usuario-card" data-uid="<?= (int)$uid ?>">
                <div class="usuario-header">
                    <div class="usuario-info">
                        <div class="usuario-nome">
                            <?= htmlspecialchars($dados['nome'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="usuario-setor">
                            <?= htmlspecialchars($dados['loja'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div class="usuario-resumo">
                        <div class="grafico-pizza"
                             aria-hidden="true"
                             style="background:
                                conic-gradient(
                                    #22c55e 0deg <?= $anguloConcl ?>deg,
                                    #e5e7eb <?= $anguloConcl ?>deg 360deg
                                );">
                        </div>
                        <div class="usuario-resumo-texto">
                            <?= htmlspecialchars($textoResumo, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <button type="button"
                                class="btn-detalhes"
                                aria-expanded="false">
                            <span class="btn-detalhes-icone">▼</span>
                            <span>Detalhes</span>
                        </button>
                    </div>
                </div>

                <div class="usuario-body">
                    <div class="usuario-body-inner">
                        <?php if ($total > 0): ?>
                            <div class="detalhes-topo">
                                <div class="detalhes-grafico"
                                     style="background:
                                        conic-gradient(
                                            #22c55e 0deg <?= $anguloConcl ?>deg,
                                            #e5e7eb <?= $anguloConcl ?>deg 360deg
                                        );">
                                    <div class="detalhes-grafico-centro">
                                        <?= $percConcl ?>%
                                    </div>
                                </div>
                                <div class="detalhes-texto">
                                    <div><strong>Feitos:</strong> <?= $concluidos ?> de <?= $total ?></div>
                                    <div><strong>Faltam:</strong> <?= $naoConcluidos ?> treinamentos</div>
                                    <div><strong>Em andamento:</strong> <?= $emAndamento ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($faltantes)): ?>
                            <div class="lista-faltantes-titulo">
                                Treinamentos que ainda faltam
                            </div>
                            <ul class="lista-faltantes">
                                <?php foreach ($faltantes as $t): ?>
                                    <li>
                                        <?= htmlspecialchars($t['treinamento_titulo'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php
                                            $st   = $t['status'];
                                            $prog = (int)$t['progresso'];
                                            if ($st === 'em_andamento') {
                                                echo " – em andamento ({$prog}%)";
                                            } elseif ($st === 'nao_iniciado') {
                                                echo " – não iniciado";
                                            }
                                        ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div style="font-size:.78rem;color:#16a34a;margin-bottom:10px;">
                                Todos os treinamentos deste colaborador estão concluídos.
                            </div>
                        <?php endif; ?>

                        <table class="
                        <table class="tabela-trein">
                            <thead>
                                <tr>
                                    <th>Treinamento</th>
                                    <th>Situação</th>
                                    <th>Detalhe</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($treins as $t):
                                    $status = $t['status'];
                                    $prog   = (int)$t['progresso'];

                                    if ($status === 'concluido') {
                                        $situacao = 'Feito';
                                        $detalhe  = 'Concluído 100%';
                                        $classe   = 'status-concluido';
                                    } elseif ($status === 'em_andamento') {
                                        $situacao = 'Em andamento';
                                        $detalhe  = "Já fez {$prog}%";
                                        $classe   = 'status-em_andamento';
                                    } else {
                                        $situacao = 'Não feito';
                                        $detalhe  = 'Ainda não iniciado';
                                        $classe   = 'status-nao_iniciado';
                                    }
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($t['treinamento_titulo'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <span class="status-pill <?= $classe ?>">
                                                <?= htmlspecialchars($situacao, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($detalhe, ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<script>
(function() {
    console.log('script de detalhes carregado');

    const cards = document.querySelectorAll('.usuario-card');
    console.log('cards encontrados:', cards.length);

    const toggleCard = (card) => {
        const btn = card.querySelector('.btn-detalhes');
        const isOpen = card.classList.toggle('aberto');

        console.log('toggleCard, aberto?', isOpen, card);

        if (btn) {
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            const icon = btn.querySelector('.btn-detalhes-icone');
            if (icon) icon.textContent = isOpen ? '▲' : '▼';
        }
    };

    cards.forEach((card, index) => {
        const btn = card.querySelector('.btn-detalhes');
        console.log('card', index, 'btn:', btn);
        if (!btn) return;

        btn.addEventListener('click', () => {
            console.log('clique no botão do card', index);

            // fecha os outros
            cards.forEach(other => {
                if (other !== card) {
                    other.classList.remove('aberto');
                    const otherBtn  = other.querySelector('.btn-detalhes');
                    const otherIcon = otherBtn ? otherBtn.querySelector('.btn-detalhes-icone') : null;
                    if (otherBtn)  otherBtn.setAttribute('aria-expanded', 'false');
                    if (otherIcon) otherIcon.textContent = '▼';
                }
            });

            toggleCard(card);
        });
    });
})();
</script>
</body>
</html>
