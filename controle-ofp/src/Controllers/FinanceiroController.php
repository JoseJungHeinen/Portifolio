<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Ofp;
use App\Models\Unidade;
use App\Services\ConciliacaoService;
use App\Support\RegraException;
use App\Support\ValidacaoException;

class FinanceiroController extends Controller
{
    private const POR_PAGINA = 50;

    public function index(): void
    {
        $creditadas = ($_GET['situacao'] ?? '') === 'creditadas';
        $f = [
            'status'     => $creditadas ? 'creditada' : 'aguardando_credito',
            'unidade_id' => trim((string)($_GET['unidade_id'] ?? '')),
            'q'          => trim((string)($_GET['q'] ?? '')),
            'ordem'      => $creditadas ? 'credito' : '',
        ];
        $model = new Ofp();
        $total = $model->contar($f);
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina = min($paginas, max(1, (int)($_GET['pagina'] ?? 1)));
        $this->view('financeiro/index', [
            'title'    => 'Financeiro',
            'filtros'  => $f,
            'situacao' => $creditadas ? 'creditadas' : 'aguardando',
            'ofps'     => $model->buscar($f, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA),
            'total'    => $total,
            'pagina'   => $pagina,
            'paginas'  => $paginas,
            'unidades' => (new Unidade())->permitidas(),
        ]);
    }

    public function baixa(string $id): void
    {
        try {
            $r = (new ConciliacaoService())->baixar($id, $_POST, auth());
            $numero = (new Ofp())->find($id)['numero_ofp'] ?? '';
            session_flash('success', $r === 'creditada'
                ? "OFP {$numero} creditada. Ela saiu desta lista e agora aparece na aba \"Creditadas\"."
                : "Crédito parcial registrado na OFP {$numero}. Ela continua nesta lista até receber o restante.");
        } catch (ValidacaoException $e) {
            session_flash('error', implode(' ', $e->erros));
        } catch (RegraException $e) {
            session_flash('error', $e->getMessage());
        }
        redirect('/financeiro');
    }

    public function lote(): void
    {
        $ids = array_values(array_filter((array)($_POST['ids'] ?? []), 'is_string'));
        $titulo = trim((string)($_POST['titulo_apollo'] ?? ''));
        $data = trim((string)($_POST['data_credito'] ?? ''));
        if (!$ids || $titulo === '' || $data === '') {
            session_flash('error', 'Selecione as OFPs e informe o título e a data do crédito.');
            redirect('/financeiro');
        }
        try {
            $r = (new ConciliacaoService())->baixarLote($ids, $titulo, $data, auth());
        } catch (ValidacaoException $e) {
            session_flash('error', implode(' ', $e->erros));
            redirect('/financeiro');
        }
        session_flash('success', "{$r['ok']} OFP(s) creditada(s). Elas agora aparecem na aba \"Creditadas\".");
        if ($r['erros']) {
            session_flash('warning', implode(' ', $r['erros']));
        }
        redirect('/financeiro');
    }

    public function conciliacao(): void
    {
        $this->view('financeiro/conciliacao', ['title' => 'Conciliação', 'linhas' => (new ConciliacaoService())->conciliacao()]);
    }
}
