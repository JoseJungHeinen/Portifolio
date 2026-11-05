<?php
declare(strict_types=1);

/**
 * Cadastro de conferentes: quem pode entrar na Conferência, com PIN de 4
 * números. Só admin, como cadastro_usuario.php. Não há excluir: o nome
 * precisa continuar valendo nas conferências antigas, então sai pelo desativar.
 */

session_start();
require 'conexao_inventario.php';
require 'conferentes_lib.php';

if (empty($_SESSION['inventario_login'])) {
    header('Location: login_gestor.php');
    exit;
}
if (($_SESSION['inventario_login']['perfil'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

if (empty($_SESSION['conferentes_csrf'])) {
    $_SESSION['conferentes_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['conferentes_csrf'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $id   = (int)($_POST['id'] ?? 0);
    $flash = ['tipo' => 'success', 'texto' => ''];

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $flash = ['tipo' => 'danger', 'texto' => 'Formulário expirado. Tente de novo.'];
    } else {
        try {
            if ($acao === 'cadastrar') {
                $nome = trim((string)($_POST['nome'] ?? ''));
                conferentes_cadastrar($pdo, $nome, (string)($_POST['pin'] ?? ''));
                $flash['texto'] = 'Conferente ' . $nome . ' cadastrado.';
            } elseif ($acao === 'trocar_pin') {
                conferentes_trocar_pin($pdo, $id, (string)($_POST['pin'] ?? ''));
                $flash['texto'] = 'PIN trocado.';
            } elseif ($acao === 'ativar' || $acao === 'desativar') {
                conferentes_definir_ativo($pdo, $id, $acao === 'ativar');
                $flash['texto'] = $acao === 'ativar' ? 'Conferente ativado.' : 'Conferente desativado.';
            } elseif ($acao === 'desbloquear') {
                conferentes_desbloquear($pdo, $id);
                $flash['texto'] = 'Conferente desbloqueado.';
            } else {
                $flash = ['tipo' => 'danger', 'texto' => 'Ação inválida.'];
            }
        } catch (InvalidArgumentException $e) {
            $flash = ['tipo' => 'danger', 'texto' => $e->getMessage()];
        } catch (PDOException $e) {
            // Erro de banco que não é validação: registra no log do servidor e
            // não mostra SQL para o admin.
            error_log('cadastro_conferentes: ' . $e->getMessage());
            $flash = ['tipo' => 'danger', 'texto' => 'Erro ao gravar. Tente de novo.'];
        }
    }

    // Post/Redirect/Get: recarregar a página não repete a ação.
    $_SESSION['conferentes_flash'] = $flash;
    header('Location: cadastro_conferentes.php');
    exit;
}

$flash = $_SESSION['conferentes_flash'] ?? null;
unset($_SESSION['conferentes_flash']);

$conferentes = conferentes_listar($pdo, false);

$botaoAcao = function (string $acao, int $id, string $rotulo, string $classe) use ($csrf): string {
    return '<form method="post" style="display:inline;margin:0;">'
        . '<input type="hidden" name="csrf" value="' . htmlspecialchars($csrf) . '">'
        . '<input type="hidden" name="acao" value="' . $acao . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="btn btn-sm ' . $classe . '">' . $rotulo . '</button>'
        . '</form>';
};
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <?php $pageTitle = 'Conferentes'; require __DIR__ . '/includes/head.php'; ?>
</head>
<body>
  <main class="main-content" style="max-width:860px;margin:0 auto;padding:24px 16px;">
    <div class="d-flex justify-between align-center mb-3">
      <h1 class="page-title" style="margin:0;">Conferentes</h1>
      <a href="dashboard_inventario.php" class="btn btn-tertiary btn-sm">Voltar ao dashboard</a>
    </div>
    <p class="text-muted">Quem pode entrar na Conferência. No login a pessoa escolhe o nome e digita o PIN.</p>

    <?php if (is_array($flash) && $flash['texto'] !== ''): ?>
    <div class="alert alert--<?php echo $flash['tipo'] === 'danger' ? 'danger' : 'success'; ?> mb-3" role="status">
      <div class="alert-text"><?php echo htmlspecialchars($flash['texto']); ?></div>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-title">Novo conferente</div>
      <form method="post" class="d-flex gap-2 flex-wrap align-center" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="acao" value="cadastrar">
        <input class="form-input flex-1" name="nome" maxlength="120" aria-label="Nome" placeholder="Nome" required style="min-width:200px;">
        <input class="form-input" name="pin" type="password" inputmode="numeric" maxlength="4"
               pattern="\d{4}" title="4 números" aria-label="PIN" placeholder="PIN (4 números)" required style="width:160px;">
        <button type="submit" class="btn btn-primary">Cadastrar</button>
      </form>
    </div>

    <div class="card">
      <div class="card-title">Cadastrados</div>
      <?php if ($conferentes === []): ?>
        <p class="text-muted" id="sem-conferentes">Nenhum conferente cadastrado.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Nome</th><th>Situação</th><th>Trocar PIN</th><th>Ações</th></tr></thead>
          <tbody>
          <?php foreach ($conferentes as $c): ?>
            <tr>
              <td><?php echo htmlspecialchars($c['nome']); ?></td>
              <td>
                <?php if (!$c['ativo']): ?>
                  <span class="badge badge--muted">Inativo</span>
                <?php elseif ($c['bloqueado']): ?>
                  <span class="badge badge--danger">Bloqueado até <?php echo htmlspecialchars((string)$c['ate']); ?></span>
                <?php else: ?>
                  <span class="badge badge--success">Ativo</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" class="d-flex gap-1 align-center" style="margin:0;" autocomplete="off">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                  <input type="hidden" name="acao" value="trocar_pin">
                  <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                  <input class="form-input" name="pin" type="password" inputmode="numeric" maxlength="4"
                         pattern="\d{4}" title="4 números" aria-label="Novo PIN de <?php echo htmlspecialchars($c['nome']); ?>" placeholder="Novo PIN" required style="width:110px;">
                  <button type="submit" class="btn btn-secondary btn-sm">Trocar</button>
                </form>
              </td>
              <td class="text-right">
                <?php if ($c['bloqueado']) { echo $botaoAcao('desbloquear', (int)$c['id'], 'Desbloquear', 'btn-warning'); } ?>
                <?php echo $c['ativo']
                    ? $botaoAcao('desativar', (int)$c['id'], 'Desativar', 'btn-danger-outline')
                    : $botaoAcao('ativar', (int)$c['id'], 'Ativar', 'btn-success'); ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
