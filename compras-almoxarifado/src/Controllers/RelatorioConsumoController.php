<?php
// @versao Ver.002.281026

namespace App\Controllers;

use App\Config\Database;
use App\Controllers\Concerns\EscopoRelatorio;
use App\Controllers\Concerns\RespostaCsv;
use App\Core\Controller;
use App\Services\ConsumoReposicaoService;
use App\Services\ValorEstoqueService;

/** Consumo por Período: consumo mensal do estoque e reposição até o mínimo, com CSV. */
class RelatorioConsumoController extends Controller
{
    use EscopoRelatorio, RespostaCsv;

    private const POR_PAGINA = 50;

    public function index(): void
    {
        $this->requirePermissaoRelatorio('consumo');
        $db  = Database::getInstance();
        $get = fn(string $chave): ?string => is_string($_GET[$chave] ?? null) && $_GET[$chave] !== '' ? $_GET[$chave] : null;
        $mesValido = fn(?string $v): bool => $v !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) === 1;

        $mesAtual = date('Y-m');
        $de  = $mesValido($get('de'))  ? $get('de')  : date('Y-m', strtotime(date('Y-m-01') . ' -11 months'));
        $ate = $mesValido($get('ate')) ? $get('ate') : $mesAtual;
        if ($de > $ate) {
            [$de, $ate] = [$ate, $de];
        }
        $ate = min($ate, $mesAtual);
        $de  = min($de, $ate);

        [$escopo, $filiaisFiltro] = $this->escopoEFiltroFiliais($db);
        $filialSel = $get('filial_id');
        $grupoId   = $get('grupo_produto_id');
        $soRepor   = $get('so_repor') === '1';

        $svc    = new ConsumoReposicaoService($db, new ValorEstoqueService($db));
        $todas  = $svc->linhas($de, $ate, $escopo, $grupoId);
        $meses  = ConsumoReposicaoService::meses($de, $ate);
        $porMes = ConsumoReposicaoService::porMes($todas, $meses);

        $ordenar = function (array $lista): array {
            usort($lista, fn($a, $b) => [$a['mes'], $b['repor_valor'], $b['consumo_valor'], $a['nome']]
                                     <=> [$b['mes'], $a['repor_valor'], $a['consumo_valor'], $b['nome']]);
            return $lista;
        };
        $filtradas = $soRepor ? array_values(array_filter($todas, fn($l) => $l['repor'] > 0)) : $todas;

        if ($get('export') === 'csv') {
            [$cabecalho, $linhasCsv] = ConsumoReposicaoService::exportar($ordenar($filtradas));
            $this->enviarCsv('consumo_reposicao_' . str_replace('-', '', $de) . '_' . str_replace('-', '', $ate) . '.csv',
                             $cabecalho, $linhasCsv);
            exit;
        }

        // Detalhe de um mês (padrão: o último do período)
        $mes     = in_array($get('mes'), $meses, true) ? $get('mes') : end($meses);
        $doMes   = $ordenar(array_values(array_filter($filtradas, fn($l) => $l['mes'] === $mes)));
        $totalMes = [
            'consumo_valor' => array_sum(array_column($doMes, 'consumo_valor')),
            'repor_valor'   => array_sum(array_column($doMes, 'repor_valor')),
        ];

        $total        = count($doMes);
        $totalPaginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina       = min(max(1, (int)($get('pagina') ?? 1)), $totalPaginas);
        $linhas       = array_slice($doMes, ($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA);

        $grupos = $db->query("SELECT id, nome FROM grupos_produtos WHERE ativo = 1 ORDER BY nome")->fetchAll(\PDO::FETCH_ASSOC);

        $this->view('relatorios/consumo', compact(
            'de', 'ate', 'mes', 'filialSel', 'grupoId', 'soRepor', 'filiaisFiltro', 'grupos',
            'porMes', 'linhas', 'totalMes', 'total', 'pagina', 'totalPaginas'
        ));
    }
}
