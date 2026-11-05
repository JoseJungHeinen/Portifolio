<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ROOT', dirname(__DIR__));
require ROOT . '/src/bootstrap.php';

use App\Models\Unidade;
use App\Models\Usuario;

$o = getopt('', ['nome:', 'email:', 'perfil:', 'senha:', 'unidades::', 'redefinir']);
$redefinir = isset($o['redefinir']);
if (!empty($o['senha'])) {
    fwrite(STDERR, "Aviso: --senha pode ficar no histórico do shell; prefira a variável de ambiente OFP_SENHA ou a digitação interativa.\n");
}
$o['senha'] = getenv('OFP_SENHA') ?: ($o['senha'] ?? '');
foreach ($redefinir ? ['email'] : ['nome', 'email', 'perfil'] as $chave) {
    if (empty($o[$chave])) {
        fwrite(STDERR, "Uso: php cli/criar_usuario.php --redefinir --email=x@exemplo.com (nova senha e desbloqueio)
ou: php cli/criar_usuario.php --nome=\"Nome\" --email=x@exemplo.com --perfil=admin [--unidades=\"Eurovale - Matriz,Eurovale - Jeep\"]\n(a senha é pedida; OFP_SENHA no ambiente também é aceita)\n");
        exit(1);
    }
}
if ($o['senha'] === '') {
    fwrite(STDOUT, 'Senha: ');
    $oculta = DIRECTORY_SEPARATOR !== '\\' && function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($oculta) {
        shell_exec('stty -echo');
    }
    $linha = fgets(STDIN);
    if ($oculta) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    $o['senha'] = $linha === false ? '' : rtrim($linha, "\r\n");
}
if (!$redefinir && !isset(Usuario::PERFIS[$o['perfil']])) {
    fwrite(STDERR, 'Perfil inválido. Use: ' . implode(', ', array_keys(Usuario::PERFIS)) . "\n");
    exit(1);
}
if (strlen($o['senha']) < 8) {
    fwrite(STDERR, "Senha com pelo menos 8 caracteres.\n");
    exit(1);
}
$usuarios = new Usuario();
if (!filter_var($o['email'], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "E-mail inválido.\n");
    exit(1);
}
if ($redefinir) {
    $existente = $usuarios->findByEmail(mb_strtolower(trim($o['email'])));
    if (!$existente) {
        fwrite(STDERR, "Usuário não encontrado.
");
        exit(1);
    }
    $usuarios->update($existente['id'], [
        'senha_hash'       => password_hash($o['senha'], PASSWORD_BCRYPT, ['cost' => bcrypt_cost()]),
        'tentativas_login' => 0,
        'bloqueado_ate'    => null,
    ]);
    echo "Senha redefinida e bloqueio removido: {$o['email']}
";
    exit(0);
}
if ($usuarios->findByEmail($o['email'])) {
    fwrite(STDERR, "E-mail já cadastrado.\n");
    exit(1);
}
$unidadeIds = [];
foreach (array_filter(array_map('trim', explode(',', (string)($o['unidades'] ?? '')))) as $nome) {
    $u = (new Unidade())->findByNome($nome);
    if (!$u) {
        fwrite(STDERR, "Unidade não encontrada: {$nome}\n");
        exit(1);
    }
    $unidadeIds[] = $u['id'];
}
$custo = bcrypt_cost();
$db = App\Config\Database::getInstance();
$db->beginTransaction();
try {
    $id = $usuarios->create([
        'nome'       => $o['nome'],
        'email'      => mb_strtolower(trim($o['email'])),
        'perfil'     => $o['perfil'],
        'senha_hash' => password_hash($o['senha'], PASSWORD_BCRYPT, ['cost' => $custo]),
    ]);
    $usuarios->sincronizarUnidades($id, $unidadeIds);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Falha ao criar usuário: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Usuário criado: {$o['email']} ({$o['perfil']})\n";
