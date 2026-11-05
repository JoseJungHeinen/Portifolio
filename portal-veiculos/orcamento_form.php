<?php
require_once __DIR__ . '/config_versao.php';
// orcamento_form.php
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Orçamento de Veículo - Empresa Exemplo</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; color:#000; max-width:900px; margin:20px auto; }
        h1 { font-size: 22px; }
        fieldset { border:1px solid #ccc; padding:15px; margin-bottom:15px; }
        legend { font-weight:bold; font-size:15px; }
        label { display:block; margin-top:8px; }
        input, select, textarea { width:100%; padding:6px; box-sizing:border-box; }
        .linha { display:flex; gap:10px; flex-wrap:wrap; }
        .col-50 { flex:1 1 50%; }
        .col-33 { flex:1 1 33%; min-width:150px; }
        button { padding:10px 20px; margin-top:15px; cursor:pointer; }
        textarea { min-height:80px; }
        .app-version-label{
    position:fixed;
    bottom:4px;
    right:8px;
    font-size:10px;
    color:#888;
    opacity:0.6;
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    pointer-events:none;
    z-index:9999;
}
    </style>
</head>
<body>

<h1>Orçamento de Veículo - Empresa Exemplo</h1>

<form action="gerar_pdf.php" method="post" target="_blank">

    <!-- DADOS DO CLIENTE -->
    <fieldset>
        <legend>Dados do Cliente</legend>

        <label>Código do Cliente
            <input type="text" name="cliente_codigo" required>
        </label>

        <label>Nome Completo
            <input type="text" name="cliente_nome" required>
        </label>

        <div class="linha">
            <div class="col-50">
                <label>E-mail
                    <input type="email" name="cliente_email">
                </label>
            </div>
            <div class="col-50">
                <label>Telefone / Celular
                    <input type="text" name="cliente_telefone">
                </label>
            </div>
        </div>

        <label>Endereço Completo
            <input type="text" name="cliente_endereco">
        </label>
    </fieldset>

    <!-- VEÍCULO VENDIDO -->
        <!-- VEÍCULOS VENDIDOS (vários) -->
    <fieldset>
    <legend>Veículos Vendidos</legend>

    <p>Cadastre um ou mais veículos que fazem parte deste orçamento.</p>

    <div id="veiculos_container">

        <!-- Veículo 1 (base) -->
        <div class="veiculo-bloco" data-index="0">
            <h3>Veículo 1</h3>

            <div class="linha">
                <div class="col-33">
                    <label>Marca
                        <input type="text" name="veiculos[0][marca]" required>
                    </label>
                </div>
                <div class="col-33">
                    <label>Modelo
                        <input type="text" name="veiculos[0][modelo]" required>
                    </label>
                </div>
                <div class="col-33">
                    <label>Ano
                        <input type="text" name="veiculos[0][ano]" required>
                    </label>
                </div>
            </div>

            <div class="linha">
                <div class="col-33">
                    <label>Cor
                        <input type="text" name="veiculos[0][cor]">
                    </label>
                </div>
                <div class="col-33">
                    <label>Chassi / Placa
                        <input type="text" name="veiculos[0][chassi]">
                    </label>
                </div>
                <div class="col-33">
                    <label>Valor do veículo (R$)
                        <input type="number" step="0.01" name="veiculos[0][valor]" required>
                    </label>
                </div>
            </div>
        </div>

    </div>

    <button type="button" id="add_veiculo">Adicionar veículo</button>
    <button type="button" id="remove_veiculo">Remover último</button>

</fieldset>


    <!-- VEÍCULO DA TROCA -->
    <fieldset>
        <legend>Veículo da Troca (usado)</legend>
        <p>Preencher apenas se houver veículo na troca.</p>

        <div class="linha">
            <div class="col-33">
                <label>Marca
                    <input type="text" name="veiculo_marca_troca">
                </label>
            </div>
            <div class="col-33">
                <label>Modelo
                    <input type="text" name="veiculo_modelo_troca">
                </label>
            </div>
            <div class="col-33">
                <label>Ano
                    <input type="text" name="veiculo_ano_troca">
                </label>
            </div>
        </div>

        <div class="linha">
            <div class="col-33">
                <label>Cor
                    <input type="text" name="veiculo_cor_troca">
                </label>
            </div>
            <div class="col-33">
                <label>Quilometragem
                    <input type="text" name="veiculo_km_troca">
                </label>
            </div>
            <div class="col-33">
                <label>Valor de avaliação (R$)
                    <input type="number" step="0.01" name="valor_troca">
                </label>
            </div>
        </div>
    </fieldset>

    <!-- CONDIÇÕES DE PAGAMENTO -->
    <fieldset>
        <legend>Condições de Pagamento</legend>

        <label>Forma de pagamento
            <select name="forma_pagamento" required>
                <option value="A vista">À vista (dinheiro / TED / PIX)</option>
                <option value="Financiamento">Financiamento</option>
                <option value="Consorcio">Consórcio</option>
                <option value="Misto">Mix: entrada + financiamento</option>
            </select>
        </label>

        <div class="linha">
            <div class="col-33">
                <label>Entrada (R$)
                    <input type="number" step="0.01" name="valor_entrada">
                </label>
            </div>
            <div class="col-33">
                <label>Valor a financiar (R$)
                    <input type="number" step="0.01" name="valor_financiar">
                </label>
            </div>
            <div class="col-33">
                <label>Nº de parcelas
                    <input type="number" name="qtd_parcelas">
                </label>
            </div>
        </div>

        <div class="linha">
            <div class="col-33">
                <label>Valor da parcela (R$)
                    <input type="number" step="0.01" name="valor_parcela">
                </label>
            </div>
            <div class="col-33">
                <label>Taxas e documentação (R$)
                    <input type="number" step="0.01" name="valor_taxas" value="500">
                </label>
            </div>
            <div class="col-33">
                <label>Desconto comercial (R$)
                    <input type="number" step="0.01" name="valor_desconto">
                </label>
            </div>
        </div>

        <label>Observações / condições de pagamento
            <textarea name="observacoes"
                      placeholder="Ex.: Itens de bonificação, condições específicas, etc."></textarea>
        </label>
    </fieldset>

    <button type="submit">Gerar Orçamento em PDF</button>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('veiculos_container');
    const btnAdd = document.getElementById('add_veiculo');
    const btnRem = document.getElementById('remove_veiculo');

    if (!container || !btnAdd || !btnRem) return;

    function atualizarIndices() {
        const blocos = container.querySelectorAll('.veiculo-bloco');
        blocos.forEach((bloco, i) => {
            bloco.dataset.index = i;
            const titulo = bloco.querySelector('h3');
            if (titulo) titulo.textContent = 'Veículo ' + (i + 1);

            bloco.querySelectorAll('input').forEach(inp => {
                const name = inp.getAttribute('name');
                const novo = name.replace(/veiculos\[\d+\]/, 'veiculos[' + i + ']');
                inp.setAttribute('name', novo);
            });
        });
    }

    btnAdd.addEventListener('click', () => {
        const blocos = container.querySelectorAll('.veiculo-bloco');
        const ultimo = blocos[blocos.length - 1];
        const clone = ultimo.cloneNode(true);
        clone.querySelectorAll('input').forEach(inp => inp.value = '');
        container.appendChild(clone);
        atualizarIndices();
    });

    btnRem.addEventListener('click', () => {
        const blocos = container.querySelectorAll('.veiculo-bloco');
        if (blocos.length > 1) {
            blocos[blocos.length - 1].remove();
            atualizarIndices();
        }
    });

    atualizarIndices();
});
</script>
<div class="app-version-label">
    <?php echo VERSAO_ORCAMENTO_FORM; ?>
</div>
</body>
</html>
