<?php
// @versao Ver.002.281026

namespace App\Services;

use App\Config\Database;

/**
 * Regras numéricas da Política de Compras COMP-001 que o sistema aplica.
 * Os valores padrão são os da política; podem ser ajustados em
 * parametros_sistema sem mexer no código.
 */
class PoliticaComprasService
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** §8: até este valor (inclusive) bastam menos cotações. */
    public function limiteFaixaCotacao(): float
    {
        return (float)$this->parametro('politica.cotacao_limite_faixa', 1000);
    }

    /** §8: mínimo de cotações para uma compra deste valor (2 até R$ 1.000; 3 acima). */
    public function minimoCotacoes(float $valor): int
    {
        return $valor <= $this->limiteFaixaCotacao()
            ? $this->parametro('politica.cotacao_minimo_ate_limite', 2)
            : $this->parametro('politica.cotacao_minimo_acima_limite', 3);
    }

    /** Texto da regra para as telas, ex.: "2 cotações até R$ 1.000,00; 3 acima". */
    public function descricaoRegraCotacoes(): string
    {
        return sprintf('%d cotações até R$ %s; %d acima',
            $this->parametro('politica.cotacao_minimo_ate_limite', 2),
            number_format($this->limiteFaixaCotacao(), 2, ',', '.'),
            $this->parametro('politica.cotacao_minimo_acima_limite', 3));
    }

    /** Fornecedores da cotação que responderam com preço (link ou resposta manual). */
    public function cotacoesValidas(string $cotacaoId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cotacao_fornecedores cf
             WHERE cf.cotacao_id = ?
               AND EXISTS (SELECT 1 FROM cotacao_itens ci
                           WHERE ci.cotacao_fornecedor_id = cf.id AND ci.valor_unitario > 0)"
        );
        $stmt->execute([$cotacaoId]);
        return (int)$stmt->fetchColumn();
    }

    private function parametro(string $chave, int $padrao): int
    {
        try {
            $stmt = $this->db->prepare("SELECT valor FROM parametros_sistema WHERE chave = ? LIMIT 1");
            $stmt->execute([$chave]);
            $valor = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return $padrao;
        }
        return $valor !== false && (int)$valor > 0 ? (int)$valor : $padrao;
    }
}
