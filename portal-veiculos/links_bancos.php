<?php
session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

/* função para normalizar nome do banco (minúsculo, sem acento) */
function normaliza_nome($str) {
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

$bancos = [
    [
        'nome' => 'Itaú',
        'url'  => 'https://www.credlineitau.com.br/',
        'cor'  => '#ffffff',
        'desc' => 'Internet banking e serviços gerais.',
    ],
    [
        'nome' => 'Santander',
        'url'  => 'https://brpioneer.accenture.com/originacao-auto/login',
        'cor'  => '#ffffff',
        'desc' => 'Financiamento Santander Pioneer.',
    ],
    [
        'nome' => 'FIAT financiamentos',
        'url'  => 'https://www.fiat.com.br/financiamentos.html',
        'cor'  => '#ffffff',
        'desc' => 'Financiamento de veículos FIAT.',
    ],
    [
        'nome' => 'JEEP financiamentos',
        'url'  => 'https://www.jeep.com.br/servicos-financeiros/financiamentos.html',
        'cor'  => '#ffffff',
        'desc' => 'Financiamentos para veículos Jeep.',
    ],
    [
        'nome' => 'PSA',
        'url'  => 'https://financeira-da-marca.exemplo.com/login',
        'cor'  => '#ffffff',
        'desc' => 'Financiamentos Fabricante / PSA.',
    ],
    [
        'nome' => 'Sicredi',
        'url'  => 'https://auth-em.sicredi.io/auth/realms/externaluser-employee/protocol/openid-connect/auth?response_type=code&client_id=plataforma-parceiros&state=S0hveDlxZlNEYTFveG9hM1BPbEhnS0VUeEtHVWxJZml6M3ZFTE5haTVPMHRU&redirect_uri=https%3A%2F%2Fplataforma-parceiros.app.sicredi.com.br%2Fferramenta-credito%2F%23%2F&scope=openid%20application%3Aplataforma_parceiros&code_challenge=VlscJ7PiG3I2CQZ9awc_smeHm-N7YBUoE678WlnVtlo&code_challenge_method=S256&nonce=S0hveDlxZlNEYTFveG9hM1BPbEhnS0VUeEtHVWxJZml6M3ZFTE5haTVPMHRU',
        'cor'  => '#ffffff',
        'desc' => 'Crédito e financiamento Sicredi.',
    ],
    [
        'nome' => 'Bradesco',
        'url'  => 'https://wwwsn.bradescofinanciamentos.com.br/finilojmobile/#/login-beta',
        'cor'  => '#ffffff',
        'desc' => 'Financiamento Bradesco.',
    ],
    [
        'nome' => 'VW Financiamento',
        'url'  => 'https://www.vwfs.com.br/Automoveis/Financiamento.html',
        'cor'  => '#ffffff',
        'desc' => 'Financiamentos Volkswagen.',
    ],
    [
        'nome' => 'Banco BV',
        'url'  => 'https://www.bv.com.br/simulador/',
        'cor'  => '#ffffff',
        'desc' => 'Simulador e financiamento BV.',
    ],
    [
        'nome' => 'Safra Financiamento',
        'url'  => 'https://www.safrafinanceira.com.br/',
        'cor'  => '#ffffff',
        'desc' => 'Financiamentos Safra Financeira.',
    ],
];

/* mapa nome normalizado -> logo em /Veiculos/img/logo_bancos/ */
$logosBancos = [
    normaliza_nome('Itaú')              => '/Veiculos/img/logo_bancos/itau.png',
    normaliza_nome('Santander')        => '/Veiculos/img/logo_bancos/santander.png',
    normaliza_nome('FIAT financiamentos') => '/Veiculos/img/logo_bancos/fiat.jpg',
    normaliza_nome('JEEP financiamentos') => '/Veiculos/img/logo_bancos/jeep.png',
    normaliza_nome('PSA')              => '/Veiculos/img/logo_bancos/Fabricante.png',
    normaliza_nome('Sicredi')          => '/Veiculos/img/logo_bancos/sicredi.png',
    normaliza_nome('Bradesco')         => '/Veiculos/img/logo_bancos/bradesco.jpg',
    normaliza_nome('VW Financiamento') => '/Veiculos/img/logo_bancos/vw.jpg',
    normaliza_nome('Banco BV')         => '/Veiculos/img/logo_bancos/banco_bv.png',
    normaliza_nome('Safra Financiamento') => '/Veiculos/img/logo_bancos/safra.png',
];
?>

<style>
.bancos-wrap {
    max-width: 1100px;
    margin: 0 auto;
    font-family: 'Segoe UI', system-ui, sans-serif;
}

.bancos-header {
    margin-bottom: 1.6rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f0f0f0;
}

.bancos-header h2 {
    font-size: 1.4rem;
    font-weight: 700;
    color: #111;
    margin: 0 0 .25rem 0;
    letter-spacing: -.01em;
}

.bancos-header p {
    font-size: .88rem;
    color: #888;
    margin: 0;
}

.bancos-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
    gap: 12px;
}

.banco-card {
    background: #fff;
    border: 1px solid #ebebeb;
    border-radius: 14px;
    padding: 18px 14px 14px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 10px;
    transition: box-shadow .2s, transform .2s, border-color .2s;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
}

.banco-card:hover {
    box-shadow: 0 6px 24px rgba(0,0,0,0.10);
    transform: translateY(-2px);
    border-color: #d0d0d0;
}

.banco-logo-wrap {
    width: 60px;
    height: 60px;
    border-radius: 14px;
    background: #f5f5f5;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
}

.banco-logo-wrap img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 6px;
}

.banco-logo-fallback {
    font-size: 1.2rem;
    font-weight: 800;
    color: #cc0000;
}

.banco-nome {
    font-size: .88rem;
    font-weight: 700;
    color: #1a1a1a;
    line-height: 1.2;
}

.banco-desc {
    font-size: .77rem;
    color: #888;
    line-height: 1.35;
    flex: 1;
}

.banco-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    width: 100%;
    padding: 7px 10px;
    border-radius: 999px;
    background: #cc0000;
    color: #fff;
    font-size: .8rem;
    font-weight: 700;
    text-decoration: none;
    letter-spacing: .02em;
    transition: background .15s;
    margin-top: auto;
}

.banco-btn:hover {
    background: #a80000;
}

.banco-btn svg {
    flex-shrink: 0;
}

.bancos-dica {
    margin-top: 1.2rem;
    font-size: .78rem;
    color: #bbb;
}
</style>

<div class="bancos-wrap">
    <div class="bancos-header">
        <h2>Bancos – acesso rápido</h2>
        <p>Links úteis para acessar financiamentos e internet banking na hora da venda.</p>
    </div>

    <div class="bancos-grid">
        <?php foreach ($bancos as $b):
            $nomeNorm = normaliza_nome($b['nome']);
            $logo     = $logosBancos[$nomeNorm] ?? '';
        ?>
            <div class="banco-card" onclick="window.open('<?= htmlspecialchars($b['url'], ENT_QUOTES, 'UTF-8') ?>', '_blank')">
                <div class="banco-logo-wrap">
                    <?php if ($logo): ?>
                        <img src="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($b['nome'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <span class="banco-logo-fallback">
                            <?= htmlspecialchars(mb_substr($b['nome'], 0, 2, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="banco-nome"><?= htmlspecialchars($b['nome'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="banco-desc"><?= htmlspecialchars($b['desc'], ENT_QUOTES, 'UTF-8') ?></div>

                <a class="banco-btn"
                   href="<?= htmlspecialchars($b['url'], ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank"
                   onclick="event.stopPropagation()">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none">
                        <path d="M1 11L11 1M11 1H4M11 1V8" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Abrir site
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="bancos-dica">
        💡 Dica: deixe esta aba aberta enquanto faz a simulação de financiamento ou cartão.
    </div>
</div>