<?php
require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

$is_admin = !empty($_SESSION['is_admin']);

// filtros básicos
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$q    = isset($_GET['q'])    ? trim($_GET['q']) : '';

// paginação
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit  = 10; // 10 por página
$offset = ($page - 1) * $limit;

// para LIKE parcial por letras
$like = $q !== '' ? '%'.$q.'%' : null;

$sql         = '';
$params      = [];
$types       = '';
$total_sql   = '';
$total_params = [];
$total_types  = '';

switch ($tipo) {
    case 'transferencia':
        $sql = "
        SELECT
            'Transferência' AS origem,
            t.id,
            t.cpf_usuario,
            u.nome AS nome_usuario,
            CONCAT('Tipo: ', t.tipo_nome, ' | Ano: ', t.ano_veiculo) AS descricao,
            t.valor_total,
            t.criado_em
        FROM transferencia t
        LEFT JOIN usuarios u ON u.cpf = t.cpf_usuario
        WHERE 1=1
        ";
        $total_sql = "
        SELECT COUNT(*) AS total
        FROM transferencia t
        LEFT JOIN usuarios u ON u.cpf = t.cpf_usuario
        WHERE 1=1
        ";

        if ($q !== '') {
            $cond = " AND (t.cpf_usuario LIKE ? OR u.nome LIKE ?)";
            $sql       .= $cond;
            $total_sql .= $cond;

            $params[]       = $like;
            $params[]       = $like;
            $types         .= 'ss';

            $total_params[] = $like;
            $total_params[] = $like;
            $total_types   .= 'ss';
        }

        $sql     .= " ORDER BY t.criado_em DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types   .= 'ii';
        break;

    case 'emplacamento':
        $sql = "
        SELECT
            'Emplacamento' AS origem,
            e.id AS id,
            e.cpf_usuario,
            u.nome AS nome_usuario,
            CONCAT('Placa: ', IFNULL(e.placa,''), ' | Tipo: ', e.tipo_veiculo) AS descricao,
            e.valor AS valor_total,
            e.criado_em
        FROM emplacamento e
        LEFT JOIN usuarios u ON u.cpf = e.cpf_usuario
        WHERE 1=1
        ";
        $total_sql = "
        SELECT COUNT(*) AS total
        FROM emplacamento e
        LEFT JOIN usuarios u ON u.cpf = e.cpf_usuario
        WHERE 1=1
        ";

        if ($q !== '') {
            $cond = " AND (e.cpf_usuario LIKE ? OR u.nome LIKE ?)";
            $sql       .= $cond;
            $total_sql .= $cond;

            $params[]       = $like;
            $params[]       = $like;
            $types         .= 'ss';

            $total_params[] = $like;
            $total_params[] = $like;
            $total_types   .= 'ss';
        }

        $sql     .= " ORDER BY e.criado_em DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types   .= 'ii';
        break;

    case 'cartao':
        $sql = "
        SELECT
            'Cartão' AS origem,
            c.id,
            c.cpf_usuario,
            u.nome AS nome_usuario,
            CONCAT('Cliente: ', IFNULL(c.nome_cliente,''), ' | Bandeira: ', IFNULL(c.bandeira,'')) AS descricao,
            c.valor_total,
            c.criado_em
        FROM cartao c
        LEFT JOIN usuarios u ON u.cpf = c.cpf_usuario
        WHERE 1=1
        ";
        $total_sql = "
        SELECT COUNT(*) AS total
        FROM cartao c
        LEFT JOIN usuarios u ON u.cpf = c.cpf_usuario
        WHERE 1=1
        ";

        if ($q !== '') {
            $cond = " AND (c.cpf_usuario LIKE ? OR u.nome LIKE ?)";
            $sql       .= $cond;
            $total_sql .= $cond;

            $params[]       = $like;
            $params[]       = $like;
            $types         .= 'ss';

            $total_params[] = $like;
            $total_params[] = $like;
            $total_types   .= 'ss';
        }

        $sql     .= " ORDER BY c.criado_em DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types   .= 'ii';
        break;

    case 'orcamento':
        $sql = "
        SELECT
            'Orçamento' AS origem,
            o.id,
            o.cpf_usuario,
            u.nome AS nome_usuario,
            CONCAT('Cliente: ', IFNULL(o.nome_cliente,''), ' | Desc.: ', o.descricao) AS descricao,
            o.valor_total,
            o.criado_em
        FROM orcamento o
        LEFT JOIN usuarios u ON u.cpf = o.cpf_usuario
        WHERE 1=1
        ";
        $total_sql = "
        SELECT COUNT(*) AS total
        FROM orcamento o
        LEFT JOIN usuarios u ON u.cpf = o.cpf_usuario
        WHERE 1=1
        ";

        if ($q !== '') {
            $cond = " AND (o.cpf_usuario LIKE ? OR u.nome LIKE ?)";
            $sql       .= $cond;
            $total_sql .= $cond;

            $params[]       = $like;
            $params[]       = $like;
            $types         .= 'ss';

            $total_params[] = $like;
            $total_params[] = $like;
            $total_types   .= 'ss';
        }

        $sql     .= " ORDER BY o.criado_em DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types   .= 'ii';
        break;

    case 'acessorios':
        $sql = "
        SELECT
            'Acessórios' AS origem,
            oa.id,
            ua.cpf       AS cpf_usuario,
            ua.nome      AS nome_usuario,
            CONCAT('Itens: ', oa.ids_itens) AS descricao,
            oa.total     AS valor_total,
            oa.criado_em
        FROM orcamentos_acessorios oa
        LEFT JOIN usuarios ua ON ua.id = oa.user_id
        WHERE 1=1
        ";
        $total_sql = "
        SELECT COUNT(*) AS total
        FROM orcamentos_acessorios oa
        LEFT JOIN usuarios ua ON ua.id = oa.user_id
        WHERE 1=1
        ";

        if ($q !== '') {
            $cond = " AND (ua.cpf LIKE ? OR ua.nome LIKE ?)";
            $sql       .= $cond;
            $total_sql .= $cond;

            $params[]       = $like;
            $params[]       = $like;
            $types         .= 'ss';

            $total_params[] = $like;
            $total_params[] = $like;
            $total_types   .= 'ss';
        }

        $sql     .= " ORDER BY oa.criado_em DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types   .= 'ii';
        break;

    case 'usuarios':
        if (!$is_admin) {
            http_response_code(403);
            exit('Acesso negado');
        }

        $sql = "
        SELECT
            'Usuário' AS origem,
            u.id,
            u.cpf AS cpf_usuario,
            u.nome AS nome_usuario,
            CONCAT(u.nome, ' (', u.email, ')') AS descricao,
            NULL AS valor_total,
            u.criado_em
        FROM usuarios u
        WHERE 1=1
        ";
        $total_sql = "SELECT COUNT(*) AS total FROM usuarios u WHERE 1=1";

        if ($q !== '') {
            $cond = " AND (u.cpf LIKE ? OR u.nome LIKE ?)";
            $sql       .= $cond;
            $total_sql .= $cond;

            $params[]       = $like;
            $params[]       = $like;
            $types         .= 'ss';

            $total_params[] = $like;
            $total_params[] = $like;
            $total_types   .= 'ss';
        }

        $sql     .= " ORDER BY u.criado_em DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types   .= 'ii';
        break;

    case 'todos':
    default:
        if ($q === '') {
            // total de todos
            $total_sql = "
            SELECT (
                (SELECT COUNT(*) FROM transferencia)
              + (SELECT COUNT(*) FROM emplacamento)
              + (SELECT COUNT(*) FROM cartao)
              + (SELECT COUNT(*) FROM orcamento)
              + (SELECT COUNT(*) FROM orcamentos_acessorios)
            ) AS total
            ";

            // consulta com UNION sem filtro
            $sql = "
            SELECT
                'Transferência' AS origem,
                t.id,
                t.cpf_usuario,
                u.nome AS nome_usuario,
                CONCAT('Tipo: ', t.tipo_nome, ' | Ano: ', t.ano_veiculo) AS descricao,
                t.valor_total,
                t.criado_em
            FROM transferencia t
            LEFT JOIN usuarios u ON u.cpf = t.cpf_usuario

            UNION ALL

            SELECT
                'Emplacamento' AS origem,
                e.id,
                e.cpf_usuario,
                u.nome AS nome_usuario,
                CONCAT('Placa: ', IFNULL(e.placa,''), ' | Tipo: ', e.tipo_veiculo) AS descricao,
                e.valor AS valor_total,
                e.criado_em
            FROM emplacamento e
            LEFT JOIN usuarios u ON u.cpf = e.cpf_usuario

            UNION ALL

            SELECT
                'Cartão' AS origem,
                c.id,
                c.cpf_usuario,
                u.nome AS nome_usuario,
                CONCAT('Cliente: ', IFNULL(c.nome_cliente,''), ' | Bandeira: ', IFNULL(c.bandeira,'')) AS descricao,
                c.valor_total,
                c.criado_em
            FROM cartao c
            LEFT JOIN usuarios u ON u.cpf = c.cpf_usuario

            UNION ALL

            SELECT
                'Orçamento' AS origem,
                o.id,
                o.cpf_usuario,
                u.nome AS nome_usuario,
                CONCAT('Cliente: ', IFNULL(o.nome_cliente,''), ' | Desc.: ', o.descricao) AS descricao,
                o.valor_total,
                o.criado_em
            FROM orcamento o
            LEFT JOIN usuarios u ON u.cpf = o.cpf_usuario

            UNION ALL

            SELECT
                'Acessórios' AS origem,
                oa.id,
                ua.cpf       AS cpf_usuario,
                ua.nome      AS nome_usuario,
                CONCAT('Itens: ', oa.ids_itens) AS descricao,
                oa.total     AS valor_total,
                oa.criado_em
            FROM orcamentos_acessorios oa
            LEFT JOIN usuarios ua ON ua.id = oa.user_id

            ORDER BY criado_em DESC
            LIMIT ? OFFSET ?
            ";
            $params = [$limit, $offset];
            $types  = 'ii';
        } else {
            // total com filtro por CPF ou nome (parcial)
            $total_sql = "
            SELECT (
                (SELECT COUNT(*)
                 FROM transferencia t
                 LEFT JOIN usuarios u ON u.cpf = t.cpf_usuario
                 WHERE t.cpf_usuario LIKE ? OR u.nome LIKE ?)
              + (SELECT COUNT(*)
                 FROM emplacamento e
                 LEFT JOIN usuarios u ON u.cpf = e.cpf_usuario
                 WHERE e.cpf_usuario LIKE ? OR u.nome LIKE ?)
              + (SELECT COUNT(*)
                 FROM cartao c
                 LEFT JOIN usuarios u ON u.cpf = c.cpf_usuario
                 WHERE c.cpf_usuario LIKE ? OR u.nome LIKE ?)
              + (SELECT COUNT(*)
                 FROM orcamento o
                 LEFT JOIN usuarios u ON u.cpf = o.cpf_usuario
                 WHERE o.cpf_usuario LIKE ? OR u.nome LIKE ?)
              + (SELECT COUNT(*)
                 FROM orcamentos_acessorios oa
                 LEFT JOIN usuarios ua ON ua.id = oa.user_id
                 WHERE ua.cpf LIKE ? OR ua.nome LIKE ?)
            ) AS total
            ";
            $total_params = [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like];
            $total_types  = 'ssssssssss';

            // consulta com UNION + filtro (CPF ou nome)
            $sql = "
            SELECT
                'Transferência' AS origem,
                t.id,
                t.cpf_usuario,
                u.nome AS nome_usuario,
                CONCAT('Tipo: ', t.tipo_nome, ' | Ano: ', t.ano_veiculo) AS descricao,
                t.valor_total,
                t.criado_em
            FROM transferencia t
            LEFT JOIN usuarios u ON u.cpf = t.cpf_usuario
            WHERE t.cpf_usuario LIKE ? OR u.nome LIKE ?

            UNION ALL

            SELECT
                'Emplacamento' AS origem,
                e.id,
                e.cpf_usuario,
                u.nome AS nome_usuario,
                CONCAT('Placa: ', IFNULL(e.placa,''), ' | Tipo: ', e.tipo_veiculo) AS descricao,
                e.valor AS valor_total,
                e.criado_em
            FROM emplacamento e
            LEFT JOIN usuarios u ON u.cpf = e.cpf_usuario
            WHERE e.cpf_usuario LIKE ? OR u.nome LIKE ?

            UNION ALL

            SELECT
                'Cartão' AS origem,
                c.id,
                c.cpf_usuario,
                u.nome AS nome_usuario,
                CONCAT('Cliente: ', IFNULL(c.nome_cliente,''), ' | Bandeira: ', IFNULL(c.bandeira,'')) AS descricao,
                c.valor_total,
                c.criado_em
            FROM cartao c
            LEFT JOIN usuarios u ON u.cpf = c.cpf_usuario
            WHERE c.cpf_usuario LIKE ? OR u.nome LIKE ?

            UNION ALL

            SELECT
                'Orçamento' AS origem,
                o.id,
                o.cpf_usuario,
                u.nome AS nome_usuario,
                CONCAT('Cliente: ', IFNULL(o.nome_cliente,''), ' | Desc.: ', o.descricao) AS descricao,
                o.valor_total,
                o.criado_em
            FROM orcamento o
            LEFT JOIN usuarios u ON u.cpf = o.cpf_usuario
            WHERE o.cpf_usuario LIKE ? OR u.nome LIKE ?

            UNION ALL

            SELECT
                'Acessórios' AS origem,
                oa.id,
                ua.cpf       AS cpf_usuario,
                ua.nome      AS nome_usuario,
                CONCAT('Itens: ', oa.ids_itens) AS descricao,
                oa.total     AS valor_total,
                oa.criado_em
            FROM orcamentos_acessorios oa
            LEFT JOIN usuarios ua ON ua.id = oa.user_id
            WHERE ua.cpf LIKE ? OR ua.nome LIKE ?

            ORDER BY criado_em DESC
            LIMIT ? OFFSET ?
            ";
            $params = [
                $like, $like, // transferencia
                $like, $like, // emplacamento
                $like, $like, // cartao
                $like, $like, // orcamento
                $like, $like, // acessorios
                $limit, $offset
            ];
            $types  = 'ssssssssssii';
        }
        break;
}

// 1) total de registros
$total_rows  = 0;
$total_pages = 1;

if ($total_sql !== '') {
    $stmt_total = $mysqli->prepare($total_sql);
    if ($total_params) {
        $stmt_total->bind_param($total_types, ...$total_params);
    }
    $stmt_total->execute();
    $res_total   = $stmt_total->get_result()->fetch_assoc();
    $total_rows  = (int)($res_total['total'] ?? 0);
    $total_pages = max(1, (int)ceil($total_rows / $limit));
    $stmt_total->close();
}

// 2) consulta paginada
$stmt = $mysqli->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// query base para links
$baseQuery = http_build_query([
    'tipo' => $tipo,
    'q'    => $q,
]);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Busca de Simulações</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    font-size:.9rem;
    color:#333;
    margin:0;
    padding:12px 14px 26px 14px;
    background:#f5f5f5;
}
h1{
    font-size:1.2rem;
    color:#555;
    margin-bottom:10px;
}
.busca-container{
    background:#fff;
    border-radius:8px;
    padding:10px 12px;
    box-shadow:0 1px 4px rgba(0,0,0,.08);
    margin-bottom:10px;
}
.filtros{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:flex-end;
}
.filtros label{
    font-size:.78rem;
    color:#555;
    display:flex;
    flex-direction:column;
}
.filtros select,
.filtros input{
    margin-top:3px;
    padding:5px 7px;
    border-radius:4px;
    border:1px solid #ccc;
    font-size:.85rem;
    min-width:150px;
}
.filtros button{
    padding:6px 14px;
    border-radius:16px;
    border:0;
    background:#d81807;
    color:#fff;
    font-size:.8rem;
    cursor:pointer;
    font-weight:600;
}
.filtros button:hover{
    background:#b81406;
}
.resumo{
    margin-top:6px;
    font-size:.78rem;
    color:#777;
}

.resultados-container{
    margin-top:8px;
    background:#fff;
    border-radius:8px;
    box-shadow:0 1px 4px rgba(0,0,0,.08);
    overflow:hidden;
}
.resultados-header{
    padding:8px 12px;
    border-bottom:1px solid #eee;
    display:flex;
    justify-content:space-between;
    align-items:center;
}
.resultados-header span{
    font-size:.82rem;
    color:#666;
}

table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    padding:6px 8px;
    font-size:.8rem;
    border-bottom:1px solid #eee;
}
th{
    background:#fafafa;
    text-align:left;
    color:#555;
}
tr.linha-principal:hover{
    background:#fdf5f5;
}
.linha-detalhe td{
    background:#fdfdfd;
    font-size:.78rem;
}
.valor-cell{
    white-space:nowrap;
}
.btn-view{
    font-size:.75rem;
    padding:4px 10px;
    border-radius:999px;
    border:1px solid #d0d0d0;
    background:#ffffff;
    cursor:pointer;
}
.btn-view:hover{
    background:#f3f3f3;
}
.paginacao{
    padding:6px 10px 8px 10px;
    text-align:center;
    background:#fafafa;
    border-top:1px solid #eee;
}
.paginacao a{
    display:inline-block;
    margin:0 3px;
    padding:3px 9px;
    border-radius:999px;
    text-decoration:none;
    color:#555;
    font-size:.78rem;
    border:1px solid transparent;
}
.paginacao a:hover{
    border-color:#d81807;
    color:#d81807;
}
.paginacao .ativo{
    background:#d81807;
    color:#fff;
    border-color:#d81807;
}
.app-version-label{
    position:fixed;
    bottom:4px;
    right:8px;
    font-size:10px;
    color:#888;
    opacity:0.6;
    pointer-events:none;
    z-index:9999;
}
</style>
</head>
<body>

<div class="busca-container">
    <h1>Busca de simulações</h1>
    <form method="get">
        <div class="filtros">
            <label>
                Tipo
                <select name="tipo">
                    <option value="todos"         <?= $tipo === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="transferencia" <?= $tipo === 'transferencia' ? 'selected' : '' ?>>Transferência</option>
                    <option value="emplacamento"  <?= $tipo === 'emplacamento' ? 'selected' : '' ?>>Emplacamento</option>
                    <option value="cartao"        <?= $tipo === 'cartao' ? 'selected' : '' ?>>Cartão</option>
                    <option value="orcamento"     <?= $tipo === 'orcamento' ? 'selected' : '' ?>>Orçamento</option>
                    <option value="acessorios"    <?= $tipo === 'acessorios' ? 'selected' : '' ?>>Acessórios</option>
                    <?php if ($is_admin): ?>
                        <option value="usuarios"  <?= $tipo === 'usuarios' ? 'selected' : '' ?>>Usuários</option>
                    <?php endif; ?>
                </select>
            </label>

            <label>
                CPF / Nome
                <input type="text"
                       name="q"
                       value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
            </label>

            <button type="submit">Filtrar</button>
        </div>
    </form>

    <div class="resumo">
        <?php if ($total_rows > 0): ?>
            Exibindo página <?= $page; ?> de <?= $total_pages; ?> • <?= $total_rows; ?> registro(s) encontrado(s)
        <?php else: ?>
            Nenhum registro encontrado para os filtros informados.
        <?php endif; ?>
    </div>
</div>

<?php if ($result->num_rows > 0): ?>
<div class="resultados-container">
    <div class="resultados-header">
        <span>Resultados</span>
    </div>

    <table>
        <thead>
        <tr>
            <th>Origem</th>
            <th>ID</th>
            <th>Usuário</th>
            <th>Descrição</th>
            <th>Valor</th>
            <th>Data</th>
            <th style="width:120px;">Ações</th>
        </tr>
        </thead>
        <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
            <?php
            $id   = (int)$row['id'];
            $orig = $row['origem'];
            $link = '';

            if ($orig === 'Transferência') {
                $link = 'ver_transferencia.php?id='.$id;
            } elseif ($orig === 'Emplacamento') {
                $link = 'ver_emplacamento.php?id='.$id;
            } elseif ($orig === 'Cartão') {
                $link = 'ver_cartao.php?id='.$id;
            } elseif ($orig === 'Orçamento') {
                $link = 'ver_orcamento.php?id='.$id;
            } elseif ($orig === 'Acessórios') {
                $link = 'acessorios_orcamento_impressao.php?orcamento_id='.$id;
            }

            $cpf  = $row['cpf_usuario']  ?? '';
            $nome = $row['nome_usuario'] ?? '';
            ?>
            <tr class="linha-principal" onclick="toggleDetalhe(<?= $id; ?>, '<?= $orig; ?>')">
                <td><?= htmlspecialchars($orig, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= $id; ?></td>
                <td>
                    <?php
                    if ($nome !== '') {
                        echo htmlspecialchars($nome . ' (' . $cpf . ')', ENT_QUOTES, 'UTF-8');
                    } else {
                        echo htmlspecialchars($cpf, ENT_QUOTES, 'UTF-8');
                    }
                    ?>
                </td>
                <td><?= htmlspecialchars($row['descricao'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="valor-cell">
                    <?php
                    if ($orig === 'Usuário' || $row['valor_total'] === null) {
                        echo '-';
                    } else {
                        echo 'R$ '.number_format((float)$row['valor_total'], 2, ',', '.');
                    }
                    ?>
                </td>
                <td><?= htmlspecialchars($row['criado_em'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                    <?php if ($link !== ''): ?>
                        <button type="button"
                                class="btn-view"
                                onclick="event.stopPropagation(); window.open('<?= $link; ?>','_blank');">
                            Visualizar PDF
                        </button>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
            </tr>

            <tr id="detalhe-<?= $orig.'-'.$id; ?>" class="linha-detalhe" style="display:none;">
                <td colspan="7">
                    Detalhes completos de <?= htmlspecialchars($orig, ENT_QUOTES, 'UTF-8'); ?> #<?= $id; ?>.
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>

    <div class="paginacao">
        <?php if ($page > 1): ?>
            <a href="?<?= $baseQuery; ?>&page=<?= $page-1; ?>">«</a>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end   = min($total_pages, $page + 2);
        for ($i = $start; $i <= $end; $i++):
        ?>
            <a href="?<?= $baseQuery; ?>&page=<?= $i; ?>"
               class="<?= $i === $page ? 'ativo' : ''; ?>">
                <?= $i; ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="?<?= $baseQuery; ?>&page=<?= $page+1; ?>">»</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="app-version-label">
    <?= VERSAO_BUSCAR_SERVIDOR; ?>
</div>

<script>
function toggleDetalhe(id, origem){
    var rowId = 'detalhe-' + origem + '-' + id;
    var tr = document.getElementById(rowId);
    if (!tr) return;

    if (tr.style.display === 'none' || tr.style.display === '') {
        tr.style.display = 'table-row';
    } else {
        tr.style.display = 'none';
    }
}
</script>
</body>
</html>
