<?php
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado'])) {
    header('Location: index.php');
    exit;
}

// Carrega templates ativos (usado pelo card de geração em lote)
$templates = [];
$res = mysqli_query($mysqli, "SELECT id, nome FROM documento_templates WHERE ativo = 1 ORDER BY nome ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $templates[] = $row;
    }
    $res->free();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Geração de Documentos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            color: #1a1a1a;
            padding: 2rem 1rem 3rem;
        }

        .page-container {
            background: #ffffff;
            padding: 2rem 2.5rem 2.5rem;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
            width: 100%;
            max-width: 720px;
            border: 1px solid #ebebeb;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 1.8rem;
            padding-bottom: 1.2rem;
            border-bottom: 2px solid #f0f0f0;
            font-size: 1.2rem;
            font-weight: 700;
            color: #111;
            letter-spacing: -.01em;
            text-transform: none;
            text-align: left;
        }
        .page-title::before {
            content: '';
            display: inline-flex;
            width: 44px; height: 44px; min-width: 44px;
            background: #cc0000 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='22' height='22' fill='none' viewBox='0 0 24 24'%3E%3Cpath d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z' stroke='white' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3Cpath d='M14 2v6h6M16 13H8M16 17H8M10 9H8' stroke='white' stroke-width='1.8' stroke-linecap='round'/%3E%3C/svg%3E") center/22px no-repeat;
            border-radius: 12px;
        }

        .form-group { margin-bottom: 1.1rem; }

        label {
            display: block;
            margin-bottom: .4rem;
            color: #444;
            font-weight: 600;
            font-size: .85rem;
            letter-spacing: .02em;
        }

        select, input[type="text"], input[type="date"],
        input[type="number"], input[type="email"], textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 999px;
            font-size: .92rem;
            background: #fafafa;
            color: #1a1a1a;
            font-family: inherit;
            transition: border-color .15s, box-shadow .15s, background .15s;
            outline: none;
        }
        select:focus, input[type="text"]:focus, input[type="date"]:focus,
        input[type="number"]:focus, input[type="email"]:focus, textarea:focus {
            border-color: #cc0000;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(204,0,0,.08);
        }
        textarea { border-radius: 12px; min-height: 70px; resize: vertical; }

        .btn-primary {
            width: 100%; padding: 11px 18px;
            background: #cc0000; color: #fff;
            border: none; border-radius: 999px;
            font-size: .92rem; font-weight: 700;
            cursor: pointer; letter-spacing: .04em; text-transform: uppercase;
            box-shadow: 0 4px 14px rgba(204,0,0,.35);
            transition: background .15s, box-shadow .15s, transform .1s;
            font-family: inherit;
        }
        .btn-primary:hover { background: #aa0000; box-shadow: 0 6px 18px rgba(204,0,0,.45); }
        .btn-primary:active { transform: translateY(1px); }

        .btn-secondary {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 9px 20px;
            border-radius: 999px; border: 1px solid #ddd;
            background: #fff; color: #444;
            font-size: .85rem; font-weight: 600;
            text-decoration: none; cursor: pointer;
            transition: border-color .15s, background .15s;
            font-family: inherit;
        }
        .btn-secondary:hover { background: #fafafa; border-color: #bbb; }

        .message { margin-top: .9rem; padding: .75rem 1rem; border-radius: 10px; font-size: .85rem; }
        .message.info  { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .message.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        .dynamic-fields { margin-top: 1.2rem; }
        .field-row { margin-bottom: .85rem; }
        .field-row label { margin-bottom: .3rem; }
        .helper { font-size: .78rem; color: #888; margin-top: .4rem; }

        .options-group {
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .options-group label {
            display: inline-flex; align-items: center; gap: 6px;
            font-weight: 500; margin-bottom: 0; cursor: pointer;
            padding: 6px 12px;
            border: 1px solid #ddd; border-radius: 999px;
            background: #fafafa; font-size: .85rem;
            transition: border-color .12s, background .12s;
        }
        .options-group label:hover { background: #fff; border-color: #bbb; }
        .options-group input[type="radio"],
        .options-group input[type="checkbox"] { width: auto; accent-color: #cc0000; }

        @media (max-width: 640px) {
            body { padding: 0; }
            .page-container { border-radius: 0; box-shadow: none; border: none; padding: 1.5rem 1.2rem 2rem; }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-title">Geração de Documentos</div>

    <?php
    /**
     * ✅ ÚNICO FLUXO:
     * - Selecionou 1 template -> gera “individual” (um PDF)
     * - Selecionou 2+ templates -> gera “lote” (vários PDFs)
     *
     * A UI “individual” antiga foi removida para não duplicar telas/validações.
     * O card abaixo deve conter:
     * - seleção por checkbox
     * - botão "Carregar campos"
     * - botão "Gerar PDF(s)"
     * - inputs globais (1x) + exclusivos (por template)
     */
    include __DIR__ . '/documento_gerar_lote_card.php';
    ?>

    <a href="menu.php" class="btn-secondary" style="margin-top:1.2rem;">
        ← Voltar ao menu
    </a>
</div>

<script>
// ======================================================================
// ✅ Funções utilitárias (máscaras + render) para uso no card de LOTE
// Observação: não amarramos listeners em IDs da tela “individual” (removida)
// ======================================================================

// Campos em maiúsculas
const camposUpper = [
    'nome_cliente',
    'endereço_completo',
    'endereco_completo',
    'loja_retirada',
    'loja_entrega'
];

function onlyDigits(value) {
    return (value || '').toString().replace(/\D+/g, '');
}
function maskCpf(value) {
    let v = onlyDigits(value).slice(0, 11);
    if (v.length >= 10) {
        return v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    } else if (v.length >= 7) {
        return v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
    } else if (v.length >= 4) {
        return v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
    }
    return v;
}
function maskCnpj(value) {
    let v = onlyDigits(value).slice(0, 14);
    if (v.length >= 13) {
        return v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    } else if (v.length >= 9) {
        return v.replace(/(\d{2})(\d{3})(\d{3})(\d{1,4})/, '$1.$2.$3/$4');
    } else if (v.length >= 6) {
        return v.replace(/(\d{2})(\d{3})(\d{1,3})/, '$1.$2.$3');
    } else if (v.length >= 3) {
        return v.replace(/(\d{2})(\d{1,3})/, '$1.$2');
    }
    return v;
}


function maskCpfCnpj(value) {
    // Mantém apenas dígitos e decide a máscara conforme tamanho (CPF=11, CNPJ=14)
    const digits = String(value || '').replace(/\D+/g, '').slice(0, 14);
    if (digits.length <= 11) {
        return maskCpf(digits);
    }
    return maskCnpj(digits);
}
function maskTelefone(value) {
    let v = onlyDigits(value).slice(0, 11);
    if (v.length > 10) {
        return v.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3');
    } else if (v.length > 6) {
        return v.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
    } else if (v.length > 2) {
        return v.replace(/(\d{2})(\d{0,5})/, '($1) $2');
    } else if (v.length > 0) {
        return '(' + v;
    }
    return v;
}
function maskCep(value) {
    let v = onlyDigits(value).slice(0, 8);
    if (v.length > 5) {
        return v.replace(/(\d{5})(\d{0,3})/, '$1-$2');
    }
    return v;
}
function maskHora(value) {
    let v = onlyDigits(value).slice(0, 4);
    if (v.length > 2) {
        return v.replace(/(\d{2})(\d{0,2})/, '$1:$2');
    }
    return v;
}
function maskPlaca(value) {
    return (value || '').toString().toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 7);
}
function maskChassi(value) {
    return (value || '').toString().toUpperCase().replace(/\s+/g, '').slice(0, 25);
}
function maskInteiro(value) {
    let digits = onlyDigits(value);
    if (!digits) return '';
    digits = digits.replace(/^0+(?=\d)/, '');
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}
function maskDecimal(value) {
    // Aceita digitação com vírgula ou ponto, mas normaliza para pt-BR:
    // milhar com ponto e decimal com vírgula.
    let v = (value || '').toString();
    v = v.replace(/[^0-9,\.]/g, '');
    if (!v) return '';

    const lastComma = v.lastIndexOf(',');
    const lastDot   = v.lastIndexOf('.');
    const decPos = Math.max(lastComma, lastDot);

    let intPart = '';
    let decPart = '';

    if (decPos >= 0) {
        intPart = v.slice(0, decPos).replace(/[^0-9]/g, '');
        decPart = v.slice(decPos + 1).replace(/[^0-9]/g, '').slice(0, 2);
    } else {
        intPart = v.replace(/[^0-9]/g, '');
    }

    intPart = intPart.replace(/^0+(?=\d)/, '');
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    if (decPos >= 0) return intPart + ',' + decPart;
    return intPart;
}
function maskMoeda(value) {
    let digits = onlyDigits(value);
    if (digits.length === 0) return '';

    // moeda sempre com 2 casas
    if (digits.length === 1) digits = '0' + digits;

    let intPart = digits.slice(0, -2);
    let decPart = digits.slice(-2);

    intPart = intPart.replace(/^0+(?=\d)/, '');
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return 'R$ ' + intPart + ',' + decPart;
}

function maskPercentual(value) {
    let v = maskDecimal(value);
    if (!v) return '';
    v = v.replace(/%+$/g, '');
    return v + '%';
}

function applyMaskByType(input, tipo, nomeCampo) {
    if (!input) return;
    let v = input.value || '';

    switch (tipo) {
        case 'cpf': input.value = maskCpf(v); break;
        case 'cnpj': input.value = maskCnpj(v); break;
        case 'cpf_cnpj': input.value = maskCpfCnpj(v); break;
        case 'telefone': input.value = maskTelefone(v); break;
        case 'cep': input.value = maskCep(v); break;
        case 'hora': input.value = maskHora(v); break;
        case 'inteiro': input.value = maskInteiro(v); break;
        case 'decimal': input.value = maskDecimal(v); break;
        case 'percentual': input.value = maskPercentual(v); break;
        case 'moeda': input.value = maskMoeda(v); break;
        case 'placa': input.value = maskPlaca(v); break;
        case 'chassi': input.value = maskChassi(v); break;
        default: break;
    }

    if (camposUpper.includes(nomeCampo)) {
        input.value = (input.value || '').toUpperCase();
    }
}

function attachMasks(root) {
    const scope = root || document;
    const els = scope.querySelectorAll('input[data-tipo], textarea[data-tipo]');
    els.forEach(el => {
        const tipo = el.getAttribute('data-tipo') || '';
        const nomeCampo = el.getAttribute('data-nome-campo') || '';
        if (!tipo) return;

        if (['select','enum','radio','checkbox','booleano','data'].includes(tipo)) return;
        if (el.dataset.maskAttached === '1') return;
        el.dataset.maskAttached = '1';

        const handler = () => applyMaskByType(el, tipo, nomeCampo);
        el.addEventListener('input', handler);
        el.addEventListener('blur', handler);
        handler();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    attachMasks(document);
});


// ----------------------------------------------------------------------
// Render genérico de campos (para ser reutilizado no lote se você quiser)
// Obs: o card de lote pode ter sua própria renderização; aqui mantemos
// uma função pronta e reutilizável.
// ----------------------------------------------------------------------
function renderCampos(container, campos, namePrefix) {
    // namePrefix exemplo:
    //  - para exclusivo de template: 'exclusivos[12]'
    //  - para globais: 'globais'
    // Se não informar, cai no padrão antigo "campos[...]"
    namePrefix = namePrefix || 'campos';

    if (!container) return;

    if (!Array.isArray(campos) || campos.length === 0) {
        container.innerHTML = '<div class="message info">Nenhum campo configurado.</div>';
        return;
    }

    let html = '';
    campos.forEach(function (campo, index) {
        const inputName = namePrefix + '[' + campo.nome_campo + ']';
        const idBase    = (container.id ? container.id + '_' : 'campo_') + index;
        const tipo      = campo.tipo;
        const opcoes    = Array.isArray(campo.opcoes) ? campo.opcoes : [];

        html += '<div class="field-row">';
        html += '<label for="'+idBase+'">' + campo.rotulo + (campo.obrigatorio ? ' *' : '') + '</label>';

        if (tipo === 'booleano') {
            const grupoId = idBase;
            const opcs = opcoes.length ? opcoes : [
                { valor: '1', label: 'Sim' },
                { valor: '0', label: 'Não' }
            ];
            html += '<div class="options-group" id="'+grupoId+'" ' +
                    'data-tipo="booleano" ' +
                    'data-nome-campo="'+campo.nome_campo+'" ' +
                    (campo.obrigatorio ? 'data-obrigatorio="1"' : '') +
                    '>';
            opcs.forEach(function (opt, idx) {
                const optId = grupoId + '_bool_' + idx;
                html += '<label for="'+optId+'">' +
                        '<input type="radio" id="'+optId+'" ' +
                        'name="'+inputName+'" ' +
                        'value="'+encodeURIComponent(opt.valor)+'"> '+
                        (opt.label || opt.valor) +
                        '</label>';
            });
            html += '</div>';

        } else if (['select','enum','radio','checkbox'].includes(tipo) && opcoes.length > 0) {

            if (tipo === 'select' || tipo === 'enum') {
                html += '<select id="'+idBase+'" ' +
                        'data-tipo="'+tipo+'" ' +
                        'data-nome-campo="'+campo.nome_campo+'" ' +
                        (campo.obrigatorio ? 'data-obrigatorio="1" ' : '') +
                        'name="'+inputName+'">';
                html += '<option value="">Selecione...</option>';
                opcoes.forEach(function (opt) {
                    html += '<option value="'+encodeURIComponent(opt.valor)+'">'+
                            (opt.label || opt.valor)+'</option>';
                });
                html += '</select>';

            } else if (tipo === 'radio' || tipo === 'checkbox') {
                html += '<div class="options-group" id="'+idBase+'" ' +
                        'data-tipo="'+tipo+'" ' +
                        'data-nome-campo="'+campo.nome_campo+'" ' +
                        (campo.obrigatorio ? 'data-obrigatorio="1"' : '') +
                        '>';
                opcoes.forEach(function (opt, idx) {
                    const optId = idBase + '_opt_' + idx;
                    const inputType = (tipo === 'radio') ? 'radio' : 'checkbox';
                    const nameAttr  = (tipo === 'radio')
                        ? inputName
                        : inputName + '[]';

                    html += '<label for="'+optId+'">' +
                            '<input type="'+inputType+'" id="'+optId+'" ' +
                            'name="'+nameAttr+'" ' +
                            'value="'+encodeURIComponent(opt.valor)+'"> '+
                            (opt.label || opt.valor) +
                            '</label>';
                });
                html += '</div>';
            }

        } else if (tipo === 'checkbox' && opcoes.length === 0) {
            const optId = idBase + '_single';
            html += '<div class="options-group" id="'+idBase+'" ' +
                    'data-tipo="checkbox" ' +
                    'data-nome-campo="'+campo.nome_campo+'" ' +
                    (campo.obrigatorio ? 'data-obrigatorio="1"' : '') +
                    '>';
            html += '<label for="'+optId+'">' +
                    '<input type="checkbox" id="'+optId+'" ' +
                    'name="'+inputName+'" value="1"> Sim' +
                    '</label>';
            html += '</div>';

        } else {
            if (tipo === 'texto_longo' || tipo === 'observacao' || tipo === 'endereco') {
                html += '<textarea id="'+idBase+'" ' +
                        'data-tipo="'+tipo+'" ' +
                        'data-nome-campo="'+campo.nome_campo+'" ' +
                        (campo.obrigatorio ? 'data-obrigatorio="1"' : '') +
                        ' name="' + inputName + '"></textarea>';
            } else if (tipo === 'data') {
                html += '<input id="'+idBase+'" type="date" ' +
                        'data-tipo="'+tipo+'" ' +
                        'data-nome-campo="'+campo.nome_campo+'" ' +
                        (campo.obrigatorio ? 'data-obrigatorio="1"' : '') +
                        ' name="' + inputName + '">';
            } else {
                html += '<input id="'+idBase+'" type="text" ' +
                        'data-tipo="'+tipo+'" ' +
                        'data-nome-campo="'+campo.nome_campo+'" ' +
                        (campo.obrigatorio ? 'data-obrigatorio="1"' : '') +
                        ' name="' + inputName + '">';
            }
        }

        html += '</div>';
    });

    container.innerHTML = html;

    // aplica máscaras em inputs de texto
    campos.forEach(function (campo, index) {
        const id = (container.id ? container.id + '_' : 'campo_') + index;
        const el = document.getElementById(id);
        if (!el) return;

        const tipo = campo.tipo;
        const nomeCampo = campo.nome_campo;

        if (['select','enum','radio','checkbox','booleano','data'].includes(tipo)) return;

        el.addEventListener('input', function () {
            applyMaskByType(el, tipo, nomeCampo);
        });
        el.addEventListener('blur', function () {
            applyMaskByType(el, tipo, nomeCampo);
        });
    });
}
</script>
</body>
</html>