<?php
require_once __DIR__ . '/config_database.php';
require_once __DIR__ . '/config_versao.php';
session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

/* busca TODOS os links ativos */
$sql = "SELECT titulo, url, grupo, descricao
        FROM links_config
        WHERE ativo = 1
        ORDER BY grupo ASC, ordem ASC, id ASC";

$res = $mysqli->query($sql);

$grupos = [];
while ($row = $res->fetch_assoc()) {
    $grupos[$row['grupo']][] = $row;
}

/* nomes dos grupos (como já está na tela) */
$nomesGrupos = [
    'cadastro'      => 'Cadastro',
    'financeiro'    => 'Financeiro / Fiscal',
    'interno'       => 'Interno',
    'venda_veiculo' => 'Venda Veículo',
    'monte_seu_carro' => 'Monte o seu Carro',
];

/* normaliza título: minúsculo, sem acento, sem espaços extras */
function normaliza_titulo($str) {
    $str = mb_strtolower($str, 'UTF-8');
    $str = strtr($str, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a',
        'é'=>'e','ê'=>'e',
        'í'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o',
        'ú'=>'u',
        'ç'=>'c',
    ]);
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str);
}

/* MAPEAMENTO: título normalizado -> arquivo da logo em /Veiculos/img/logo_links/ */
$logosNormalizados = [
    // CADASTRO
    normaliza_titulo('Consulta CPF')                     => '/Veiculos/img/logo_links/consulta_cpf.png',
    normaliza_titulo('Consulta CNPJ')                    => '/Veiculos/img/logo_links/consulta_cnpj.png',
    normaliza_titulo('Sintegra')                         => '/Veiculos/img/logo_links/sintegra.png',

    // FINANCEIRO / FISCAL
    normaliza_titulo('Tabela FIPE')                      => '/Veiculos/img/logo_links/tabela_fipe.png',
    normaliza_titulo('Gov.br (DFE)')                     => '/Veiculos/img/logo_links/gov(DF-e).png',

    // INTERNO
    normaliza_titulo('APOLLO')                           => '/Veiculos/img/logo_links/apollo.jpg',
    normaliza_titulo('E-mail')                           => '/Veiculos/img/logo_links/e-mail.png',
    normaliza_titulo('Dealer')                           => '/Veiculos/img/logo_links/dealler.png',
    normaliza_titulo('GLPI')                             => '/Veiculos/img/logo_links/glpi.png',
    normaliza_titulo('Exemplo')                     => '/Veiculos/img/logo.svg',

    // VENDA VEÍCULO
    normaliza_titulo('Venda Direta O que está Valendo')  => '/Veiculos/img/logo_links/VD.png',
    normaliza_titulo('Impressão ICMS')                   => '/Veiculos/img/logo_links/impressao.png',
    normaliza_titulo('Detran')                           => '/Veiculos/img/logo_links/Detran.png',
    normaliza_titulo('Auto Avaliar')                     => '/Veiculos/img/logo_links/autoavaliar.png',
    normaliza_titulo('Placa Fipe')                       => '/Veiculos/img/logo_links/placa_fipe.png',
    normaliza_titulo('Tabela Fipe')                      => '/Veiculos/img/logo_links/tabela_fipe.png',
    normaliza_titulo('Leilão')                                =>'/Veiculos/img/logo_bancos/leilao.png',
    // Monte seu carro
    normaliza_titulo('Monte seu Fiat')       => '/Veiculos/img/logo_bancos/fiat.jpg',
    normaliza_titulo('Monte o seu Jeep')     => '/Veiculos/img/logo_bancos/jeep.png',
    normaliza_titulo('Monte o seu RAM')=> '/Veiculos/img/logo_bancos/ram.png',

];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Links internos</title>
<style>
body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #f3f4f6;
    margin: 0;
    padding: 20px 10px 32px;
}

.links-wrapper {
    max-width: 1100px;
    margin: 0 auto;
}

.links-header {
    margin-bottom: 1.6rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e5e7eb;
}

.links-header h2 {
    font-size: 1.4rem;
    font-weight: 700;
    color: #111;
    margin: 0 0 .25rem 0;
    letter-spacing: -.01em;
}

.links-header p {
    font-size: .88rem;
    color: #888;
    margin: 0;
}

/* seção por grupo */
.links-grupo {
    margin-bottom: 2rem;
}

.links-grupo-titulo {
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #cc0000;
    margin: 0 0 .75rem 0;
    padding-bottom: .4rem;
    border-bottom: 1px solid #f0f0f0;
}

/* grid de cards */
.links-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
}

/* card individual */
.link-card {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 14px;
    padding: 18px 14px 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 8px;
    transition: box-shadow .2s, transform .2s, border-color .2s;
    cursor: pointer;
}

.link-card:hover {
    box-shadow: 0 6px 24px rgba(0,0,0,0.10);
    transform: translateY(-2px);
    border-color: #d0d0d0;
}

.link-card-icon {
    width: 52px;
    height: 52px;
    border-radius: 12px;
    background: #f5f5f5;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}

.link-card-icon img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 5px;
}

.link-card-fallback {
    font-size: 1rem;
    font-weight: 800;
    color: #cc0000;
}

.link-card-title {
    font-size: .85rem;
    font-weight: 700;
    color: #1a1a1a;
    line-height: 1.2;
}

.link-card-desc {
    font-size: .75rem;
    color: #888;
    line-height: 1.35;
    flex: 1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.link-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    width: 100%;
    padding: 7px 10px;
    border-radius: 999px;
    background: #cc0000;
    color: #fff;
    font-size: .78rem;
    font-weight: 700;
    text-decoration: none;
    letter-spacing: .02em;
    transition: background .15s;
    margin-top: auto;
}

.link-btn:hover {
    background: #a80000;
}

.links-dica {
    margin-top: 1.2rem;
    font-size: .78rem;
    color: #bbb;
}

.app-version-label {
    position: fixed;
    bottom: 4px;
    right: 8px;
    font-size: 10px;
    color: #888;
    opacity: 0.6;
    pointer-events: none;
    z-index: 9999;
}
</style>
</head>
<body>
<div class="links-wrapper">
    <div class="links-header">
        <h2>Links internos – acesso rápido</h2>
        <p>Atalhos para sistemas usados na venda, cadastro e rotinas internas.</p>
    </div>

    <?php
    $gruposVisiveis = ['cadastro', 'interno', 'venda_veiculo', 'monte_seu_carro'];
    foreach ($gruposVisiveis as $grupo):
        if (empty($grupos[$grupo])) continue;
        $itens   = $grupos[$grupo];
        $tituloG = $nomesGrupos[$grupo] ?? ucfirst($grupo);
    ?>
        <div class="links-grupo">
            <div class="links-grupo-titulo"><?= htmlspecialchars($tituloG, ENT_QUOTES, 'UTF-8') ?></div>

            <div class="links-grid">
                <?php foreach ($itens as $lk):
                    $titulo     = $lk['titulo'];
                    $desc       = $lk['descricao'] ?: 'Acesso ao sistema.';
                    $tituloNorm = normaliza_titulo($titulo);
                    $logoLink   = $logosNormalizados[$tituloNorm] ?? '';
                ?>
                    <div class="link-card"
                         onclick="window.open('<?= htmlspecialchars($lk['url'], ENT_QUOTES, 'UTF-8') ?>', '_blank')">

                        <div class="link-card-icon">
                            <?php if ($logoLink): ?>
                                <img src="<?= htmlspecialchars($logoLink, ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?>">
                            <?php else: ?>
                                <span class="link-card-fallback">
                                    <?= htmlspecialchars(mb_substr($titulo, 0, 2, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="link-card-title"><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="link-card-desc"><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></div>

                        <a class="link-btn"
                           href="<?= htmlspecialchars($lk['url'], ENT_QUOTES, 'UTF-8') ?>"
                           target="_blank"
                           onclick="event.stopPropagation()">
                            <svg width="11" height="11" viewBox="0 0 12 12" fill="none">
                                <path d="M1 11L11 1M11 1H4M11 1V8" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Abrir link
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="links-dica">
        💡 Dica: deixe esta aba aberta enquanto utiliza os sistemas internos.
    </div>
</div>

<div class="app-version-label">
    <?php echo VERSAO_LINKS_UTEIS_SERVIDOR; ?>
</div>

</body>
</html>