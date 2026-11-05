<?php
// @versao Ver.002.281026
/**
 * Preenche sla_vence_em das solicitações de compra que ainda não têm vencimento
 * (uso único depois da migration 066; seguro rodar de novo — só toca linhas NULL).
 *
 *   php cli/recalcular_sla.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT', dirname(__DIR__));
require_once ROOT . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(ROOT)->load();
date_default_timezone_set('America/Sao_Paulo');

$n = (new App\Services\SolicitacaoCompraService())->recalcularSlaPendentes();
echo '[' . date('Y-m-d H:i:s') . "] SLA calculado para {$n} solicitação(ões)." . PHP_EOL;
