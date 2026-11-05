// ======================================================
// MÁSCARAS
// ======================================================

function moneyMask(event) {
  let value = event.target.value.replace(/\D/g, "");
  if (!value) {
    event.target.value = "";
    calculaResumo();
    return;
  }
  value = (parseInt(value, 10) / 100).toFixed(2);
  value = Number(value).toLocaleString("pt-BR", {
    style: "currency",
    currency: "BRL",
  });
  event.target.value = value;
  calculaResumo();
}

function unmaskMoney(str) {
  if (!str) return 0;
  return (
    parseFloat(
      str.toString().replace(/[R$\s\.]/g, "").replace(",", ".")
    ) || 0
  );
}

function phoneMask(event) {
  let v = event.target.value.replace(/\D/g, "");
  if (v.length > 11) v = v.slice(0, 11);

  if (v.length > 6) {
    event.target.value = v.replace(/(\d{2})(\d{5})(\d{0,4})/, "($1) $2-$3");
  } else if (v.length > 2) {
    event.target.value = v.replace(/(\d{2})(\d{0,5})/, "($1) $2");
  } else {
    event.target.value = v.replace(/(\d{0,2})/, "($1");
  }
}

// ======================================================
// VEÍCULOS DINÂMICOS
// ======================================================

function addVeiculo() {
  const container = document.getElementById("veiculoscontainer");
  if (!container) return;

  const blocos = container.querySelectorAll(".veiculo-bloco");
  if (!blocos.length) return;

  const newIndex = blocos.length;
  const clone = blocos[0].cloneNode(true);
  clone.setAttribute("data-index", newIndex);

  clone.querySelectorAll("input").forEach((inp) => {
    inp.value = "";
    if (inp.name) {
      inp.name = inp.name.replace(/\[\d+\]/, "[" + newIndex + "]");
    }
    if (inp.id) {
      inp.id = inp.id.replace(/_\d+$/, "_" + newIndex);
    }
    if (inp.classList.contains("mask-money")) {
      inp.removeEventListener("input", moneyMask);
      inp.removeEventListener("blur", moneyMask);
      inp.addEventListener("input", moneyMask);
      inp.addEventListener("blur", moneyMask);
    }
    if (inp.classList.contains("mask-phone")) {
      inp.removeEventListener("input", phoneMask);
      inp.addEventListener("input", phoneMask);
    }
  });

  const numDiv = clone.querySelector(".veiculo-numero");
  if (numDiv) numDiv.textContent = "Veículo " + (newIndex + 1);

  container.appendChild(clone);
  calculaResumo();
}

function removeVeiculo() {
  const container = document.getElementById("veiculoscontainer");
  if (!container) return;

  const blocos = container.querySelectorAll(".veiculo-bloco");
  if (blocos.length > 1) {
    container.removeChild(blocos[blocos.length - 1]);
    calculaResumo();
  }
}

// ======================================================
// CÁLCULO DO RESUMO
// ======================================================

function calculaResumo() {
  // Implementar lógica de cálculo se necessário
}

// ======================================================
// GERADOR DE RELATÓRIO COM WINDOW.PRINT()
// ======================================================

function gerarRelatorioImpressao() {
  // Coletar dados do formulário
  const numero = document.getElementById("numero_orcamento")?.value || "2025-001";
  const data = new Date().toLocaleString("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit"
  });

  const clienteNome = document.getElementById("cliente_nome")?.value || "";
  const clienteEmail = document.getElementById("cliente_email")?.value || "";
  const clienteTelefone = document.getElementById("cliente_telefone")?.value || "";
  const clienteEndereco = document.getElementById("cliente_endereco")?.value || "";
  const clienteCodigo = document.getElementById("cliente_codigo")?.value || "";

  // Veículos
  let veiculosHTML = "";
  const blocos = document.querySelectorAll(".veiculo-bloco");
  let idx = 1;

  blocos.forEach((bloco) => {
    const ano = bloco.querySelector(".veiculo-ano")?.value || "";
    const cor = bloco.querySelector(".veiculo-cor")?.value || "";
    const chassi = bloco.querySelector(".veiculo-chassi")?.value || "";
    const valor = bloco.querySelector(".veiculo-valor")?.value || "";

    if (ano || cor || chassi || valor) {
      veiculosHTML += `
        <div class="rel-veiculo-item">
          <div class="rel-veiculo-titulo">Veículo ${idx}</div>
          <div class="rel-veiculo-row">
            <span class="rel-veiculo-label">Ano:</span>
            <span class="rel-veiculo-value">${ano}</span>
            <span class="rel-veiculo-label">Cor:</span>
            <span class="rel-veiculo-value">${cor}</span>
          </div>
          <div class="rel-veiculo-row">
            <span class="rel-veiculo-label">Chassi/Placa:</span>
            <span class="rel-veiculo-value">${chassi}</span>
          </div>
          <div class="rel-veiculo-row">
            <span class="rel-veiculo-label">Valor:</span>
            <span class="rel-veiculo-value rel-money">${valor || "R$ 0,00"}</span>
          </div>
        </div>
      `;
      idx++;
    }
  });

  const modeloTroca = document.getElementById("veiculo_modelo_troca")?.value || "";
  const anoTroca = document.getElementById("veiculo_ano_troca")?.value || "";
  const corTroca = document.getElementById("veiculo_cor_troca")?.value || "";

  const observacoes = document.getElementById("observacoes")?.value || "";
  const formaPagamento = document.getElementById("forma_pagamento")?.value || "";

  // HTML do relatório
  const htmlRelatorio = `
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>Orçamento ${numero}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body { 
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Tahoma, sans-serif;
      color: #2c3e50;
      line-height: 1.5;
      background: white;
    }
    
    .rel-container {
      width: 210mm;
      height: 297mm;
      margin: 0 auto;
      padding: 20mm;
      background: white;
    }

    .rel-header { 
      display: flex; 
      justify-content: space-between; 
      align-items: flex-start; 
      margin-bottom: 18px;
      padding-bottom: 14px;
      border-bottom: 2px solid #2347C8;
    }
    
    .rel-logo img { 
      width: 90px; 
      height: auto;
    }
    
    .rel-company { 
      text-align: right; 
      font-size: 10px;
      line-height: 1.8;
      color: #606060;
    }
    
    .rel-company strong { 
      font-size: 16px;
      font-weight: 700;
      color: #2347C8;
      display: block;
      margin-bottom: 2px;
      letter-spacing: 0.05em;
    }
    
    .rel-title { 
      font-size: 22px; 
      font-weight: 700;
      text-align: center; 
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #2c3e50;
      margin: 16px 0 4px;
    }
    
    .rel-subtitle { 
      text-align: center; 
      font-size: 10px; 
      color: #606060; 
      margin-bottom: 18px;
      letter-spacing: 0.04em;
    }
    
    .rel-section { 
      border: 1px solid #d0d0d0; 
      margin-bottom: 14px; 
      border-radius: 4px;
      background: #fafbfc;
      page-break-inside: avoid;
    }
    
    .rel-section-header { 
      background: linear-gradient(135deg, #2347C8 0%, #cc0000 100%);
      padding: 8px 12px; 
      font-size: 10px; 
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      color: white;
      border-radius: 4px 4px 0 0;
    }
    
    .rel-section-body { 
      padding: 12px 14px; 
      font-size: 10px;
    }
    
    .rel-row { 
      display: flex;
      margin-bottom: 6px;
      align-items: baseline;
    }
    
    .rel-label { 
      font-weight: 600; 
      width: 90px;
      color: #606060;
      flex-shrink: 0;
    }
    
    .rel-value { 
      flex: 1;
      color: #2c3e50;
      word-break: break-word;
    }
    
    .rel-veiculo-item { 
      border: 1px solid #e0e0e0; 
      padding: 10px; 
      margin-bottom: 8px; 
      background: white;
      border-radius: 3px;
      border-left: 3px solid #2347C8;
    }
    
    .rel-veiculo-titulo {
      font-size: 10px;
      font-weight: 700;
      color: #2347C8;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    
    .rel-veiculo-row { 
      display: flex;
      margin-bottom: 4px;
      font-size: 9px;
      align-items: baseline;
    }
    
    .rel-veiculo-label { 
      font-weight: 600;
      width: 85px;
      color: #606060;
      flex-shrink: 0;
    }
    
    .rel-veiculo-value { 
      flex: 1;
      color: #2c3e50;
      margin-right: 12px;
    }
    
    .rel-money{
      font-family: 'Courier New', monospace;
      font-weight: 700;
      color: #2347C8;
    }
    
    table { 
      width: 100%; 
      border-collapse: collapse; 
      font-size: 10px;
      margin-top: 8px;
    }
    
    th { 
      background: #f0f0f0; 
      padding: 7px 8px; 
      text-align: left;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border-bottom: 2px solid #2347C8;
      color: #2c3e50;
    }
    
    td { 
      padding: 7px 8px; 
      border-bottom: 1px solid #e8e8e8;
      color: #2c3e50;
    }
    
    .rel-valor { 
      text-align: right;
      font-family: 'Courier New', monospace;
      font-weight: 700;
      color: #2347C8;
    }
    
    .rel-total{
      font-weight: 700;
      background: #7A4FD0;
      border-top: 2px solid #2347C8;
      border-bottom: 2px solid #2347C8;
      color: #2c3e50;
    }
    
    .rel-obs {
      background: #f5f7fa;
      padding: 10px 12px;
      border-radius: 3px;
      font-size: 9px;
      line-height: 1.6;
      color: #2c3e50;
      border-left: 3px solid #7A4FD0;
    }

    @media print {
      * { margin: 0; padding: 0; }
      body { margin: 0; padding: 0; }
      .rel-container { width: 100%; height: 100%; padding: 12mm; margin: 0; }
      .rel-section { page-break-inside: avoid; }
      @page { size: A4; margin: 12mm; }
    }
  </style>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<div class="rel-container">

  <!-- CABEÇALHO -->
  <div class="rel-header">
    <div class="rel-logo">
      <img src="assets/logo.svg" alt="Exemplo">
    </div>
    <div class="rel-company">
      <strong>EXEMPLO</strong>
      <div style="margin-top: 2px;">Empresa Exemplo</div>
      <div>Concessionária de Veículos</div>
      <div style="margin-top: 4px; font-size: 9px;">CNPJ: XX.XXX.XXX/0001-XX</div>
      <div>Tel: (00) 0000-0000</div>
    </div>
  </div>

  <!-- TÍTULO -->
  <div class="rel-title">ORÇAMENTO DE VEÍCULO</div>
  <div class="rel-subtitle">Nº ${numero} | Data: ${data}</div>

  <!-- DADOS DO CLIENTE -->
  <div class="rel-section">
    <div class="rel-section-header">DADOS DO CLIENTE</div>
    <div class="rel-section-body">
      ${clienteCodigo ? `<div class="rel-row"><span class="rel-label">Código:</span><span class="rel-value">${clienteCodigo}</span></div>` : ''}
      ${clienteNome ? `<div class="rel-row"><span class="rel-label">Nome:</span><span class="rel-value">${clienteNome}</span></div>` : ''}
      ${clienteEmail ? `<div class="rel-row"><span class="rel-label">E-mail:</span><span class="rel-value">${clienteEmail}</span></div>` : ''}
      ${clienteTelefone ? `<div class="rel-row"><span class="rel-label">Telefone:</span><span class="rel-value">${clienteTelefone}</span></div>` : ''}
      ${clienteEndereco ? `<div class="rel-row"><span class="rel-label">Endereço:</span><span class="rel-value">${clienteEndereco}</span></div>` : ''}
    </div>
  </div>

  <!-- VEÍCULOS VENDIDOS -->
  ${veiculosHTML ? `
  <div class="rel-section">
    <div class="rel-section-header">VEÍCULOS VENDIDOS</div>
    <div class="rel-section-body">
      ${veiculosHTML}
    </div>
  </div>
  ` : ''}

  <!-- VEÍCULO DA TROCA -->
  ${modeloTroca || anoTroca || corTroca ? `
  <div class="rel-section">
    <div class="rel-section-header">VEÍCULO DA TROCA</div>
    <div class="rel-section-body">
      <div class="rel-row">
        <span class="rel-label">Modelo:</span>
        <span class="rel-value">${modeloTroca}</span>
      </div>
      <div class="rel-row">
        <span class="rel-label">Ano:</span>
        <span class="rel-value">${anoTroca}</span>
      </div>
      <div class="rel-row">
        <span class="rel-label">Cor:</span>
        <span class="rel-value">${corTroca}</span>
      </div>
    </div>
  </div>
  ` : ''}

  <!-- RESUMO FINANCEIRO -->
  <div class="rel-section">
    <div class="rel-section-header">RESUMO FINANCEIRO</div>
    <div class="rel-section-body">
      <table>
        <thead>
          <tr>
            <th>DESCRIÇÃO</th>
            <th style="text-align: right;">VALOR (R$)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Total de veículos vendidos</td>
            <td class="rel-valor">0,00</td>
          </tr>
          <tr>
            <td>Subtotal</td>
            <td class="rel-valor">0,00</td>
          </tr>
          <tr class="rel-total">
            <td><strong>TOTAL DO NEGÓCIO</strong></td>
            <td class="rel-valor"><strong>R$ 0,00</strong></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- CONDIÇÃO DE PAGAMENTO -->
  <div class="rel-section">
    <div class="rel-section-header">CONDIÇÃO DE PAGAMENTO</div>
    <div class="rel-section-body">
      <div class="rel-row">
        <span class="rel-label">Forma:</span>
        <span class="rel-value" style="border-bottom: 1px solid #999; flex: 1; padding-bottom: 2px;">${formaPagamento || "_________________________________"}</span>
      </div>
    </div>
  </div>

  <!-- OBSERVAÇÕES -->
  ${observacoes ? `
  <div class="rel-section">
    <div class="rel-section-header">OBSERVAÇÕES</div>
    <div class="rel-section-body">
      <div class="rel-obs">
        ${observacoes.replace(/\n/g, '<br>')}
      </div>
    </div>
  </div>
  ` : ''}

</div>

</body>
</html>
  `;

  // Abrir em nova aba e imprimir
  const novaAba = window.open("", "relatorio_" + numero, "width=950,height=1000");
  novaAba.document.write(htmlRelatorio);
  novaAba.document.close();

  // Imprimir após carregar
  setTimeout(() => {
    novaAba.focus();
    novaAba.print();
  }, 1000);
}

// ======================================================
// ADICIONAR/REMOVER LINHAS DINÂMICAS
// ======================================================

function addRow(sectionId, templateClass) {
  const container = document.getElementById(sectionId);
  const first = container?.querySelector("." + templateClass);
  if (!container || !first) return;

  const clone = first.cloneNode(true);
  clone.classList.add("dynamic-row");

  clone.querySelectorAll("input, textarea, select").forEach((inp) => {
    if (inp.tagName === "SELECT") {
      inp.selectedIndex = 0;
    } else {
      inp.value = "";
    }
  });

  clone.querySelectorAll(".mask-money").forEach((inp) => {
    inp.addEventListener("input", moneyMask);
    inp.addEventListener("blur", moneyMask);
  });

  clone.querySelectorAll(".mask-phone").forEach((inp) => {
    inp.addEventListener("input", phoneMask);
  });

  container.appendChild(clone);
  calculaResumo();
}

function removeLastRow(sectionId, templateClass) {
  const container = document.getElementById(sectionId);
  if (!container) return;

  const rows = container.querySelectorAll("." + templateClass + ".dynamic-row");
  if (rows.length > 1) {
    container.removeChild(rows[rows.length - 1]);
    calculaResumo();
  }
}

// ======================================================
// TOGGLE DE PAGAMENTO
// ======================================================

function togglePayment(index) {
  const btn = document.querySelector(`.payment-pill[data-index="${index}"]`);
  const line = document.querySelector(`.payment-line[data-index="${index}"]`);
  if (!btn || !line) return;

  const isActive = btn.classList.contains("active");

  if (isActive) {
    btn.classList.remove("active");
    line.style.display = "none";
    line.querySelectorAll("input").forEach((inp) => (inp.value = ""));
  } else {
    btn.classList.add("active");
    line.style.display = "flex";
  }

  calculaResumo();
}

// ======================================================
// INICIALIZAÇÃO
// ======================================================

document.addEventListener("DOMContentLoaded", () => {
  // Máscaras
  document.querySelectorAll(".mask-money").forEach((inp) => {
    inp.addEventListener("input", moneyMask);
    inp.addEventListener("blur", moneyMask);
  });

  document.querySelectorAll(".mask-phone").forEach((inp) => {
    inp.addEventListener("input", phoneMask);
  });

  // Botões de veículos
  const addBtn = document.getElementById("addveiculo");
  if (addBtn) {
    addBtn.addEventListener("click", (e) => {
      e.preventDefault();
      addVeiculo();
    });
  }

  const remBtn = document.getElementById("removeveiculo");
  if (remBtn) {
    remBtn.addEventListener("click", (e) => {
      e.preventDefault();
      removeVeiculo();
    });
  }

  // Botão de impressão
  const printBtn =
    document.getElementById("btn-print") ||
    document.querySelector('button[data-action="print"]');
  if (printBtn) {
    printBtn.addEventListener("click", (e) => {
      e.preventDefault();
      gerarRelatorioImpressao();
    });
  }

  calculaResumo();
});
