<?php
// @versao Ver.001.160926

namespace App\Controllers;

use App\Config\Database;
use App\Controllers\Concerns\EscopoRelatorio;
use App\Controllers\Concerns\RespostaCsv;
use App\Core\Controller;
use App\Services\SaudeEstoqueService;
use App\Services\ValorEstoqueService;

/** Saúde do Estoque: valor imobilizado, parados, abaixo do mínimo e cobertura, com CSV. */
class RelatorioSaudeEstoqueController extends Controller
{
    use EscopoRelatorio, RespostaCsv;

    private const ABAS       = ['parados', 'minimo', 'cobertura'];
    private const PRAZOS     = [60, 90, 180];
    private const POR_PAGINA = 50;

    public function index(): void
    {
        $this->requirePermissaoRelatorio('saude_estoque');
        $db  = Database::getInstance();
        $get = fn(string $chave): ?string => is_string($_GET[$chave] ?? null) && $_GET[$chave] !== '' ? $_GET[$chave] : null;

        [$escopo, $filiaisFiltro] = $this->escopoEFiltroFiliais($db);
        $filialSel = $get('filial_id');
        $grupoId   = $get('grupo_produto_id');
        $prazo     = in_array((int)$get('prazo'), self::PRAZOS, true) ? (int)$get('prazo') : 90;
        $aba       = in_array($get('aba'), self::ABAS, true) ? $get('aba') : 'parados';
        $ordem     = $get('ordem') === 'sobra' ? 'sobra' : 'acaba';
        $agora     = date('Y-m-d H:i:s');

        $svc    = new SaudeEstoqueService($db, new ValorEstoqueService($db));
        $resumo = SaudeEstoqueService::resumir($svc->linhas($escopo, $grupoId, $agora), $prazo, $agora, $ordem);
        $lista  = $resumo[$aba];

        if ($get('export') === 'csv') {
            [$cabecalho, $linhasCsv] = SaudeEstoqueService::exportar($aba, $lista);
            $this->enviarCsv('saude_estoque_' . $aba . '_' . date('Ymd') . '.csv', $cabecalho, $linhasCsv);
            exit;
        }

        $total        = count($lista);
        $totalPaginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina       = min(max(1, (int)($get('pagina') ?? 1)), $totalPaginas);
        $linhas       = array_slice($lista, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);

        $grupos = $db->query("SELECT id, nome FROM grupos_produtos WHERE ativo = 1 ORDER BY nome")->fetchAll(\PDO::FETCH_ASSOC);

        $this->view('relatorios/saude_estoque', compact(
            'aba', 'prazo', 'ordem', 'filialSel', 'grupoId', 'filiaisFiltro', 'grupos',
            'resumo', 'linhas', 'total', 'pagina', 'totalPaginas'
        ));
    }
}
