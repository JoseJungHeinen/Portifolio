<?php
// @versao Fat_08 V_03_30/09/2026
declare(strict_types=1);

/**
 * Carga inicial do cadastro de Modelos (Listas › Modelos de Veículos):
 *   RAM  — "Ram - Linha Produto Set-26.pdf" (tabela de 09/09/2026, MG): 15 modelos.
 *   FIAT — "Linha de Produto Nacional Agosto 26.pdf" (vigência 30/06/2026, SP): 54 modelos.
 * Só a tabela-resumo de cada PDF foi usada (MVS, MY, descrição, combustível, preço); as fichas
 * técnicas (itens de série, cores etc.) ficam de fora de propósito. Os preços mudam — a tabela
 * é temporária e sujeita a modificações diárias; edite/atualize em Listas › Modelos de Veículos.
 *
 * Uso: php cli/seed_faturamento_modelos.php
 * Idempotente: só insere o que ainda não existe (MVS é único); nunca sobrescreve edições.
 */

require_once __DIR__ . '/../config/constants.php';
require_once CONFIG . '/database.php';
require_once SRC . '/Models/Faturamento.php';

$pdo = Database::getInstance();

// [MVS, MY, descrição, combustível, preço]
$ram = [
    ['591.31F.0',  2027, 'RAMPAGE BIG HORN 2.2L TURBODIESEL AT9 4X4 2027',                   'Diesel',          230990.00],
    ['591.24R.0',  2027, 'RAMPAGE REBEL IGNITION FLEX MY27',                                 'Álcool/Gasolina', 250990.00],
    ['591.23F.0',  2027, 'RAMPAGE REBEL 2.2L TURBODIESEL AT9 4X4 2027',                      'Diesel',          259990.00],
    ['591.25F.0',  2027, 'RAMPAGE LARAMIE 2.2L TURBODIESEL AT9 4X4 2027',                    'Diesel',          269990.00],
    ['591.27R.0',  2027, 'RAMPAGE R/T 2.0L TURBO FLEX AT9 4X4 2027',                         'Álcool/Gasolina', 275990.00],
    ['539.2H1.1',  2027, 'DAKOTA BIG HORN 2.2L TURBODIESEL AT8 4X4 2027',                    'Diesel',          285990.00],
    ['539.2L1.1',  2027, 'DAKOTA WARLOCK 2.2L TURBODIESEL AT8 4X4 2026',                     'Diesel',          299990.00],
    ['539.2N1.1',  2027, 'DAKOTA LARAMIE 2.2L TURBODIESEL AT8 4X4 2026',                     'Diesel',          322990.00],
    ['539.2X1.1',  2027, 'DAKOTA LARAMIE NIGHT EDITION 2.2L TURBODIESEL AT8 4X4 2026',       'Diesel',          329990.00],
    ['575.3MZ.T',  2026, '1500 BIG HORN 3.0L BITURBO GASOLINA AT8 4X4 2026',                 'Gasolina',        449990.00],
    ['575.3DK.T',  2026, '1500 LARAMIE 3.0L BITURBO GASOLINA AT8 4X4 2026',                  'Gasolina',        584990.00],
    ['575.3GK.T',  2026, '1500 LARAMIE NIGHT EDITION 3.0L BITURBO GASOLINA AT8 4X4 2026',    'Gasolina',        599990.00],
    ['570.2AH.T',  2026, '2500 LARAMIE 6.7L TURBODIESEL HIGH-OUTPUT AT8 4X4 2026',           'Diesel',          559990.00],
    ['573.22H.T',  2026, '3500 LARAMIE NIGHT EDITION 6.7 TURBODIESEL HIGH-OUTPUT AT8 4X4 2026', 'Diesel',       619990.00],
    ['573.24K.T',  2026, '3500 LIMITED LONGHORN 6.7L TURBODIESEL HIGH-OUTPUT AT8 4X4 2026',  'Diesel',          679990.00],
];

$fiat = [
    ['341AGW0', 2027, 'MOBI LIKE 1.0 2027', 'Álcool/Gasolina', 85490.00],
    ['341ATW0', 2027, 'MOBI TREKKING 1.0 2027', 'Álcool/Gasolina', 87990.00],
    ['358ATS1', 2026, 'ARGO DRIVE 1.0 FLEX 4P 2026', 'Álcool/Gasolina', 97990.00],
    ['358AFB1', 2026, 'ARGO DRIVE 1.3 AT FLEX 4P 2026', 'Álcool/Gasolina', 108990.00],
    ['358AGB1', 2026, 'ARGO TREKKING 1.3 AT FLEX 4P 2026', 'Álcool/Gasolina', 111990.00],
    ['358AGU1', 2026, 'ARGO TREKKING 1.3 FLEX 4P 2026', 'Álcool/Gasolina', 103990.00],
    ['359ATH1', 2027, 'CRONOS DRIVE 1.0 FLEX 4P 2027', 'Álcool/Gasolina', 109990.00],
    ['359AFU1', 2027, 'CRONOS DRIVE 1.3 FLEX 4P 2027', 'Álcool/Gasolina', 114990.00],
    ['359AFV1', 2027, 'CRONOS DRIVE 1.3 AT FLEX 4P 2027', 'Álcool/Gasolina', 119990.00],
    ['363ANN1', 2027, 'PULSE DRIVE 1.3 MT FLEX 4P 2027', 'Álcool/Gasolina', 104990.00],
    ['363ANP1', 2027, 'PULSE DRIVE 1.3 AT FLEX 4P 2027', 'Álcool/Gasolina', 116990.00],
    ['363AP01', 2027, 'PULSE AUDACE T200 MHEV AT FLEX 4P 2027', 'Híbrido', 137990.00],
    ['363ASZ1', 2027, 'PULSE ABARTH TURBO 270 FLEX AT 4P 2027', 'Álcool/Gasolina', 163490.00],
    ['376AJA1', 2027, 'FASTBACK TURBO 200 FLEX AT 2027', 'Álcool/Gasolina', 119990.00],
    ['376AJD1', 2026, 'FASTBACK TURBO 200 FLEX AT 2026', 'Álcool/Gasolina', 119990.00],
    ['376AJK1', 2027, 'FASTBACK SMART DRIVE TURBO 200 FLEX AT 2027', 'Álcool/Gasolina', 127990.00],
    ['376AK01', 2027, 'FASTBACK AUDACE TURBO 200 MHEV FLEX AT 2027', 'Híbrido', 168490.00],
    ['376AK41', 2026, 'FASTBACK AUDACE TURBO 200 HYBRID FLEX AT 2026', 'Híbrido', 167490.00],
    ['376AL01', 2027, 'FASTBACK IMPETUS TURBO 200 MHEV FLEX AT 2027', 'Híbrido', 174490.00],
    ['376AL41', 2026, 'FASTBACK IMPETUS TURBO 200 HYBRID FLEX AT 2026', 'Híbrido', 173490.00],
    ['376AM61', 2026, 'FASTBACK LIMITED EDITION TURBO 270 FLEX AT 2026', 'Álcool/Gasolina', 177990.00],
    ['376AN61', 2026, 'FASTBACK ABARTH TURBO 270 FLEX AT 2026', 'Álcool/Gasolina', 183990.00],
    ['376AN61', 2027, 'FASTBACK ABARTH TURBO 270 FLEX AT 2027', 'Álcool/Gasolina', 184990.00],
    ['281AJP1', 2027, 'STRADA ENDURANCE CABINE PLUS 1.3 FLEX 2027', 'Álcool/Gasolina', 116990.00],
    ['281AKP1', 2027, 'STRADA FREEDOM CABINE PLUS 1.3 FLEX 2027', 'Álcool/Gasolina', 123990.00],
    ['281BKP1', 2027, 'STRADA FREEDOM CABINE DUPLA 1.3 FLEX 2027', 'Álcool/Gasolina', 133490.00],
    ['281BLP1', 2027, 'STRADA VOLCANO CABINE DUPLA 1.3 FLEX 2027', 'Álcool/Gasolina', 136490.00],
    ['281BLU1', 2027, 'STRADA VOLCANO CABINE DUPLA 1.3 AT FLEX 2027', 'Álcool/Gasolina', 142490.00],
    ['281BMY1', 2027, 'STRADA ULTRA CABINE DUPLA TURBO 200 AT FLEX 2027', 'Álcool/Gasolina', 153990.00],
    ['281BNY1', 2027, 'STRADA RANCH CABINE DUPLA TURBO 200 AT FLEX 2027', 'Álcool/Gasolina', 153990.00],
    ['2263PU2', 2027, 'TORO ENDURANCE TURBO 270 FLEX AT6 2027', 'Álcool/Gasolina', 167490.00],
    ['2263RU2', 2027, 'TORO FREEDOM TURBO 270 FLEX AT6 2027', 'Álcool/Gasolina', 177490.00],
    ['2262S02', 2027, 'TORO VOLCANO MHEV FLEX T270 AT6 2027', 'Híbrido', 197490.00],
    ['2262T02', 2027, 'TORO ULTRA MHEV FLEX T270 AT6 2027', 'Híbrido', 206490.00],
    ['2263SW2', 2027, 'TORO VOLCANO TURBODIESEL 4x4 AT9 2027', 'Diesel', 220490.00],
    ['2262WW2', 2027, 'TORO RANCH TURBODIESEL 4x4 AT9 2027', 'Diesel', 238490.00],
    ['5791C23', 2026, 'TITANO ENDURANCE MULTIJET TURBODIESEL MT 4X4', 'Diesel', 238490.00],
    ['5791H33', 2026, 'TITANO VOLCANO MULTIJET TURBODIESEL AT 4X4', 'Diesel', 268490.00],
    ['5791N33', 2026, 'TITANO RANCH MULTIJET TURBODIESEL AT 4X4', 'Diesel', 290490.00],
    ['2651PF1', 2027, 'FIORINO ENDURANCE 1.3 FLEX', 'Álcool/Gasolina', 132990.00],
    ['50864Y4', 2025, 'SCUDO CARGO 2.2 TD 4 P 2026', 'Diesel', 229990.00],
    ['50865Y4', 2025, 'SCUDO MULTI 2.2 TD 2026', 'Diesel', 235990.00],
    ['295GLF2', 2026, 'NOVO DUCATO MINIBUS COMFORT 18L 2.2 DIESEL', 'Diesel', 375990.00],
    ['295GWF2', 2026, 'NOVO DUCATO MINIBUS LUXO 16L 2.2 DIESEL', 'Diesel', 384990.00],
    ['529BGF0', 2026, 'NOVO DUCATO MAXICARGO 13M 2.2 DIESEL', 'Diesel', 301990.00],
    ['529BGF0', 2027, 'NOVO DUCATO MAXICARGO 13M 2.2 DIESEL', 'Diesel', 301990.00],
    ['529GKF0', 2026, 'DUCATO NOVO MULTI 2.2 DIESEL 4P', 'Diesel', 309990.00],
    ['529GKF0', 2027, 'NOVO DUCATO MULTI 2.2 DIESEL 4P', 'Diesel', 309990.00],
    ['529GGF0', 2026, 'NOVO DUCATO MAXICARGO HEAVY 13M 2.2 DIESEL', 'Diesel', 286990.00],
    ['529GGF0', 2027, 'NOVO DUCATO MAXICARGO HEAVY 13M 2.2 DIESEL', 'Diesel', 286990.00],
    ['529GMF0', 2026, 'NOVO DUCATO MINIBUS LUXO 16L 2.2 DIESEL', 'Diesel', 390990.00],
    ['529GMF0', 2027, 'NOVO DUCATO MINIBUS LUXO 2.2 DIESEL 4P 16L', 'Diesel', 390990.00],
    ['529GNF0', 2026, 'NOVO DUCATO MINIBUS COMFORT 18L 2.2 DIESEL', 'Diesel', 381990.00],
    ['529GNF0', 2027, 'NOVO DUCATO MINIBUS COMFORT 18L 2.2 DIESEL', 'Diesel', 381990.00],
];

$cargas = [
    ['RAM',  '2026-09-09', $ram],
    ['FIAT', '2026-06-30', $fiat],
];

// O MVS é a chave única do modelo. Quando a tabela traz o mesmo MVS em dois anos-modelo
// (ex.: 376AN61 em 2026 e 2027), fica só o mais recente.
$unicos = static function (array $lista): array {
    $porMvs = [];
    foreach ($lista as $l) {
        if (!isset($porMvs[$l[0]]) || $l[1] >= $porMvs[$l[0]][1]) {
            $porMvs[$l[0]] = $l;
        }
    }
    return array_values($porMvs);
};

$existe = $pdo->prepare('SELECT 1 FROM nf_fat_modelos WHERE mvs = ?');
$ins    = $pdo->prepare(
    'INSERT INTO nf_fat_modelos (id, marca, mvs, ano_modelo, descricao, combustivel, preco_sugerido, preco_vigencia)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($cargas as [$marca, $vigencia, $lista]) {
    $novos = 0;
    $lista = $unicos($lista);
    foreach ($lista as [$mvs, $my, $desc, $comb, $preco]) {
        $existe->execute([$mvs]);
        if ($existe->fetchColumn()) {
            continue;
        }
        $ins->execute([Faturamento::uuid(), $marca, $mvs, $my, $desc, $comb, $preco, $vigencia]);
        $novos++;
    }
    echo "$marca: $novos novo(s) de " . count($lista) . ".
";
}
