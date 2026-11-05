<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';

session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

// opcional: só admin pode mexer
if (empty($_SESSION['is_admin'])) {
    exit('Permissão negada');
}

$erro    = '';
$sucesso = '';

// 1) EXCLUSÃO DE REGISTRO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir_id'])) {
    $idExcluir = (int)$_POST['excluir_id'];
    if ($idExcluir > 0) {
        $stmtDel = $mysqli->prepare("DELETE FROM carros_apresentacao WHERE id = ?");
        if ($stmtDel) {
            $stmtDel->bind_param('i', $idExcluir);
            if ($stmtDel->execute()) {
                $sucesso = 'Veículo excluído com sucesso.';
            } else {
                $erro = 'Erro ao excluir: ' . $stmtDel->error;
            }
            $stmtDel->close();
        } else {
            $erro = 'Erro ao preparar SQL de exclusão: ' . $mysqli->error;
        }
    }
}

// 2) CADASTRO DE NOVO REGISTRO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['excluir_id'])) {
    $nome       = trim($_POST['nome'] ?? '');
    $ano        = (int)($_POST['ano'] ?? 0);
    $categoria  = trim($_POST['categoria'] ?? '');
    $youtubeUrl = trim($_POST['youtube_url'] ?? '');
    $destaque   = isset($_POST['destaque']) ? 1 : 0;
    $ativo      = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '' || $ano <= 0 || $categoria === '' || $youtubeUrl === '') {
        $erro = 'Preencha todos os campos obrigatórios.';
    } else {
        $sql = "
            INSERT INTO carros_apresentacao
                (nome, ano, categoria, youtube_url, destaque, ativo)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $erro = 'Erro ao preparar SQL: ' . $mysqli->error;
        } else {
            $stmt->bind_param('sissii', $nome, $ano, $categoria, $youtubeUrl, $destaque, $ativo);
            if ($stmt->execute()) {
                $sucesso = 'Veículo cadastrado com sucesso.';
            } else {
                $erro = 'Erro ao salvar: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// 3) LISTA DOS ÚLTIMOS CADASTRADOS
$lista = [];
$res = $mysqli->query("
    SELECT id, nome, ano, categoria, youtube_url, destaque, ativo, criado_em
    FROM carros_apresentacao
    ORDER BY criado_em DESC
    LIMIT 20
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $lista[] = $row;
    }
}
?>
<div style="max-width:900px;margin:0 auto;">
    <h2 style="color:#5A5A5A;font-size:1.6rem;margin-bottom:.5rem;">
        Configurar vídeos de apresentação
    </h2>
    <p style="color:#666;font-size:.95rem;margin-bottom:1rem;">
        Cadastre aqui os veículos e os links dos vídeos do YouTube para aparecerem na tela de apresentação.
    </p>

    <?php if ($erro): ?>
        <div style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;
                    padding:8px 10px;border-radius:4px;margin-bottom:10px;">
            <?php echo htmlspecialchars($erro); ?>
        </div>
    <?php elseif ($sucesso): ?>
        <div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;
                    padding:8px 10px;border-radius:4px;margin-bottom:10px;">
            <?php echo htmlspecialchars($sucesso); ?>
        </div>
    <?php endif; ?>

    <!-- FORMULÁRIO DE CADASTRO -->
    <form method="post" action="config_carros_apresentacao.php"
          style="background:#f4f4f4;padding:12px 16px;border-radius:8px;border:1px solid #ddd;margin-bottom:18px;">
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <div style="flex:1 1 260px;">
                <label style="font-size:.8rem;font-weight:600;">Nome do veículo *</label>
                <input type="text" name="nome" required
                       style="width:100%;padding:6px 8px;border-radius:4px;border:1px solid #ccc;">
            </div>

            <div style="flex:0 0 100px;">
                <label style="font-size:.8rem;font-weight:600;">Ano *</label>
                <input type="number" name="ano" required min="1900" max="2100"
                       style="width:100%;padding:6px 8px;border-radius:4px;border:1px solid #ccc;">
            </div>

            <div style="flex:1 1 180px;">
                <label style="font-size:.8rem;font-weight:600;">Categoria *</label>
                <input type="text" name="categoria" placeholder="SUV, Sedan, Hatch..."
                       style="width:100%;padding:6px 8px;border-radius:4px;border:1px solid #ccc;">
            </div>

            <div style="flex:1 1 100%;">
                <label style="font-size:.8rem;font-weight:600;">Link do vídeo do YouTube *</label>
                <input type="url" name="youtube_url" required
                       placeholder="https://www.youtube.com/watch?v=..."
                       style="width:100%;padding:6px 8px;border-radius:4px;border:1px solid #ccc;">
            </div>

            <div style="flex:1 1 160px;align-self:flex-end;">
                <label style="font-size:.8rem;font-weight:600;">Opções</label>
                <div style="display:flex;gap:12px;margin-top:4px;font-size:.85rem;color:#444;">
                    <label>
                        <input type="checkbox" name="destaque" value="1"> Destaque
                    </label>
                    <label>
                        <input type="checkbox" name="ativo" value="1" checked> Ativo
                    </label>
                </div>
            </div>

            <div style="flex:0 0 140px;align-self:flex-end;">
                <button type="submit"
                        style="width:100%;padding:8px 10px;border-radius:999px;border:none;
                               background:#2347c8;color:#fff;font-weight:600;cursor:pointer;">
                    Salvar
                </button>
            </div>
        </div>
    </form>

    <!-- LISTA DE ÚLTIMOS CADASTRADOS -->
    <?php if ($lista): ?>
        <h3 style="color:#5A5A5A;font-size:1.1rem;margin-bottom:.4rem;">
            Últimos cadastrados
        </h3>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <thead>
                <tr style="background:#eee;">
                    <th style="border:1px solid #ddd;padding:4px 6px;">ID</th>
                    <th style="border:1px solid #ddd;padding:4px 6px;">Nome</th>
                    <th style="border:1px solid #ddd;padding:4px 6px;">Ano</th>
                    <th style="border:1px solid #ddd;padding:4px 6px;">Categoria</th>
                    <th style="border:1px solid #ddd;padding:4px 6px;">YouTube</th>
                    <th style="border:1px solid #ddd;padding:4px 6px;">Dest.</th>
                    <th style="border:1px solid #ddd;padding:4px 6px;">Ativo</th>
                    <th style="border:1px solid #ddd;padding:4px 6px;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lista as $row): ?>
                    <tr>
                        <td style="border:1px solid #ddd;padding:4px 6px;"><?php echo $row['id']; ?></td>
                        <td style="border:1px solid #ddd;padding:4px 6px;"><?php echo htmlspecialchars($row['nome']); ?></td>
                        <td style="border:1px solid #ddd;padding:4px 6px;"><?php echo $row['ano']; ?></td>
                        <td style="border:1px solid #ddd;padding:4px 6px;"><?php echo htmlspecialchars($row['categoria']); ?></td>
                        <td style="border:1px solid #ddd;padding:4px 6px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php echo htmlspecialchars($row['youtube_url']); ?>
                        </td>
                        <td style="border:1px solid #ddd;padding:4px 6px;text-align:center;">
                            <?php echo $row['destaque'] ? 'Sim' : 'Não'; ?>
                        </td>
                        <td style="border:1px solid #ddd;padding:4px 6px;text-align:center;">
                            <?php echo $row['ativo'] ? 'Sim' : 'Não'; ?>
                        </td>
                        <td style="border:1px solid #ddd;padding:4px 6px;text-align:center;">
                            <form method="post" action="config_carros_apresentacao.php"
                                  onsubmit="return confirm('Excluir este vídeo?');"
                                  style="margin:0;">
                                <input type="hidden" name="excluir_id" value="<?php echo $row['id']; ?>">
                                <button type="submit"
                                        style="border:none;background:#dc3545;color:#fff;font-size:.8rem;
                                               padding:3px 8px;border-radius:4px;cursor:pointer;">
                                    Excluir
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
