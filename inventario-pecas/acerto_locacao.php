<?php
declare(strict_types=1);

/**
 * Acerto de locação do gestor, aberto pelo dashboard: grava a locação de um
 * item em pec_item_revenda no Apollo e leva a mesma locação para o inventário
 * aberto da loja.
 *
 * Mesmas garantias do ajuste da conferência (conferencia_ajuste.php), de cujas
 * funções esta tela depende: conexão do conexao_oracle.php (a senha não fica
 * aqui), sessão de gestor, token contra POST forjado, loja só da lista, item
 * resolvido no servidor a cada requisição, locação validada pelas regras das
 * colunas do Apollo e gravação que só confirma com exatamente uma linha.
 */

session_start();
require 'conexao_inventario.php';
require 'conexao_oracle.php';
require_once 'conferencia_lib.php';

// Só gestor e admin: o login do coletor também preenche inventario_login, mas
// sem perfil — e esta tela escreve no Apollo.
if (!in_array($_SESSION['inventario_login']['perfil'] ?? '', ['gestor', 'admin'], true)) {
    header('Location: login_gestor.php');
    exit;
}

if (empty($_SESSION['acerto_locacao_csrf'])) {
    $_SESSION['acerto_locacao_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['acerto_locacao_csrf'];

$LOJAS = [
    ['empresa' => 1, 'revenda' => 1, 'nome' => 'Cidade A - Fiat'],
    ['empresa' => 1, 'revenda' => 4, 'nome' => 'Cidade C'],
    ['empresa' => 1, 'revenda' => 5, 'nome' => 'Cidade A - Jeep'],
    ['empresa' => 1, 'revenda' => 8, 'nome' => 'Santa Cruz do Sul'],
];

// Loja só da lista: empresa/revenda chegam do formulário e decidem onde se
// grava. Fora da lista, volta para a primeira.
$empresa = (int)($_POST['empresa'] ?? 0);
$revenda = (int)($_POST['revenda'] ?? 0);
$lojaOk  = false;
foreach ($LOJAS as $l) {
    if ($l['empresa'] === $empresa && $l['revenda'] === $revenda) {
        $lojaOk = true;
    }
}
if (!$lojaOk) {
    $empresa = $LOJAS[0]['empresa'];
    $revenda = $LOJAS[0]['revenda'];
}

$acao    = (string)($_POST['acao'] ?? '');
$itemPub = trim((string)($_POST['item_pub'] ?? ''));

$item_id       = null;
$descricao     = '';
$locacao_atual = [];
$status        = 'ready';
$status_msg    = 'Pronto';

/** z/r/e/p/n da tela a partir dos campos do Apollo (conferencia_locacao_da_loja). */
$paraTela = function (array $v): array {
    return ['z' => $v['zona'], 'r' => $v['rua'], 'e' => $v['estante'], 'p' => $v['prateleira'], 'n' => $v['numero']];
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($acao, ['buscar', 'gravar'], true)) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $status     = 'error';
        $status_msg = 'Formulário expirado. Busque o item de novo.';
    } elseif ($itemPub === '') {
        $status     = 'error';
        $status_msg = 'Informe o código do item.';
    } elseif (!($ora = oracle_conectar())) {
        $status     = 'error';
        $status_msg = 'Apollo indisponível. Tente de novo.';
    } else {
        try {
            // O item sai do código público, resolvido aqui, e nunca de um
            // campo escondido do formulário.
            $itemEstoque = conferencia_resolver_item($ora, $empresa, $itemPub);

            if ($itemEstoque === null) {
                $status     = 'error';
                $status_msg = 'Item não encontrado.';
            } else {
                $busca     = conferencia_buscar_oracle($ora, $empresa, $revenda, [], $itemPub);
                $descricao = (string)($busca['item']['descricao'] ?? '') ?: 'SEM DESCRIÇÃO';
                $atual     = conferencia_locacao_da_loja($ora, $empresa, $revenda, $itemEstoque);

                if ($atual === null) {
                    $status     = 'error';
                    $status_msg = 'Este item não tem cadastro de estoque nesta loja.';
                } elseif ($acao === 'buscar') {
                    $item_id       = $itemEstoque;
                    $locacao_atual = $paraTela($atual);
                } else {
                    $item_id   = $itemEstoque;
                    $validacao = conferencia_validar_locacao([
                        'zona'       => $_POST['z'] ?? '',
                        'rua'        => $_POST['r'] ?? '',
                        'estante'    => $_POST['e'] ?? '',
                        'prateleira' => $_POST['p'] ?? '',
                        'numero'     => $_POST['n'] ?? '',
                    ]);

                    if ($validacao['erros'] !== []) {
                        // Volta o que foi digitado, para corrigir sem redigitar.
                        $locacao_atual = [
                            'z' => $_POST['z'] ?? '', 'r' => $_POST['r'] ?? '', 'e' => $_POST['e'] ?? '',
                            'p' => $_POST['p'] ?? '', 'n' => $_POST['n'] ?? '',
                        ];
                        $status     = 'error';
                        $status_msg = implode(' ', $validacao['erros']);
                    } else {
                        conferencia_gravar_locacao($ora, $empresa, $revenda, $itemEstoque, $validacao['valores']);
                        $locacao_atual = $paraTela($validacao['valores']);
                        $status        = 'success';
                        $status_msg    = 'Sucesso! Dados gravados.';

                        // A locação nova entra na hora no inventário aberto da loja.
                        // O Apollo já gravou: se só isto falhar, avisa, sem desfazer.
                        try {
                            $linhasInv = conferencia_atualizar_locacao_inventario(
                                $pdo, $empresa, $revenda, (string)$itemEstoque,
                                (string)($busca['item']['item_estoque_pub'] ?? $itemPub), $validacao['valores']
                            );
                            if ($linhasInv > 0) {
                                $status_msg .= ' Inventário aberto atualizado.';
                            }
                        } catch (Throwable $e) {
                            error_log('acerto_locacao: locação gravada no Apollo, mas o inventário não acompanhou: ' . $e->getMessage());
                            $status_msg .= ' Atenção: o inventário aberto não foi atualizado.';
                        }
                    }
                }
            }
        } catch (RuntimeException $e) {
            error_log('acerto_locacao: ' . $e->getMessage());
            $status     = 'error';
            $status_msg = $acao === 'gravar'
                ? 'O Apollo não gravou a locação. Nada foi alterado — tente de novo.'
                : 'O Apollo recusou a consulta. Tente de novo.';
        }
        oci_close($ora);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualizar Locação - Pec Item Revenda</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container {
            max-width: 520px;
            margin: 0 auto;
            background: #ffffff;
            padding: 20px 22px 24px;
            border-radius: 10px;
            border: 1px solid #dde3ec;
            box-shadow: 0 4px 16px rgba(0, 34, 68, 0.08);
        }
        h1 {
            font-size: 18px;
            margin-bottom: 14px;
            color: #0c2a4d;
            border-bottom: 2px solid #ff7a00;
            padding-bottom: 6px;
        }
        .busca {
            background: #e6f0fb;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 14px;
            border: 1px solid #c3d6f3;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .busca label {
            font-size: 13px;
            font-weight: 600;
            color: #0c2a4d;
        }
        .busca input[type="text"], .busca select {
            padding: 6px 10px;
            border: 1px solid #b4c5dd;
            border-radius: 4px;
            min-width: 140px;
            font-size: 13px;
            outline: none;
        }
        .busca input[type="text"]:focus, .busca select:focus {
            border-color: #ff7a00;
            box-shadow: 0 0 0 2px rgba(255,122,0,0.18);
        }
        .busca button {
            padding: 7px 16px;
            border-radius: 4px;
            border: none;
            background: #004c8b;
            color: #ffffff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.05s;
        }
        .busca button:hover {
            background: #003a6a;
        }
        .busca button:active {
            transform: scale(0.97);
        }
        .info {
            margin: 12px 0;
            padding: 10px 12px;
            background: #fff9f2;
            border-left: 4px solid #ff7a00;
            border-radius: 4px;
            font-size: 13px;
            color: #333;
        }
        .info strong {
            color: #c75a00;
        }
        .grid {
            background: #f8fafc;
            padding: 16px 14px;
            border-radius: 8px;
            margin: 12px 0;
            border: 1px solid #dde3ec;
        }
        .grid table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .grid td {
            padding: 6px 4px;
            vertical-align: middle;
        }
        .grid label {
            font-weight: 600;
            color: #0c2a4d;
            min-width: 55px;
            display: inline-block;
        }
        .grid input[type="text"] {
            width: 70px;
            padding: 5px 6px;
            border: 1px solid #b4c5dd;
            border-radius: 4px;
            text-align: center;
            font-size: 13px;
            outline: none;
        }
        .grid input[type="text"]:focus {
            border-color: #ff7a00;
            box-shadow: 0 0 0 2px rgba(255,122,0,0.18);
        }
        .botoes {
            margin-top: 10px;
            text-align: center;
        }
        .botoes button {
            padding: 9px 18px;
            margin: 0 4px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.2s, transform 0.05s;
        }
        .btn-gravar {
            background: #ff7a00;
            color: #ffffff;
        }
        .btn-gravar:hover:not(:disabled) {
            background: #e86d00;
        }
        .btn-gravar:disabled {
            background: #d0d4dd;
            color: #7a7f88;
            cursor: not-allowed;
        }
        .btn-limpar {
            background: #ffffff;
            color: #0c2a4d;
            border: 1px solid #b4c5dd;
        }
        .btn-limpar:hover {
            background: #f0f4fb;
        }
        .btn-gravar:active,
        .btn-limpar:active {
            transform: scale(0.97);
        }
        .status {
            padding: 9px 10px;
            border-radius: 4px;
            margin: 12px 0 4px;
            text-align: center;
            font-weight: 500;
            font-size: 13px;
        }
        .status.ready {
            background: #eef5ff;
            color: #0c2a4d;
            border: 1px dashed #b4c5dd;
        }
        .status.loading {
            background: #fff5e6;
            color: #e66a00;
            border: 1px solid #ffd199;
        }
        .status.success {
            background: #e6f6ec;
            color: #1b7a3a;
            border: 1px solid #9fd9b5;
        }
        .status.error {
            background: #ffe9ea;
            color: #c62828;
            border: 1px solid #f3b5b8;
        }
        @media (max-width: 480px) {
            body { padding: 10px; }
            .container { margin: 0; padding: 16px; }
            .busca { flex-direction: column; align-items: flex-start; }
            .busca input[type="text"], .busca select { width: 100%; min-width: 0; }
            .grid table { font-size: 12px; }
            .grid input[type="text"] { width: 60px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Atualizar Locação - Pec Item Revenda</h1>

        <!-- Formulário de Busca -->
        <form method="POST" class="busca">
            <input type="hidden" name="acao" value="buscar">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <label>Loja:</label>
            <select name="empresa_revenda" onchange="atualizaEmpresaRevenda(this.value)">
                <?php foreach ($LOJAS as $l):
                    $val = $l['empresa'] . '-' . $l['revenda'];
                    $sel = ($l['empresa'] == $empresa && $l['revenda'] == $revenda) ? 'selected' : '';
                ?>
                    <option value="<?= $val ?>" <?= $sel ?>>
                        <?= htmlspecialchars($l['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="hidden" name="empresa" value="<?= htmlspecialchars((string)$empresa) ?>">
            <input type="hidden" name="revenda" value="<?= htmlspecialchars((string)$revenda) ?>">

            <label>Item Estoque PUB:</label>
            <input type="text" name="item_pub" value="<?= htmlspecialchars($itemPub) ?>"
                   placeholder="Digite o código" maxlength="20" required autofocus>
            <button type="submit">Buscar</button>
        </form>

        <?php if ($descricao): ?>
        <!-- Informações do Item -->
        <div class="info">
            <strong>Descrição:</strong> <?= htmlspecialchars($descricao) ?>
        </div>
        <?php endif; ?>

        <!-- Grid Nova Locação -->
        <form method="POST" class="grid">
            <input type="hidden" name="acao" value="gravar">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="item_pub" value="<?= htmlspecialchars($itemPub) ?>">
            <input type="hidden" name="empresa" value="<?= htmlspecialchars((string)$empresa) ?>">
            <input type="hidden" name="revenda" value="<?= htmlspecialchars((string)$revenda) ?>">

            <table>
                <tr>
                    <td><label>Zona:</label></td>
                    <td><input type="text" name="z" value="<?= htmlspecialchars((string)($locacao_atual['z'] ?? '')) ?>" maxlength="10"></td>
                    <td><label>Rua:</label></td>
                    <td><input type="text" name="r" value="<?= htmlspecialchars((string)($locacao_atual['r'] ?? '')) ?>" maxlength="10"></td>
                    <td><label>Estante:</label></td>
                    <td><input type="text" name="e" value="<?= htmlspecialchars((string)($locacao_atual['e'] ?? '')) ?>" maxlength="10"></td>
                </tr>
                <tr>
                    <td><label>Prat.:</label></td>
                    <td><input type="text" name="p" value="<?= htmlspecialchars((string)($locacao_atual['p'] ?? '')) ?>" maxlength="10"></td>
                    <td><label>Número:</label></td>
                    <td><input type="text" name="n" value="<?= htmlspecialchars((string)($locacao_atual['n'] ?? '')) ?>" maxlength="10"></td>
                    <td></td><td></td>
                </tr>
            </table>

            <div class="botoes">
                <?php if ($item_id): ?>
                <button type="submit" class="btn-gravar" id="btnGravar">Gravar no Banco</button>
                <?php endif; ?>
                <button type="button" class="btn-limpar" onclick="limparForm()">Limpar</button>
            </div>
        </form>

        <!-- Status -->
        <div class="status <?= $status ?>"><?= htmlspecialchars($status_msg) ?></div>
    </div>

    <script>
        function limparForm() {
            document.querySelectorAll('form')[0].submit();
        }

        function atualizaEmpresaRevenda(valor) {
            const partes = valor.split('-');
            if (partes.length === 2) {
                document.querySelector('input[name="empresa"]').value = partes[0];
                document.querySelector('input[name="revenda"]').value = partes[1];
            }
        }

        const campoPub = document.querySelector('input[name="item_pub"]');
        if (campoPub) {
            campoPub.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.form.submit();
                }
            });
        }
    </script>
</body>
</html>
