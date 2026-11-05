<?php
session_start();

if (empty($_SESSION['portal_logado'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db_connect.php';
$id = $_GET['sistema'] ?? '';
$sistemas = carregar_todos_sistemas();

$autorizado = isset($sistemas[$id]) && ($_SESSION['portal_sistemas'][$id] ?? false) === true;

if (!$autorizado) {
    header('Location: index.php');
    exit;
}

$cfg   = $sistemas[$id];
$email = $_SESSION['portal_email'];
$senha = $_SESSION['portal_senha'];

/*
 * Sistemas com múltiplas instâncias (mesmo cadastro, bancos separados) podem
 * ter batido em qualquer uma delas no login — usa a instância registrada na
 * sessão pra saber pra qual banco/URL mandar o SSO. Sem registro (ex: sistema
 * sincronizado depois, sem instância marcada), cai pra primeira como padrão.
 */
$idInstancia = $_SESSION['portal_instancias'][$id] ?? ($cfg['instancias'][0]['id'] ?? 'principal');
$instancia   = null;
foreach ($cfg['instancias'] as $inst) {
    if ($inst['id'] === $idInstancia) {
        $instancia = $inst;
        break;
    }
}
$instancia = $instancia ?? $cfg['instancias'][0];

/*
 * Alguns sistemas identificam o login por uma coluna diferente do e-mail
 * (CPF, usuário...). O portal continua casando identidade por e-mail, mas
 * pra fazer o SSO de verdade precisa enviar o valor que aquele sistema
 * realmente espera — busca esse valor na hora, se for diferente do e-mail.
 */
$colunaIdentificador = $cfg['coluna_identificador_login'] ?? $cfg['coluna_email'];
if ($colunaIdentificador === $cfg['coluna_email']) {
    $valorIdentificador = $email;
} else {
    $pdo  = conectar_sistema($instancia['db']);
    $stmt = $pdo->prepare("SELECT {$colunaIdentificador} AS v FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?");
    $stmt->execute([$email]);
    $linha = $stmt->fetch();
    $valorIdentificador = $linha['v'] ?? '';
}

/*
 * Sistemas modernos (SPA + API própria) não usam sessão por cookie — fazem
 * login contra uma API que devolve um token, guardado no localStorage do
 * navegador. Nesses casos não dá pra simular um POST de formulário: o portal
 * chama a API por trás (aqui no servidor), pega a resposta, e devolve uma
 * página que só escreve o resultado no localStorage antes de redirecionar —
 * como portal e sistema estão na mesma origem, o app já encontra tudo pronto.
 */
if (($cfg['sso_tipo'] ?? '') === 'token_localstorage') {
    $corpo = json_encode([
        $cfg['sso_campo_post_email'] => $valorIdentificador,
        $cfg['sso_campo_post_senha'] => $senha,
    ]);

    // Algumas APIs (ex: Locadora) exigem um Bearer fixo em toda chamada, além
    // do e-mail/senha do usuário — é uma chave de aplicação, não credencial
    // pessoal. Só é enviado se o manifesto declarar sso_bearer_estatico.
    $headers = ['Content-Type: application/json'];
    if (!empty($cfg['sso_bearer_estatico'])) {
        $headers[] = 'Authorization: Bearer ' . $cfg['sso_bearer_estatico'];
    }

    // Algumas APIs bloqueiam (ou fazem a senha "sempre falhar") requisições que
    // não parecem vir do navegador real — sem Origin/Referer/User-Agent, que o
    // cURL não manda por padrão mas o fetch() do navegador manda sozinho por
    // estar na mesma origem do sistema de destino. Simula isso aqui.
    $origemPartes = parse_url($instancia['home_url']);
    if (!empty($origemPartes['scheme']) && !empty($origemPartes['host'])) {
        $headers[] = 'Origin: ' . $origemPartes['scheme'] . '://' . $origemPartes['host'];
    }
    $headers[] = 'Referer: ' . $instancia['home_url'];
    $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    $ch = curl_init($cfg['sso_login_api']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corpo,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $respostaBruta = curl_exec($ch);
    $erroCurl       = curl_error($ch);
    $statusHttpCurl = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resposta = json_decode((string)$respostaBruta, true);

    // Diagnóstico temporário: registra o que a API de login realmente devolveu
    // sempre que a decodificação falhar ou nenhum campo esperado for encontrado,
    // pra investigar por que o SSO de token não está completando (ver conversa
    // sobre o Locadora não logando automaticamente).
    error_log(sprintf(
        "[SSO diag] sistema=%s url=%s http=%s curl_erro=%s corpo=%s",
        $id,
        $cfg['sso_login_api'],
        $statusHttpCurl,
        $erroCurl ?: '(nenhum)',
        substr((string)$respostaBruta, 0, 500)
    ));

    // Diagnóstico extra: mostra o que exatamente foi enviado (sem expor a senha
    // inteira) pra comparar com o que a pessoa digitou manualmente no teste direto.
    $senhaMascarada = strlen($senha) >= 2
        ? $senha[0] . str_repeat('*', max(0, strlen($senha) - 2)) . substr($senha, -1)
        : str_repeat('*', strlen($senha));
    $diagEnvio = "email_enviado=" . $valorIdentificador . " | senha_enviada(mascarada)=" . $senhaMascarada . " | tamanho_senha=" . strlen($senha);

    $paresLocalStorage = [];
    if (is_array($resposta)) {
        foreach ($cfg['sso_localstorage'] as $regra) {
            // campo_resposta aceita caminho com ponto (ex: "data.token") pra
            // ler campos aninhados — respostas no formato {ok, data:{...}}
            // como a do Imóveis, além de campos soltos no nível raiz (Locadora).
            $valor = $resposta;
            foreach (explode('.', $regra['campo_resposta']) as $parte) {
                $valor = is_array($valor) ? ($valor[$parte] ?? null) : null;
            }
            if ($valor === null) {
                continue;
            }
            $paresLocalStorage[] = [
                'chave' => $regra['chave'],
                'valor' => !empty($regra['json']) ? json_encode($valor) : (string)$valor,
            ];
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
    <meta charset="UTF-8">
    <title>Entrando em <?= htmlspecialchars($cfg['nome']) ?>…</title>
    </head>
    <body>
    <?php if (!$paresLocalStorage): ?>
    <p>Não foi possível entrar em <?= htmlspecialchars($cfg['nome']) ?> automaticamente. Tente pelo site do sistema diretamente.</p>
    <?php if (!empty($_SESSION['portal_is_admin'])): ?>
    <div style="max-width:640px;margin:24px auto;text-align:left;font-family:monospace;font-size:12px;background:#f5f5f5;color:#111;padding:16px;border-radius:8px;white-space:pre-wrap;word-break:break-all">Diagnóstico (só visível pra admin):
API: <?= htmlspecialchars($cfg['sso_login_api']) ?>
HTTP: <?= htmlspecialchars((string)$statusHttpCurl) ?>
Erro cURL: <?= htmlspecialchars($erroCurl ?: '(nenhum)') ?>
Corpo da resposta: <?= htmlspecialchars(substr((string)$respostaBruta, 0, 500)) ?>
Envio: <?= htmlspecialchars($diagEnvio) ?></div>
    <?php endif; ?>
    <?php else: ?>
    <script>
    <?php foreach ($paresLocalStorage as $par): ?>
    localStorage.setItem(<?= json_encode($par['chave']) ?>, <?= json_encode($par['valor']) ?>);
    <?php endforeach; ?>
    window.location = <?= json_encode($instancia['home_url']) ?>;
    </script>
    <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

/*
 * Sistemas onde não dá pra automatizar o login (caso do Notas: portal em
 * http://portal.exemplo.local, sistema em https://notas.exemplo.local — origens
 * diferentes, então um cookie definido por uma nunca é aceito pela outra, e
 * o sistema exige um token CSRF amarrado à sessão, então nem repassar
 * cookie via cURL nem fetch() no navegador funcionam; a correção certa
 * seria um arquivo-ponte hospedado no próprio servidor do sistema, mas
 * ninguém aqui tem acesso a esse servidor pra instalar isso). Nesses casos
 * só levamos a pessoa direto pra tela de login real do sistema — sem
 * mandar credenciais por lugar nenhum. Como a senha já é sincronizada em
 * todo login bem-sucedido (sincronizar_senha_federada), a pessoa só
 * precisa digitar o mesmo e-mail/senha que usa no portal.
 */
if (($cfg['sso_tipo'] ?? '') === 'link_direto') {
    header('Location: ' . $instancia['home_url']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entrando em <?= htmlspecialchars($cfg['nome']) ?>…</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Jost:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/design-system.css">
<style>
body{display:flex;align-items:center;justify-content:center;background:var(--color-dark)}
.sso-box{text-align:center;color:#fff}
.sso-spinner{width:40px;height:40px;border-radius:50%;border:3px solid rgba(255,255,255,.2);border-top-color:<?= htmlspecialchars($cfg['cor']) ?>;margin:0 auto var(--space-5);animation:spin .8s linear infinite}
.sso-text{font-family:var(--font-bold);font-size:var(--text-h4)}
.sso-sub{font-family:var(--font-regular);font-size:var(--text-body-sm);color:rgba(255,255,255,.5);margin-top:var(--space-2)}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="sso-box">
    <div class="sso-spinner"></div>
    <div class="sso-text">Entrando em <?= htmlspecialchars($cfg['nome']) ?>…</div>
    <div class="sso-sub">Autenticação automática do portal</div>
</div>
<?php
$campoEmail = $cfg['campo_post_email'] ?? 'email';
$campoSenha = $cfg['campo_post_senha'] ?? 'senha';
?>
<form id="sso-form" method="POST" action="<?= htmlspecialchars($instancia['login_url']) ?>">
    <input type="hidden" name="<?= htmlspecialchars($campoEmail) ?>" value="<?= htmlspecialchars($valorIdentificador) ?>">
    <input type="hidden" name="<?= htmlspecialchars($campoSenha) ?>" value="<?= htmlspecialchars($senha) ?>">
</form>
<script>document.getElementById('sso-form').submit();</script>
</body>
</html>
