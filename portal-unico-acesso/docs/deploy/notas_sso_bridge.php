<?php
/**
 * notas_sso_bridge.php — ponte de SSO para o sistema de Notas
 *
 * O portal roda numa origem diferente (http://portal.exemplo.local) do Notas
 * (https://notas.exemplo.local) — um cookie definido por uma origem nunca é
 * aceito pela outra, então nem repassar cookie via cURL no portal nem
 * fetch() no navegador funcionam aqui (CORS bloqueia o fetch; e mesmo sem
 * CORS, cookie de uma origem não atravessa pra outra).
 *
 * Este arquivo roda NO PRÓPRIO servidor do Notas (mesma origem dele) — o
 * navegador do usuário é trazido até aqui via um POST real (form
 * auto-submit, feito pelo portal), o handshake de CSRF+login acontece a
 * partir daqui, e a RESPOSTA DESTA PÁGINA (que já sai de notas.exemplo.local)
 * é quem define o cookie de sessão — por isso o navegador aceita.
 *
 * SEGURANÇA: quem tiver o TOKEN abaixo consegue tentar logar como qualquer
 * e-mail/senha que enviar pra cá — mesma capacidade que a própria tela de
 * login pública já tem (não é um acesso novo, só automatiza o mesmo
 * formulário). Ainda assim, trate o token como senha: não coloque em lugar
 * público, só no config do portal.
 */

const TOKEN = 'TROQUE_POR_TOKEN_GERADO';

const LOGIN_FORM_URL = 'https://notas.exemplo.local/Notas/';
const LOGIN_POST_URL = 'https://notas.exemplo.local/Notas/?module=auth&action=login';
const HOME_URL        = 'https://notas.exemplo.local/Notas/';
const NOME_COOKIE     = 'sessid';
const REGEX_CSRF      = '/name="csrf_token" value="([a-f0-9]+)"/';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

if (!hash_equals(TOKEN, $_POST['token'] ?? '')) {
    http_response_code(403);
    exit('Token inválido.');
}

$email = trim($_POST['email'] ?? '');
$senha = $_POST['senha'] ?? '';

if ($email === '' || $senha === '') {
    http_response_code(400);
    exit('E-mail/senha ausentes.');
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_COOKIEFILE     => '',
    CURLOPT_COOKIEJAR      => '',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_FOLLOWLOCATION => true,
]);

curl_setopt($ch, CURLOPT_URL, LOGIN_FORM_URL);
curl_setopt($ch, CURLOPT_HTTPGET, true);
$html = curl_exec($ch);

preg_match(REGEX_CSRF, (string)$html, $m);
$csrfToken = $m[1] ?? '';

$campos = [
    'csrf_token' => $csrfToken,
    'next'       => '/Notas/',
    'email'      => $email,
    'senha'      => $senha,
];

curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_URL, LOGIN_POST_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos));
curl_exec($ch);

$status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cookieList = curl_getinfo($ch, CURLINFO_COOKIELIST);
curl_close($ch);

$sucesso = $status >= 300 && $status < 400;

if ($sucesso) {
    foreach ($cookieList as $linhaCookie) {
        $partes = explode("\t", $linhaCookie);
        if (($partes[5] ?? '') === NOME_COOKIE) {
            setcookie(NOME_COOKIE, $partes[6] ?? '', [
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
    }
    header('Location: ' . HOME_URL);
    exit;
}

http_response_code(401);
echo 'Não foi possível autenticar no Notas automaticamente. <a href="' . htmlspecialchars(LOGIN_FORM_URL) . '">Entrar manualmente</a>.';
