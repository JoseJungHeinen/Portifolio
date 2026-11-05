<?php
// @versao Fis_08 V_01_26/08/2026
declare(strict_types=1);

/**
 * Consulta na Receita (cnpj.ws) a situação dos emitentes da tela Fiscal — CLI.
 *
 * Mesmo trabalho do botão "Consultar situação" da tela, sem depender de
 * ninguém com o navegador aberto. É o caminho prático quando faltam centenas
 * de CNPJ: a 20s por consulta, 500 CNPJ levam quase 3 horas.
 *
 * Percorre TODOS os emitentes carregados em nf_fiscal_totais, não só os de um
 * período. Quem já tem situação (no cadastro ou no cache) é pulado, e quem já
 * falhou antes só volta com --retentar.
 *
 * Uso:
 *   php cli/fiscal_cnpj_consulta.php              → todos os pendentes
 *   php cli/fiscal_cnpj_consulta.php 50           → no máximo 50 nesta rodada
 *   php cli/fiscal_cnpj_consulta.php 50 --retentar → inclui os que falharam antes
 *
 * Agendar (madrugada, 200 por noite):
 *   0 2 * * * php /var/www/exemplo/notas/cli/fiscal_cnpj_consulta.php 200
 */

define('ROOT', dirname(__DIR__));
define('SRC', ROOT . '/src');
define('STORAGE', ROOT . '/storage');
date_default_timezone_set('America/Sao_Paulo');

require ROOT . '/config/database.php';
require SRC . '/Helpers/CnpjReceita.php';
require SRC . '/Models/FiscalCnpj.php';

// Lock: duas rodadas juntas furariam o limite de 3/min da API.
$lockDir = STORAGE . '/locks';
if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
$lock = fopen($lockDir . '/fiscal_cnpj.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Já existe uma consulta de CNPJ em andamento.\n");
    exit(1);
}

$args      = array_slice($argv, 1);
$retentar  = in_array('--retentar', $args, true);
$limite    = 0;
foreach ($args as $a) {
    if (ctype_digit($a)) { $limite = (int) $a; break; }
}

$pdo   = Database::getInstance();
$todos = $pdo->query('SELECT DISTINCT emitente_cnpj FROM nf_fiscal_totais')
             ->fetchAll(PDO::FETCH_COLUMN);

$pendentes = FiscalCnpj::pendentes($todos, $retentar);
$total     = count($pendentes);

if ($limite > 0) {
    $pendentes = array_slice($pendentes, 0, $limite);
}

printf("%d CNPJ sem situação (de %d emitentes). Consultando %d nesta rodada.\n",
    $total, count($todos), count($pendentes));
if ($pendentes === []) {
    flock($lock, LOCK_UN); fclose($lock);
    exit(0);
}
printf("A %ds por consulta, isso leva cerca de %d min.\n\n",
    CnpjReceita::INTERVALO, (int) ceil(count($pendentes) * CnpjReceita::INTERVALO / 60));

$ok = $erro = 0;
foreach ($pendentes as $i => $cnpj) {
    // A trava é global (compartilhada com a tela de Processos): se outra
    // consulta acabou de sair, espera o resto do intervalo em vez de tomar 429.
    while (($espera = CnpjReceita::esperaRestante()) > 0) {
        sleep($espera);
    }

    $r = CnpjReceita::consultar($cnpj);

    if ($r['sucesso'] ?? false) {
        $onde = FiscalCnpj::gravar($cnpj, $r['dados']);
        $ok++;
        printf("[%d/%d] %s  %-10s %-8s → %s\n",
            $i + 1, count($pendentes), $cnpj,
            $r['dados']['situacao_cadastral'] ?: '-',
            ($r['dados']['optante_mei'] === 1 ? 'MEI' : ($r['dados']['optante_simples'] === 1 ? 'Simples' : '')),
            $onde);
    } elseif (isset($r['esperar'])) {
        // Limite do serviço, não problema do CNPJ: espera e tenta o mesmo de novo.
        printf("[%d/%d] %s  limite atingido — aguardando %ds\n",
            $i + 1, count($pendentes), $cnpj, $r['esperar']);
        sleep((int) $r['esperar']);
        $r = CnpjReceita::consultar($cnpj);
        if ($r['sucesso'] ?? false) {
            FiscalCnpj::gravar($cnpj, $r['dados']);
            $ok++;
            continue;
        }
        FiscalCnpj::registrarErro($cnpj, (string) ($r['erro'] ?? 'Falha'));
        $erro++;
    } else {
        FiscalCnpj::registrarErro($cnpj, (string) ($r['erro'] ?? 'Falha'));
        $erro++;
        printf("[%d/%d] %s  ERRO: %s\n", $i + 1, count($pendentes), $cnpj, $r['erro'] ?? '');
    }
}

printf("\nconsultados=%d  erros=%d  restam=%d\n", $ok, $erro, $total - $ok);

flock($lock, LOCK_UN);
fclose($lock);
