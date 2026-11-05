<?php
// @versao Ver.001.140926

namespace App\Controllers;

use App\Core\Controller;
use App\Config\Database;

/**
 * Ramais: exibição somente-leitura, espelho das telas ramais_uteis_servidor.php
 * e ramais_uteis_impressao.php do Veiculos.
 *
 * A fonte é o banco do Veiculos, não o do compras. A migration 012 tinha feito
 * uma cópia das tabelas para cá, mas sem tela de manutenção ela congelou em
 * maio/2026 e divergiu da lista real. Lendo direto, o que for salvo no
 * config_ramais.php do Veiculos aparece aqui na hora.
 */
class RamaisController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        [$ramaisPorCidade, $erro] = $this->carregar();
        $this->view('ramais/index', compact('ramaisPorCidade', 'erro'));
    }

    public function imprimir(): void
    {
        $this->requireAuth();
        [$ramaisPorCidade, $erro] = $this->carregar();
        $this->view('ramais/imprimir', compact('ramaisPorCidade', 'erro'), '');
    }

    /**
     * Agrupa por id da unidade, e não pelo nome como faz o Veiculos: o
     * config_ramais de lá garante nome único, mas agrupar por id não depende
     * dessa garantia e custa o mesmo.
     *
     * @return array{0: array<int, array{nome: string, telefone: ?string, itens: array}>, 1: ?string}
     */
    private function carregar(): array
    {
        try {
            $linhas = Database::veiculos()->query(
                "SELECT c.id AS id_cidade, c.nome AS cidade, c.telefone, r.setor, r.ramal
                   FROM ramais r
                   JOIN ramais_cidades c ON c.id = r.id_cidade
                  WHERE r.ativo = 1
                  ORDER BY c.ordem ASC, c.nome ASC, r.ordem ASC, r.id ASC"
            )->fetchAll();
        } catch (\Throwable $e) {
            // Banco de outro sistema: falha dele não pode derrubar a página.
            error_log('[ramais] ' . $e->getMessage());
            return [[], 'Não foi possível carregar os ramais agora — o banco do Veiculos não respondeu. Tente novamente em instantes.'];
        }

        $porCidade = [];
        foreach ($linhas as $r) {
            $id = (int)$r['id_cidade'];
            if (!isset($porCidade[$id])) {
                $porCidade[$id] = [
                    'nome'     => $r['cidade'],
                    'telefone' => $r['telefone'],
                    'itens'    => [],
                ];
            }
            $porCidade[$id]['itens'][] = ['setor' => $r['setor'], 'ramal' => $r['ramal']];
        }

        return [$porCidade, null];
    }
}
