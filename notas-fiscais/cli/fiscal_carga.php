<?php
// @versao Fis_05 V_01_26/08/2026
declare(strict_types=1);

/**
 * Carga fiscal — CLI.
 *
 * Relê os XML já gravados em storage/nfe_xml e materializa os totais
 * (produtos, nota, IBS/CBS) em nf_fiscal_totais. É o mesmo trabalho do botão
 * "Atualizar" da tela Fiscal, sem o teto por execução: serve para a PRIMEIRA
 * carga, quando o acervo tem dezenas de milhares de arquivos e não faz
 * sentido fazer o usuário clicar várias vezes.
 *
 * Não fala com a Brobot — quem busca nota nova é cli/brobot_sync.php.
 *
 * Uso:  php cli/fiscal_carga.php [limite]
 *       php cli/fiscal_carga.php         → tudo que faltar
 *       php cli/fiscal_carga.php 5000    → no máximo 5.000 arquivos
 */

define('ROOT', dirname(__DIR__));
define('SRC', ROOT . '/src');
define('STORAGE', ROOT . '/storage');
date_default_timezone_set('America/Sao_Paulo');

require ROOT . '/config/database.php';
require SRC . '/Helpers/XmlDfeParser.php';
require SRC . '/Models/FiscalNota.php';

// Lock: a carga inteira pode passar de vários minutos e duas rodando juntas
// só disputam o mesmo banco.
$lockDir = STORAGE . '/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lock = fopen($lockDir . '/fiscal_carga.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Já existe uma carga fiscal em andamento.\n");
    exit(1);
}

$limite = isset($argv[1]) ? max(1, (int) $argv[1]) : PHP_INT_MAX;
$inicio = microtime(true);

echo "Lendo XML de " . STORAGE . "/nfe_xml ...\n";
$r = FiscalNota::carregar($limite);

printf(
    "arquivos=%d  lidos=%d  novos=%d  atualizados=%d  pulados=%d  ignorados=%d  restantes=%d  (%.1fs)\n",
    $r['arquivos'], $r['lidos'], $r['novos'], $r['atualizados'],
    $r['pulados'], $r['ignorados'], $r['restantes'], microtime(true) - $inicio
);

if ($r['restantes'] > 0) {
    echo "Faltam {$r['restantes']} arquivos — rode de novo para continuar.\n";
}

flock($lock, LOCK_UN);
fclose($lock);
