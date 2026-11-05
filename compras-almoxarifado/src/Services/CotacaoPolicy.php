<?php
// @versao Ver.001.270826

namespace App\Services;

/**
 * Regras que definem se uma cotação ainda pode ser alterada. Consultada em dois
 * lugares — a view, para exibir ou desabilitar os botões, e os controllers, para
 * validar de fato. Esconder o botão não protege nada: sem a checagem no servidor,
 * um POST direto passaria.
 */
final class CotacaoPolicy
{
    /**
     * @param array $cotacao Linha de `cotacoes`. Quando vier da listagem, traz
     *                       também `oc_id`; de um SELECT simples, não — por isso
     *                       o chamador que não tem `oc_id` precisa buscá-lo antes
     *                       (ver CompraController::carregarCotacaoEditavel).
     * @return array{permitido: bool, motivo: ?string}
     */
    public static function avaliar(array $cotacao): array
    {
        if (!empty($cotacao['oc_id'])) {
            return ['permitido' => false, 'motivo' => 'Ordem de Compra já emitida para esta cotação.'];
        }

        if (($cotacao['status'] ?? '') === 'cancelada') {
            return ['permitido' => false, 'motivo' => 'Cotação cancelada.'];
        }

        return ['permitido' => true, 'motivo' => null];
    }
}
