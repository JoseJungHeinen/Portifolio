<?php
declare(strict_types=1);

/**
 * acerto_locacao.php: só gestor/admin entra, POST sem o token da sessão não
 * faz nada, loja fora da lista não vale, e a locação é validada antes de
 * chegar ao Apollo. Nenhum caso aqui grava no Apollo: o único POST de gravar
 * leva locação inválida, que para na validação.
 */

chdir(__DIR__ . '/../..');
require __DIR__ . '/../contagem2/assert.php';

echo "test_seguranca\n";

$tmp = sys_get_temp_dir();

$abrir = function (array $session, ?array $post = null) use ($tmp): string {
    $caso = ['session' => $session];
    if ($post !== null) {
        $caso['post'] = $post;
    }
    file_put_contents($tmp . '/acerto_caso.json', json_encode($caso));

    return (string)shell_exec(
        'C:/xampp/php/php.exe -d display_errors=1 tests/acerto/run.php ' . escapeshellarg($tmp . '/acerto_caso.json')
    );
};

$gestor = ['inventario_login' => ['usuario' => 'gestor.teste', 'perfil' => 'gestor'], 'acerto_locacao_csrf' => 'tok'];
$status = function (string $html): string {
    return preg_match('/<div class="status [a-z]+">([^<]*)<\/div>/', $html, $m) ? html_entity_decode($m[1]) : '(sem status)';
};

// ── Quem entra ─────────────────────────────────────────────────────────────
t_true('sem sessao nao abre a tela', strpos($abrir([]), '<!DOCTYPE') === false);
t_true('coletor (sem perfil) nao abre',
    strpos($abrir(['inventario_login' => ['usuario' => 'Coletor 1', 'coletor_num' => 1]]), '<!DOCTYPE') === false);

$html = $abrir($gestor);
t_true('gestor abre', strpos($html, '<!DOCTYPE') !== false);
// O session_start() repetido é do runner, que abre a sessão antes da tela.
$semRunner = preg_replace('/.*session_start\(\): Ignoring session_start\(\).*\n?/', '', $html);
t_true('sem aviso nem erro do PHP', !preg_match('/(Warning|Notice|Fatal error|Deprecated):/', $semRunner));
t_eq('os dois formularios levam o token', 2, substr_count($html, 'name="csrf" value="tok"'));
t_true('admin abre', strpos($abrir(['inventario_login' => ['perfil' => 'admin'], 'acerto_locacao_csrf' => 'tok']), '<!DOCTYPE') !== false);

// ── Token ──────────────────────────────────────────────────────────────────
$html = $abrir($gestor, ['acao' => 'gravar', 'csrf' => 'forjado', 'item_pub' => '063806', 'empresa' => 1, 'revenda' => 1,
                         'z' => '1', 'r' => '1', 'e' => '1', 'p' => 'A', 'n' => '1']);
t_eq('POST com token errado nao grava', 'Formulário expirado. Busque o item de novo.', $status($html));
$html = $abrir($gestor, ['acao' => 'gravar', 'item_pub' => '063806', 'empresa' => 1, 'revenda' => 1]);
t_eq('POST sem token nao grava', 'Formulário expirado. Busque o item de novo.', $status($html));

// ── Busca (lê o Apollo) ────────────────────────────────────────────────────
$html = $abrir($gestor, ['acao' => 'buscar', 'csrf' => 'tok', 'item_pub' => '000063806-01', 'empresa' => 1, 'revenda' => 1]);
t_eq('busca com bipe cru acha o item', 'Pronto', $status($html));
t_true('mostra o botao de gravar', strpos($html, 'id="btnGravar"') !== false);
t_true('sem campo item_id escondido', strpos($html, 'name="item_id"') === false);

$html = $abrir($gestor, ['acao' => 'buscar', 'csrf' => 'tok', 'item_pub' => 'ZZZNAOEXISTE', 'empresa' => 1, 'revenda' => 1]);
t_eq('item inexistente', 'Item não encontrado.', $status($html));

// Loja fora da lista volta para a primeira (1/1), e não chega ao Apollo.
$html = $abrir($gestor, ['acao' => 'buscar', 'csrf' => 'tok', 'item_pub' => '063806', 'empresa' => 1, 'revenda' => 77]);
t_true('loja fora da lista vira a primeira', strpos($html, 'name="revenda" value="1"') !== false);
t_true('e nunca aparece na tela', strpos($html, 'value="77"') === false);

// ── Validação antes do Apollo ──────────────────────────────────────────────
$html = $abrir($gestor, ['acao' => 'gravar', 'csrf' => 'tok', 'item_pub' => '063806', 'empresa' => 1, 'revenda' => 1,
                         'z' => '1', 'r' => '500', 'e' => 'x', 'p' => 'A,', 'n' => '']);
$msg = $status($html);
t_true('locacao invalida para na validacao', strpos($msg, 'Rua vai de 0 a 99.') !== false);
t_true('com todos os erros de uma vez', strpos($msg, 'Estante') !== false && strpos($msg, 'Prateleira') !== false
    && strpos($msg, 'número') !== false);
t_true('o digitado volta na tela', strpos($html, 'name="r" value="500"') !== false);

t_fim();
