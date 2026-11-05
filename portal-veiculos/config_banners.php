<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado']) || empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit('Acesso negado');
}


$erro = '';
$ok   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_FILES['banner']) || $_FILES['banner']['error'] !== UPLOAD_ERR_OK) {
        $codigo = $_FILES['banner']['error'] ?? 'sem arquivo';
        $erro = 'Erro no upload da imagem (código '.$codigo.').';
    } else {
        // DEBUG: ver se o arquivo chegou
        // echo '<pre>'; var_dump($_FILES['banner']); echo '</pre>';

        $nomeOriginal = $_FILES['banner']['name'];
        $tmp          = $_FILES['banner']['tmp_name'];
        $ext          = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        $permitidas   = ['jpg','jpeg','png'];

        if (!in_array($ext, $permitidas)) {
            $erro = 'Formato inválido. Use JPG ou PNG.';
        } else {
            $novoNome  = 'banner_'.time().'_'.mt_rand(1000,9999).'.'.$ext;

            $destinoFs  = __DIR__ . '/img/'.$novoNome; // pasta física
            $destinoUrl = '/Veiculos/img/'.$novoNome;           // caminho salvo no banco

            if (!move_uploaded_file($tmp, $destinoFs)) {
                $erro = 'Não foi possível mover o arquivo para a pasta img.';
            } else {
                $titulo = isset($_POST['titulo']) ? trim($_POST['titulo']) : '';
                $stmt = $mysqli->prepare("
                    INSERT INTO menu_banners (caminho, titulo, ordem)
                    VALUES (?, ?, 0)
                ");
                if (!$stmt) {
                    $erro = 'Erro no prepare: '.$mysqli->error;
                } else {
                    $stmt->bind_param('ss', $destinoUrl, $titulo);
                    if ($stmt->execute()) {
                        $ok = 'Banner enviado com sucesso.';
                    } else {
                        $erro = 'Erro ao salvar no banco: '.$stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// lista banners
$banners = [];
$res = $mysqli->query("
    SELECT id, caminho, COALESCE(titulo,'') AS titulo, criado_em
    FROM menu_banners
    ORDER BY criado_em DESC, id DESC
");
while ($row = $res->fetch_assoc()) {
    $banners[] = $row;
}
?>
<style>
/* ... seu CSS existente ... */

.app-version-label{
    position:fixed;
    bottom:4px;
    right:8px;
    font-size:10px;
    color:#888;
    opacity:0.6;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    pointer-events:none;
    z-index:9999;
}
</style>
<div style="max-width:700px;margin:auto;text-align:left;">
    <h2 style="color:#5A5A5A;font-size:1.6rem;margin-bottom:.8rem;">
        Banners do menu
    </h2>
    <p style="color:#666;font-size:.95rem;margin-bottom:1.2rem;">
        Envie imagens (JPG ou PNG) para aparecerem no carrossel da tela inicial.
        As 3 mais recentes são usadas automaticamente.
    </p>

    <?php if ($erro): ?>
    <div style="color:#b00;margin-bottom:.8rem;">
        <?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php elseif ($ok): ?>
    <div style="color:#060;margin-bottom:.8rem;">
        <?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

    <form method="post" enctype="multipart/form-data"
          style="background:#f4f4f4;padding:12px 16px;border-radius:10px;border:1px solid #ddd;margin-bottom:1rem;">
        <div style="margin-bottom:.5rem;">
            <label style="font-size:.85rem;font-weight:600;">Título (opcional)</label><br>
            <input type="text" name="titulo"
                   style="width:100%;padding:6px 8px;border-radius:4px;border:1px solid #ccc;">
        </div>

        <div style="margin-bottom:.5rem;">
            <label style="font-size:.85rem;font-weight:600;">Imagem (JPG/PNG)</label><br>
            <input type="file" name="banner" accept=".jpg,.jpeg,.png" required>
        </div>

        <button type="submit"
                style="margin-top:.5rem;padding:8px 14px;border-radius:999px;border:none;background:#2347c8;color:#fff;font-weight:600;cursor:pointer;">
            Enviar banner
        </button>
    </form>

    <?php if ($banners): ?>
        <h3 style="color:#5A5A5A;font-size:1.1rem;margin-bottom:.5rem;">Banners cadastrados</h3>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php foreach ($banners as $b): ?>
                <div style="width:160px;border:1px solid #ccc;border-radius:6px;padding:4px;font-size:.8rem;">
                    <div style="width:100%;height:90px;overflow:hidden;margin-bottom:4px;">
                        <img src="<?php echo htmlspecialchars($b['caminho'], ENT_QUOTES, 'UTF-8'); ?>"
                             style="width:100%;height:100%;object-fit:contain;">
                    </div>
                    <div><strong>
                        <?php echo htmlspecialchars($b['titulo'] ?: 'Sem título', ENT_QUOTES, 'UTF-8'); ?>
                    </strong></div>
                    <div style="color:#777;font-size:.75rem;">
                        <?php echo htmlspecialchars($b['criado_em'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p style="color:#777;">Nenhum banner cadastrado ainda.</p>
    <?php endif; ?>
    <div class="app-version-label">
    <?php echo VERSAO_CONFIG_BANNERS; ?>
</div>
</div>
