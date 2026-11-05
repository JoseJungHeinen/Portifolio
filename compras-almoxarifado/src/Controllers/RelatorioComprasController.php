<?php
// @versao Ver.002.160926

namespace App\Controllers;

use App\Config\Database;
use App\Controllers\Concerns\EscopoRelatorio;
use App\Controllers\Concerns\RespostaCsv;
use App\Core\Controller;
use App\Services\ComprasRelatorioService;

/** Relatório de Compras: resumo, gráficos e abas de OCs, notas fiscais e itens, com CSV. */
class RelatorioComprasController extends Controller
{
    use EscopoRelatorio, RespostaCsv;

    private const ABAS       = ['ocs', 'nfs', 'itens'];
    private const POR_PAGINA = 20;

    public function index(): void
    {
        $this->requirePermissaoRelatorio('compras');
        $db  = Database::getInstance();
        $get = fn(string $chave): ?string => is_string($_GET[$chave] ?? null) && $_GET[$chave] !== '' ? $_GET[$chave] : null;

        [$de, $ate]               = $this->periodoDoFiltro(date('Y-m-01'), date('Y-m-d'));
        [$escopo, $filiaisFiltro] = $this->escopoEFiltroFiliais($db);

        $aba       = in_array($get('aba'), self::ABAS, true) ? $get('aba') : 'ocs';
        $status    = array_key_exists($get('status') ?? '', ComprasRelatorioService::STATUS_OC) ? $get('status') : null;
        $filialSel = $get('filial_id');
        $filtros   = [
            'de'            => $de,
            'ate'           => $ate,
            'filiais'       => $escopo,
            'setor_id'      => $get('setor_id'),
            'fornecedor_id' => $get('fornecedor_id'),
            'status'        => $status,
        ];
        $svc = new ComprasRelatorioService($db);

        if ($get('export') === 'csv') {
            [$cabecalho, $linhasCsv] = $svc->exportar($aba, $filtros);
            $this->enviarCsv('compras_' . $aba . '_' . date('Ymd') . '.csv', $cabecalho, $linhasCsv);
            exit;
        }

        $total = match ($aba) {
            'nfs'   => $svc->contarNfs($filtros),
            'itens' => $svc->contarItens($filtros),
            default => $svc->contarOcs($filtros),
        };
        $totalPaginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina       = min(max(1, (int)($get('pagina') ?? 1)), $totalPaginas);
        $offset       = ($pagina - 1) * self::POR_PAGINA;
        $linhas = match ($aba) {
            'nfs'   => $svc->nfs($filtros, self::POR_PAGINA, $offset),
            'itens' => $svc->itens($filtros, self::POR_PAGINA, $offset),
            default => $svc->ocs($filtros, self::POR_PAGINA, $offset),
        };

        $resumo        = $svc->resumo($filtros);
        $porSetor      = $svc->porSetor($filtros);
        $porFornecedor = $svc->porFornecedor($filtros);

        $setores      = $db->query("SELECT id, nome FROM setores WHERE ativo = 1 ORDER BY nome")->fetchAll(\PDO::FETCH_ASSOC);
        $fornecedores = $db->query(
            "SELECT id, COALESCE(NULLIF(nome_fantasia, ''), razao_social) AS nome
             FROM fornecedores WHERE ativo = 1 ORDER BY nome"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $statusOc = ComprasRelatorioService::STATUS_OC;
        $statusNf = ComprasRelatorioService::STATUS_NF;

        $this->view('relatorios/compras', compact(
            'de', 'ate', 'aba', 'status', 'filialSel', 'filtros', 'filiaisFiltro', 'setores', 'fornecedores',
            'resumo', 'porSetor', 'porFornecedor', 'linhas', 'total', 'pagina', 'totalPaginas', 'statusOc', 'statusNf'
        ));
    }

}
