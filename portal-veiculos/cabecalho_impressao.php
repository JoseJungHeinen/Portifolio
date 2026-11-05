<?php
// cabecalho_impressao.php
// Espera que as variáveis abaixo já existam:
// $titulo_orcamento, $numero_orcamento, $data_emissao
// $vend_nome, $vend_tel, $loja_nome, $loja_cnpj, $loja_telefone
?>
<header>
    <div class="orc-header-top">
        <div class="orc-logo-bloco">
            <img src="img/logo.svg" alt="Exemplo">
        </div>

        <div class="orc-header-center">
            <br>    </br>
            <div class="orc-header-title">
                <?= htmlspecialchars($titulo_orcamento, ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <div class="orc-header-sub">
                Nº <?= htmlspecialchars($numero_orcamento, ENT_QUOTES, 'UTF-8'); ?>
                | Data: <?= htmlspecialchars($data_emissao, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </div>
    </div>

    <div class="orc-header-line"></div>

    <div class="orc-header-bottom">
        <div class="orc-header-left">
            Vendedor: <?= htmlspecialchars($vend_nome ?: '', ENT_QUOTES, 'UTF-8'); ?><br>
            Telefone: <?= htmlspecialchars($vend_tel, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div class="orc-header-right">
            <?= htmlspecialchars($loja_nome, ENT_QUOTES, 'UTF-8'); ?><br>
            
            Telefone: <?= htmlspecialchars($loja_telefone ?? '', ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
</header>
<style>
/* CABEÇALHO COMPARTILHADO */
.orc-header-top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:32px;
    margin-bottom:8px;
}

.orc-logo-bloco{
    min-width:20px;
    font-size:8pt;
    line-height:1.5;
}

.orc-logo-bloco img{
    width:100px;
    height:auto;
    display:block;
    margin-bottom:8px;
}

.orc-header-center{
    flex:1;
    text-align:center;
    font-family:Arial,Helvetica,sans-serif;
    margin-left:-100px; /* ajuste se quiser mais centralizado */
}

.orc-header-title{
    font-size:18pt;
    font-weight:bold;
    margin-bottom:6px;
}

.orc-header-sub{
    font-size:10pt;
    margin-top:4px;
    margin-bottom:4px;
}

.orc-header-line{
    height:1px;
    background:#cccccc;
    margin:10px 0 8px 0;
    width:100%;
}

.orc-header-bottom{
    display:flex;
    justify-content:space-between;
    font-size:8pt;
    width:100%;
    margin-top:4px;
}

.orc-header-left,
.orc-header-right{
    line-height:1.5;
    font-size: 15px;
}
</style>