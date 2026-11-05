<?php
// @versao Ver.005.301026

namespace App\Services;

use App\Config\Database;

/**
 * Regras da Solicitação de Compra (chamado aberto pelo solicitante).
 * Spec: docs/superpowers/specs/2026-10-27-solicitacao-compra-design.md
 *
 * Erros de validação do usuário saem como \InvalidArgumentException e
 * transição de status inválida como \DomainException — o controller mostra
 * a mensagem de ambos em flash.
 */
class SolicitacaoCompraService
{
    public const STATUS_LABEL = [
        'aguardando_aprovacao' => 'Aguardando aprovação',
        'aprovada'             => 'Aprovada',
        'recusada'             => 'Recusada',
        'em_compra'            => 'Em compra',
        'concluida'            => 'Concluída',
        'cancelada'            => 'Cancelada',
    ];

    public const STATUS_BADGE = [
        // app.css pinta todo badge com texto branco e bg-primary = vermelho da marca
        'aguardando_aprovacao' => 'warning',
        'aprovada'             => 'success',
        'recusada'             => 'danger',
        'em_compra'            => 'info',
        'concluida'            => 'dark',
        'cancelada'            => 'secondary',
    ];

    /** de => [para permitidos]. Status fora daqui são finais. */
    private const TRANSICOES = [
        'aguardando_aprovacao' => ['aprovada', 'recusada', 'cancelada'],
        'aprovada'             => ['em_compra'],
        'em_compra'            => ['concluida'],
    ];

    public const MAX_ITENS = 30;

    /** Perfis que podem abrir solicitação em nome de outra pessoa. */
    public const PERFIS_EM_NOME_DE = ['gestor', 'comprador', 'diretor', 'admin'];
    /** Perfis que escolhem unidade/setor da SOL (em vez de herdar do solicitante). */
    public const PERFIS_DEFINEM_UNIDADE = ['comprador', 'admin'];

    public const RECORRENCIAS = [
        'unica'  => 'Compra única',
        'mensal' => 'Contrato / serviço mensal',
    ];

    /** SLA em dias úteis por urgência (seg–sex, sem feriados). Azul ausente = sem SLA. */
    public const SLA_DIAS_UTEIS = ['emergencial' => 1, 'urgente' => 2, 'planejado' => 5];

    /** Urgência por cor (ordem = prioridade). */
    public const URGENCIAS = [
        'emergencial' => ['rotulo' => 'Emergencial', 'cor' => 'Vermelho', 'hex' => '#dc3545', 'ajuda' => 'Operação parada ou risco imediato'],
        'urgente'     => ['rotulo' => 'Urgente',     'cor' => 'Laranja',  'hex' => '#fd7e14', 'ajuda' => 'Priorizar nos próximos dias'],
        'planejado'   => ['rotulo' => 'Planejado',   'cor' => 'Verde',    'hex' => '#198754', 'ajuda' => 'Compra normal, no prazo pedido'],
        'estrategico' => ['rotulo' => 'Estratégico', 'cor' => 'Azul',     'hex' => '#0d6efd', 'ajuda' => 'Médio prazo / investimento, sem pressa'],
    ];
    public const URGENCIA_PADRAO = 'planejado';
    private const STATUS_FINAIS  = ['recusada', 'cancelada', 'concluida'];

    /** Política COMP-001 §2.1 — compras que não abrem RC (fluxo e controles próprios). */
    public const EXCECOES_POLITICA = [
        'Veículos novos',
        'Veículos seminovos',
        'Contabilidade, fiscal, tributos, taxas, folha de pagamento, encargos e despesas bancárias',
        'Adiantamentos de viagem e reembolsos',
        'Imóveis',
    ];

    public const MAX_FOTOS = 5;
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const EXTENSOES = ['jpg', 'jpeg', 'png', 'webp'];

    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function numeroFormatado(array $sol): string
    {
        return 'SOL-' . date('Y', strtotime($sol['criado_em'])) . '-'
            . str_pad((string)$sol['numero'], 5, '0', STR_PAD_LEFT);
    }

    /** <span> colorido da urgência (HTML escapado). */
    public static function seloUrgencia(?string $urgencia): string
    {
        $u = self::URGENCIAS[$urgencia ?? ''] ?? self::URGENCIAS[self::URGENCIA_PADRAO];
        return '<span class="badge" style="background:' . $u['hex'] . '" title="' . e($u['cor']) . '">'
             . e($u['rotulo']) . '</span>';
    }

    /** Abertura + N dias úteis (seg–sex, sem feriados), mesma hora; fim de semana começa na segunda 00:00. */
    public static function vencimentoSla(\DateTimeImmutable $abertura, ?string $urgencia): ?\DateTimeImmutable
    {
        $dias = self::SLA_DIAS_UTEIS[$urgencia ?? ''] ?? null;
        if ($dias === null) return null;
        $d = $abertura;
        if ((int)$d->format('N') >= 6) {
            $d = $d->modify('next monday')->setTime(0, 0);
        }
        while ($dias > 0) {
            $d = $d->modify('+1 day');
            if ((int)$d->format('N') < 6) $dias--;
        }
        return $d;
    }

    /** Dias úteis inteiros entre duas datas (para "atrasada N dias" e o Painel de compras). */
    public static function diasUteisEntre(\DateTimeImmutable $de, \DateTimeImmutable $ate): int
    {
        $n = 0;
        for ($d = $de->modify('+1 day'); $d <= $ate; $d = $d->modify('+1 day')) {
            if ((int)$d->format('N') < 6) $n++;
        }
        return $n;
    }

    /**
     * Situação do SLA para exibir. null = não se aplica (cancelada/recusada).
     * @return array{chave:string, texto:string, classe:string, dias?:int}|null
     */
    public static function situacaoSla(array $sol, ?\DateTimeImmutable $agora = null): ?array
    {
        if (in_array($sol['status'] ?? '', ['cancelada', 'recusada'], true)) return null;
        if (empty($sol['sla_vence_em'])) return ['chave' => 'sem_sla', 'texto' => 'Sem SLA', 'classe' => 'text-muted'];

        $vence = new \DateTimeImmutable($sol['sla_vence_em']);
        if (($sol['status'] ?? '') === 'concluida' && !empty($sol['concluida_em'])) {
            return new \DateTimeImmutable($sol['concluida_em']) <= $vence
                ? ['chave' => 'concluida_no_prazo', 'texto' => 'Concluída no prazo', 'classe' => 'text-success']
                : ['chave' => 'concluida_fora',     'texto' => 'Concluída fora do prazo', 'classe' => 'text-danger'];
        }
        $agora ??= new \DateTimeImmutable();
        if ($agora > $vence) {
            $dias = max(1, self::diasUteisEntre($vence, $agora));
            return ['chave' => 'atrasada', 'texto' => 'Atrasada ' . $dias . ($dias === 1 ? ' dia útil' : ' dias úteis'),
                    'classe' => 'text-danger fw-semibold', 'dias' => $dias];
        }
        if ($vence->format('Y-m-d') === $agora->format('Y-m-d')) {
            return ['chave' => 'vence_hoje', 'texto' => 'Vence hoje ' . $vence->format('H:i'), 'classe' => 'text-warning-emphasis fw-semibold'];
        }
        return ['chave' => 'no_prazo', 'texto' => 'Vence ' . $vence->format('d/m H:i'), 'classe' => 'text-muted'];
    }

    /** Preenche sla_vence_em das SOLs existentes sem vencimento (exceto Azul). @return int linhas atualizadas */
    public function recalcularSlaPendentes(): int
    {
        $rows = $this->db->query(
            "SELECT id, urgencia, criado_em FROM solicitacoes_compra
             WHERE sla_vence_em IS NULL AND urgencia <> 'estrategico'"
        )->fetchAll();
        $upd = $this->db->prepare('UPDATE solicitacoes_compra SET sla_vence_em = ? WHERE id = ?');
        $n = 0;
        foreach ($rows as $r) {
            $v = self::vencimentoSla(new \DateTimeImmutable($r['criado_em']), $r['urgencia']);
            if ($v === null) continue;
            $upd->execute([$v->format('Y-m-d H:i:s'), $r['id']]);
            $n++;
        }
        return $n;
    }

    public static function diretorioAnexos(): string
    {
        return ROOT . '/storage/solicitacoes/';
    }

    // ── Papéis ────────────────────────────────────────────────────────────────

    /** Comprador, admin e diretor enxergam todas as solicitações. */
    public function veTodas(array $user): bool
    {
        return in_array($user['perfil'] ?? '', ['comprador', 'admin', 'diretor'], true);
    }

    /** Quem pode gerar requisição e concluir. */
    public function podeComprar(array $user): bool
    {
        return in_array($user['perfil'] ?? '', ['comprador', 'admin', 'diretor'], true);
    }

    /** Tem aba "Aguardando minha aprovação": admin, diretor ou gestor em gestores_setor. */
    public function ehAprovador(array $user): bool
    {
        if (in_array($user['perfil'] ?? '', ['admin', 'diretor'], true)) return true;
        $stmt = $this->db->prepare(
            "SELECT 1 FROM gestores_setor gs
             JOIN usuarios u ON u.id = gs.usuario_id AND u.ativo = 1
             WHERE gs.usuario_id = ? AND gs.ativo = 1 LIMIT 1"
        );
        $stmt->execute([$user['id']]);
        return (bool)$stmt->fetchColumn();
    }

    public function ehGestorDoSetor(array $user, array $sol): bool
    {
        if (empty($sol['filial_id']) || empty($sol['setor_id'])) return false;
        $stmt = $this->db->prepare(
            "SELECT 1 FROM gestores_setor gs
             JOIN usuarios u ON u.id = gs.usuario_id AND u.ativo = 1
             WHERE gs.usuario_id = ? AND gs.filial_id = ? AND gs.setor_id = ? AND gs.ativo = 1 LIMIT 1"
        );
        $stmt->execute([$user['id'], $sol['filial_id'], $sol['setor_id']]);
        return (bool)$stmt->fetchColumn();
    }

    public function podeAprovar(array $user, array $sol): bool
    {
        // Segregação: ninguém aprova o que abriu ou o que é para si mesmo (gestor).
        if (($sol['aberto_por'] ?? null) === $user['id']) return false;
        return in_array($user['perfil'] ?? '', ['admin', 'diretor'], true)
            || ($sol['solicitante_id'] !== $user['id'] && $this->ehGestorDoSetor($user, $sol));
    }

    public function podeVer(array $user, array $sol): bool
    {
        return $sol['solicitante_id'] === $user['id']
            || ($sol['aberto_por'] ?? null) === $user['id']
            || $this->veTodas($user)
            // Almoxarife entrega o que chegou: vê a SOL depois do recebimento (fase 2).
            || (($user['perfil'] ?? '') === 'almoxarife' && !empty($sol['recebido_em']))
            || $this->ehGestorDoSetor($user, $sol);
    }

    // ── Consultas ─────────────────────────────────────────────────────────────

    public function buscar(string $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, u.nome AS solicitante_nome, f.nome AS filial_nome, st.nome AS setor_nome,
                    d.nome AS decidido_por_nome, c.nome AS comprador_nome, ab.nome AS aberto_por_nome,
                    er.nome AS entrega_registrada_por_nome,
                    r.numero AS requisicao_numero, r.tipo AS requisicao_tipo, r.criado_em AS requisicao_criado_em
             FROM solicitacoes_compra s
             JOIN usuarios u       ON u.id  = s.solicitante_id
             LEFT JOIN filiais f   ON f.id  = s.filial_id
             LEFT JOIN setores st  ON st.id = s.setor_id
             LEFT JOIN usuarios d  ON d.id  = s.decidido_por
             LEFT JOIN usuarios c  ON c.id  = s.comprador_id
             LEFT JOIN usuarios ab ON ab.id = s.aberto_por
             LEFT JOIN usuarios er ON er.id = s.entrega_registrada_por
             LEFT JOIN requisicoes r ON r.id = s.requisicao_id
             WHERE s.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param string $aba minhas | aprovar | todas (o controller garante que o
     *                    usuário tem direito à aba antes de chamar)
     */
    public function listar(array $user, string $aba, ?string $status): array
    {
        $where  = [];
        $params = [];

        if ($aba === 'aprovar') {
            $where[] = "s.status = 'aguardando_aprovacao'";
            // Nunca aprova o que abriu (vale também para admin/diretor).
            $where[]  = '(s.aberto_por IS NULL OR s.aberto_por <> ?)';
            $params[] = $user['id'];
            if (!in_array($user['perfil'] ?? '', ['admin', 'diretor'], true)) {
                $where[]  = "EXISTS (SELECT 1 FROM gestores_setor gs
                                     JOIN usuarios ug ON ug.id = gs.usuario_id AND ug.ativo = 1
                                     WHERE gs.ativo = 1 AND gs.usuario_id = ?
                                       AND gs.filial_id = s.filial_id AND gs.setor_id = s.setor_id)";
                $params[] = $user['id'];
                $where[]  = 's.solicitante_id <> ?';
                $params[] = $user['id'];
            }
        } elseif ($aba !== 'todas') {
            $where[]  = '(s.solicitante_id = ? OR s.aberto_por = ?)';
            $params[] = $user['id'];
            $params[] = $user['id'];
        }

        if ($status !== null && isset(self::STATUS_LABEL[$status])) {
            $where[]  = 's.status = ?';
            $params[] = $status;
        }

        $ordem = in_array($aba, ['aprovar', 'todas'], true)
            ? " ORDER BY FIELD(s.urgencia, 'emergencial', 'urgente', 'planejado', 'estrategico'),
                         s.sla_vence_em IS NULL, s.sla_vence_em,
                         s.necessario_ate IS NULL, s.necessario_ate, s.criado_em DESC"
            : ' ORDER BY s.criado_em DESC';

        $sql = "SELECT s.*, u.nome AS solicitante_nome, f.nome AS filial_nome,
                       (SELECT MAX(e.criado_em) FROM solicitacao_compra_eventos e
                        WHERE e.solicitacao_id = s.id) AS ultima_atualizacao
                FROM solicitacoes_compra s
                JOIN usuarios u     ON u.id = s.solicitante_id
                LEFT JOIN filiais f ON f.id = s.filial_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . $ordem . ' LIMIT 300';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function eventos(string $solId): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, u.nome AS usuario_nome
             FROM solicitacao_compra_eventos e
             JOIN usuarios u ON u.id = e.usuario_id
             WHERE e.solicitacao_id = ?
             ORDER BY e.criado_em, e.id"
        );
        $stmt->execute([$solId]);
        return $stmt->fetchAll();
    }

    public function anexos(string $solId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM solicitacao_compra_anexos WHERE solicitacao_id = ? ORDER BY criado_em, id"
        );
        $stmt->execute([$solId]);
        return $stmt->fetchAll();
    }

    public function buscarAnexo(string $anexoId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM solicitacao_compra_anexos WHERE id = ? LIMIT 1");
        $stmt->execute([$anexoId]);
        return $stmt->fetch() ?: null;
    }

    /** Solicitação que originou a requisição, para o aviso no detalhe da requisição. */
    public function daRequisicao(string $requisicaoId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, numero, titulo, criado_em FROM solicitacoes_compra WHERE requisicao_id = ? LIMIT 1"
        );
        $stmt->execute([$requisicaoId]);
        return $stmt->fetch() ?: null;
    }

    /** @return string[] ids dos gestores ativos da filial + setor */
    public function gestoresDoSetor(?string $filialId, ?string $setorId): array
    {
        if (!$filialId || !$setorId) return [];
        $stmt = $this->db->prepare(
            "SELECT gs.usuario_id FROM gestores_setor gs
             JOIN usuarios u ON u.id = gs.usuario_id AND u.ativo = 1
             WHERE gs.filial_id = ? AND gs.setor_id = ? AND gs.ativo = 1"
        );
        $stmt->execute([$filialId, $setorId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function podeAbrirEmNomeDeOutro(array $user): bool
    {
        return in_array($user['perfil'] ?? '', self::PERFIS_EM_NOME_DE, true);
    }

    public function podeDefinirUnidade(array $user): bool
    {
        return in_array($user['perfil'] ?? '', self::PERFIS_DEFINEM_UNIDADE, true);
    }

    /** Usuários ativos para o select "Solicitante" (com unidade e setor para mostrar na tela). */
    public function usuariosAtivos(): array
    {
        return $this->db->query(
            "SELECT u.id, u.nome, u.filial_id, u.setor_id, f.nome AS filial_nome, st.nome AS setor_nome
             FROM usuarios u
             LEFT JOIN filiais f  ON f.id  = u.filial_id
             LEFT JOIN setores st ON st.id = u.setor_id
             WHERE u.ativo = 1
             ORDER BY u.nome"
        )->fetchAll();
    }

    public function usuarioAtivo(string $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.nome, u.perfil, u.filial_id, u.setor_id, f.nome AS filial_nome, st.nome AS setor_nome
             FROM usuarios u
             LEFT JOIN filiais f  ON f.id  = u.filial_id
             LEFT JOIN setores st ON st.id = u.setor_id
             WHERE u.id = ? AND u.ativo = 1 LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Nome de uma filial/setor (tabela fixa, nunca vinda do usuário). */
    private function nomeDe(string $tabela, string $id): ?string
    {
        $stmt = $this->db->prepare("SELECT nome FROM {$tabela} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $n = $stmt->fetchColumn();
        return $n === false ? null : (string)$n;
    }

    /** Filial ativa existe e (se informado) setor ativo existe? */
    public function unidadeSetorValidos(string $filialId, ?string $setorId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM filiais WHERE id = ? AND ativo = 1");
        $stmt->execute([$filialId]);
        if (!$stmt->fetchColumn()) return false;
        if ($setorId === null) return true;
        $stmt = $this->db->prepare("SELECT 1 FROM setores WHERE id = ? AND ativo = 1");
        $stmt->execute([$setorId]);
        return (bool)$stmt->fetchColumn();
    }

    public function itens(string $solId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM solicitacao_compra_itens WHERE solicitacao_id = ? ORDER BY ordem"
        );
        $stmt->execute([$solId]);
        return $stmt->fetchAll();
    }

    /** Título da lista/notificações: 1º item + " e mais N itens", até 150 caracteres. */
    public static function tituloDosItens(array $itens): string
    {
        $resto  = count($itens) - 1;
        $sufixo = $resto > 0 ? ' e mais ' . $resto . ($resto === 1 ? ' item' : ' itens') : '';
        $desc   = trim((string)($itens[0]['descricao'] ?? ''));
        $limite = 150 - mb_strlen($sufixo);
        if (mb_strlen($desc) > $limite) {
            $desc = rtrim(mb_substr($desc, 0, $limite - 1)) . '…';
        }
        return $desc . $sufixo;
    }

    /** @return string[] */
    private function compradores(): array
    {
        return $this->db->query("SELECT id FROM usuarios WHERE perfil = 'comprador' AND ativo = 1")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    // ── Ações ─────────────────────────────────────────────────────────────────

    /**
     * @return string id da solicitação criada
     * Abre a solicitação. $dados vem validado pelo controller:
     *   solicitante (linha de usuarios: id, nome, filial_id, setor_id), itens (lista de
     *   descricao/quantidade/unidade/valor_unitario), motivo, necessario_ate (Y-m-d),
     *   finalidade (chave de Requisicao::FINALIDADES ou null = "não sei"), fornecedor_sugerido,
     *   recorrencia, fora_orcamento, emergencial, observacoes.
     * Filial, setor e gestores são os do SOLICITANTE (ou, se vierem em $dados['filial_id'] /
     * $dados['setor_id'], os escolhidos por comprador/admin); aberto_por = quem preencheu.
     */
    public function abrir(array $autor, array $dados, array $arquivos): string
    {
        $fotos       = $this->validarFotos($arquivos);
        $solicitante = $dados['solicitante'];
        $filialId    = $solicitante['filial_id'] ?? null;
        $setorId     = $solicitante['setor_id'] ?? null;
        $unidadeDefinida = array_key_exists('filial_id', $dados);
        if ($unidadeDefinida) {
            $filialId = $dados['filial_id'] ?: null;
            $setorId  = ($dados['setor_id'] ?? null) ?: null;
        }
        $titulo      = self::tituloDosItens($dados['itens']);
        $gestoresSetor = $this->gestoresDoSetor($filialId, $setorId);
        // Aprovadores: gestores do setor, exceto o solicitante e o autor (segregação de funções).
        $gestores    = array_values(array_diff($gestoresSetor, [$solicitante['id'], $autor['id']]));
        $autorGestor = in_array($autor['id'], $gestoresSetor, true);

        // Revenda (S2, spec 2026-10-30): pula o gestor e vai direto para a solicitação
        // de OC; a alçada por valor da requisição continua valendo.
        $revenda       = ($dados['finalidade'] ?? null) === 'revenda';
        $aguardaGestor = $gestores && !$revenda;
        // Sem aprovador possível: aprova na hora para nada ficar parado sem dono.
        $status      = $aguardaGestor ? 'aguardando_aprovacao' : 'aprovada';
        $decididoPor = null;
        if ($revenda) {
            $mensagem = 'Revenda: segue direto para a solicitação de OC (sem aprovação do gestor).';
        } elseif ($gestores) {
            $mensagem = 'Solicitação aberta.';
        } elseif ($autorGestor) {
            $mensagem    = 'Aprovada na abertura: aberta pelo gestor do setor (' . $autor['nome'] . ').';
            $decididoPor = $autor['id'];
        } else {
            $mensagem = 'Aprovada automaticamente: setor sem gestor cadastrado.';
        }
        if ($solicitante['id'] !== $autor['id']) {
            $mensagem = 'Aberta por ' . $autor['nome'] . ' em nome de ' . $solicitante['nome'] . '. ' . $mensagem;
        }
        if ($unidadeDefinida && ($filialId !== ($solicitante['filial_id'] ?? null) || $setorId !== ($solicitante['setor_id'] ?? null))) {
            $nomeFilial = $filialId ? ($this->nomeDe('filiais', $filialId) ?? '—') : '—';
            $nomeSetor  = $setorId ? ($this->nomeDe('setores', $setorId) ?? '—') : 'sem setor';
            $mensagem  .= ' Unidade/setor definidos por ' . $autor['nome'] . ': ' . $nomeFilial . ' / ' . $nomeSetor . '.';
        }

        $id = uuid();
        $movidos = [];
        $this->db->beginTransaction();
        try {
            $numero = (int)$this->db->query(
                "SELECT COALESCE(MAX(numero), 0) + 1 FROM solicitacoes_compra FOR UPDATE"
            )->fetchColumn();

            $this->db->prepare(
                "INSERT INTO solicitacoes_compra
                   (id, numero, titulo, descricao, motivo, necessario_ate, finalidade,
                    fornecedor_sugerido, recorrencia, fora_orcamento, emergencial, urgencia, sla_vence_em,
                    solicitante_id, aberto_por, filial_id, setor_id, status, decidido_em, decidido_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $id, $numero, $titulo, $dados['observacoes'] ?: null, $dados['motivo'],
                $dados['necessario_ate'], $dados['finalidade'],
                $dados['fornecedor_sugerido'] ?: null, $dados['recorrencia'],
                $dados['fora_orcamento'] ? 1 : 0, $dados['emergencial'] ? 1 : 0,
                $dados['urgencia'],
                self::vencimentoSla(new \DateTimeImmutable(), $dados['urgencia'])?->format('Y-m-d H:i:s'),
                $solicitante['id'], $autor['id'], $filialId, $setorId, $status,
                $aguardaGestor ? null : date('Y-m-d H:i:s'), $decididoPor,
            ]);

            $stmtItem = $this->db->prepare(
                "INSERT INTO solicitacao_compra_itens
                   (id, solicitacao_id, ordem, descricao, quantidade, unidade, valor_unitario)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach (array_values($dados['itens']) as $i => $it) {
                $stmtItem->execute([uuid(), $id, $i + 1, $it['descricao'], $it['quantidade'],
                    $it['unidade'], $it['valor_unitario']]);
            }

            $this->registrarEvento($id, $autor['id'], 'status', $mensagem, null, $status);
            $movidos = $this->salvarAnexos($id, null, $autor['id'], $fotos);
            $this->db->commit();
        } catch (\Throwable $e) {
            foreach ($movidos as $p) @unlink($p);
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        $sol = ['id' => $id, 'numero' => $numero, 'criado_em' => date('Y-m-d H:i:s')];
        $num = self::numeroFormatado($sol);
        if ($aguardaGestor) {
            $this->notificar($gestores, $autor['id'], 'Solicitação de compra para aprovar',
                $num . ' — ' . $titulo, $id);
        } else {
            $this->notificar($this->compradores(), $autor['id'], 'Nova solicitação de compra aprovada',
                $num . ' — ' . $titulo, $id);
        }
        if ($solicitante['id'] !== $autor['id']) {
            $this->notificar([$solicitante['id']], $autor['id'], 'Solicitação aberta em seu nome',
                $num . ' — ' . $titulo . ' (por ' . $autor['nome'] . ')', $id);
        }
        return $id;
    }

    public function podeMudarUrgencia(array $user, array $sol): bool
    {
        if (in_array($sol['status'], self::STATUS_FINAIS, true)) return false;
        return ($sol['status'] === 'aguardando_aprovacao' && $this->podeAprovar($user, $sol))
            || $this->podeComprar($user);
    }

    public function mudarUrgencia(array $sol, array $user, string $nova): void
    {
        if (!isset(self::URGENCIAS[$nova])) {
            throw new \InvalidArgumentException('Urgência inválida.');
        }
        $atual = $sol['urgencia'] ?? self::URGENCIA_PADRAO;
        if ($nova === $atual) return;

        $de   = self::URGENCIAS[$atual];
        $para = self::URGENCIAS[$nova];
        $vence = self::vencimentoSla(new \DateTimeImmutable($sol['criado_em']), $nova);
        $msg  = 'Urgência alterada de ' . $de['cor'] . ' (' . $de['rotulo'] . ') para '
              . $para['cor'] . ' (' . $para['rotulo'] . ').'
              . ($vence ? ' Novo vencimento: ' . $vence->format('d/m H:i') . '.' : ' Sem SLA.');

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE solicitacoes_compra SET urgencia = ?, sla_vence_em = ? WHERE id = ?")
                     ->execute([$nova, $vence?->format('Y-m-d H:i:s'), $sol['id']]);
            $this->registrarEvento($sol['id'], $user['id'], 'andamento', $msg, null, null);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        $this->notificar([$sol['solicitante_id'], $sol['aberto_por'] ?? null], $user['id'],
            'Solicitação ' . self::numeroFormatado($sol) . ': urgência alterada', $sol['titulo'] . ' — ' . $msg, $sol['id']);
    }

    /**
     * Aplica uma transição de status, grava o evento e notifica.
     *
     * @param array<string, string|null> $extra colunas extras do UPDATE — só
     *        nomes fixos vindos deste serviço (nunca entrada do usuário)
     */
    public function mudarStatus(array $sol, array $user, string $para, ?string $mensagem, array $extra = []): void
    {
        $de = $sol['status'];
        if (!in_array($para, self::TRANSICOES[$de] ?? [], true)) {
            throw new \DomainException(sprintf(
                'Não é possível passar de "%s" para "%s".',
                self::STATUS_LABEL[$de] ?? $de,
                self::STATUS_LABEL[$para] ?? $para
            ));
        }

        if ($para === 'recusada' && trim((string)$mensagem) === '') {
            throw new \InvalidArgumentException('Informe o motivo da recusa.');
        }

        $sets   = ['status = ?'];
        $params = [$para];
        if (in_array($para, ['aprovada', 'recusada'], true)) {
            $sets[]   = 'decidido_por = ?';
            $params[] = $user['id'];
            $sets[]   = 'decidido_em = NOW()';
        }
        if ($para === 'concluida') {
            $sets[] = 'concluida_em = NOW()';
        }
        foreach ($extra as $coluna => $valor) {
            if (!in_array($coluna, ['requisicao_id', 'comprador_id'], true)) {
                throw new \LogicException('Coluna não permitida: ' . $coluna);
            }
            $sets[]   = $coluna . ' = ?';
            $params[] = $valor;
        }
        $params[] = $sol['id'];
        $params[] = $de;

        // Dentro da transação de quem chamou (ex.: confirmarRecebimento), não abre outra.
        $propria = !$this->db->inTransaction();
        if ($propria) $this->db->beginTransaction();
        try {
            // AND status = ?: se outra pessoa mudou antes, não sobrescreve.
            $stmt = $this->db->prepare(
                'UPDATE solicitacoes_compra SET ' . implode(', ', $sets) . ' WHERE id = ? AND status = ?'
            );
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                throw new \DomainException('A solicitação foi alterada por outra pessoa. Recarregue a página.');
            }
            $this->registrarEvento($sol['id'], $user['id'], 'status', $mensagem, $de, $para);
            if ($propria) $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        $num    = self::numeroFormatado($sol);
        $titulo = 'Solicitação ' . $num . ': ' . self::STATUS_LABEL[$para];
        $texto  = $sol['titulo'] . ($mensagem ? ' — ' . $mensagem : '');
        $destinatarios = [$sol['solicitante_id']];
        if (!empty($sol['aberto_por']) && $sol['aberto_por'] !== $sol['solicitante_id']) {
            $destinatarios[] = $sol['aberto_por'];
        }
        if ($para === 'aprovada') {
            $destinatarios = array_merge($destinatarios, $this->compradores());
        }
        $this->notificar($destinatarios, $user['id'], $titulo, $texto, $sol['id']);
    }

    public function comentar(array $sol, array $user, string $mensagem, array $arquivos): void
    {
        $fotos = $this->validarFotos($arquivos);
        if ($mensagem === '' && !$fotos) {
            throw new \InvalidArgumentException('Escreva um comentário ou anexe uma foto.');
        }

        $movidos = [];
        $this->db->beginTransaction();
        try {
            $eventoId = $this->registrarEvento($sol['id'], $user['id'], 'comentario', $mensagem ?: null, null, null);
            $movidos = $this->salvarAnexos($sol['id'], $eventoId, $user['id'], $fotos);
            $this->db->commit();
        } catch (\Throwable $e) {
            foreach ($movidos as $p) @unlink($p);
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        $destinatarios = array_merge(
            [$sol['solicitante_id'], $sol['aberto_por'] ?? null, $sol['comprador_id'] ?? null],
            $this->gestoresDoSetor($sol['filial_id'], $sol['setor_id'])
        );
        $this->notificar($destinatarios, $user['id'],
            'Novo comentário na solicitação ' . self::numeroFormatado($sol),
            $user['nome'] . ': ' . mb_strimwidth($mensagem ?: '(foto)', 0, 150, '…'),
            $sol['id']);
    }

    /**
     * Chamado pelo RequisicaoController::store depois de criar a requisição.
     * Só vincula se a solicitação ainda está aprovada e o usuário pode comprar.
     */
    public function vincularRequisicao(string $solId, string $requisicaoId, array $user): bool
    {
        $sol = $this->buscar($solId);
        if (!$sol || $sol['status'] !== 'aprovada' || !$this->podeComprar($user)) return false;

        $stmt = $this->db->prepare("SELECT tipo, numero, criado_em FROM requisicoes WHERE id = ? LIMIT 1");
        $stmt->execute([$requisicaoId]);
        $req = $stmt->fetch();
        if (!$req) return false;

        $numReq = formatar_numero_requisicao($req['tipo'], $req['criado_em'], (int)$req['numero']);
        $this->mudarStatus($sol, $user, 'em_compra', 'Requisição gerada: ' . $numReq, [
            'requisicao_id' => $requisicaoId,
            'comprador_id'  => $user['id'],
        ]);
        return true;
    }

    // ── Andamento da compra ───────────────────────────────────────────────────
    // Chamados pelos módulos de Aprovações, Compras e Fiscal. Só agem quando a
    // requisição veio de uma solicitação "em compra"; fora isso não fazem nada.
    // Falha aqui nunca derruba a operação de quem chamou (try/catch interno).

    public function aoAprovarRequisicao(string $requisicaoId, array $user): void
    {
        $this->seguro(function () use ($requisicaoId, $user) {
            $this->registrarAndamento($requisicaoId, $user['id'],
                'Requisição ' . $this->numeroRequisicao($requisicaoId) . ' aprovada.');
        });
    }

    public function aoRejeitarRequisicao(string $requisicaoId, array $user, string $observacao): void
    {
        $this->seguro(function () use ($requisicaoId, $user, $observacao) {
            $this->registrarAndamento($requisicaoId, $user['id'],
                'Requisição ' . $this->numeroRequisicao($requisicaoId) . ' rejeitada: ' . $observacao
                . ' O comprador vai revisar e reenviar.');
        });
    }

    public function aoGerarOrdemCompra(string $ocId, array $user): void
    {
        $this->seguro(function () use ($ocId, $user) {
            $stmt = $this->db->prepare(
                "SELECT oc.numero_oc, oc.requisicao_id,
                        COALESCE(NULLIF(f.nome_fantasia, ''), f.razao_social) AS fornecedor
                 FROM ordens_compra oc
                 JOIN fornecedores f ON f.id = oc.fornecedor_id
                 WHERE oc.id = ? LIMIT 1"
            );
            $stmt->execute([$ocId]);
            $oc = $stmt->fetch();
            if (!$oc) return;
            $this->registrarAndamento($oc['requisicao_id'], $user['id'],
                'Ordem de compra ' . $oc['numero_oc'] . ' emitida para ' . $oc['fornecedor'] . '. Aguardando entrega.');
        });
    }

    /** Marcações manuais da OC (S5): etapas 06, 08 e 09 da barra de andamento. */
    public const MARCAS_OC = [
        'enviada'     => 'enviada ao fornecedor',
        'transito'    => 'em trânsito',
        'recebimento' => 'mercadoria recebida',
    ];

    /**
     * OC marcada como enviada / em trânsito / recebida: evento "andamento" na SOL
     * em compra ligada à requisição (se houver). $complemento vai no fim da frase
     * (ex.: "CT-e 123", "em 02/11 por João").
     */
    public function aoMarcarOc(string $ocId, string $marca, array $user, string $complemento = ''): void
    {
        $this->seguro(function () use ($ocId, $marca, $user, $complemento) {
            if (!isset(self::MARCAS_OC[$marca])) return;
            $stmt = $this->db->prepare("SELECT numero_oc, requisicao_id FROM ordens_compra WHERE id = ? LIMIT 1");
            $stmt->execute([$ocId]);
            $oc = $stmt->fetch();
            if (!$oc) return;
            $this->registrarAndamento($oc['requisicao_id'], $user['id'],
                'Ordem de compra ' . $oc['numero_oc'] . ': ' . self::MARCAS_OC[$marca]
                . ($complemento !== '' ? ' (' . $complemento . ')' : '') . '.');
        });
    }

    /**
     * NF aprovada/conferida: registra o recebimento e, se todas as OCs não
     * canceladas da requisição já têm NF aprovada, grava recebido_em e pede ao
     * solicitante que confirme a entrega (a conclusão vem em confirmarRecebimento).
     * Olha a NF e não ordens_compra.status porque a conferência completa
     * (FiscalController::confirmarConferencia) não atualiza o status da OC.
     */
    public function aoAprovarNotaFiscal(string $nfId, array $user): void
    {
        $this->seguro(function () use ($nfId, $user) {
            $stmt = $this->db->prepare(
                "SELECT nf.numero_nf, oc.numero_oc, oc.requisicao_id
                 FROM notas_fiscais nf
                 JOIN ordens_compra oc ON oc.id = nf.oc_id
                 WHERE nf.id = ? LIMIT 1"
            );
            $stmt->execute([$nfId]);
            $nf = $stmt->fetch();
            if (!$nf) return;

            $sol = $this->registrarAndamento($nf['requisicao_id'], $user['id'],
                'Produto recebido: NF ' . $nf['numero_nf'] . ' (OC ' . $nf['numero_oc'] . ').');
            if (!$sol) return;

            $pendentes = $this->db->prepare(
                "SELECT COUNT(*) FROM ordens_compra oc
                 WHERE oc.requisicao_id = ? AND oc.status <> 'cancelado'
                   AND NOT EXISTS (SELECT 1 FROM notas_fiscais nf
                                   WHERE nf.oc_id = oc.id AND nf.status = 'aprovada')"
            );
            $pendentes->execute([$nf['requisicao_id']]);
            if ((int)$pendentes->fetchColumn() !== 0) return;

            // Fase 2: não conclui mais sozinha — marca o recebimento e espera o
            // solicitante confirmar (confirmarRecebimento). Só na primeira vez.
            $upd = $this->db->prepare(
                "UPDATE solicitacoes_compra SET recebido_em = NOW()
                 WHERE id = ? AND status = 'em_compra' AND recebido_em IS NULL"
            );
            $upd->execute([$sol['id']]);
            if ($upd->rowCount() === 0) return;

            $msg = 'Produto recebido: todas as ordens de compra têm NF aprovada'
                 . ' — aguardando confirmação de recebimento pelo solicitante.';
            $this->registrarEvento($sol['id'], $user['id'], 'andamento', $msg, null, null);
            $this->notificar([$sol['solicitante_id'], $sol['aberto_por'] ?? null], $user['id'],
                'Solicitação ' . self::numeroFormatado($sol) . ': confirme o recebimento',
                $sol['titulo'] . ' — o produto chegou. Confirme na solicitação quando receber.', $sol['id']);
        });
    }

    // ── Entrega ao solicitante (fase 2) ───────────────────────────────────────

    /** Perfis que registram a entrega em nome do solicitante (informando quem recebeu). */
    public const PERFIS_REGISTRAM_ENTREGA = ['comprador', 'almoxarife', 'admin'];
    public const MAX_RECEBIDO_POR = 150;

    /** Recebido (NF aprovada) e ainda sem a confirmação de entrega. */
    public static function aguardandoConfirmacao(array $sol): bool
    {
        return ($sol['status'] ?? '') === 'em_compra'
            && !empty($sol['recebido_em']) && empty($sol['entregue_em']);
    }

    /**
     * Quem pode confirmar a entrega: 'proprio' (solicitante ou quem abriu — sem
     * informar nada) ou 'em_nome' (comprador/almoxarife/admin — informa quem
     * recebeu); null = ninguém. Não olha o status (ver aguardandoConfirmacao).
     */
    public function modoConfirmacao(array $user, array $sol): ?string
    {
        if ($sol['solicitante_id'] === $user['id'] || ($sol['aberto_por'] ?? null) === $user['id']) return 'proprio';
        if (in_array($user['perfil'] ?? '', self::PERFIS_REGISTRAM_ENTREGA, true)) return 'em_nome';
        return null;
    }

    /**
     * Confirma (ou registra em nome do solicitante) a entrega e conclui a SOL.
     * @param string|null $recebidoPor obrigatório no modo 'em_nome'; ignorado no 'proprio'
     */
    public function confirmarRecebimento(array $sol, array $user, ?string $recebidoPor): void
    {
        $modo = $this->modoConfirmacao($user, $sol);
        if ($modo === null) {
            throw new \DomainException('Você não pode confirmar o recebimento desta solicitação.');
        }
        if (!self::aguardandoConfirmacao($sol)) {
            throw new \DomainException('Esta solicitação não está aguardando confirmação de recebimento.');
        }
        if ($modo === 'proprio') {
            $entregueA = (string)$user['nome'];
            $msg = 'Recebimento confirmado por ' . $entregueA . '.';
        } else {
            $entregueA = trim((string)$recebidoPor);
            if ($entregueA === '') {
                throw new \InvalidArgumentException('Informe quem recebeu o produto.');
            }
            if (mb_strlen($entregueA) > self::MAX_RECEBIDO_POR) {
                throw new \InvalidArgumentException('"Recebido por" pode ter no máximo ' . self::MAX_RECEBIDO_POR . ' caracteres.');
            }
            $msg = 'Entrega registrada por ' . $user['nome'] . ' em nome do solicitante: recebido por ' . $entregueA . '.';
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE solicitacoes_compra
                 SET entregue_em = NOW(), entregue_a = ?, entrega_registrada_por = ?
                 WHERE id = ? AND status = 'em_compra' AND recebido_em IS NOT NULL AND entregue_em IS NULL"
            );
            $stmt->execute([mb_substr($entregueA, 0, self::MAX_RECEBIDO_POR), $user['id'], $sol['id']]);
            if ($stmt->rowCount() === 0) {
                throw new \DomainException('A solicitação foi alterada por outra pessoa. Recarregue a página.');
            }
            $this->registrarEvento($sol['id'], $user['id'], 'andamento', $msg, null, null);
            // Usa a transação aberta aqui (mudarStatus grava concluida_em e notifica).
            $this->mudarStatus($sol, $user, 'concluida', 'Concluída: produto entregue a ' . $entregueA . '.');
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    /**
     * Grava um evento "andamento" na solicitação em compra ligada à requisição
     * e avisa o solicitante. @return array|null a solicitação, ou null se não há
     */
    private function registrarAndamento(string $requisicaoId, string $usuarioId, string $mensagem): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM solicitacoes_compra WHERE requisicao_id = ? AND status = 'em_compra' LIMIT 1"
        );
        $stmt->execute([$requisicaoId]);
        $solId = $stmt->fetchColumn();
        if (!$solId) return null;

        $sol = $this->buscar($solId);
        $this->registrarEvento($sol['id'], $usuarioId, 'andamento', $mensagem, null, null);
        $this->notificar([$sol['solicitante_id']], $usuarioId,
            'Solicitação ' . self::numeroFormatado($sol) . ': andamento', $mensagem, $sol['id']);
        return $sol;
    }

    private function numeroRequisicao(string $requisicaoId): string
    {
        $stmt = $this->db->prepare("SELECT tipo, numero, criado_em FROM requisicoes WHERE id = ? LIMIT 1");
        $stmt->execute([$requisicaoId]);
        $req = $stmt->fetch();
        return $req ? formatar_numero_requisicao($req['tipo'], $req['criado_em'], (int)$req['numero']) : '';
    }

    /**
     * UUID que começa pelo instante em microssegundos. criado_em só guarda o
     * segundo, e eventos do mesmo clique (ex.: "Produto recebido" + "Concluída")
     * precisam de desempate estável em ORDER BY criado_em, id.
     */
    private static function idOrdenado(): string
    {
        $hex = str_pad(dechex((int)(microtime(true) * 1000000)), 14, '0', STR_PAD_LEFT)
             . bin2hex(random_bytes(9));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
             . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /** Andamento é informativo: um erro aqui é logado e não sobe para quem chamou. */
    private function seguro(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('[SolicitacaoCompraService] Falha ao registrar andamento: ' . $e->getMessage());
        }
    }

    private function registrarEvento(string $solId, string $usuarioId, string $tipo, ?string $mensagem, ?string $de, ?string $para): string
    {
        $id = self::idOrdenado();
        $this->db->prepare(
            "INSERT INTO solicitacao_compra_eventos (id, solicitacao_id, usuario_id, tipo, mensagem, status_de, status_para)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$id, $solId, $usuarioId, $tipo, $mensagem, $de, $para]);
        return $id;
    }

    /**
     * Normaliza e valida $_FILES['fotos'] (input multiple).
     * @return array<int, array{tmp: string, nome: string, ext: string, tamanho: int}>
     */
    private function validarFotos(array $arquivos): array
    {
        $fotos = [];
        foreach ((array)($arquivos['name'] ?? []) as $i => $nome) {
            $erro = (int)($arquivos['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($erro === UPLOAD_ERR_NO_FILE) continue;
            if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
                throw new \InvalidArgumentException('A foto "' . $nome . '" passa de 5 MB.');
            }
            if ($erro !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException('Falha no envio da foto "' . $nome . '" (código ' . $erro . ').');
            }
            $tamanho = (int)$arquivos['size'][$i];
            if ($tamanho > self::MAX_BYTES) {
                throw new \InvalidArgumentException('A foto "' . $nome . '" passa de 5 MB.');
            }
            $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
            if (!in_array($ext, self::EXTENSOES, true)) {
                throw new \InvalidArgumentException('"' . $nome . '": formato não permitido. Use JPG, PNG ou WEBP.');
            }
            // MIME real (magic bytes): barra executável renomeado e HEIC do iPhone.
            if (!upload_mime_valido($arquivos['tmp_name'][$i], $ext)) {
                throw new \InvalidArgumentException('"' . $nome . '" não é uma imagem válida. Use JPG, PNG ou WEBP — fotos de iPhone (HEIC) precisam ser convertidas.');
            }
            $fotos[] = ['tmp' => $arquivos['tmp_name'][$i], 'nome' => $nome, 'ext' => $ext, 'tamanho' => $tamanho];
        }
        if (count($fotos) > self::MAX_FOTOS) {
            throw new \InvalidArgumentException('Envie no máximo ' . self::MAX_FOTOS . ' fotos por vez.');
        }
        return $fotos;
    }

    /**
     * Move as fotos para storage/solicitacoes/ e grava as linhas. Roda dentro da
     * transação do chamador; se algo falhar, apaga os arquivos já movidos antes
     * de relançar (o rollback do chamador cuida das linhas).
     */
    private function salvarAnexos(string $solId, ?string $eventoId, string $usuarioId, array $fotos): array
    {
        if (!$fotos) return [];
        $dir = self::diretorioAnexos();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar ' . $dir);
        }

        $movidos = [];
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO solicitacao_compra_anexos
                   (id, solicitacao_id, evento_id, arquivo_path, nome_original, tamanho, enviado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($fotos as $f) {
                $arquivo = uuid() . '.' . $f['ext'];
                if (!move_uploaded_file($f['tmp'], $dir . $arquivo)) {
                    throw new \RuntimeException('Falha ao mover ' . $f['nome'] . ' para ' . $dir);
                }
                $movidos[] = $dir . $arquivo;
                $stmt->execute([uuid(), $solId, $eventoId, $arquivo, $f['nome'], $f['tamanho'], $usuarioId]);
            }
        } catch (\Throwable $e) {
            foreach ($movidos as $p) @unlink($p);
            throw $e;
        }
        return $movidos;
    }

    /** Falha de notificação nunca desfaz a ação. O autor não notifica a si mesmo. */
    private function notificar(array $destinatarios, string $autorId, string $titulo, string $mensagem, string $solId): void
    {
        // Emergencial chama atenção no sino.
        $stmtUrg = $this->db->prepare("SELECT urgencia FROM solicitacoes_compra WHERE id = ?");
        $stmtUrg->execute([$solId]);
        if ($stmtUrg->fetchColumn() === 'emergencial') {
            $titulo = 'EMERGENCIAL · ' . $titulo;
        }

        $svc = new NotificacaoService();
        foreach (array_unique(array_filter($destinatarios)) as $uid) {
            if ($uid === $autorId) continue;
            try {
                $svc->criar($uid, 'solicitacao_compra', $titulo, $mensagem, 'solicitacao_compra', $solId);
            } catch (\Throwable $e) {
                error_log('[SolicitacaoCompraService] Falha ao notificar ' . $uid . ': ' . $e->getMessage());
            }
        }
    }
}
