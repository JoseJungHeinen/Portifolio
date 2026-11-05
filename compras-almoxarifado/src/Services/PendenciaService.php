<?php
// @versao Ver.003.061026

namespace App\Services;

use App\Config\Database;
use App\Models\Aprovacao;

/**
 * Coleta as tarefas pendentes do sistema, já filtradas pelo corte de antiguidade
 * e agrupadas por responsável.
 *
 * Não sabe nada sobre e-mail: quem envia é o ComunicadoPendenciaService. Assim
 * esta classe também serve, mais tarde, para um painel "minhas pendências" na
 * interface.
 */
class PendenciaService
{
    /** Perfis que podem ter aprovação na fila. */
    public const PERFIS_APROVADORES = ['almoxarife', 'comprador', 'gestor', 'diretor', 'admin'];

    private \PDO $db;
    private \DateTimeImmutable $hoje;
    private \DateTimeImmutable $corte;

    public function __construct(?\DateTimeImmutable $hoje = null)
    {
        $this->db    = Database::getInstance();
        $this->hoje  = ($hoje ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $this->corte = self::corteDiaUtil($this->hoje);
    }

    /**
     * Meia-noite do dia útil anterior ao informado. Uma pendência só entra no
     * comunicado se nasceu ANTES desse instante — o que garante um dia útil
     * inteiro de folga, independente da hora em que ela nasceu.
     *
     * Sábado e domingo são pulados. Feriados não são tratados: o sistema não tem
     * calendário de feriados, e o efeito é apenas cobrar um dia antes do ideal
     * na volta de um.
     */
    public static function corteDiaUtil(\DateTimeImmutable $hoje): \DateTimeImmutable
    {
        $d = $hoje->setTime(0, 0)->modify('-1 day');
        while ((int)$d->format('N') >= 6) {  // 6 = sábado, 7 = domingo
            $d = $d->modify('-1 day');
        }
        return $d;
    }

    public function corte(): \DateTimeImmutable
    {
        return $this->corte;
    }

    /**
     * Formato normalizado consumido pelo template de e-mail.
     *
     * $chave identifica a pendência em si, independente de quem responde por
     * ela. É o que permite ao consolidado listar cada item UMA vez: a mesma
     * requisição aparece na fila de todos os aprovadores daquele nível, e sem a
     * chave o e-mail de gestão a repetiria uma vez por aprovador.
     */
    private function item(string $chave, string $tipo, string $titulo, string $detalhe, string $url, string $desde): array
    {
        $nasceu = (new \DateTimeImmutable($desde))->setTime(0, 0);

        return [
            'chave'       => $chave,
            'tipo'        => $tipo,
            'titulo'      => $titulo,
            'detalhe'     => $detalhe,
            'url'         => $url,
            'desde'       => $desde,
            'dias_parado' => (int)$nasceu->diff($this->hoje)->days,
        ];
    }

    /**
     * Aprovações paradas com este usuário.
     *
     * Reusa Aprovacao::pendentesParaUsuario(), que já sabe quem vê o quê —
     * inclusive o escopo por gestores_setor. Reimplementar a regra aqui criaria
     * uma segunda cópia que divergiria na primeira mudança. O método recebe
     * usuário e perfil por parâmetro e não toca em auth()/$_SESSION, então
     * funciona no CLI.
     */
    public function aprovacoesDe(array $usuario): array
    {
        $corteSql  = $this->corte->format('Y-m-d H:i:s');
        $pendentes = (new Aprovacao())->pendentesParaUsuario($usuario['id'], $usuario['perfil']);

        // Admin/diretor VEEM toda a fila na tela (poder de override), mas só são
        // cobrados pelo que é deles: o que foi atribuído a eles ou o nível que a
        // alçada do perfil cobre. Sem isso, todo admin recebia todas as pendências.
        $niveisDoPerfil = null;
        if (in_array($usuario['perfil'], ['admin', 'diretor'], true)) {
            $niveisDoPerfil = array_map('intval', array_column($this->varios(
                "SELECT DISTINCT nivel FROM alcadas WHERE perfil = ? AND ativo = 1",
                [$usuario['perfil']]
            ), 'nivel'));
        }

        $itens = [];
        foreach ($pendentes as $p) {
            if ($p['criado_em'] >= $corteSql) {
                continue;  // nova demais para cobrar
            }

            if ($niveisDoPerfil !== null
                && ($p['atribuido_para'] ?? null) !== $usuario['id']
                && !in_array((int)$p['nivel'], $niveisDoPerfil, true)) {
                continue;  // visível por override, mas o responsável é outro
            }

            $desc = $this->descreverAprovacao($p);
            if ($desc === null) {
                continue;  // entidade apagada ou tipo sem descrição
            }

            $itens[] = $this->item('aprovacao:' . $p['entidade_tipo'] . ':' . $p['entidade_id'], 'aprovacao', $desc['titulo'], $desc['detalhe'], url('aprovacoes'), $p['criado_em']);
        }

        return $itens;
    }

    /** @return array{titulo: string, detalhe: string}|null */
    private function descreverAprovacao(array $aprov): ?array
    {
        if ($aprov['entidade_tipo'] === 'requisicao') {
            $r = $this->um(
                "SELECT r.numero, r.criado_em, r.tipo, r.valor_total,
                        f.nome AS filial, s.nome AS setor, u.nome AS solicitante
                 FROM requisicoes r
                 JOIN filiais  f ON f.id = r.filial_id
                 JOIN setores  s ON s.id = r.setor_id
                 JOIN usuarios u ON u.id = r.usuario_id
                 WHERE r.id = ?",
                [$aprov['entidade_id']]
            );
            if (!$r) return null;

            return [
                'titulo'  => formatar_numero_requisicao($r['tipo'], $r['criado_em'], (int)$r['numero']),
                'detalhe' => "{$r['filial']} · {$r['setor']} · {$r['solicitante']} · " . money($r['valor_total']),
            ];
        }

        if ($aprov['entidade_tipo'] === 'ordem_compra') {
            $oc = $this->um(
                "SELECT oc.numero_oc, oc.valor_total, fo.razao_social AS fornecedor
                 FROM ordens_compra oc
                 LEFT JOIN fornecedores fo ON fo.id = oc.fornecedor_id
                 WHERE oc.id = ?",
                [$aprov['entidade_id']]
            );
            if (!$oc) return null;

            return [
                'titulo'  => 'OC ' . $oc['numero_oc'],
                'detalhe' => ($oc['fornecedor'] ?? 'Fornecedor não informado') . ' · ' . money($oc['valor_total']),
            ];
        }

        if ($aprov['entidade_tipo'] === 'requisicao_brinde') {
            $rb = $this->um(
                "SELECT rb.numero, rb.valor_total, f.nome AS filial
                 FROM requisicoes_brinde rb
                 JOIN filiais f ON f.id = rb.filial_id
                 WHERE rb.id = ?",
                [$aprov['entidade_id']]
            );
            if (!$rb) return null;

            return [
                'titulo'  => 'Brinde #' . $rb['numero'],
                'detalhe' => $rb['filial'] . ' · ' . money($rb['valor_total']),
            ];
        }

        return null;
    }

    /** Cotações abertas cujo fornecedor ainda não respondeu. */
    public function cotacoesDe(string $usuarioId): array
    {
        $linhas = $this->varios(
            "SELECT c.id, c.criado_em, c.prazo_resposta,
                    r.numero, r.criado_em AS req_criado_em, r.tipo AS req_tipo,
                    COUNT(cf.id) AS aguardando
             FROM cotacoes c
             JOIN requisicoes r            ON r.id  = c.requisicao_id
             JOIN cotacao_fornecedores cf  ON cf.cotacao_id = c.id AND cf.status = 'enviada'
             WHERE c.criado_por = ?
               AND c.status IN ('aberta', 'aguardando_respostas')
               AND c.criado_em < ?
             GROUP BY c.id, c.criado_em, c.prazo_resposta, r.numero, r.criado_em, r.tipo
             ORDER BY c.criado_em",
            [$usuarioId, $this->corte->format('Y-m-d H:i:s')]
        );

        $itens = [];
        foreach ($linhas as $l) {
            $req      = formatar_numero_requisicao($l['req_tipo'], $l['req_criado_em'], (int)$l['numero']);
            $prazo    = new \DateTimeImmutable($l['prazo_resposta']);
            $vencido  = $prazo < $this->hoje ? ' · PRAZO VENCIDO' : '';
            $detalhe  = "{$l['aguardando']} fornecedor(es) sem resposta · prazo "
                      . $prazo->format('d/m/Y') . $vencido;

            $itens[] = $this->item('cotacao:' . $l['id'], 'cotacao', 'Cotação da ' . $req, $detalhe, url('compras/cotacoes'), $l['criado_em']);
        }

        return $itens;
    }

    /** OCs enviadas sem recebimento fechado, e notas fiscais divergentes. */
    public function recebimentosDe(string $usuarioId): array
    {
        $corteSql = $this->corte->format('Y-m-d H:i:s');

        $ocs = $this->varios(
            "SELECT oc.id, oc.numero_oc, oc.status, oc.valor_total, oc.criado_em,
                    fo.razao_social AS fornecedor
             FROM ordens_compra oc
             LEFT JOIN fornecedores fo ON fo.id = oc.fornecedor_id
             WHERE oc.criado_por = ?
               AND oc.status IN ('enviado', 'recebido_parcial')
               AND oc.criado_em < ?
             ORDER BY oc.criado_em",
            [$usuarioId, $corteSql]
        );

        $itens = [];
        foreach ($ocs as $oc) {
            $rotulo = $oc['status'] === 'recebido_parcial' ? 'recebimento parcial' : 'aguardando recebimento';
            $itens[] = $this->item(
                'recebimento:oc:' . $oc['id'],
                'recebimento',
                'OC ' . $oc['numero_oc'],
                ($oc['fornecedor'] ?? 'Fornecedor não informado') . ' · ' . money($oc['valor_total']) . ' · ' . $rotulo,
                url('fiscal'),
                $oc['criado_em']
            );
        }

        $nfs = $this->varios(
            "SELECT nf.id, nf.numero_nf, nf.valor_total, nf.criado_em, oc.numero_oc
             FROM notas_fiscais nf
             JOIN ordens_compra oc ON oc.id = nf.oc_id
             WHERE oc.criado_por = ?
               AND nf.status = 'divergente'
               AND nf.criado_em < ?
             ORDER BY nf.criado_em",
            [$usuarioId, $corteSql]
        );

        foreach ($nfs as $nf) {
            $itens[] = $this->item(
                'recebimento:nf:' . $nf['id'],
                'recebimento',
                'NF ' . $nf['numero_nf'] . ' divergente',
                'OC ' . $nf['numero_oc'] . ' · ' . money($nf['valor_total']),
                url('fiscal'),
                $nf['criado_em']
            );
        }

        return $itens;
    }

    /**
     * Contratos ativos dentro da janela de alerta.
     *
     * Não têm responsável: a tabela contratos não tem usuario_id nem filial_id.
     * Por isso só aparecem no consolidado de admin/diretor — ver a spec.
     *
     * O corte de 1 dia útil não se aplica aqui: a pendência não "nasceu" num
     * instante, ela é uma data que se aproxima.
     */
    public function contratosVencendo(): array
    {
        $linhas = $this->varios(
            "SELECT c.id, c.numero, c.objeto, c.data_fim, c.valor_mensal, c.criado_em,
                    fo.razao_social AS fornecedor
             FROM contratos c
             LEFT JOIN fornecedores fo ON fo.id = c.fornecedor_id
             WHERE c.status = 'ativo'
               AND c.data_fim <= DATE_ADD(CURDATE(), INTERVAL c.alerta_antecipacao_dias DAY)
             ORDER BY c.data_fim"
        );

        $itens = [];
        foreach ($linhas as $l) {
            $fim  = new \DateTimeImmutable($l['data_fim']);
            $dias = (int)$this->hoje->diff($fim)->format('%r%a');
            $qdo  = $dias < 0 ? 'venceu há ' . abs($dias) . ' dia(s)' : 'vence em ' . $dias . ' dia(s)';

            $itens[] = [
                'chave'       => 'contrato:' . $l['id'],
                'tipo'        => 'contrato',
                'titulo'      => 'Contrato ' . ($l['numero'] ?: 'sem número'),
                'detalhe'     => ($l['fornecedor'] ?? 'Fornecedor não informado') . ' · ' . $qdo
                                 . ' (' . $fim->format('d/m/Y') . ')',
                'url'         => url('contratos'),
                'desde'       => $l['data_fim'],
                'dias_parado' => max(0, -$dias),
            ];
        }

        return $itens;
    }

    /**
     * Pendências agrupadas por responsável.
     *
     * @return array<int, array{usuario: array, itens: array}> só usuários COM pendência
     */
    public function porResponsavel(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PERFIS_APROVADORES), '?'));

        $usuarios = $this->varios(
            "SELECT id, nome, email, perfil
             FROM usuarios
             WHERE ativo = 1 AND perfil IN ({$placeholders})
             ORDER BY nome",
            self::PERFIS_APROVADORES
        );

        $resultado = [];
        foreach ($usuarios as $u) {
            $itens = array_merge(
                $this->aprovacoesDe($u),
                $this->cotacoesDe($u['id']),
                $this->recebimentosDe($u['id'])
            );

            if ($itens === []) {
                continue;  // quem não tem pendência não recebe nada
            }

            usort($itens, fn($a, $b) => $b['dias_parado'] <=> $a['dias_parado']);
            $resultado[] = ['usuario' => $u, 'itens' => $itens];
        }

        return $resultado;
    }

    /**
     * Tudo que está parado no sistema, para o e-mail de admin/diretor: a união
     * das pendências com dono (marcadas com o nome de quem responde) mais os
     * contratos, que não têm dono.
     */
    public function consolidado(array $porResponsavel): array
    {
        // Deduplica por chave: a mesma requisição está na fila de TODOS os
        // aprovadores daquele nível, e sem isso o consolidado a repetiria uma vez
        // por aprovador. Os nomes de quem responde por ela viram uma lista só.
        $porChave = [];
        foreach ($porResponsavel as $bloco) {
            foreach ($bloco['itens'] as $item) {
                $chave = $item['chave'];
                if (!isset($porChave[$chave])) {
                    $porChave[$chave] = ['item' => $item, 'nomes' => []];
                }
                $porChave[$chave]['nomes'][] = $bloco['usuario']['nome'];
            }
        }

        $itens = [];
        foreach ($porChave as $registro) {
            $item = $registro['item'];
            $item['detalhe'] .= ' · com ' . $this->listarNomes($registro['nomes']);
            $itens[] = $item;
        }

        $itens = array_merge($itens, $this->contratosVencendo());
        usort($itens, fn($a, $b) => $b['dias_parado'] <=> $a['dias_parado']);

        return $itens;
    }

    /**
     * "Fulano", "Fulano e Beltrano", "Fulano, Beltrano e mais 3".
     * Uma pendência de alçada alta pode estar na fila de muita gente; listar
     * todos os nomes tornaria a linha ilegível.
     */
    private function listarNomes(array $nomes): string
    {
        $nomes = array_values(array_unique($nomes));
        $total = count($nomes);

        if ($total === 1) return $nomes[0];
        if ($total === 2) return $nomes[0] . ' e ' . $nomes[1];

        return $nomes[0] . ', ' . $nomes[1] . ' e mais ' . ($total - 2);
    }

    /** Admin e diretor ativos, com e-mail — destinatários do consolidado. */
    public function destinatariosConsolidado(): array
    {
        return $this->varios(
            "SELECT id, nome, email, perfil
             FROM usuarios
             WHERE ativo = 1 AND perfil IN ('admin','diretor') AND email <> ''
             ORDER BY nome"
        );
    }

    private function um(string $sql, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function varios(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
