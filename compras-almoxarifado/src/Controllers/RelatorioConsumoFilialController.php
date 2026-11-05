<?php
// @versao Ver.002.160926

namespace App\Controllers;

use App\Config\Database;
use App\Controllers\Concerns\EscopoRelatorio;
use App\Core\Controller;
use App\Services\ConsumoService;
use App\Services\ValorEstoqueService;

/** Consumo por Filial: saídas de estoque atribuídas à filial/setor que consumiu. */
class RelatorioConsumoFilialController extends Controller
{
    use EscopoRelatorio;

    public function index(): void
    {
        $this->requirePermissaoRelatorio('filiais');
        $db = Database::getInstance();

        // M7: parâmetros em array (ex.: ?agrupamento[]=x) são tratados como ausentes.
        $get = fn(string $chave): ?string => is_string($_GET[$chave] ?? null) ? $_GET[$chave] : null;

        $agrupamento = in_array($get('agrupamento') ?? '', ['dia', 'mes', 'ano'], true) ? $get('agrupamento') : 'mes';
        $padraoDe = match ($agrupamento) {
            'dia' => date('Y-m-01'),
            'mes' => date('Y-01-01'),
            'ano' => (date('Y') - 4) . '-01-01',
        };
        [$de, $ate] = $this->periodoDoFiltro($padraoDe, date('Y-m-d'));

        // I4(b): visualização diária é limitada a 1 ano (agregação por dia cresce
        // rápido); períodos maiores têm o início empurrado para os últimos 365 dias.
        $periodoAjustado = false;
        if ($agrupamento === 'dia' && (new \DateTimeImmutable($de))->diff(new \DateTimeImmutable($ate))->days > 366) {
            $de              = (new \DateTimeImmutable($ate))->modify('-365 days')->format('Y-m-d');
            $periodoAjustado = true;
        }

        $ordem     = $get('ordem') === 'quantidade' ? 'quantidade' : 'valor';
        $setorId   = $get('setor_id') ?: null;
        $filialSel = $get('filial_id') ?: null;

        [$escopo, $filiaisFiltro] = $this->escopoEFiltroFiliais($db);

        $consumo = new ConsumoService($db, new ValorEstoqueService($db));
        $resumo  = ConsumoService::resumir($consumo->linhas($de, $ate, $escopo, $setorId), $agrupamento, $de, $ate, $ordem);

        $setores = $db->query("SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY nome")->fetchAll();

        $this->view('relatorios/filiais', compact(
            'agrupamento', 'de', 'ate', 'ordem', 'setorId', 'filialSel', 'filiaisFiltro', 'setores', 'resumo',
            'periodoAjustado'
        ));
    }
}
