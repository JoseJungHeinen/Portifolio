<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Causal;
use App\Models\Ofp;
use App\Models\OfpAnexo;
use App\Models\Motivo;
use App\Models\OfpEvento;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Services\AnexoService;
use App\Services\ConciliacaoService;
use App\Services\CorrecaoService;
use App\Services\OfpService;
use App\Services\StatusService;
use App\Support\RegraException;
use App\Support\ValidacaoException;
use App\Support\XlsxWriter;
use finfo;

class OfpController extends Controller
{
    private const POR_PAGINA = 50;

    private function filtros(): array
    {
        $f = [];
        foreach (['status', 'unidade_id', 'causal_id', 'responsavel_id', 'semaforo', 'ano', 'q', 'aging', 'sugestao'] as $chave) {
            $f[$chave] = trim((string)($_GET[$chave] ?? ''));
        }
        if ($f['status'] === '') {
            $f['status'] = 'ativos';
        }
        return $f;
    }

    public function index(): void
    {
        $f = $this->filtros();
        $ofps = new Ofp();
        $total = $ofps->contar($f);
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina = min($paginas, max(1, (int)($_GET['pagina'] ?? 1)));
        $this->view('ofp/index', [
            'title'    => 'OFPs',
            'filtros'  => $f,
            'ofps'     => $ofps->buscar($f, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA),
            'total'    => $total,
            'pagina'   => $pagina,
            'paginas'  => $paginas,
            'unidades' => (new Unidade())->permitidas(),
            'causais'  => (new Causal())->todos(),
        ]);
    }

    public function exportar(): void
    {
        $linhas = [];
        foreach ((new Ofp())->buscar($this->filtros(), 0) as $o) {
            $linhas[] = [
                $o['numero_ofp'] ?? '', $o['unidade_nome'], $o['causal_codigo'], $o['desenho'], $o['descricao_peca'] ?? '',
                (float)$o['quantidade'], (float)$o['valor_reclamado'], status_label($o['status']),
                date_br($o['data_recebimento']), date_br($o['data_abertura']), date_br($o['data_decisao']),
                $o['nf_devolucao'] ?? '', date_br($o['data_nf_devolucao']), (float)($o['valor_nf_devolucao'] ?? 0),
                (float)($o['valor_icms_st'] ?? 0), (float)$o['aberto_peca'], (float)$o['aberto_st'],
                date_br($o['prazo_data']), $o['responsavel_nome'] ?? '',
            ];
        }
        $xlsx = XlsxWriter::generate([
            'Nº OFP', 'Unidade', 'Causal', 'Desenho', 'Peça', 'Qtd', 'Valor reclamado', 'Status', 'Recebimento',
            'Abertura', 'Decisão', 'NF devolução', 'Data NF', 'Valor NF', 'ICMS ST', 'Aberto peça', 'Aberto ST', 'Prazo', 'Responsável',
        ], $linhas);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="ofps-' . date('Ymd-His') . '.xlsx"');
        header('Content-Length: ' . strlen($xlsx));
        echo $xlsx;
    }

    /** Valores do formulário a reexibir após erro (só campos conhecidos, sem arrays). */
    private function antigos(): array
    {
        return array_filter(
            array_intersect_key($_POST, array_flip([...OfpService::CAMPOS, ...OfpService::CAMPOS_COLETA])),
            'is_scalar'
        );
    }

    private function listasFormulario(): array
    {
        return [
            'unidades'     => (new Unidade())->permitidas(),
            'causais'      => (new Causal())->ativos(),
            'responsaveis' => (new Usuario())->responsaveis(),
        ];
    }

    /** Carrega a OFP respeitando o escopo de unidades; 404 se não puder ver. */
    private function carregar(string $id): array
    {
        $ofp = (new Ofp())->findComCausal($id);
        if (!$ofp || !unidade_permitida($ofp['unidade_id'])) {
            $this->abort(404);
        }
        return $ofp;
    }

    public function nova(): void
    {
        $this->view('ofp/form', [
            'title' => 'Nova OFP', 'ofp' => null, 'dados' => flash('old') ?? [], 'erros' => flash('erros') ?? [],
        ] + $this->listasFormulario());
    }

    public function store(): void
    {
        try {
            $r = (new OfpService())->registrar($_POST, auth());
        } catch (ValidacaoException $e) {
            session_flash('old', $this->antigos());
            session_flash('erros', $e->erros);
            session_flash('error', 'Corrija os campos destacados. Anexe as fotos novamente.');
            redirect('/ofps/nova');
        }
        try {
            $errosAnexo = (new AnexoService())->salvar($r['id'], AnexoService::lista($_FILES['fotos'] ?? null), 'foto', auth()['id']);
        } catch (RegraException $e) {
            $errosAnexo = [$e->getMessage()];
        }
        session_flash('success', 'OFP registrada.');
        if ($r['avisos'] || $errosAnexo) {
            session_flash('warning', implode(' ', array_merge($r['avisos'], $errosAnexo)));
        }
        redirect('/ofps/' . $r['id']);
    }

    public function editar(string $id): void
    {
        $ofp = $this->carregar($id);
        if (!StatusService::ativo($ofp['status'])) {
            session_flash('error', 'OFP encerrada não pode ser editada.');
            redirect('/ofps/' . $id);
        }
        $this->view('ofp/form', [
            'title' => 'Editar OFP', 'ofp' => $ofp, 'dados' => flash('old') ?? $ofp, 'erros' => flash('erros') ?? [],
        ] + $this->listasFormulario());
    }

    public function atualizar(string $id): void
    {
        try {
            $avisos = (new OfpService())->atualizar($id, $_POST, auth());
        } catch (ValidacaoException $e) {
            session_flash('old', $this->antigos());
            session_flash('erros', $e->erros);
            session_flash('error', 'Corrija os campos destacados.');
            redirect('/ofps/' . $id . '/editar');
        } catch (RegraException $e) {
            session_flash('error', $e->getMessage());
            redirect('/ofps/' . $id);
        }
        session_flash('success', 'OFP atualizada.');
        if ($avisos) {
            session_flash('warning', implode(' ', $avisos));
        }
        redirect('/ofps/' . $id);
    }

    public function anexar(string $id): void
    {
        $this->carregar($id);
        try {
            $erros = (new AnexoService())->salvar($id, AnexoService::lista($_FILES['arquivos'] ?? null), (string)$this->input('tipo', 'outro'), auth()['id']);
        } catch (RegraException $e) {
            $erros = [$e->getMessage()];
        }
        session_flash($erros ? 'error' : 'success', $erros ? implode(' ', $erros) : 'Anexo(s) adicionado(s).');
        redirect('/ofps/' . $id);
    }

    public function baixarAnexo(string $id): void
    {
        $anexo = (new OfpAnexo())->find($id);
        if (!$anexo) {
            $this->abort(404);
        }
        $this->carregar($anexo['ofp_id']);
        $caminho = ROOT . '/storage/anexos/' . $anexo['caminho'];
        if (!is_file($caminho)) {
            $this->abort(404);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: 'application/octet-stream';
        // Só imagem/PDF abrem no navegador; o resto (ex.: XML) é sempre download, em sandbox.
        $inline = in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true);
        $nome = str_replace(['"', "\r", "\n", '\\'], '', $anexo['nome_original']);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $nome) . "\"; filename*=UTF-8''" . rawurlencode($nome));
        header('X-Content-Type-Options: nosniff');
        if (!$inline) {
            header('Content-Security-Policy: sandbox'); // (o visualizador de PDF do Chrome não abre sob sandbox)
        }
        header('Content-Length: ' . (string)filesize($caminho));
        readfile($caminho);
    }

    public function show(string $id): void
    {
        $ofp = $this->carregar($id);
        $this->view('ofp/show', [
            'title'   => 'OFP ' . ($ofp['numero_ofp'] ?? 'sem nº'),
            'ofp'     => $ofp,
            'eventos' => (new OfpEvento())->doOfp($id),
            'anexos'  => (new OfpAnexo())->doOfp($id),
            'acoes'   => StatusService::transicoesPermitidas($ofp['status'], auth()['perfil'], $ofp['tipo_devolucao'], (bool)$ofp['exige_tp41']),
            'motivos'        => (new Motivo())->ativosPorStatus(),
            'camposCorrecao' => CorrecaoService::camposPermitidos(auth()['perfil'], $ofp),
        ]);
    }

    public function transicao(string $id): void
    {
        $para = (string)$this->input('para', '');
        try {
            if ($para === 'creditada') {
                // Crédito passa pela conciliação (trata crédito parcial).
                $r = (new ConciliacaoService())->baixar($id, $_POST, auth());
                session_flash('success', $r === 'creditada' ? 'Crédito registrado. OFP creditada.' : 'Crédito parcial registrado.');
            } else {
                (new StatusService())->transitar($id, $para, $_POST, auth());
                session_flash('success', 'Status alterado para "' . status_label($para) . '".');
            }
        } catch (ValidacaoException $e) {
            session_flash('error', 'Não foi possível alterar o status: ' . implode(' ', $e->erros));
        } catch (RegraException $e) {
            session_flash('error', $e->getMessage());
        }
        redirect('/ofps/' . $id);
    }

    public function corrigir(string $id): void
    {
        try {
            $n = (new CorrecaoService())->corrigir($id, $_POST, auth());
            session_flash('success', "{$n} campo(s) corrigido(s). As mudanças estão no histórico.");
        } catch (ValidacaoException $e) {
            session_flash('error', 'Correção não salva: ' . implode(' ', $e->erros));
        } catch (RegraException $e) {
            session_flash('error', $e->getMessage());
        }
        redirect('/ofps/' . $id);
    }

    public function pendencias(): void
    {
        $this->view('ofp/pendencias', [
            'title' => 'Minhas pendências',
            'ofps'  => (new Ofp())->pendencias(StatusService::statusOndeAtua(auth()['perfil'])),
        ]);
    }
}
