<?php
// @versao Cap_23 V_01_21/09/2026
declare(strict_types=1);

/**
 * Teste manual e isolado da Ciência da Operação (NfeEventoClient) contra a
 * HOMOLOGAÇÃO da SEFAZ — não toca no banco, não integra com NfeDistSync.
 * Serve só pra validar mTLS + envelope SOAP + assinatura XML antes de ligar
 * o envio automático em produção (NfeDistSync::enviarCienciaDaOperacao).
 *
 * Uso:
 *   php cli/testar_ciencia_operacao.php <chave44> <cnpj> [certPath] [certSenha]
 *
 * certPath/certSenha, se omitidos, usam o certificado padrão de distribuição
 * (Settings dist.cert_path / dist.cert_senha).
 *
 * Uma chave que não existe de verdade na base da SEFAZ deve devolver uma
 * REJEIÇÃO de negócio (algo como "NF-e não consta na base de dados") — isso
 * já é sinal positivo: só se chega lá depois do envelope, do schema e da
 * ASSINATURA passarem na validação. Erro de schema/assinatura vem com cStat
 * de rejeição de leiaute, bem diferente — é isso que este teste quer
 * descartar antes de qualquer coisa tocar produção de verdade.
 */

define('ROOT', dirname(__DIR__));
require ROOT . '/config/database.php';
require ROOT . '/src/Helpers/Settings.php';
require ROOT . '/src/Helpers/XmlAssinatura.php';
require ROOT . '/src/Helpers/NfeEventoClient.php';

$chaveArg = $argv[1] ?? null;
$cnpjArg  = $argv[2] ?? null;

if ($chaveArg === null || $cnpjArg === null) {
    fwrite(STDERR, "Uso: php cli/testar_ciencia_operacao.php <chave44> <cnpj> [certPath] [certSenha]\n");
    exit(1);
}

$chave = preg_replace('/\D+/', '', $chaveArg) ?? '';
if (strlen($chave) !== 44) {
    fwrite(STDERR, "Chave inválida — precisa ter 44 dígitos (tem " . strlen($chave) . ").\n");
    exit(1);
}

$certPath  = $argv[3] ?? (string) Settings::get('dist.cert_path', '');
$certSenha = $argv[4] ?? (string) Settings::get('dist.cert_senha', '');

echo "Certificado: {$certPath}\n";
echo "CNPJ (autor): {$cnpjArg}\n";
echo "Chave NF-e:   {$chave}\n";
echo "Ambiente:     HOMOLOGAÇÃO\n\n";

$cliente = new NfeEventoClient($certPath, $certSenha, $cnpjArg, true);
$r = $cliente->cienciaDaOperacao($chave);

echo "Resultado:\n";
foreach ($r as $k => $v) {
    echo "  {$k}: " . ($v ?? '(null)') . "\n";
}

echo "\nSOAP bruto da resposta:\n";
echo ($cliente->ultimaRespostaBruta() ?? '(sem resposta)') . "\n";
