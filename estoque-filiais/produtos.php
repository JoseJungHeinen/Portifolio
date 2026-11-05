<?php 
require 'includes/db.php';
include 'includes/header.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SESSION['cargo'] == 'filial') {
    echo "<div class='alert alert-danger'>Acesso negado.</div>";
    include 'includes/footer.php';
    exit;
}

// SALVAR PRODUTO (usa campo 'finalidade' como Setor na tela)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_produto'])) {
    $id          = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $nome        = $_POST['nome'];
    $desc        = $_POST['descricao'];
    $finalidade  = $_POST['finalidade'];   // aqui representa o Setor
    $recorrencia = $_POST['recorrencia'];
    $unidade     = $_POST['unidade_medida'];

    if ($id) {
        $stmt = $pdo->prepare("
            UPDATE produtos 
               SET nome = ?, descricao = ?, finalidade = ?, recorrencia = ?, unidade_medida = ?
             WHERE id = ?
        ");
        $stmt->execute([$nome, $desc, $finalidade, $recorrencia, $unidade, $id]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO produtos (nome, descricao, finalidade, recorrencia, unidade_medida, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$nome, $desc, $finalidade, $recorrencia, $unidade]);
    }
    header("Location: produtos.php?success=1");
    exit;
}

// LISTA DE PRODUTOS
$produtos = $pdo->query("
    SELECT p.*
      FROM produtos p
     ORDER BY p.nome
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800">Catálogo de Itens (Base Planilha)</h1>
        <p class="text-muted small">Produtos inspirados nas compras reais da planilha.</p>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">Produto salvo com sucesso.</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="flex-grow-1 me-3">
                <input 
                    type="text" 
                    id="buscaProduto" 
                    class="form-control" 
                    placeholder="Buscar por nome, setor ou descrição..."
                    onkeyup="filtrarProdutos()"
                >
            </div>
            <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#produtoModal">
                <i class="fas fa-plus me-2"></i> Novo Item
            </button>
        </div>

        <ul class="list-group" id="listaProdutos">
            <?php foreach ($produtos as $p): 

                // saldo total em estoque
                $stmt = $pdo->prepare("SELECT SUM(quantidade) AS total FROM estoque WHERE produto_id = ?");
                $stmt->execute([$p['id']]);
                $saldo = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

                // melhor preço de referência por fornecedor
                $stmt2 = $pdo->prepare("
                    SELECT pf.preco_referencia, f.nome_fantasia
                      FROM produto_fornecedor pf
                      JOIN fornecedores f ON pf.fornecedor_id = f.id
                     WHERE pf.produto_id = ?
                     ORDER BY pf.preco_referencia ASC
                     LIMIT 1
                ");
                $stmt2->execute([$p['id']]);
                $melhor = $stmt2->fetch(PDO::FETCH_ASSOC);

                $badge_class = 'bg-light text-dark border';
                if ($p['recorrencia'] == 'alta')  $badge_class = 'bg-danger text-white';
                if ($p['recorrencia'] == 'media') $badge_class = 'bg-warning text-dark';

                $pSafe = [
                    'id'          => $p['id'],
                    'nome'        => $p['nome'] ?? '',
                    'descricao'   => $p['descricao'] ?? '',
                    'finalidade'  => $p['finalidade'] ?? '',
                    'recorrencia' => $p['recorrencia'] ?? 'baixa',
                    'unidade'     => $p['unidade_medida'] ?? '',
                ];
                $jsonProduto = htmlspecialchars(json_encode($pSafe), ENT_QUOTES, 'UTF-8');
            ?>
            <li class="list-group-item produto-item">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1 me-3">
                        <h6 class="mb-1 text-primary">
                            <?php echo htmlspecialchars($p['nome']); ?>
                        </h6>
                        <div class="small text-muted">
                            <strong>Setor:</strong> <?php echo $p['finalidade'] ?: 'Não informado'; ?> ·
                            <strong>Unid.:</strong> <?php echo $p['unidade_medida'] ?: 'un'; ?>
                        </div>
                        <div class="small text-muted mt-1">
                            <?php echo $p['descricao'] ?: 'Sem descrição detalhada'; ?>
                        </div>
                        <?php if (!empty($melhor['nome_fantasia'])): ?>
                            <div class="small mt-1">
                                <strong>Fornecedor ref.:</strong> <?php echo $melhor['nome_fantasia']; ?> ·
                                <strong>Preço ref.:</strong> R$ 
                                <?php 
                                    $vl = $melhor['preco_referencia'] ?? 0;
                                    echo number_format($vl, 2, ',', '.'); 
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end" style="min-width:120px;">
                        <span class="badge <?php echo $badge_class; ?> mb-1">
                            <?php echo ucfirst($p['recorrencia']); ?>
                        </span>
                        <div class="small text-muted">
                            Estoque:
                            <span class="fw-bold text-<?php echo $saldo > 0 ? 'success' : 'danger'; ?>">
                                <?php echo (int)$saldo; ?>
                            </span>
                        </div>
                        <div class="mt-2">
                            <button 
                                class="btn btn-sm btn-outline-primary me-1"
                                onclick="editarProduto(<?php echo $jsonProduto; ?>)"
                            >
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="estoque.php" class="btn btn-sm btn-light border">
                                <i class="fas fa-eye"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<!-- Modal Produto -->
<div class="modal fade" id="produtoModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
      <div class="modal-header border-0 p-4 pb-0">
          <h5 class="modal-title font-weight-bold" id="modalTitle">Cadastrar Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <input type="hidden" name="salvar_produto" value="1">
        <input type="hidden" name="id" id="prod_id">

        <div class="mb-3">
            <label>Descrição (Nome do Item)</label>
            <input type="text" name="nome" id="prod_nome" class="form-control" 
                   placeholder="Ex: PAPEL A4, CAFÉ EM GRÃOS" required>
        </div>

        <div class="mb-3">
            <label>Descrição Técnica / Pacote</label>
            <textarea name="descricao" id="prod_desc" class="form-control" rows="2"
                      placeholder="Ex: Pacote 500 folhas, Caixa com 50 unidades..."></textarea>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label>Setor</label>
                <input type="text" name="finalidade" id="prod_finalidade" class="form-control"
                       placeholder="Ex: LAVAGEM, OFICINA, PREPARAÇÃO">
            </div>
            <div class="col-md-3 mb-3">
                <label>Unidade</label>
                <input type="text" name="unidade_medida" id="prod_unidade" class="form-control"
                       placeholder="un, cx, kg...">
            </div>
            <div class="col-md-3 mb-3">
                <label>Recorrência</label>
                <select name="recorrencia" id="prod_recorrencia" class="form-select">
                    <option value="baixa">Baixa</option>
                    <option value="media">Média</option>
                    <option value="alta">Alta</option>
                </select>
            </div>
        </div>

      </div>
      <div class="modal-footer border-0 p-4 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary px-4">Salvar Alterações</button>
      </div>
    </form>
  </div>
</div>

<script>
function editarProduto(p) {
    document.getElementById('modalTitle').innerText = 'Editar Item: ' + p.nome;
    document.getElementById('prod_id').value          = p.id;
    document.getElementById('prod_nome').value        = p.nome || '';
    document.getElementById('prod_desc').value        = p.descricao || '';
    document.getElementById('prod_finalidade').value  = p.finalidade || '';
    document.getElementById('prod_unidade').value     = p.unidade || '';
    document.getElementById('prod_recorrencia').value = p.recorrencia || 'baixa';
    new bootstrap.Modal(document.getElementById('produtoModal')).show();
}

function filtrarProdutos() {
    const input  = document.getElementById('buscaProduto');
    const filtro = input.value.toLowerCase();
    const lista  = document.getElementById('listaProdutos');
    const itens  = lista.getElementsByClassName('produto-item');

    for (let i = 0; i < itens.length; i++) {
        const texto = itens[i].innerText.toLowerCase();
        if (texto.indexOf(filtro) > -1) {
            itens[i].style.display = '';
        } else {
            itens[i].style.display = 'none';
        }
    }
}
</script>

<?php include 'includes/footer.php'; ?>
