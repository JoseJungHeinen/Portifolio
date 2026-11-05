<?php
// @versao Ver.003.291026

namespace App\Controllers\Concerns;

use App\Services\EscopoGestorService;

/**
 * Regras comuns dos relatórios: filiais visíveis, permissão e período do filtro.
 * Usar em classes que estendem App\Core\Controller.
 */
trait EscopoRelatorio
{
    /**
     * Filiais visíveis no relatório.
     *   null  → todas (diretor/admin sem filtro)
     *   []    → nenhuma (gestor sem vínculo)
     *   [ids] → as unidades do escopo, ou a filtrada pelo diretor/admin
     *
     * @return array<int, string>|null
     */
    protected function filiaisRelatorio(): ?array
    {
        if (ve_todas_filiais()) {
            // M7: ?filial_id[]=x chega como array — tratado como ausente, não como erro.
            $selecionada = $_GET['filial_id'] ?? null;
            $selecionada = is_string($selecionada) ? $selecionada : null;
            return $selecionada ? [$selecionada] : null;
        }

        if (can('gestor')) {
            return (new EscopoGestorService())->filiaisDoUsuario(auth()['id']);
        }

        $filial = auth()['filial_id'] ?? null;
        return $filial ? [$filial] : [];
    }

    /**
     * Escopo efetivo de filiais + opções do filtro de filial da tela.
     *   diretor/admin: todas as filiais no filtro (filiaisRelatorio já aplica ?filial_id)
     *   gestor com mais de uma unidade: só as dele no filtro; ?filial_id estreita dentro do escopo
     *   demais: sem filtro (escopo fixo)
     *
     * @return array{0: array<int, string>|null, 1: list<array{id: string, nome: string}>}
     */
    protected function escopoEFiltroFiliais(\PDO $db): array
    {
        $escopo      = $this->filiaisRelatorio();
        $selecionada = $_GET['filial_id'] ?? null;
        $selecionada = is_string($selecionada) && $selecionada !== '' ? $selecionada : null;
        $todas       = $db->query("SELECT id, nome FROM filiais WHERE ativo = 1 ORDER BY nome")->fetchAll(\PDO::FETCH_ASSOC);

        if (ve_todas_filiais()) {
            return [$escopo, $todas];
        }
        if (is_array($escopo) && count($escopo) > 1) {
            $opcoes = array_values(array_filter($todas, fn($f) => in_array($f['id'], $escopo, true)));
            return [$selecionada && in_array($selecionada, $escopo, true) ? [$selecionada] : $escopo, $opcoes];
        }
        return [$escopo, []];
    }

    /**
     * Exige acesso ao módulo "relatorios" E ao relatório específico (chave
     * composta "relatorios.<chave>"). Mesmo comportamento de 403 que
     * Controller::requirePerfil() já usa.
     */
    protected function requirePermissaoRelatorio(string $chave): void
    {
        $this->requireAuth();
        if (!podeModulo('relatorios') || !podeModulo("relatorios.$chave")) {
            http_response_code(403);
            $this->view('errors/403', [], 'main');
            exit;
        }
    }

    /**
     * Lê De/Até (Y-m-d) do GET. Data inválida volta ao padrão; De > Até troca as duas.
     *
     * @return array{0: string, 1: string}
     */
    protected function periodoDoFiltro(string $padraoDe, string $padraoAte,
                                       string $campoDe = 'data_inicio', string $campoAte = 'data_fim'): array
    {
        $valida = function ($v): bool {
            return is_string($v)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1
                && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4));
        };
        $de  = $valida($_GET[$campoDe] ?? null)  ? $_GET[$campoDe]  : $padraoDe;
        $ate = $valida($_GET[$campoAte] ?? null) ? $_GET[$campoAte] : $padraoAte;
        return $de > $ate ? [$ate, $de] : [$de, $ate];
    }
}
