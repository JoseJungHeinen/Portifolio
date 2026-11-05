<?php
require_once __DIR__ . '/config_database.php';
session_start();
if (empty($_SESSION['logado'])) {
    exit('Acesso negado');
}

$tarifas = [
    [
        'categoria' => 'Comercial',
        'slug' => 'comercial',
        'cor' => '#2347c8',
        'veiculos' => [
            ['nome' => 'Mobi Like 1.0',                 'valor_2000' => 2490.00, 'valor_3000' => 2690.00, 'valor_5000' => 2890.00, 'km_extra' => 0.40],
            ['nome' => 'Argo Drive 1.0',               'valor_2000' => 2690.00, 'valor_3000' => 2890.00, 'valor_5000' => 3090.00, 'km_extra' => 0.40],
            ['nome' => 'Argo Trekking 1.3',            'valor_2000' => 2890.00, 'valor_3000' => 3190.00, 'valor_5000' => 3390.00, 'km_extra' => 0.60],
            ['nome' => 'Argo Drive 1.3 AT',            'valor_2000' => 2990.00, 'valor_3000' => 3290.00, 'valor_5000' => 3490.00, 'km_extra' => 0.60],
            ['nome' => 'Cronos Drive 1.0',             'valor_2000' => 2990.00, 'valor_3000' => 3290.00, 'valor_5000' => 3490.00, 'km_extra' => 0.40],
            ['nome' => 'Cronos Drive 1.3',             'valor_2000' => 3190.00, 'valor_3000' => 3390.00, 'valor_5000' => 3590.00, 'km_extra' => 0.60],
            ['nome' => 'Cronos Drive 1.3 AT',          'valor_2000' => 3290.00, 'valor_3000' => 3490.00, 'valor_5000' => 3690.00, 'km_extra' => 0.60],
            ['nome' => 'Polo Track',                   'valor_2000' => 2990.00, 'valor_3000' => 3190.00, 'valor_5000' => 3390.00, 'km_extra' => 0.40],
            ['nome' => 'Polo Sense',                   'valor_2000' => 3590.00, 'valor_3000' => 3790.00, 'valor_5000' => 3990.00, 'km_extra' => 0.60],
        ],
    ],
    [
        'categoria' => 'Utilitário',
        'slug' => 'utilitario',
        'cor' => '#ef6c00',
        'veiculos' => [
            ['nome' => 'Strada Endurance 1.3 CP',      'valor_2000' => 3290.00, 'valor_3000' => 3490.00, 'valor_5000' => 3690.00, 'km_extra' => 0.60],
            ['nome' => 'Strada Freedom 1.3 CD',        'valor_2000' => 3890.00, 'valor_3000' => 4190.00, 'valor_5000' => 4390.00, 'km_extra' => 0.60],
            ['nome' => 'Fiorino Endurance 1.4',        'valor_2000' => 3690.00, 'valor_3000' => 3990.00, 'valor_5000' => 4190.00, 'km_extra' => 0.60],
            ['nome' => 'Toro Endurance T270',          'valor_2000' => 5390.00, 'valor_3000' => 5590.00, 'valor_5000' => 5790.00, 'km_extra' => 0.60],
        ],
    ],
    [
        'categoria' => 'Executivo',
        'slug' => 'executivo',
        'cor' => '#263238',
        'veiculos' => [
            ['nome' => 'Pulse Turbo 200 AT',           'valor_2000' => 3690.00, 'valor_3000' => 3890.00, 'valor_5000' => 4190.00, 'km_extra' => 0.60],
            ['nome' => 'Pulse Audace Turbo 200 Híbrido AT','valor_2000' => 3890.00,'valor_3000' => 4090.00,'valor_5000' => 4390.00,'km_extra' => 0.60],
            ['nome' => 'Renegade T270 AT',             'valor_2000' => 3890.00, 'valor_3000' => 4090.00, 'valor_5000' => 4390.00, 'km_extra' => 0.60],
            ['nome' => 'Renegade Sport T270 AT',       'valor_2000' => 4190.00, 'valor_3000' => 4390.00, 'valor_5000' => 4690.00, 'km_extra' => 0.60],
            ['nome' => 'Renegade Longitude T270',      'valor_2000' => 4490.00, 'valor_3000' => 4690.00, 'valor_5000' => 4990.00, 'km_extra' => 0.60],
            ['nome' => 'Fastback Audace Híbrido',      'valor_2000' => 4490.00, 'valor_3000' => 4690.00, 'valor_5000' => 4990.00, 'km_extra' => 0.60],
            ['nome' => 'Compass Sport AT',             'valor_2000' => 5790.00, 'valor_3000' => 5990.00, 'valor_5000' => 6290.00, 'km_extra' => 0.60],
            ['nome' => 'Compass Longitude T270',       'valor_2000' => 6590.00, 'valor_3000' => 6790.00, 'valor_5000' => 7090.00, 'km_extra' => 0.60],
            ['nome' => 'Commander Limited T270',       'valor_2000' => 6990.00, 'valor_3000' => 7290.00, 'valor_5000' => 7590.00, 'km_extra' => 0.60],
            ['nome' => 'Commander Overland TD380 4x4', 'valor_2000' => 8590.00, 'valor_3000' => 8990.00, 'valor_5000' => 9290.00, 'km_extra' => 0.60],
        ],
    ],
];

$coberturas = [
    ['valor_mensal' => 39.90, 'limite' => 200000, 'descricao' => 'danos materiais e corporais a terceiros'],
    ['valor_mensal' => 59.90, 'limite' => 400000, 'descricao' => 'danos materiais e corporais a terceiros'],
];

$rastreador = [
    'valor_mensal' => 79.90,
    'descricao' => 'controle de velocidade, localização e cercamento eletrônico com relatórios automáticos',
];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Orçamento de locação</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        :root {
            --primary: #2347c8;
            --secondary: #ffd200;
            --accent: #00a1ff;
            --bg: #f5f7fb;
            --text: #222;
            --radius: 0.75rem;
            --shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        *{box-sizing:border-box;}
        body{
            margin:0;
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            background:var(--bg);
            color:var(--text);
        }
        .tarifario{
            max-width:1200px;
            margin:2rem auto;
            padding:1.5rem;
        }
        .tarifario-header{
            display:flex;
            justify-content:space-between;
            align-items:flex-end;
            gap:1rem;
            margin-bottom:1.5rem;
        }
        .tarifario-header h1{
            font-size:1.8rem;
            margin:0;
        }
        .tarifario-header p{
            margin:0.25rem 0 0;
            color:#555;
        }
        .tarifario-highlight{
            padding:0.5rem 1rem;
            background:var(--primary);
            color:#fff;
            border-radius:999px;
            font-size:0.85rem;
            white-space:nowrap;
            border:none;
            cursor:pointer;
        }
        .tarifario-filtros{
            display:flex;
            flex-wrap:wrap;
            gap:1rem;
            margin-bottom:1.5rem;
        }
        .filtro-item{
            display:flex;
            flex-direction:column;
            min-width:160px;
        }
        .filtro-item label{
            font-size:0.8rem;
            color:#666;
            margin-bottom:0.25rem;
        }
        .filtro-item select{
            padding:0.4rem 0.6rem;
            border-radius:0.5rem;
            border:1px solid #d0d5dd;
            background:#fff;
        }
        .tarifario-categoria{
            margin-bottom:2rem;
            background:#fff;
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            overflow:hidden;
        }
        .categoria-header{
            padding:0.75rem 1rem;
            color:#fff;
            font-weight:600;
            text-transform:uppercase;
            letter-spacing:0.05em;
            font-size:0.85rem;
        }
        .tarifario-table-wrapper{
            width:100%;
            overflow-x:auto;
        }
        table{
            width:100%;
            border-collapse:collapse;
            min-width:680px;
        }
        th,td{
            padding:0.75rem 1rem;
            text-align:left;
            font-size:0.9rem;
            white-space:nowrap;
        }
        thead{
            background:#ffe666;
        }
        tbody tr:nth-child(even){
            background:#fafbff;
        }
        tbody tr:hover{
            background:#eaf3ff;
        }
        .tarifario-info-grid{
            display:grid;
            grid-template-columns:minmax(0,2fr) minmax(0,1.5fr);
            gap:1.5rem;
            margin-top:2rem;
        }
        .tarifario-card{
            background:#fff;
            border-radius:var(--radius);
            padding:1.25rem 1.5rem;
            box-shadow:var(--shadow);
        }
        .tarifario-card h3{
            margin-top:0;
            margin-bottom:0.75rem;
        }
        .tarifario-card ul{
            padding-left:1.1rem;
            margin:0;
        }
        .tarifario-card li{
            margin-bottom:0.5rem;
        }
        .card-coberturas{
            border-left:4px solid var(--secondary);
            background:#fff9d6;
        }
        .card-rastreador{
            border-left:4px solid var(--accent);
            background:#e6f5ff;
        }

        #rodape-print {
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:6px 16px;
            border-top:2px solid #00a1ff;
            margin-top:10px;
            font-size:10px;
        }

            .rodape-contatos span {
                display:block;
                font-size:10px;
            }

        .rodape-logo {
            text-align:right;
            font-size:10px;
        }

        .rodape-logo-img {
            max-height:32px;
            display:block;
            margin-left:auto;
        }

        .rodape-slogan {
            margin-top:2px;
        }

        @media (max-width:768px){
            .tarifario-header{
                flex-direction:column;
                align-items:flex-start;
            }
            .tarifario-highlight{
                align-self:flex-start;
            }
            .tarifario-info-grid{
                grid-template-columns:1fr;
            }
        }

        /* ===== layout especial somente para impressão / PDF ===== */
        @media print {
            .tarifario-highlight,
            .tarifario-filtros,
            button,
            select {
                display:none !important;
            }

            body {
                background:#ffffff !important;
            }

            .tarifario {
                max-width:100%;
                margin:0;
                padding:0;
                box-shadow:none;
            }

            .tarifario-header {
                display:block;
                text-align:center;
                margin:0 0 4px 0;
                padding:4px 12px;
            }

            .tarifario-header h1 {
                font-size:16px;
                font-weight:700;
                background:#d4d4d4;
                padding:4px 0;
                margin:0;
                text-transform:uppercase;
            }

            .tarifario-header p {
                font-size:9px;
                margin:2px 0 0;
            }

            #tarifario-print-area {
                padding:2px 8px 8px;
            }

            .tarifario-categoria {
                box-shadow:none;
                border-radius:0;
                margin:0 0 15px 0; /* Aumentei o espaço entre categorias */
                page-break-inside:avoid;
            }

            /* CABEÇALHOS MAIORES NA IMPRESSÃO */
            .categoria-header {
                padding:6px 10px; /* Aumentei o padding */
                font-size:11px; /* Aumentei o tamanho da fonte */
                text-transform:uppercase;
                font-weight:700;
                letter-spacing:0.08em;
                margin-bottom:5px;
            }

            .categoria-header h2 {
                margin:0;
                font-size:11px; /* Aumentei o tamanho da fonte */
                text-transform:uppercase;
            }

            table {
                border-collapse:collapse;
                width:100%;
                min-width:0;
                font-size:8px;
            }

            thead {
                background:#ffe666;
            }

            th, td {
                padding:2px 3px;
                border-bottom:0.4px solid #ddd;
                white-space:nowrap;
            }

            .tarifario-info-grid {
                display:grid;
                grid-template-columns:2fr 1.4fr;
                gap:4px;
                margin-top:6px;
            }

            .tarifario-card {
                box-shadow:none;
                border-radius:0;
                padding:4px 6px;
                font-size:8px;
            }

            .tarifario-card h3 {
                font-size:9px;
                margin-bottom:3px;
                text-transform:uppercase;
            }

            .card-coberturas {
                background:#ffe666;
                border-left:none;
            }

            .card-rastreador {
                background:#00a1ff;
                color:#ffffff;
                border-left:none;
            }

            .card-rastreador strong {
                color:#ffffff;
            }

            /* rodapé mais compacto e logo maior */
            #rodape-print {
                display:flex;
                align-items:center;
                justify-content:space-between;
                padding:4px 8px;
                border-top:2px solid #00a1ff;
                margin-top:6px;
                font-size:8px;
            }

            .rodape-contatos span {
                display:block;
            }

            .rodape-logo-bloco {
                display:flex;
                flex-direction:column;
                align-items:center;
            }

            .rodape-logo-img {
                max-height:60px;
                display:block;
            }

            .rodape-logo-frase {
                margin-top:2px;
                font-size:12px;
                font-weight:bold;
                text-transform:uppercase;
            }
        }

    </style>
</head>
<body>

<section class="tarifario">

    <header class="tarifario-header">
        <div>
            <h1>TARIFÁRIO PARA LOCAÇÃO</h1>
            <p>Para contratos de 12 ou 24 meses.</p>
        </div>
        <button type="button" onclick="window.print()" class="tarifario-highlight">
             PDF
        </button>
    </header>

    <!-- filtros apenas na tela -->
    <div class="tarifario-filtros">
        <div class="filtro-item">
            <label for="filtro-categoria">Categoria</label>
            <select id="filtro-categoria">
                <option value="">Todas</option>
                <?php foreach ($tarifas as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['slug']); ?>">
                        <?php echo htmlspecialchars($cat['categoria']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filtro-item">
            <label for="filtro-km">Pacote de km</label>
            <select id="filtro-km">
                <option value="">2.000 / 3.000 / 5.000 km</option>
                <option value="2000">2.000 km</option>
                <option value="3000">3.000 km</option>
                <option value="5000">5.000 km</option>
            </select>
        </div>
    </div>

    <!-- tudo que deve aparecer no PDF -->
    <div id="tarifario-print-area">
        <?php foreach ($tarifas as $categoria): ?>
            <section class="tarifario-categoria" data-categoria="<?php echo htmlspecialchars($categoria['slug']); ?>">
                <header class="categoria-header" style="background: <?php echo htmlspecialchars($categoria['cor']); ?>">
                    <h2><?php echo htmlspecialchars($categoria['categoria']); ?></h2>
                </header>
                <div class="tarifario-table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>Veículo de frota</th>
                            <th data-km="2000">2.000 km</th>
                            <th data-km="3000">3.000 km</th>
                            <th data-km="5000">5.000 km</th>
                            <th>Km extra</th>
                            <th>Cob. 200k</th>
                            <th>Cob. 400k</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($categoria['veiculos'] as $veiculo): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($veiculo['nome']); ?></td>
                                <td data-km="2000">R$ <?php echo number_format($veiculo['valor_2000'], 2, ',', '.'); ?></td>
                                <td data-km="3000">R$ <?php echo number_format($veiculo['valor_3000'], 2, ',', '.'); ?></td>
                                <td data-km="5000">R$ <?php echo number_format($veiculo['valor_5000'], 2, ',', '.'); ?></td>
                                <td>R$ <?php echo number_format($veiculo['km_extra'], 2, ',', '.'); ?></td>
                                <td>R$ 39,90</td>
                                <td>R$ 59,90</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="tarifario-info-grid">
            <section class="tarifario-card card-coberturas">
                <h3>Defina suas coberturas</h3>
                <ul>
                    <?php foreach ($coberturas as $cob): ?>
                        <li>
                            <strong>R$ <?php echo number_format($cob['valor_mensal'], 2, ',', '.'); ?></strong>
                            mensais: cobertura de
                            R$ <?php echo number_format($cob['limite'], 2, ',', '.'); ?>
                            para <?php echo htmlspecialchars($cob['descricao']); ?>.
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="tarifario-card card-rastreador">
                <h3>Instalação de rastreador</h3>
                <p>
                    Por adicionais
                    <strong>R$ <?php echo number_format($rastreador['valor_mensal'], 2, ',', '.'); ?></strong>
                    mensais, você terá <?php echo htmlspecialchars($rastreador['descricao']); ?>.
                </p>
            </section>
        </div>

        <!-- rodapé somente na impressão -->
        <footer id="rodape-print">
    <div class="rodape-contatos">
        <span>✉ comercial.locadora@exemplo.com</span>
        <span>☎ (51) 99677.3859</span>
    </div>

    <div class="rodape-logo-bloco">
        <img src="/Veiculos/img/logo.svg"
             alt="Exemplo aluguel de frotas"
             class="rodape-logo-img">
        <div class="rodape-logo-frase">
            <strong>aluguel de frotas</strong>
        </div>
    </div>
</footer>


</section>

<script>
(function () {
    const selectCategoria = document.getElementById('filtro-categoria');
    const selectKm = document.getElementById('filtro-km');
    const blocosCategoria = document.querySelectorAll('.tarifario-categoria');

    function filtrarCategoria() {
        const categoriaSelecionada = selectCategoria.value;
        blocosCategoria.forEach(function (bloco) {
            const slug = bloco.getAttribute('data-categoria');
            bloco.style.display = !categoriaSelecionada || categoriaSelecionada === slug ? '' : 'none';
        });
    }

    function filtrarKm() {
        const kmSelecionado = selectKm.value;
        const colunas = document.querySelectorAll('th[data-km], td[data-km]');
        colunas.forEach(function (col) {
            const km = col.getAttribute('data-km');
            col.style.display = !kmSelecionado || kmSelecionado === km ? '' : 'none';
        });
    }

    if (selectCategoria) selectCategoria.addEventListener('change', filtrarCategoria);
    if (selectKm) selectKm.addEventListener('change', filtrarKm);
})();
</script>

</body>
</html>
