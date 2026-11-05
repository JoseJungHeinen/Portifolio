<?php
/**
 * Regra de status das multas — tradução das colunas R e S do "Controle Geral".
 *
 * A planilha deduz o status de quais campos do checklist já estão
 * preenchidos, em ordem, parando no primeiro vazio.
 * Validada contra as 3.154 linhas da planilha (cli/validar.php).
 */

const ETAPAS = [
    'falta_data'        => 'Falta Data',
    'falta_email'       => 'Falta E-mail',
    'falta_identificar' => 'Falta Identificar',
    'falta_faturar'     => 'Falta Faturar',
    'aguardando_vcto'   => 'Aguardando Vcto',
    'falta_pagar'       => 'Falta Pagar',
    'aguardando_baixar' => 'Aguardando Baixar',
    'finalizado_detran' => 'Finalizado Detran',
    'fraude'            => 'FRAUDE',
];

const STATUS = [
    'nova'            => 'Nova',
    'em_processo'     => 'Em Processo',
    'a_faturar'       => 'A Faturar',
    'aguardando_vcto' => 'Aguardando Vcto',
    'a_pagar'         => 'A Pagar',
    'finalizado'      => 'Finalizado',
];

/** Linhas do "Painel Andamento das Multas", na ordem da planilha. */
const LINHAS_PAINEL = [
    ['chave' => 'nova',              'rotulo' => 'Novas Infrações',                       'etapas' => ['falta_data']],
    ['chave' => 'em_processo',       'rotulo' => 'Em Processo de Identificação Condutor', 'etapas' => ['falta_email', 'falta_identificar']],
    ['chave' => 'a_faturar',         'rotulo' => 'Realizar o Faturamento ao Cliente',     'etapas' => ['falta_faturar']],
    ['chave' => 'aguardando_vcto',   'rotulo' => 'Aguardando Vcto no Detran',             'etapas' => ['aguardando_vcto']],
    ['chave' => 'a_pagar',           'rotulo' => 'Realizar o Pagamento da Infração',      'etapas' => ['falta_pagar']],
    ['chave' => 'aguardando_baixar', 'rotulo' => 'Paga aguardando Baixar no Detran',      'etapas' => ['aguardando_baixar']],
];

function preenchido($v): bool
{
    return $v !== null && !(is_string($v) && trim($v) === '');
}

/**
 * @param array $m campos da multa (nomes das colunas da tabela `multas`)
 */
function calcular_etapa(array $m): string
{
    if (($m['ocorrencia_especial'] ?? null) === 'fraude') return 'fraude';
    if (empty($m['presente_no_detran'])) return 'finalizado_detran';
    if (!preenchido($m['prazo_identificacao'] ?? null) && !preenchido($m['prazo_identificacao_nota'] ?? null)) return 'falta_data';
    if (!preenchido($m['email_enviado'] ?? null)) return 'falta_email';
    if (!preenchido($m['identificacao_condutor'] ?? null)) return 'falta_identificar';
    if (!preenchido($m['fatura_numero'] ?? null)) return 'falta_faturar';
    if (!preenchido($m['pagamento_situacao'] ?? null)) {
        return !empty($m['vencimento_pendente']) ? 'aguardando_vcto' : 'falta_pagar';
    }
    return 'aguardando_baixar';
}

function status_da_etapa(string $etapa): string
{
    switch ($etapa) {
        case 'falta_data':        return 'nova';
        case 'falta_email':
        case 'falta_identificar': return 'em_processo';
        case 'falta_faturar':     return 'a_faturar';
        case 'aguardando_vcto':   return 'aguardando_vcto';
        case 'falta_pagar':       return 'a_pagar';
        default:                  return 'finalizado';
    }
}

/** Ainda em aberto no DETRAN (entra no "Total em Aberto"). */
function em_aberto(string $etapa): bool
{
    return $etapa !== 'finalizado_detran' && $etapa !== 'fraude';
}

/** Preenche etapa/status a partir dos demais campos. */
function com_status(array $m): array
{
    $m['etapa'] = calcular_etapa($m);
    $m['status'] = status_da_etapa($m['etapa']);
    return $m;
}
