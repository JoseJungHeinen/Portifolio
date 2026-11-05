<?php
// @versao Apo_03 V_01_30/09/2026
declare(strict_types=1);

/**
 * Consulta de PEDIDO no Apollo para pré-preencher o pedido de Faturamento
 * (cliente, vendedor, veículo, valores e formas de pagamento).
 *
 * Modo (Settings `apollo.pedido_modo`):
 *   desligado — padrão; nenhuma consulta é feita (ex.: Server-APP, que não enxerga o Apollo).
 *   mock      — devolve um pedido FICTÍCIO marcado `simulado` — só para testar a tela.
 *   oracle    — consulta o Apollo pelo bridge Python (AINDA NÃO MAPEADO: falta saber as
 *               tabelas/colunas do pedido no Apollo).
 *
 * Formato devolvido por buscar():
 *   [
 *     'simulado'   => bool,
 *     'numero'     => string,
 *     'tipo_venda' => ?string,
 *     'vendedor_nome' => ?string,
 *     'cliente'    => [nome, doc, endereco, numero, cidade, uf, telefone, email],
 *     'veiculo'    => [codigo, modelo, cor, ano_fab_mod, combustivel, placa],
 *     'valores'    => [veiculo, opcionais, frete],
 *     'pagamentos' => [[tipo (texto do Apollo), valor, data (Y-m-d)], ...],
 *   ]
 */
class ApolloPedido
{
    public static function modo(): string
    {
        $m = (string) Settings::get('apollo.pedido_modo', 'desligado');
        return in_array($m, ['mock', 'oracle'], true) ? $m : 'desligado';
    }

    /**
     * @param array $filial linha de `filiais` (id, nome, cnpj…) já validada pelo chamador
     * @throws RuntimeException consulta indisponível ou pedido não encontrado
     */
    public static function buscar(array $filial, string $numero): array
    {
        switch (self::modo()) {
            case 'mock':
                return self::simulado($numero);
            case 'oracle':
                throw new RuntimeException('A consulta de pedidos no Apollo ainda não foi mapeada (tabelas do pedido).');
            default:
                throw new RuntimeException('A consulta ao Apollo não está habilitada neste ambiente.');
        }
    }

    /** Pedido fictício — nunca usar fora de teste. */
    private static function simulado(string $numero): array
    {
        return [
            'simulado'      => true,
            'numero'        => $numero,
            'tipo_venda'    => 'Seminovos',
            'vendedor_nome' => 'Fábio Ritter',
            'cliente'       => [
                'nome' => 'CLIENTE SIMULADO ' . $numero, 'doc' => '111.444.777-35',
                'endereco' => 'RUA DAS FLORES', 'numero' => '100', 'cidade' => 'Erechim', 'uf' => 'RS',
                'telefone' => '(00) 0000-0000', 'email' => 'cliente.simulado@exemplo.com.br',
            ],
            'veiculo'       => [
                'codigo' => 'ABC1D23', 'modelo' => 'PULSE DRIVE 1.3 FLEX AT', 'cor' => 'Prata',
                'ano_fab_mod' => '2025/2026', 'combustivel' => 'Álcool/Gasolina', 'placa' => 'ABC1D23',
            ],
            'valores'       => ['veiculo' => 98500.00, 'opcionais' => 1500.00, 'frete' => 0.00],
            'pagamentos'    => [
                ['tipo' => 'FINANCIAMENTO BANCARIO', 'valor' => 60000.00, 'data' => date('Y-m-d')],
                ['tipo' => 'ENTRADA',                'valor' => 40000.00, 'data' => date('Y-m-d')],
            ],
        ];
    }

    /** Tipo de pagamento do Apollo (texto livre) → lista do Faturamento; null = não reconhecido. */
    public static function tipoPagamento(string $textoApollo): ?string
    {
        $t = mb_strtolower(trim($textoApollo));
        $t = strtr($t, ['á' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);
        foreach ([
            'financ'  => 'Financiamento',
            'entrada' => 'Entrada',
            'troca'   => 'Troca',
            'usado'   => 'Troca',
            'consorc' => 'Consórcio',
            'boleto'  => 'Boleto',
            'terceiro' => 'Pagamento de terceiro',
        ] as $trecho => $tipo) {
            if (str_contains($t, $trecho)) {
                return $tipo;
            }
        }
        return null;
    }
}
