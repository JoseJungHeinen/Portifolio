<?php
require_once __DIR__ . '/config_database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

$nome      = $_SESSION['nome'] ?? 'Usuário';
$idUsuario = $_SESSION['id_usuario'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Orçamento de avarias</title>
    <!-- Exportar XLSX -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        body{
            margin:0;
            font-family:system-ui,sans-serif;
            background:#fff;
            color:#333;
        }
        .page{
            max-width:1100px;
            margin:0 auto;
            padding:20px 10px 30px;
        }
        .topo-pagina{
            margin-bottom:15px;
            text-align:left;
        }
        .topo-pagina h2{
            color:#5A5A5A;
            font-size:1.8rem;
            margin-bottom:5px;
            font-weight:700;
        }
        .topo-pagina p{
            color:#666;
            font-size:.95rem;
        }

        .card{
            background:#fff;
            border:1px solid #ddd;
            border-radius:8px;
            padding:12px 14px 14px;
            margin-bottom:12px;
        }
        .card h3{
            font-size:0.9rem;
            text-transform:uppercase;
            letter-spacing:.06em;
            color:#777;
            margin:0 0 8px;
        }

        .grid{
            display:grid;
            grid-template-columns:repeat(12,minmax(0,1fr));
            gap:8px 10px;
        }
        .col-2{grid-column:span 2;}
        .col-3{grid-column:span 3;}
        .col-4{grid-column:span 4;}
        .col-6{grid-column:span 6;}
        .col-12{grid-column:span 12;}

        @media(max-width:900px){
            .col-2,.col-3,.col-4,.col-6{
                grid-column:span 12;
            }
        }

        label{
            display:block;
            font-size:.78rem;
            font-weight:600;
            text-transform:uppercase;
            color:#666;
            margin-bottom:2px;
        }
        input,select,textarea{
            width:100%;
            max-width: 100%;
            min-width: 0;
            padding:6px 8px;
            border-radius:4px;
            border:1px solid #ccc;
            font-size:.9rem;
            outline:none;
        }
        input:focus,select:focus,textarea:focus{
            border-color:#2347c8;
        }
        textarea{
            min-height:60px;
            resize:none;      /* não permite puxar a borda */
            overflow:auto;    /* scroll interno se passar do tamanho */
        }

        table{
            width:100%;
            border-collapse:collapse;
            font-size:.85rem;
        }
        th,td{
            padding:5px 6px;
            border-bottom:1px solid #eee;
        }
        thead th{
            text-align:left;
            font-size:.75rem;
            text-transform:uppercase;
            color:#777;
        }
        tbody tr:nth-child(even){
            background:#fafafa;
        }

        td input,td select{
            font-size:.82rem;
            padding:4px 5px;
        }
        /* Cabeçalho: campos um pouco menores, com margem interna */
.card:first-of-type .grid input,
.card:first-of-type .grid select{
    max-width: 95%;
}

/* Novo item: deixar campos mais compactos */
.card:nth-of-type(2) .grid input,
.card:nth-of-type(2) .grid select{
    max-width: 90%;
}

/* Observações gerais: reduzir largura e altura */
.card:nth-of-type(4) textarea{
    max-width: 97%;
    min-height: 80px;
    resize:none;
    overflow:auto;
}
        .toolbar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-top:8px;
            font-size:.8rem;
            color:#666;
        }

        .botoes{
            display:flex;
            gap:8px;
            margin-top:12px;
            justify-content:flex-end;
        }
        .btn{
            border:0;
            border-radius:999px;
            padding:7px 14px;
            font-size:.85rem;
            cursor:pointer;
            font-weight:600;
        }
        .btn-vermelho{
            background:#2347c8;
            color:#fff;
        }
        .btn-cinza{
            background:#f1f1f1;
            color:#555;
        }
        .btn-branco{
            background:#fff;
            border:1px solid #ccc;
            color:#555;
        }

        .totais{
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            font-size:.85rem;
            margin-top:8px;
            border-top:1px dashed #ddd;
            padding-top:6px;
        }
        .totais span strong{
            font-size:.95rem;
        }

        .expandable-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            cursor:pointer;
            user-select:none;
        }
        .expandable-header:hover{
            opacity:.85;
        }
        .expand-icon{
            font-size:16px;
            transition:transform .25s;
        }
        .expand-icon.expanded{
            transform:rotate(180deg);
        }
        .expandable-content{
            max-height:900px;
            overflow:hidden;
            transition:max-height .25s,opacity .25s;
            opacity:1;
        }
        .expandable-content.collapsed{
            max-height:0;
            opacity:0;
        }
        .table-scroll{
            overflow-x:auto;
        }
        .sheet-table{
            width:100%;
            border-collapse:collapse;
            margin-top:10px;
            font-size:.8rem;
        }
        .sheet-table thead{
            background:#f5f5f5;
        }
        .sheet-table th,
        .sheet-table td{
            border:1px solid #ddd;
            padding:6px 8px;
            text-align:left;
        }
        .sheet-table tbody tr:nth-child(even){
            background:#fafafa;
        }
        .sheet-table tbody tr:hover{
            background:#f0f0ff;
        }
    </style>
</head>
<body>
<div class="page">

    <div class="topo-pagina">
        <h2>Orçamento de avarias</h2>
        <p>Preencha os dados do veículo e adicione os itens; cada item salvo aparece na tabela abaixo.</p>
    </div>

    <form method="post" action="orcamento_avarias_salvar.php" id="form-orcamento">
        <div class="card">
            <h3>Cabeçalho</h3>
            <div class="grid">
                <div class="col-3">
                    <label>Nº orçamento</label>
                    <input type="text" name="numero_orcamento" id="numero_orcamento" readonly>
                </div>

                <div class="col-3">
                    <label>Data</label>
                    <input type="date" name="data" id="data" value="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="col-3">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="rascunho">Rascunho</option>
                        <option value="enviado">Enviado</option>
                        <option value="aprovado">Aprovado</option>
                        <option value="reprovado">Reprovado</option>
                        <option value="concluido">Concluído</option>
                    </select>
                </div>

                <div class="col-3">
                    <label>Tipo</label>
                    <select name="tipo_servico" id="tipo_servico">
                        <option value="">Selecione...</option>
                        <option value="corretiva">Corretiva</option>
                        <option value="revisao">Revisão</option>
                        <option value="pneus">Pneus</option>
                    </select>
                </div>

                <div class="col-3">
                    <label>Placa</label>
                    <input type="text" name="placa" id="placa" maxlength="8">
                </div>

                <div class="col-3">
                    <label>Veículo</label>
                    <input type="text" name="veiculo" id="veiculo">
                </div>

                <div class="col-3">
                    <label>Cliente</label>
                    <input type="text" name="cliente" id="cliente">
                </div>

                <div class="col-3">
                    <label>Km atual</label>
                    <input type="number" name="km_atual" id="km_atual" min="0">
                </div>

                <div class="col-6">
                    <label>Usuário responsável</label>
                    <input type="text" value="<?php echo htmlspecialchars($nome); ?>" readonly>
                    <input type="hidden" name="usuario_id" value="<?php echo (int) $idUsuario; ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <h3>Novo item</h3>
            <div class="grid">
                <div class="col-6">
                    <label>Descrição</label>
                    <input type="text" id="item-descricao" placeholder="Ex.: Pastilhas + retífica discos">
                </div>

                <div class="col-2">
                    <label>Qtd</label>
                    <input type="number" id="item-qtd" value="1" step="0.01" min="0">
                </div>

                <div class="col-2">
                    <label>Vlr unit.</label>
                    <input type="number" id="item-vlr-unit" value="0.00" step="0.01" min="0">
                </div>

                <div class="col-2">
                    <label>Quem paga</label>
                    <select id="item-quem-paga">
                        <option value="locadora">Locadora</option>
                        <option value="cliente">Cliente</option>
                        <option value="sem_custo">Sem custo</option>
                    </select>
                </div>
            </div>

            <div class="botoes" style="justify-content:flex-start;margin-top:8px;">
                <button type="button" class="btn btn-vermelho" onclick="salvarItem()">Salvar item</button>
            </div>
        </div>

        <div class="card">
            <h3>Itens do orçamento</h3>
            <table id="tabela-itens">
                <thead>
                <tr>
                    <th>Descrição</th>
                    <th style="width:12%;">Qtd</th>
                    <th style="width:14%;">Vlr unit.</th>
                    <th style="width:14%;">Vlr total</th>
                    <th style="width:16%;">Quem paga</th>
                    <th style="width:5%;"></th>
                </tr>
                </thead>
                <tbody>
                </tbody>
            </table>

            <div class="totais">
                <span>Total orçamento: <strong id="total-geral">0,00</strong></span>
                <span>Total locadora: <strong id="total-locadora">0,00</strong></span>
                <span>Total cliente: <strong id="total-cliente">0,00</strong></span>
            </div>
        </div>

        <div class="card">
            <h3>Observações gerais</h3>
            <textarea name="observacoes_gerais"
                      id="observacoes_gerais"
                      placeholder="Detalhe a avaria, fotos, combinações com o cliente e observações da oficina."></textarea>
        </div>

        <div class="botoes">
            <button type="reset" class="btn btn-cinza">Limpar campos</button>
            <button type="submit" class="btn btn-vermelho">Salvar orçamento</button>
        </div>
    </form>

    <div class="card">
        <div class="expandable-header" onclick="toggleListaOrcamentos(this)">
            <h3 style="margin:0;">📄 Lista de orçamentos salvos</h3>
            <span>
                <button type="button" class="btn btn-branco" onclick="event.stopPropagation(); exportarOrcamentosXLSX();">
                    Exportar XLSX
                </button>
            </span>
            <span class="expand-icon">▼</span>
        </div>
        <div class="expandable-content collapsed" id="lista-orcamentos-wrapper">
            <div class="table-scroll">
                <table class="sheet-table" id="tabela-orcamentos">
                    <thead>
                    <tr>
                        <th>Nº Orçamento</th>
                        <th>Data</th>
                        <th>Placa</th>
                        <th>Veículo</th>
                        <th>Cliente</th>
                        <th>Tipo</th>
                        <th>Status</th>
                        <th>Km</th>
                        <th>Valor total</th>
                        <th>Ações</th>
                    </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
    let indiceItem = 0;
    let orcamentosSalvos = JSON.parse(localStorage.getItem('orcamentos_avarias') || '[]');

    // contador sequencial de número de orçamento
    function proximoNumeroOrcamento() {
    if (!orcamentosSalvos.length) {
        return 1;
    }
    const maxNumero = orcamentosSalvos.reduce((max, o) => {
        const n = parseInt(o.numero, 10) || 0;
        return n > max ? n : max;
    }, 0);
    return maxNumero + 1;
}


    window.addEventListener('load', () => {
    renderTabelaOrcamentos();
    if (!document.getElementById('numero_orcamento').value) {
        document.getElementById('numero_orcamento').value = proximoNumeroOrcamento();
    }
});
    function salvarItem() {
        const desc = document.getElementById('item-descricao').value.trim();
        const qtd  = parseFloat((document.getElementById('item-qtd').value || '0').replace(',', '.')) || 0;
        const vlrU = parseFloat((document.getElementById('item-vlr-unit').value || '0').replace(',', '.')) || 0;
        const quem = document.getElementById('item-quem-paga').value;

        if (!desc || !qtd || !vlrU) {
            alert('Preencha descrição, quantidade e valor unitário.');
            return;
        }

        const total = qtd * vlrU;

        const tbody = document.querySelector('#tabela-itens tbody');
        const tr = document.createElement('tr');

        tr.innerHTML = `
            <td>
                ${desc}
                <input type="hidden" name="itens[${indiceItem}][descricao]" value="${htmlEscape(desc)}">
            </td>
            <td>
                ${qtd.toFixed(2).replace('.', ',')}
                <input type="hidden" name="itens[${indiceItem}][qtd]" value="${qtd}">
            </td>
            <td>
                ${vlrU.toFixed(2).replace('.', ',')}
                <input type="hidden" name="itens[${indiceItem}][vlr_unit]" value="${vlrU}">
            </td>
            <td class="col-total">
                ${total.toFixed(2).replace('.', ',')}
                <input type="hidden" name="itens[${indiceItem}][vlr_total]" value="${total}">
            </td>
            <td>
                ${quem}
                <input type="hidden" name="itens[${indiceItem}][quem_paga]" value="${quem}">
            </td>
            <td style="text-align:center;">
                <button type="button" class="btn btn-branco" onclick="removerLinha(this)">X</button>
            </td>
        `;

        tbody.appendChild(tr);
        indiceItem++;

        atualizarTotais();
        limparFormularioItem();
    }

    function removerLinha(btn) {
        const tr = btn.closest('tr');
        if (tr) {
            tr.remove();
            atualizarTotais();
        }
    }

    function atualizarTotais() {
        let totalGeral = 0;
        let totalLoc   = 0;
        let totalCli   = 0;

        document.querySelectorAll('#tabela-itens tbody tr').forEach(tr => {
            const txtTotal = tr.querySelector('.col-total').childNodes[0].textContent.trim();
            const valor    = parseFloat(txtTotal.replace('.', '').replace(',', '.')) || 0;
            const quem     = tr.children[4].childNodes[0].textContent.trim();

            totalGeral += valor;
            if (quem === 'locadora') totalLoc += valor;
            if (quem === 'cliente')  totalCli += valor;
        });

        document.getElementById('total-geral').textContent    = totalGeral.toFixed(2).replace('.', ',');
        document.getElementById('total-locadora').textContent = totalLoc.toFixed(2).replace('.', ',');
        document.getElementById('total-cliente').textContent  = totalCli.toFixed(2).replace('.', ',');
    }

    function limparFormularioItem() {
        document.getElementById('item-descricao').value = '';
        document.getElementById('item-qtd').value       = '1';
        document.getElementById('item-vlr-unit').value  = '0.00';
        document.getElementById('item-quem-paga').value = 'locadora';
    }

    function htmlEscape(str) {
        return str
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');
    }

    function toggleListaOrcamentos(header){
        const content = document.getElementById('lista-orcamentos-wrapper');
        const icon    = header.querySelector('.expand-icon');

        content.classList.toggle('collapsed');
        icon.classList.toggle('expanded');
    }

    document.getElementById('form-orcamento').addEventListener('submit', function(e){
        e.preventDefault();

        let numero = document.getElementById('numero_orcamento').value;
        if (!numero) {
            numero = proximoNumeroOrcamento();
            document.getElementById('numero_orcamento').value = numero;
        }

        const data       = document.getElementById('data').value;
        const placa      = document.getElementById('placa').value;
        const veic       = document.getElementById('veiculo').value;
        const cli        = document.getElementById('cliente').value;
        const tipo       = document.getElementById('tipo_servico').value;
        const status     = document.getElementById('status').value;
        const km         = document.getElementById('km_atual').value;
        const valorTotal = document.getElementById('total-geral').textContent;

        const orcamento = {
            id: Date.now(),
            numero,
            data,
            placa,
            veiculo: veic,
            cliente: cli,
            tipo,
            status,
            km,
            valorTotal
        };

        orcamentosSalvos.unshift(orcamento);
        localStorage.setItem('orcamentos_avarias', JSON.stringify(orcamentosSalvos));

        renderTabelaOrcamentos();

        this.reset();
        document.getElementById('numero_orcamento').value = proximoNumeroOrcamento();
        document.getElementById('total-geral').textContent    = '0,00';
        document.getElementById('total-locadora').textContent = '0,00';
        document.getElementById('total-cliente').textContent  = '0,00';
        document.querySelector('#tabela-itens tbody').innerHTML = '';
        indiceItem = 0;

        // Para enviar ao PHP:
        // e.target.submit();
    });

    function renderTabelaOrcamentos() {
        const tbody = document.querySelector('#tabela-orcamentos tbody');
        tbody.innerHTML = '';

        orcamentosSalvos.forEach(orc => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${orc.numero}</td>
                <td>${orc.data}</td>
                <td>${orc.placa}</td>
                <td>${orc.veiculo}</td>
                <td>${orc.cliente}</td>
                <td>${orc.tipo}</td>
                <td>${orc.status}</td>
                <td>${orc.km}</td>
                <td>${orc.valorTotal}</td>
                <td>
                    <button type="button" class="btn btn-branco" onclick="excluirOrcamento(${orc.id})">Del</button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    function excluirOrcamento(id) {
        if (!confirm('Remover este orçamento da lista?')) return;
        orcamentosSalvos = orcamentosSalvos.filter(o => o.id !== id);
        localStorage.setItem('orcamentos_avarias', JSON.stringify(orcamentosSalvos));
        renderTabelaOrcamentos();
    }

    function exportarOrcamentosXLSX() {
        const tabela = document.getElementById('tabela-orcamentos');
        if (!tabela || orcamentosSalvos.length === 0) {
            alert('Não há orçamentos para exportar.');
            return;
        }

        const workbook = XLSX.utils.table_to_book(tabela, { sheet: 'Orcamentos' });
        XLSX.writeFile(workbook, 'orcamentos_avarias.xlsx');
    }

    // Carrega lista salva ao abrir a página
    window.addEventListener('load', () => {
        renderTabelaOrcamentos();
    });
</script>
</body>
</html>
