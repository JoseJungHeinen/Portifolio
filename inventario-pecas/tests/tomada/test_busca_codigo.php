<?php
declare(strict_types=1);

/**
 * Busca da Tomada de Decisão: quando o texto como veio não acha nada, o bipe
 * (sufixo de variante, zeros do leitor) resolve e a busca roda de novo.
 * Lê o inventário 45 e o Apollo, empresa 1 / revenda 1; não grava nada.
 */

chdir(__DIR__ . '/../..');
require __DIR__ . '/../contagem2/assert.php';

echo "test_busca_codigo\n";

$tmp = sys_get_temp_dir();

$buscar = function (string $modo, string $codigo) use ($tmp) {
    file_put_contents($tmp . '/tomada_busca.json', json_encode([
        'session' => ['inventario_login' => ['usuario' => 'gestor.teste', 'perfil' => 'gestor']],
        'get'     => ['modo' => $modo, 'codigo' => $codigo, 'empresa' => 1, 'revenda' => 1, 'id_inventario' => 45],
    ]));

    $saida = (string)shell_exec(
        'C:/xampp/php/php.exe -d display_errors=0 tests/tomada/run_ajax.php ' . escapeshellarg($tmp . '/tomada_busca.json')
    );
    // O OCI8 pode imprimir antes do corpo: o JSON é o último objeto da saída.
    $ini = strpos($saida, '{"ok"');

    return $ini === false ? null : json_decode(substr($saida, $ini), true);
};

$codigos = function (?array $r): array {
    return array_map(function ($i) { return (string)$i['item_estoque_pub']; }, $r['itens'] ?? []);
};

foreach (['buscar_item', 'buscar_item_oracle'] as $modo) {
    foreach (['063806', '063806-01', '063806001', '000063806', '000063806-01'] as $bipe) {
        $r = $buscar($modo, $bipe);
        t_true("$modo $bipe responde ok", $r['ok'] ?? false);
        t_true("$modo $bipe acha 063806", in_array('063806', $codigos($r), true));
    }

    // Texto livre continua como antes: descrição por LIKE, sem resolver.
    $r = $buscar($modo, 'ZZZNAOEXISTE');
    t_eq("$modo inexistente volta vazio", [], $codigos($r));
}

t_fim();
