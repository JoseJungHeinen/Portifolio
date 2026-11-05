<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Causal;
use App\Models\Motivo;
use App\Models\Unidade;
use App\Services\StatusService;
use PDOException;

class CadastroController extends Controller
{
    public function causais(): void
    {
        $this->view('cadastros/causais', ['title' => 'Causais', 'causais' => (new Causal())->todos()]);
    }

    public function novoCausal(): void
    {
        $this->view('cadastros/causal_form', ['title' => 'Novo causal', 'causal' => null]);
    }

    public function criarCausal(): void
    {
        $codigo = strtoupper(trim((string)$this->input('codigo', '')));
        if (!preg_match('/^[A-Z]{2,4}$/', $codigo)) {
            session_flash('error', 'Código deve ter de 2 a 4 letras.');
            redirect('/cadastros/causais/novo');
        }
        if ((new Causal())->findByCodigo($codigo)) {
            session_flash('error', "Causal {$codigo} já existe.");
            redirect('/cadastros/causais/novo');
        }
        $dados = $this->dadosCausal('/cadastros/causais/novo');
        try {
            (new Causal())->create(['codigo' => $codigo] + $dados);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            session_flash('error', "Causal {$codigo} já existe.");
            redirect('/cadastros/causais/novo');
        }
        session_flash('success', "Causal {$codigo} criado.");
        redirect('/cadastros/causais');
    }

    public function editarCausal(string $id): void
    {
        $causal = (new Causal())->find($id) ?? $this->abort(404);
        $this->view('cadastros/causal_form', ['title' => 'Causal ' . $causal['codigo'], 'causal' => $causal]);
    }

    public function salvarCausal(string $id): void
    {
        $causal = (new Causal())->find($id) ?? $this->abort(404);
        (new Causal())->update($id, $this->dadosCausal('/cadastros/causais/' . $id));
        session_flash('success', 'Causal ' . $causal['codigo'] . ' atualizado. Novas OFPs já usam a regra; OFPs existentes recalculam o prazo na próxima alteração.');
        redirect('/cadastros/causais');
    }

    private function dadosCausal(string $voltar): array
    {
        $descricao = trim((string)$this->input('descricao', ''));
        $prazo = trim((string)$this->input('prazo_abertura_dias', ''));
        $minimo = trim((string)$this->input('valor_minimo', ''));
        if ($descricao === '' || ($prazo !== '' && !ctype_digit($prazo))) {
            session_flash('error', 'Informe a descrição e o prazo em dias (em branco = sem prazo).');
            redirect($voltar);
        }
        if ($prazo !== '' && (strlen($prazo) > 3 || (int)$prazo > 365)) {
            session_flash('error', 'O prazo máximo é de 365 dias.');
            redirect($voltar);
        }
        if ($minimo !== '' && (
            !preg_match('/^(\d{1,3}(\.\d{3})*|\d+)(,\d{1,2})?$|^\d+\.\d{1,2}$/', $minimo)
            || parse_decimal($minimo) < 0 || parse_decimal($minimo) > 9999999999.99
        )) {
            session_flash('error', 'Valor mínimo inválido. Use o formato 1.234,56.');
            redirect($voltar);
        }
        if (mb_strlen($descricao) > 150) {
            session_flash('error', 'A descrição pode ter no máximo 150 caracteres.');
            redirect($voltar);
        }
        $transportadora = (string)$this->input('transportadora', '');
        return [
            'descricao'           => $descricao,
            'tipo_devolucao'      => $this->input('tipo_devolucao') === 'simbolica' ? 'simbolica' : 'fisica',
            'prazo_abertura_dias' => $prazo === '' ? null : (int)$prazo,
            'fotos_obrigatorias'  => trim((string)$this->input('fotos_obrigatorias', '')) ?: null,
            'valor_minimo'        => $minimo === '' ? null : parse_decimal($minimo),
            'frete_por'           => $this->input('frete_por') === 'dealer' ? 'dealer' : 'fabricante',
            'exige_tp41'          => $this->input('exige_tp41') ? 1 : 0,
            'transportadora'      => in_array($transportadora, ['azul', 'jat'], true) ? $transportadora : null,
            'ativo'               => $this->input('ativo') ? 1 : 0,
        ];
    }

    public function unidades(): void
    {
        $this->view('cadastros/unidades', ['title' => 'Unidades', 'unidades' => (new Unidade())->todas()]);
    }

    public function editarUnidade(string $id): void
    {
        $unidade = (new Unidade())->find($id) ?? $this->abort(404);
        $this->view('cadastros/unidade_form', ['title' => $unidade['nome'], 'unidade' => $unidade]);
    }

    public function salvarUnidade(string $id): void
    {
        (new Unidade())->find($id) ?? $this->abort(404);
        $nome = trim((string)$this->input('nome', ''));
        $uf = strtoupper(trim((string)$this->input('uf', '')));
        $dealer = trim((string)$this->input('codigo_dealer', ''));
        if ($nome === '' || !preg_match('/^[A-Z]{2}$/', $uf)) {
            session_flash('error', 'Informe o nome e a UF (2 letras).');
            redirect('/cadastros/unidades/' . $id);
        }
        if (mb_strlen($nome) > 80 || mb_strlen($dealer) > 20) {
            session_flash('error', 'Nome: no máximo 80 caracteres; código dealer: no máximo 20.');
            redirect('/cadastros/unidades/' . $id);
        }
        try {
            (new Unidade())->update($id, [
                'nome' => $nome, 'uf' => $uf,
                'codigo_dealer' => $dealer ?: null,
                'ativo' => $this->input('ativo') ? 1 : 0,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            session_flash('error', 'Já existe uma unidade com esse nome.');
            redirect('/cadastros/unidades/' . $id);
        }
        session_flash('success', 'Unidade atualizada.');
        redirect('/cadastros/unidades');
    }

    public function motivos(): void
    {
        $this->view('cadastros/motivos', ['title' => 'Motivos', 'motivos' => (new Motivo())->todos()]);
    }

    public function novoMotivo(): void
    {
        $this->view('cadastros/motivo_form', ['title' => 'Novo motivo', 'motivo' => null]);
    }

    public function criarMotivo(): void
    {
        $codigo = strtoupper(trim((string)$this->input('codigo', '')));
        if (!preg_match('/^[A-Z_]{2,30}$/', $codigo)) {
            session_flash('error', 'Código com 2 a 30 letras maiúsculas ou "_".');
            redirect('/cadastros/motivos/novo');
        }
        if ((new Motivo())->findByCodigo($codigo)) {
            session_flash('error', "Motivo {$codigo} já existe.");
            redirect('/cadastros/motivos/novo');
        }
        $dados = $this->dadosMotivo('/cadastros/motivos/novo');
        try {
            (new Motivo())->create(['codigo' => $codigo] + $dados);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            session_flash('error', "Motivo {$codigo} já existe.");
            redirect('/cadastros/motivos/novo');
        }
        session_flash('success', "Motivo {$codigo} criado.");
        redirect('/cadastros/motivos');
    }

    public function editarMotivo(string $id): void
    {
        $motivo = (new Motivo())->find($id) ?? $this->abort(404);
        $this->view('cadastros/motivo_form', ['title' => 'Motivo ' . $motivo['codigo'], 'motivo' => $motivo]);
    }

    public function salvarMotivo(string $id): void
    {
        (new Motivo())->find($id) ?? $this->abort(404);
        $dados = $this->dadosMotivo('/cadastros/motivos/' . $id);
        $antes = (new Motivo())->todos();
        $depois = array_map(fn($m) => $m['id'] === $id ? $dados + $m : $m, $antes);
        $novos = array_diff(Motivo::statusSemMotivoAtivo($depois), Motivo::statusSemMotivoAtivo($antes));
        if ($novos) {
            session_flash('error', 'Cada status precisa de ao menos um motivo ativo: ' . implode(', ', array_map('status_label', $novos)) . '.');
            redirect('/cadastros/motivos/' . $id);
        }
        (new Motivo())->update($id, $dados);
        session_flash('success', 'Motivo atualizado.');
        redirect('/cadastros/motivos');
    }

    private function dadosMotivo(string $voltar): array
    {
        $descricao = trim((string)$this->input('descricao', ''));
        $aplica = array_values(array_intersect(
            StatusService::EXIGEM_MOTIVO,
            array_filter((array)($_POST['aplica_a'] ?? []), 'is_string')
        ));
        if ($descricao === '' || mb_strlen($descricao) > 150 || !$aplica) {
            session_flash('error', 'Informe a descrição (até 150 caracteres) e pelo menos uma situação.');
            redirect($voltar);
        }
        return [
            'descricao'        => $descricao,
            'aplica_a'         => implode(',', $aplica),
            'exige_observacao' => $this->input('exige_observacao') ? 1 : 0,
            'ordem'            => trim((string)$this->input('ordem', '')) === '' ? 100 : max(0, min(9999, (int)$this->input('ordem'))),
            'ativo'            => $this->input('ativo') ? 1 : 0,
        ];
    }
}
