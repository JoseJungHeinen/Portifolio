<?php
// @versao Ver.002.301026

namespace App\Models;

class Fornecedor extends BaseModel
{
    protected string $table = 'fornecedores';

    /** Situações da CND Federal (painel fase 2). */
    public const CND_SITUACOES = [
        'negativa'                  => 'Negativa',
        'positiva'                  => 'Positiva',
        'positiva_efeitos_negativa' => 'Positiva com efeitos de negativa',
    ];

    /** Dias antes do vencimento da CND em que a lista passa a avisar. */
    public const CND_AVISO_DIAS = 15;

    // ── Classificação (etapa 3, protótipo do Compras) ─────────────────────

    /** Situação do fornecedor. Bloqueado não recebe cotação nem OC. */
    public const SITUACOES = [
        'pendente'   => 'Pendente',
        'homologado' => 'Homologado',
        'bloqueado'  => 'Bloqueado',
    ];

    public const REGIMES = [
        'simples_nacional' => 'Simples Nacional',
        'lucro_presumido'  => 'Lucro Presumido',
        'lucro_real'       => 'Lucro Real',
        'mei'              => 'MEI',
    ];

    /** Critérios da avaliação periódica (notas 0–5) e seus pesos. */
    public const CRITERIOS = [
        'prazo'        => ['Prazo de entrega', 0.2],
        'qualidade'    => ['Qualidade', 0.2],
        'preco'        => ['Preço / custo líquido', 0.3],
        'conformidade' => ['Conformidade fiscal e documental', 0.3],
    ];

    /** Nota abaixo disso gera plano de ação. */
    public const NOTA_PLANO_ACAO = 3.0;
    /** Nota abaixo disso nos dois últimos períodos: a política prevê bloqueio. */
    public const NOTA_BLOQUEIO = 2.0;
    /** Regime tributário é reconfirmado a cada 6 meses. */
    public const REGIME_RECONFIRMAR_DIAS = 183;

    public const REGRA_AVALIACAO = 'Fornecedores da curva A são avaliados trimestralmente: prazo 20%, qualidade 20%, '
        . 'preço/custo líquido 30%, conformidade fiscal e documental 30%. Nota abaixo de 3,0 gera plano de ação; '
        . 'abaixo de 2,0 por dois períodos, bloqueio.';

    /** Pendências do cadastro (chave => rótulo do filtro "Pendência / grupo"). */
    public const PENDENCIAS = [
        'sem_regime'            => 'Sem regime tributário',
        'cnd_positiva'          => 'CND Federal positiva',
        'cnd_vencida'           => 'CND vencida',
        'cnd_vence'             => 'CND vence em até ' . self::CND_AVISO_DIAS . ' dias',
        'cnd_sem_validade'      => 'CND Federal sem validade',
        'regime_reconfirmar'    => 'Regime a reconfirmar (> 6 meses)',
        'regime_nao_confirmado' => 'Regime nunca confirmado',
        'sem_banco'             => 'Sem dados bancários próprios',
        'sem_fiscal'            => 'Sem contato fiscal',
        'sem_email'             => 'Sem e-mail',
        'mei'                   => 'MEI',
        'mao_obra_sem_doc'      => 'Mão de obra sem documentação trabalhista',
        'contrato_sem_clausula' => 'Contrato sem cláusula de reequilíbrio',
    ];

    /**
     * Pendências do cadastro (protótipo: fornPend). Função pura.
     *
     * @return array<string, string> chave => texto
     */
    public static function pendencias(array $f, ?string $hoje = null): array
    {
        $h   = new \DateTimeImmutable($hoje ?? date('Y-m-d'));
        $p   = [];
        $vaz = fn(string $k) => trim((string)($f[$k] ?? '')) === '';

        if ($vaz('regime_tributario')) {
            $p['sem_regime'] = 'Regime tributário não informado';
        } elseif ($vaz('regime_confirmado_em')) {
            $p['regime_nao_confirmado'] = 'Regime tributário nunca confirmado — confirmar (semestral)';
        } else {
            $dias = (int)(new \DateTimeImmutable(substr((string)$f['regime_confirmado_em'], 0, 10)))->diff($h)->format('%r%a');
            if ($dias > self::REGIME_RECONFIRMAR_DIAS) {
                $p['regime_reconfirmar'] = 'Reconfirmar regime (semestral) — confirmado em ' . date_br($f['regime_confirmado_em']);
            }
        }

        if ($vaz('cnd_federal_validade')) {
            $p['cnd_sem_validade'] = 'Validade da CND Federal não informada';
        } else {
            $v    = new \DateTimeImmutable(substr((string)$f['cnd_federal_validade'], 0, 10));
            $dias = (int)$h->diff($v)->format('%r%a');
            if ($dias < 0) {
                $p['cnd_vencida'] = 'CND Federal vencida em ' . $v->format('d/m/Y');
            } elseif ($dias <= self::CND_AVISO_DIAS) {
                $p['cnd_vence'] = 'CND Federal vence em ' . $v->format('d/m/Y');
            }
        }
        if (($f['cnd_federal_situacao'] ?? null) === 'positiva') {
            $p['cnd_positiva'] = 'CND Federal positiva (débitos em aberto)';
        }

        if (empty($f['banco_proprio'])) $p['sem_banco']  = 'Dados bancários em nome do próprio CNPJ';
        if ($vaz('contato_fiscal'))     $p['sem_fiscal'] = 'Contato fiscal';
        if ($vaz('email'))              $p['sem_email']  = 'E-mail';
        if (!empty($f['mei']) || ($f['regime_tributario'] ?? null) === 'mei') {
            $p['mei'] = 'MEI — evitar contratação de serviços';
        }
        if (!empty($f['mao_de_obra']) && empty($f['doc_trabalhista'])) {
            $p['mao_obra_sem_doc'] = 'Documentação trabalhista/segurança (alinhar com RH)';
        }
        if (!empty($f['tem_contrato']) && empty($f['clausula_reequilibrio'])) {
            $p['contrato_sem_clausula'] = 'Contrato sem cláusula de reequilíbrio tributário (14.1)';
        }

        return $p;
    }

    /**
     * Pode receber cotação / OC? Bloqueado ou inativo: não.
     * Chamar no CompraController antes de convidar para cotação e de gerar OC.
     */
    public static function podeCotar(array $f): bool
    {
        return ($f['situacao'] ?? 'pendente') !== 'bloqueado' && (int)($f['ativo'] ?? 1) === 1;
    }

    /** Raiz (8 primeiros dígitos) do CNPJ; '' se incompleto. */
    public static function cnpjRaiz(?string $cnpj): string
    {
        $d = preg_replace('/\D/', '', (string)$cnpj);
        return strlen($d) === 14 ? substr($d, 0, 8) : '';
    }

    /** Matriz = ordem 0001 no CNPJ. */
    public static function ehMatriz(?string $cnpj): bool
    {
        $d = preg_replace('/\D/', '', (string)$cnpj);
        return strlen($d) === 14 && substr($d, 8, 4) === '0001';
    }

    /** Nota ponderada 0–5 (prazo 20% · qualidade 20% · preço 30% · conformidade 30%). */
    public static function notaPonderada(array $criterios): float
    {
        $n = 0.0;
        foreach (self::CRITERIOS as $k => [, $peso]) {
            $n += $peso * (float)($criterios[$k] ?? 0);
        }
        return round($n, 2);
    }

    /** null = ok; senão a mensagem de erro. */
    public static function validarCriterios(array $criterios): ?string
    {
        foreach (self::CRITERIOS as $k => [$rotulo]) {
            $v = $criterios[$k] ?? null;
            if ($v === null || $v === '' || !is_numeric($v)) {
                return 'Dê nota para os quatro critérios (' . $rotulo . ' em branco).';
            }
            if ((float)$v < 0 || (float)$v > 5) {
                return $rotulo . ': a nota vai de 0 a 5.';
            }
        }
        return null;
    }

    public static function precisaPlanoAcao(float $nota): bool
    {
        return $nota < self::NOTA_PLANO_ACAO;
    }

    /**
     * Nota abaixo de 2,0 nos dois últimos períodos avaliados → a política prevê bloqueio.
     *
     * @param array<int, array{periodo: ?string, nota: mixed, criado_em?: ?string}> $avaliacoes
     */
    public static function sugereBloqueio(array $avaliacoes): bool
    {
        usort($avaliacoes, fn($a, $b) => [(string)($b['periodo'] ?? ''), (string)($b['criado_em'] ?? '')]
                                     <=> [(string)($a['periodo'] ?? ''), (string)($a['criado_em'] ?? '')]);
        $ult = array_slice($avaliacoes, 0, 2);
        return count($ult) === 2
            && (float)$ult[0]['nota'] < self::NOTA_BLOQUEIO
            && (float)$ult[1]['nota'] < self::NOTA_BLOQUEIO;
    }

    /** Período trimestral de uma data: 2026-10-30 → '2026-T4'. */
    public static function periodoDe(string $data): string
    {
        $d = new \DateTimeImmutable(substr($data, 0, 10));
        return $d->format('Y') . '-T' . (intdiv((int)$d->format('n') - 1, 3) + 1);
    }

    public static function periodoValido(string $periodo): bool
    {
        return (bool)preg_match('/^20\d{2}-T[1-4]$/', $periodo);
    }

    /** '2026-T4' → '4º tri/2026'. */
    public static function periodoRotulo(?string $periodo): string
    {
        if (!$periodo || !self::periodoValido($periodo)) return (string)$periodo;
        return $periodo[6] . 'º tri/' . substr($periodo, 0, 4);
    }

    /** 2,85 / 3,0 / 2,3 — duas casas só quando precisa (a regra compara com 3,0 e 2,0). */
    public static function formatarNota(float $nota): string
    {
        $s = number_format($nota, 2, ',', '.');
        return str_ends_with($s, '0') ? substr($s, 0, -1) : $s;
    }

    /** Classe de cor da nota: < 2 vermelho, < 3 laranja, senão verde. */
    public static function classeNota(float $nota): string
    {
        return $nota < self::NOTA_BLOQUEIO ? 'text-danger' : ($nota < self::NOTA_PLANO_ACAO ? 'text-warning' : 'text-success');
    }

    /**
     * Selo da CND Federal para a lista/detalhe: vencida (vermelho) ou vence em
     * até CND_AVISO_DIAS dias (laranja). null = sem validade ou ainda longe.
     *
     * @return array{classe: string, estilo: string, texto: string}|null
     */
    public static function seloCnd(?string $validade, ?string $hoje = null): ?array
    {
        if (!$validade) {
            return null;
        }
        $v    = new \DateTimeImmutable(substr($validade, 0, 10));
        $h    = new \DateTimeImmutable($hoje ?? date('Y-m-d'));
        $dias = (int)$h->diff($v)->format('%r%a');

        if ($dias < 0) {
            return ['classe' => 'bg-danger', 'estilo' => '', 'texto' => 'CND vencida'];
        }
        if ($dias <= self::CND_AVISO_DIAS) {
            return ['classe' => 'text-white', 'estilo' => 'background:#fd7e14', 'texto' => $dias === 0 ? 'CND vence hoje'
                : 'CND vence em ' . $dias . ($dias === 1 ? ' dia' : ' dias')];
        }

        return null;
    }

    public function allOrdenados(): array
    {
        return $this->all([], 'ativo DESC, razao_social');
    }

    public function buscar(string $termo): array
    {
        $like   = '%' . $termo . '%';
        $digits = preg_replace('/\D/', '', $termo);

        if ($digits !== '') {
            return $this->query(
                "SELECT * FROM fornecedores
                  WHERE razao_social LIKE ? OR nome_fantasia LIKE ? OR cnpj LIKE ?
                  ORDER BY ativo DESC, razao_social",
                [$like, $like, '%' . $digits . '%']
            );
        }

        return $this->query(
            "SELECT * FROM fornecedores
              WHERE razao_social LIKE ? OR nome_fantasia LIKE ?
              ORDER BY ativo DESC, razao_social",
            [$like, $like]
        );
    }

    public function allAtivos(): array
    {
        return $this->all(['ativo' => 1], 'razao_social');
    }

    public function allParaContratos(): array
    {
        return $this->query(
            "SELECT * FROM fornecedores WHERE ativo = 1 AND para_contratos = 1 ORDER BY razao_social"
        );
    }

    public function contarPorStatus(int $ativo): int
    {
        return (int)$this->queryScalar(
            "SELECT COUNT(*) FROM fornecedores WHERE ativo = ?",
            [$ativo]
        );
    }

    public function historicoPrecosItem(string $itemId, int $limit = 12): array
    {
        return $this->query(
            "SELECT hp.*, i.nome AS item_nome, f.razao_social AS fornecedor_nome
             FROM historico_precos hp
             JOIN itens i             ON i.id = hp.item_id
             LEFT JOIN fornecedores f ON f.id = hp.fornecedor_id
             WHERE hp.item_id = ?
             ORDER BY hp.registrado_em DESC
             LIMIT ?",
            [$itemId, $limit]
        );
    }

    public function historicoCompras(string $fornecedorId, int $limit = 20): array
    {
        return $this->query(
            "SELECT hp.*, i.nome AS item_nome
             FROM historico_precos hp
             JOIN itens i ON i.id = hp.item_id
             WHERE hp.fornecedor_id = ?
             ORDER BY hp.registrado_em DESC
             LIMIT ?",
            [$fornecedorId, $limit]
        );
    }

    public function precoMedioItem(string $itemId, int $meses = 3): float
    {
        $val = $this->queryScalar(
            "SELECT AVG(valor_unitario)
             FROM historico_precos
             WHERE item_id = ?
               AND registrado_em >= DATE_SUB(NOW(), INTERVAL ? MONTH)",
            [$itemId, $meses]
        );
        return (float)$val;
    }

    public function topPorValor(int $limit = 10, ?string $filialId = null): array
    {
        return $this->query(
            "SELECT f.razao_social, SUM(oc.valor_total) AS total_comprado, COUNT(oc.id) AS qtd_oc
             FROM ordens_compra oc
             JOIN fornecedores f ON f.id = oc.fornecedor_id
             WHERE oc.status NOT IN ('cancelado','rascunho')
             GROUP BY oc.fornecedor_id, f.razao_social
             ORDER BY total_comprado DESC
             LIMIT ?",
            [$limit]
        );
    }

    // ============================================
    // AVALIAÇÃO PERIÓDICA (uma por período; refazer o período substitui)
    // ============================================

    /** Avaliações do fornecedor, período mais recente primeiro. */
    public function avaliacoes(string $fornecedorId): array
    {
        return $this->query(
            "SELECT av.*, u.nome AS usuario_nome
             FROM avaliacoes_fornecedor av
             LEFT JOIN usuarios u ON u.id = av.usuario_id
             WHERE av.fornecedor_id = ?
             ORDER BY av.periodo DESC, av.criado_em DESC",
            [$fornecedorId]
        );
    }

    /**
     * Grava a avaliação do período (substitui a do mesmo período) e atualiza
     * fornecedores.avaliacao com a nota do período mais recente.
     *
     * @return array{nota: float, atualizou: bool, antes: ?array}
     */
    public function salvarAvaliacao(string $fornecedorId, string $usuarioId, string $periodo, array $criterios, string $comentario = ''): array
    {
        $nota  = self::notaPonderada($criterios);
        $antes = $this->queryOne(
            "SELECT * FROM avaliacoes_fornecedor WHERE fornecedor_id = ? AND periodo = ?",
            [$fornecedorId, $periodo]
        );
        $vals = [(float)$criterios['prazo'], (float)$criterios['qualidade'], (float)$criterios['preco'],
                 (float)$criterios['conformidade'], $nota, $comentario !== '' ? $comentario : null];

        if ($antes) {
            $this->query(
                "UPDATE avaliacoes_fornecedor
                 SET prazo = ?, qualidade = ?, preco = ?, conformidade = ?, nota = ?, comentario = ?, usuario_id = ?
                 WHERE id = ?",
                [...$vals, $usuarioId, $antes['id']]
            );
        } else {
            $this->query(
                "INSERT INTO avaliacoes_fornecedor
                 (id, fornecedor_id, usuario_id, periodo, prazo, qualidade, preco, conformidade, nota, comentario, criado_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [uuid(), $fornecedorId, $usuarioId, $periodo, ...$vals]
            );
        }
        $this->atualizarUltimaNota($fornecedorId);

        return ['nota' => $nota, 'atualizou' => (bool)$antes, 'antes' => $antes];
    }

    public function removerAvaliacao(string $fornecedorId, string $avaliacaoId): ?array
    {
        $av = $this->queryOne(
            "SELECT * FROM avaliacoes_fornecedor WHERE id = ? AND fornecedor_id = ?",
            [$avaliacaoId, $fornecedorId]
        );
        if (!$av) return null;
        $this->query("DELETE FROM avaliacoes_fornecedor WHERE id = ?", [$avaliacaoId]);
        $this->atualizarUltimaNota($fornecedorId);
        return $av;
    }

    /** fornecedores.avaliacao = nota do período mais recente (NULL sem avaliação). */
    public function atualizarUltimaNota(string $fornecedorId): void
    {
        $this->query(
            "UPDATE fornecedores SET avaliacao =
                (SELECT nota FROM avaliacoes_fornecedor WHERE fornecedor_id = ?
                 ORDER BY periodo DESC, criado_em DESC LIMIT 1)
             WHERE id = ?",
            [$fornecedorId, $fornecedorId]
        );
    }

    /** Soma das OCs (não canceladas nem rascunho) dos últimos 12 meses. */
    public function valorOc12m(string $fornecedorId): float
    {
        return (float)$this->queryScalar(
            "SELECT COALESCE(SUM(valor_total), 0) FROM ordens_compra
             WHERE fornecedor_id = ? AND status NOT IN ('cancelado','rascunho')
               AND criado_em >= DATE_SUB(NOW(), INTERVAL 12 MONTH)",
            [$fornecedorId]
        );
    }

    /** Outros CNPJs da mesma raiz (matriz/filiais), matriz primeiro. */
    public function mesmaRaiz(array $f): array
    {
        $raiz = self::cnpjRaiz($f['cnpj'] ?? '');
        if ($raiz === '') return [];
        return $this->query(
            "SELECT id, razao_social, nome_fantasia, cnpj, cidade, estado, situacao FROM fornecedores
             WHERE cnpj_raiz = ? AND id <> ? ORDER BY SUBSTRING(cnpj, 9, 4) = '0001' DESC, cnpj",
            [$raiz, $f['id']]
        );
    }

    /**
     * Lista com tudo que a tela precisa, numa consulta só: OCs 12 meses e as duas
     * últimas avaliações (nota/período) de cada fornecedor.
     */
    public function listaClassificacao(): array
    {
        return $this->query(
            "SELECT f.*, COALESCE(oc.total, 0) AS oc_12m,
                    a1.nota AS ultima_nota, a1.periodo AS ultimo_periodo,
                    a2.nota AS penultima_nota, a2.periodo AS penultimo_periodo
             FROM fornecedores f
             LEFT JOIN (SELECT fornecedor_id, SUM(valor_total) AS total
                        FROM ordens_compra
                        WHERE status NOT IN ('cancelado','rascunho')
                          AND criado_em >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                        GROUP BY fornecedor_id) oc ON oc.fornecedor_id = f.id
             LEFT JOIN (SELECT fornecedor_id, nota, periodo,
                               ROW_NUMBER() OVER (PARTITION BY fornecedor_id ORDER BY periodo DESC, criado_em DESC) AS rn
                        FROM avaliacoes_fornecedor) a1 ON a1.fornecedor_id = f.id AND a1.rn = 1
             LEFT JOIN (SELECT fornecedor_id, nota, periodo,
                               ROW_NUMBER() OVER (PARTITION BY fornecedor_id ORDER BY periodo DESC, criado_em DESC) AS rn
                        FROM avaliacoes_fornecedor) a2 ON a2.fornecedor_id = f.id AND a2.rn = 2"
        );
    }
}
