<?php
// @versao Fat_09 V_01_30/09/2026
declare(strict_types=1);

/**
 * Carga das CORES dos modelos (Listas › Modelos de Veículos), lidas das fichas técnicas dos PDFs
 * "Ram - Linha Produto Set-26.pdf" e "Linha de Produto Nacional Agosto 26.pdf".
 * Chave = MVS (cores do ano-modelo que está no cadastro). Formato: "CÓDIGO - Nome".
 *
 * Uso: php cli/seed_faturamento_cores.php
 * Idempotente: só preenche modelo que AINDA NÃO tem cores — nunca sobrescreve o que foi editado.
 */

require_once __DIR__ . '/../config/constants.php';
require_once CONFIG . '/database.php';

$pdo = Database::getInstance();

$cores = [
    '2262S02' => ['176 - Vermelho Colorado', '345 - Azul Jazz', '847 - Granite Crystal', '097 - Prata Billet', '852 - Preto Carbon', '742 - Branco Polar', '503 - Cinza Sting', '633 - Maximum Steel + Tet'],
    '2262T02' => ['176 - Vermelho Colorado', '345 - Azul Jazz', '852 - Preto Carbon', '742 - Branco Polar', '503 - Cinza Sting'],
    '2262WW2' => ['176 - Vermelho Colorado', '345 - Azul Jazz', '847 - Granite Crystal', '097 - Prata Billet', '852 - Preto Carbon', '742 - Branco Polar', '503 - Cinza Sting'],
    '2263PU2' => ['176 - Vermelho Colorado', '847 - Granite Crystal', '097 - Prata Billet', '852 - Preto Carbon', '296 - Branco Ambiente'],
    '2263RU2' => ['176 - Vermelho Colorado', '847 - Granite Crystal', '097 - Prata Billet', '852 - Preto Carbon', '296 - Branco Ambiente'],
    '2263SW2' => ['176 - Vermelho Colorado', '345 - Azul Jazz', '847 - Granite Crystal', '097 - Prata Billet', '852 - Preto Carbon', '742 - Branco Polar', '503 - Cinza Sting'],
    '2651PF1' => ['249 - Branco Banchisa'],
    '281AJP1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '281AKP1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '281BKP1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '281BLP1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '281BLU1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '281BMY1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '281BNY1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '295GLF2' => ['549 - Branco Banchisa', '632 - Cinza Grafito'],
    '295GWF2' => ['549 - Branco Banchisa', '632 - Cinza Grafito'],
    '341AGW0' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '341ATW0' => ['806 - Preto Vulcano', '743 - Branco Banchisa com Teto Preto Vulcano', '663 - Vermelho Montecarlo com Teto Preto Vulcano', '175 - Cinza Silverstone com Teto Preto Vulcano', '832 - Prata Bari com Teto Preto Vulcano'],
    '358AFB1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '358AGB1' => ['806 - Preto Vulcano', '743 - Branco Banchisa com Teto Preto Vulcano', '663 - Vermelho Montecarlo com Teto Preto Vulcano', '175 - Cinza Silverstone com Teto Preto Vulcano', '832 - Prata Bari com Teto Preto Vulcano'],
    '358AGU1' => ['806 - Preto Vulcano', '743 - Branco Banchisa com Teto Preto Vulcano', '663 - Vermelho Montecarlo com Teto Preto Vulcano', '175 - Cinza Silverstone com Teto Preto Vulcano', '832 - Prata Bari com Teto Preto Vulcano'],
    '358ATS1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '359AFU1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '534 - Branco Alaska', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '359AFV1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '534 - Branco Alaska', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '359ATH1' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '534 - Branco Alaska', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '363ANN1' => ['540 - Cinza Strato', '806 - Preto Vulcano', '979 - Cinza Silverstone', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '363ANP1' => ['540 - Cinza Strato', '806 - Preto Vulcano', '979 - Cinza Silverstone', '249 - Branco Banchisa', '978 - Vermelho Montecarlo', '743 - Branco Banchisa com Teto Preto Vulcano', '663 - Vermelho Montecarlo com Teto Preto Vulcano', '175 - Cinza Silverstone com Teto Preto Vulcano', '832 - Prata Bari com Teto Preto Vulcano', '766 - Cinza Strato com Teto Preto Vulcano'],
    '363AP01' => ['540 - Cinza Strato', '806 - Preto Vulcano', '200 - Azul Amalfi', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo', '743 - Branco Banchisa com Teto Preto Vulcano', '663 - Vermelho Montecarlo com Teto Preto Vulcano', '782 - Azul Amalfi com Teto Preto Vulcano', '175 - Cinza Silverstone com Teto Preto Vulcano', '832 - Prata Bari com Teto Preto Vulcano', '766 - Cinza Strato com Teto Preto Vulcano'],
    '363ASZ1' => ['806 - Preto Vulcano', '743 - Branco Banchisa com Teto Preto Vulcano', '663 - Vermelho Montecarlo com Teto Preto Vulcano', '766 - Cinza Strato com Teto Preto Vulcano'],
    '376AJA1' => ['540 - Cinza Strato', '806 - Preto Vulcano'],
    '376AJD1' => ['540 - Cinza Strato', '806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '249 - Branco Banchisa'],
    '376AJK1' => ['540 - Cinza Strato', '806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '861 - Prata Stile', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '376AK01' => ['540 - Cinza Strato', '806 - Preto Vulcano', '200 - Azul Amalfi', '979 - Cinza Silverstone', '619 - Prata Bari', '861 - Prata Stile', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '376AK41' => ['540 - Cinza Strato', '806 - Preto Vulcano', '200 - Azul Amalfi', '979 - Cinza Silverstone', '619 - Prata Bari', '861 - Prata Stile', '249 - Branco Banchisa'],
    '376AL01' => ['806 - Preto Vulcano', '743 - Branco Banchisa com Teto Preto Vulcano', '663 - Vermelho Montecarlo com Teto Preto Vulcano', '782 - Azul Amalfi com Teto Preto Vulcano', '175 - Cinza Silverstone com Teto Preto Vulcano', '832 - Prata Bari com Teto Preto Vulcano', '045 - Prata Stile com Teto Preto Vulcano', '766 - Cinza Strato com Teto Preto Vulcano'],
    '376AL41' => ['806 - Preto Vulcano', '743 - Branco Banchisa com Teto Preto Vulcano', '782 - Azul Amalfi com Teto Preto Vulcano', '175 - Cinza Silverstone com Teto Preto Vulcano', '832 - Prata Bari com Teto Preto Vulcano', '045 - Prata Stile com Teto Preto Vulcano', '766 - Cinza Strato com Teto Preto Vulcano'],
    '376AM61' => ['806 - Preto Vulcano', '743 - Branco Banchisa com Teto Preto Vulcano', '175 - Cinza Silverstone com Teto Preto Vulcano', '832 - Prata Bari com Teto Preto Vulcano', '045 - Prata Stile com Teto Preto Vulcano', '766 - Cinza Strato com Teto Preto Vulcano'],
    '376AN61' => ['806 - Preto Vulcano', '743 - Branco Banchisa com Teto Preto Vulcano', '663 - Vermelho Montecarlo com Teto Preto Vulcano', '766 - Cinza Strato com Teto Preto Vulcano'],
    '50864Y4' => ['159 - Cinza Alluminium', '533 - Preto Carbon', '240 - Branco Banchisa'],
    '50865Y4' => ['159 - Cinza Alluminium', '533 - Preto Carbon', '240 - Branco Banchisa'],
    '529BGF0' => ['549 - Branco Banchisa', '000 - Extraserie'],
    '529GGF0' => ['000 - Extraserie', '549 - White'],
    '529GKF0' => ['632 - Preto Carbon', '549 - Branco Banchisa', '000 - Extraserie'],
    '529GMF0' => ['549 - Branco Banchisa', '632 - Preto'],
    '529GNF0' => ['549 - Branco Banchisa', '632 - Preto'],
    '5791C23' => ['619 - Prata Bari', '249 - Branco Banchisa', '978 - Vermelho Montecarlo'],
    '5791H33' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '534 - Branco Alaska', '978 - Vermelho Montecarlo'],
    '5791N33' => ['806 - Preto Vulcano', '979 - Cinza Silverstone', '619 - Prata Bari', '534 - Branco Alaska', '978 - Vermelho Montecarlo'],
    '539.2H1.1' => ['806 - Preto Absolut', '619 - Prata Knox', '979 - Cinza Graphite', '534 - Branco Glacier'],
    '539.2L1.1' => ['806 - Preto Absolut', '619 - Prata Knox', '979 - Cinza Graphite', '534 - Branco Glacier'],
    '539.2N1.1' => ['806 - Preto Absolut', '619 - Prata Knox', '979 - Cinza Graphite', '504 - Terra Sunrise', '534 - Branco Glacier', '860 - Azul Tempest'],
    '539.2X1.1' => ['806 - Preto Absolut', '619 - Prata Knox', '979 - Cinza Graphite', '504 - Terra Sunrise', '534 - Branco Glacier', '860 - Azul Tempest'],
    '570.2AH.T' => ['PAU - Granito Crystal', 'PCG - Azul Forged', 'PSE - Prata Zenith', 'PXJ - Preto Diamond', 'PW7 - Branco Bright'],
    '573.22H.T' => ['PAU - Granito Crystal', 'PCG - Azul Forged', 'PSE - Prata Zenith', 'PXJ - Preto Diamond', 'PW7 - Branco Bright'],
    '573.24K.T' => ['PAU - Granito Crystal', 'PCG - Azul Forged', 'PSE - Prata Zenith', 'PXJ - Preto Diamond', 'PW7 - Branco Bright'],
    '575.3DK.T' => ['PW7 - Branco Bright', 'PAU - Granito Crystal', 'PSE - Prata Zenith', 'PCG - Azul Forged', 'PXJ - Preto Diamond'],
    '575.3GK.T' => ['PW7 - Branco Bright', 'PAU - Granito Crystal', 'PSE - Prata Zenith', 'PCG - Azul Forged', 'PXJ - Preto Diamond'],
    '575.3MZ.T' => ['PW7 - Branco Bright', 'PAU - Granito Crystal', 'PSE - Prata Zenith', 'PCG - Azul Forged', 'PXJ - Preto Diamond'],
    '591.23F.0' => ['176 - Vermelho Flame', '503 - Sting Grey', '097 - Prata Billet', '345 - Azul Patriot', '660 - Maximum Steel', '852 - Preto Diamond', '325 - Azul Pacific + Teto Preto (disponível apenas com pack JBT Ignition Blue)', '742 - Branco Pérola'],
    '591.24R.0' => ['325 - Azul Pacific + Teto Preto'],
    '591.25F.0' => ['176 - Vermelho Flame', '503 - Sting Grey', '097 - Prata Billet', '345 - Azul Patriot', '660 - Maximum Steel', '852 - Preto Diamond', '742 - Branco Pérola'],
    '591.27R.0' => ['686 - Vermelho Flame + Teto Preto', '264 - Sting Grey + Teto Preto', '474 - Prata Billet + Teto Preto', '752 - Azul Patriot + Teto Preto', '633 - Maximum Steel + Teto Preto', '852 - Preto Diamond', '107 - Branco Pérola + Teto Preto'],
    '591.31F.0' => ['176 - Vermelho Flame', '503 - Sting Grey', '097 - Prata Billet', '345 - Azul Patriot', '852 - Preto Diamond', '742 - Branco Pérola'],
];

$busca = $pdo->prepare("SELECT id FROM nf_fat_modelos WHERE mvs = ? AND (cores IS NULL OR cores = '')");
$grava = $pdo->prepare('UPDATE nf_fat_modelos SET cores = ? WHERE id = ?');
$novos = 0;
foreach ($cores as $mvs => $lista) {
    $busca->execute([$mvs]);
    if ($id = $busca->fetchColumn()) {
        $grava->execute([implode("\n", $lista), $id]);
        $novos++;
    }
}
echo "Cores: $novos modelo(s) preenchido(s) de " . count($cores) . ".\n";
