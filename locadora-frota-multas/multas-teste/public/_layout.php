<?php
require_once __DIR__ . '/../config.php';
session_start();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function brl($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function data_br(?string $iso): string
{
    if (!$iso) return '';
    $t = strtotime($iso);
    return $t ? date(strlen($iso) > 10 && substr($iso, 11, 5) !== '00:00' ? 'd/m/Y H:i' : 'd/m/Y', $t) : $iso;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_campo(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_validar(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Sessão expirada. Volte e tente de novo.');
    }
}

function flash(?string $msg = null, string $tipo = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$tipo, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

const COR_ETAPA = [
    'falta_data' => 'nova', 'falta_email' => 'processo', 'falta_identificar' => 'processo',
    'falta_faturar' => 'faturar', 'aguardando_vcto' => 'vcto', 'falta_pagar' => 'pagar',
    'aguardando_baixar' => 'baixar', 'finalizado_detran' => 'final', 'fraude' => 'fraude',
];
function badge_etapa(string $etapa): string
{
    return '<span class="badge b-' . COR_ETAPA[$etapa] . '">' . h(ETAPAS[$etapa]) . '</span>';
}

function cabecalho(string $titulo, string $ativo): void
{
    $menu = ['index' => ['index.php', 'Painel'], 'multas' => ['multas.php', 'Multas'], 'resumo' => ['resumo.php', 'WhatsApp'], 'captura' => ['captura.php', 'Importar captura'], 'coletor' => ['coletor.php', 'Coletor do portal']];
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?> · Controle de Multas</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topo">
  <div class="marca">Controle de Multas <small>teste</small></div>
  <nav>
    <?php foreach ($menu as $k => [$href, $rot]): ?>
      <a href="<?= $href ?>" class="<?= $k === $ativo ? 'ativo' : '' ?>"><?= h($rot) ?></a>
    <?php endforeach; ?>
  </nav>
</header>
<main>
<?php if ($f = flash()): ?><div class="aviso aviso-<?= h($f[0]) ?>"><?= $f[1] ?></div><?php endif;
}

function rodape(): void
{
    echo "</main></body></html>";
}
