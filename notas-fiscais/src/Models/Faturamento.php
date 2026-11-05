<?php
// @versao Fat_04 V_17_30/09/2026
declare(strict_types=1);

/**
 * Model do módulo Faturamento (venda de veículos).
 *
 * Gerencia nf_fat_* no banco `compras`. Sem ORM — PDO com prepared statements.
 *
 * Conceitos:
 *   STATUS   = fase do pedido (nf_fat_fases), só muda com o OK de quem tem a vez.
 *   SITUAÇÃO = calculada a partir de nf_fat_requisitos (dados + anexos), sem campo manual.
 *   Preencher é livre para quem edita na fase; avançar exige os requisitos da fase atual.
 */
class Faturamento
{
    /** Colunas do pedido que podem ser requisito de campo; o resto vem do JSON `dados`. */
    private const COLUNAS_CAMPO = [
        'tipo_venda', 'pedido_apollo', 'filial_id', 'apollo_ref',
        'cliente_nome', 'cliente_doc', 'cliente_endereco', 'cliente_numero', 'cliente_cidade',
        'cliente_uf', 'cliente_telefone', 'cliente_email',
        'veiculo_codigo', 'veiculo_desc', 'veiculo_modelo', 'veiculo_cor', 'veiculo_ano_fab_mod',
        'veiculo_combustivel', 'veiculo_chassi', 'veiculo_placa',
    ];

    /**
     * Cliente e veículo vivem em nf_fat_clientes / nf_fat_veiculos, com a chave do FAT:
     * cada FAT guarda o seu retrato (o mesmo cliente pode ter endereços diferentes em FATs
     * diferentes). Aliases devolvem tudo "achatado" no array do pedido.
     */
    private const SELECT_DADOS = "
        c.nome AS cliente_nome, c.doc AS cliente_doc, c.endereco AS cliente_endereco,
        c.numero AS cliente_numero, c.cidade AS cliente_cidade, c.uf AS cliente_uf,
        c.telefone AS cliente_telefone, c.email AS cliente_email,
        v.codigo AS veiculo_codigo, v.descricao AS veiculo_desc, v.modelo AS veiculo_modelo,
        v.cor AS veiculo_cor, v.ano_fab_mod AS veiculo_ano_fab_mod, v.combustivel AS veiculo_combustivel,
        v.chassi AS veiculo_chassi, v.placa AS veiculo_placa";

    private const JOIN_DADOS = "
        LEFT JOIN nf_fat_clientes c ON c.pedido_id = p.id
        LEFT JOIN nf_fat_veiculos v ON v.pedido_id = p.id";

    /** Grupos que enxergam TODOS os pedidos (além do super_admin). */
    private const GRUPOS_VISAO_TOTAL = [
        'Diretoria', 'Central de Conferência', 'Documental', 'Financeiro', 'Secretárias',
    ];

    public static function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // ─────────────────────────────────────────
    // Fases
    // ─────────────────────────────────────────

    /**
     * Usuários que podem ser o vendedor do pedido: os marcados "Vendedor Veículos" no Controle de Acesso.
     * `$incluirId` garante que o vendedor já gravado apareça mesmo se saiu dos grupos.
     *
     * @return array<int, array{id:string, nome:string}>
     */
    public static function vendedores(?string $incluirId = null): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT DISTINCT u.id, u.nome
               FROM usuarios u
              WHERE u.vendedor_veiculos = 1 AND (u.ativo = 1 OR u.id = ?)
              ORDER BY u.nome"
        );
        $stmt->execute([$incluirId ?? '']);
        $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($incluirId !== null && !in_array($incluirId, array_column($lista, 'id'), true)) {
            $q = Database::getInstance()->prepare('SELECT id, nome FROM usuarios WHERE id = ?');
            $q->execute([$incluirId]);
            if ($u = $q->fetch(PDO::FETCH_ASSOC)) {
                $lista[] = $u;
            }
        }
        return $lista;
    }

    /** @return array<int, array> fases ativas em ordem crescente */
    public static function fases(): array
    {
        $pdo = Database::getInstance();
        return $pdo->query('SELECT id, codigo, nome, ordem FROM nf_fat_fases WHERE ativo = 1 ORDER BY ordem')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Próxima fase ativa depois da informada, ou null se já é a última. */
    public static function proximaFase(string $faseId): ?array
    {
        $fases = self::fases();
        foreach ($fases as $i => $f) {
            if ($f['id'] === $faseId) {
                return $fases[$i + 1] ?? null;
            }
        }
        return null;
    }

    // ─────────────────────────────────────────
    // Visibilidade
    // ─────────────────────────────────────────

    /** @return string[] ids dos grupos do usuário */
    public static function gruposDoUsuario(string $usuarioId): array
    {
        $stmt = Database::getInstance()->prepare('SELECT grupo_id FROM nf_grupo_membros WHERE usuario_id = ?');
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return string[] nomes dos grupos do usuário */
    private static function nomesGruposDoUsuario(string $usuarioId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT g.nome FROM nf_grupo_membros m JOIN nf_grupos g ON g.id = m.grupo_id WHERE m.usuario_id = ?'
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Escopo de visibilidade do usuário.
     *
     * @return array{tipo:string, vendedores:string[]}
     *   tipo: 'todos' | 'equipe' | 'proprios'; vendedores = ids visíveis (equipe/proprios)
     */
    public static function escopo(string $usuarioId, string $perfil): array
    {
        if ($perfil === 'super_admin') {
            return ['tipo' => 'todos', 'vendedores' => []];
        }

        $nomes = self::nomesGruposDoUsuario($usuarioId);
        if (array_intersect($nomes, self::GRUPOS_VISAO_TOTAL)) {
            return ['tipo' => 'todos', 'vendedores' => []];
        }

        if (in_array('Gerentes de Vendas', $nomes, true)) {
            $stmt = Database::getInstance()->prepare(
                'SELECT DISTINCT m.usuario_id
                   FROM nf_grupos g
                   JOIN nf_grupo_membros m ON m.grupo_id = g.id
                  WHERE g.gestor_id = ?'
            );
            $stmt->execute([$usuarioId]);
            $ids   = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $ids[] = $usuarioId;
            return ['tipo' => 'equipe', 'vendedores' => array_values(array_unique($ids))];
        }

        return ['tipo' => 'proprios', 'vendedores' => [$usuarioId]];
    }

    // ─────────────────────────────────────────
    // Listagem e busca
    // ─────────────────────────────────────────

    /**
     * Lista pedidos visíveis ao usuário.
     *
     * @param array{busca?:string, fase_id?:string, de?:string, ate?:string, pagina?:int, por_pagina?:int} $f
     * @return array{itens:array, total:int}
     */
    /** Colunas da lista que aceitam ordenação → expressão SQL (whitelist). */
    private const ORDENAVEIS = [
        'fat'      => ['p.ano {d}', 'p.seq {d}'],
        'veiculo'  => ['v.descricao {d}'],
        'cliente'  => ['c.nome {d}'],
        'vendedor' => ['u.nome {d}'],
        'valor'    => ['valor_exibido {d}'],
        'status'   => ['fs.ordem {d}'],
        'inicio'   => ['p.data_inicio {d}'],
    ];

    /** Devolve a chave da coluna se for ordenável; senão ''. */
    public static function colunaOrdenavel(string $chave): string
    {
        return isset(self::ORDENAVEIS[$chave]) || $chave === 'situacao' ? $chave : '';
    }

    public static function listar(string $usuarioId, string $perfil, array $f = []): array
    {
        $pdo    = Database::getInstance();
        $escopo = self::escopo($usuarioId, $perfil);

        $where  = ['1=1'];
        $params = [];

        if ($escopo['tipo'] !== 'todos') {
            if (empty($escopo['vendedores'])) {
                return ['itens' => [], 'total' => 0];
            }
            $in = implode(',', array_fill(0, count($escopo['vendedores']), '?'));
            $where[] = "p.vendedor_id IN ($in)";
            array_push($params, ...$escopo['vendedores']);
        }

        if (!empty($f['busca'])) {
            $where[]  = '(p.numero LIKE ? OR p.pedido_apollo LIKE ? OR c.nome LIKE ? OR v.descricao LIKE ? OR v.codigo LIKE ? OR u.nome LIKE ?)';
            $like     = '%' . $f['busca'] . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if (!empty($f['fase_id'])) {
            $where[]  = 'p.fase_id = ?';
            $params[] = $f['fase_id'];
        }
        if (!empty($f['de'])) {
            $where[]  = 'p.data_inicio >= ?';
            $params[] = $f['de'];
        }
        if (!empty($f['ate'])) {
            $where[]  = 'p.data_inicio <= ?';
            $params[] = $f['ate'];
        }

        $whereSql = implode(' AND ', $where);
        $from = "FROM nf_fat_pedidos p
                 JOIN nf_fat_fases fs ON fs.id = p.fase_id
                 LEFT JOIN usuarios u ON u.id = p.vendedor_id
                 " . self::JOIN_DADOS . "
                 WHERE $whereSql";

        $stmt = $pdo->prepare("SELECT COUNT(*) $from");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $porPagina = max(1, min(100, (int) ($f['por_pagina'] ?? 25)));
        $pagina    = max(1, (int) ($f['pagina'] ?? 1));
        $offset    = ($pagina - 1) * $porPagina;

        $dir     = ($f['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        // Situação é calculada em PHP (exigências × dados/anexos): ordena depois de calcular.
        $porSituacao = ($f['ordem'] ?? '') === 'situacao';
        $colunas = self::ORDENAVEIS[$f['ordem'] ?? ''] ?? null;
        $orderBy = $colunas
            ? implode(', ', array_map(static fn(string $e) => str_replace('{d}', $dir, $e), $colunas)) . ', p.seq DESC'
            : 'p.data_inicio DESC, p.seq DESC';

        $stmt = $pdo->prepare(
            "SELECT p.*, fs.nome AS fase_nome, fs.codigo AS fase_codigo, u.nome AS vendedor_nome,
                    COALESCE(p.valor_total,
                        (SELECT SUM(pg.valor) FROM nf_fat_pagamentos pg WHERE pg.pedido_id = p.id)) AS valor_exibido,
                    " . self::SELECT_DADOS . "
             $from
             ORDER BY $orderBy" . ($porSituacao ? '' : "
             LIMIT $porPagina OFFSET $offset")
        );
        $stmt->execute($params);
        $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($itens as &$it) {
            $it['situacao'] = self::situacao($it);
        }
        unset($it);

        if ($porSituacao) {
            $sinal = ($f['dir'] ?? 'desc') === 'asc' ? 1 : -1;
            usort($itens, static fn(array $a, array $b) =>
                $sinal * ($a['situacao']['pendentes'] <=> $b['situacao']['pendentes'])
                ?: ((int) $b['seq'] <=> (int) $a['seq']));
            $itens = array_slice($itens, $offset, $porPagina);
        }

        return ['itens' => $itens, 'total' => $total];
    }

    public static function buscar(string $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT p.*, fs.nome AS fase_nome, fs.codigo AS fase_codigo, fs.ordem AS fase_ordem,
                    u.nome AS vendedor_nome,' . self::SELECT_DADOS . '
               FROM nf_fat_pedidos p
               JOIN nf_fat_fases fs ON fs.id = p.fase_id
               LEFT JOIN usuarios u ON u.id = p.vendedor_id' . self::JOIN_DADOS . '
              WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** O usuário pode ver este pedido? (mesma regra da listagem) */
    public static function podeVer(array $pedido, string $usuarioId, string $perfil): bool
    {
        $escopo = self::escopo($usuarioId, $perfil);
        return $escopo['tipo'] === 'todos' || in_array($pedido['vendedor_id'], $escopo['vendedores'], true);
    }

    // ─────────────────────────────────────────
    // Permissão por fase
    // ─────────────────────────────────────────

    private static function permissaoNaFase(string $faseId, string $usuarioId, string $perfil, string $coluna): bool
    {
        if ($perfil === 'super_admin') {
            return true;
        }
        $grupos = self::gruposDoUsuario($usuarioId);
        if (!$grupos) {
            return false;
        }
        $in   = implode(',', array_fill(0, count($grupos), '?'));
        $stmt = Database::getInstance()->prepare(
            "SELECT 1 FROM nf_fat_fase_grupos WHERE fase_id = ? AND $coluna = 1 AND grupo_id IN ($in) LIMIT 1"
        );
        $stmt->execute(array_merge([$faseId], $grupos));
        return (bool) $stmt->fetchColumn();
    }

    /** Pode alterar dados/anexos do pedido na fase atual? (precisa também poder ver) */
    public static function podeEditar(array $pedido, string $usuarioId, string $perfil): bool
    {
        return self::podeVer($pedido, $usuarioId, $perfil)
            && self::permissaoNaFase($pedido['fase_id'], $usuarioId, $perfil, 'pode_editar');
    }

    /** Pode dar o OK que avança a fase atual? */
    public static function podeDarOk(array $pedido, string $usuarioId, string $perfil): bool
    {
        return self::podeVer($pedido, $usuarioId, $perfil)
            && self::permissaoNaFase($pedido['fase_id'], $usuarioId, $perfil, 'pode_ok');
    }

    // ─────────────────────────────────────────
    // Situação (requisitos)
    // ─────────────────────────────────────────

    /** Valor de um campo do pedido (coluna ou chave do JSON `dados`). */
    private static function valorCampo(array $pedido, string $chave): string
    {
        if (in_array($chave, self::COLUNAS_CAMPO, true)) {
            return trim((string) ($pedido[$chave] ?? ''));
        }
        static $cache = [];
        $id = $pedido['id'] ?? '';
        if (!isset($cache[$id])) {
            $dec = json_decode((string) ($pedido['dados'] ?? ''), true);
            $cache[$id] = is_array($dec) ? $dec : [];
        }
        $v = $cache[$id][$chave] ?? '';
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /** O requisito se aplica a este pedido? (avalia a condição, se houver) */
    private static function requisitoAplica(array $req, array $pedido): bool
    {
        if (empty($req['condicao_campo'])) {
            return true;
        }
        $valor  = self::valorCampo($pedido, $req['condicao_campo']);
        $lista  = array_map('trim', explode('|', (string) $req['condicao_valores']));
        $contem = in_array($valor, $lista, true);
        return $req['condicao_op'] === 'nao_em' ? !$contem : $contem;
    }

    /**
     * Situação do pedido: cada requisito aplicável com cumprido sim/não.
     *
     * @return array{itens:array, pendentes:int, pendentes_fase:int, completo:bool}
     */
    public static function situacao(array $pedido): array
    {
        $pdo = Database::getInstance();

        $reqs = $pdo->query(
            'SELECT r.*, f.nome AS fase_nome FROM nf_fat_requisitos r
               JOIN nf_fat_fases f ON f.id = r.fase_id
              WHERE r.ativo = 1 ORDER BY f.ordem, r.ordem'
        )->fetchAll(PDO::FETCH_ASSOC);

        $anexos = [];
        if (!empty($pedido['id'])) {
            $stmt = $pdo->prepare('SELECT DISTINCT tipo FROM nf_fat_anexos WHERE pedido_id = ?');
            $stmt->execute([$pedido['id']]);
            $anexos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $itens = [];
        $pend = $pendFase = 0;
        foreach ($reqs as $r) {
            if (!self::requisitoAplica($r, $pedido)) {
                continue;
            }
            $ok = $r['tipo'] === 'anexo'
                ? in_array($r['chave'], $anexos, true)
                : self::valorCampo($pedido, $r['chave']) !== '';

            $daFase = $r['fase_id'] === $pedido['fase_id'];
            if (!$ok) {
                $pend++;
                if ($daFase) {
                    $pendFase++;
                }
            }
            $itens[] = [
                'nome'      => $r['nome'],
                'tipo'      => $r['tipo'],
                'fase_nome' => $r['fase_nome'],
                'da_fase'   => $daFase,
                'cumprido'  => $ok,
            ];
        }

        return [
            'itens'          => $itens,
            'pendentes'      => $pend,
            'pendentes_fase' => $pendFase,
            'completo'       => $pend === 0,
        ];
    }

    // ─────────────────────────────────────────
    // Ações
    // ─────────────────────────────────────────

    /** Próximo número FAT do ano (sequência dentro de transação). */
    private static function proximoSeq(PDO $pdo, int $ano): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM nf_fat_pedidos WHERE ano = ? FOR UPDATE');
        $stmt->execute([$ano]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, array> filiais ativas para o campo A (Empresa / Filial) */
    public static function filiais(): array
    {
        return Database::getInstance()
            ->query('SELECT id, nome, cnpj, endereco, cidade, estado, telefone FROM filiais WHERE ativo = 1 ORDER BY nome')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Quem pode escolher/trocar o vendedor do pedido; os demais ficam como vendedor de si mesmos. */
    public static function podeTrocarVendedor(string $usuarioId, string $perfil): bool
    {
        if ($perfil === 'super_admin') {
            return true;
        }
        return (bool) array_intersect(
            self::nomesGruposDoUsuario($usuarioId),
            ['Secretárias', 'Gerentes de Vendas', 'Diretoria']
        );
    }

    /** "1.234,56" ou "1234.56" → float; vazio → null. */
    private static function dinheiro(string $txt, string $rotulo): ?float
    {
        $t = trim($txt);
        if ($t === '') {
            return null;
        }
        if (str_contains($t, ',')) {
            $t = str_replace(',', '.', str_replace('.', '', $t));
        }
        if (!is_numeric($t) || (float) $t < 0) {
            throw new InvalidArgumentException("Valor inválido em {$rotulo}.");
        }
        return round((float) $t, 2);
    }

    public const PAPEIS_ASSINATURA = [
        'vendedor'           => 'Vendedor',
        'gerente'            => 'Gerente',
        'cliente'            => 'Cliente',
        'terceiro_pagamento' => 'Terceiro (pgto)',
        'terceiro_veiculo'   => 'Terceiro (veíc.)',
    ];

    /** @return array<int, array> signatários do pedido, na ordem do fluxo */
    public static function assinaturasDoPedido(string $pedidoId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM nf_fat_assinaturas WHERE pedido_id = ?
              ORDER BY FIELD(papel,'vendedor','gerente','cliente','terceiro_pagamento','terceiro_veiculo'), atualizado_em"
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Nome/doc de quem assina por uma pessoa do E6: se for PJ assina o representante legal. */
    private static function signatarioDePessoa(array $p): array
    {
        $pj = ($p['tipo_pessoa'] ?? 'pf') === 'pj';
        return [
            'nome'     => $pj ? (string) ($p['rep_nome'] ?? '') : (string) ($p['nome'] ?? ''),
            'doc'      => $pj ? (string) ($p['rep_cpf'] ?? '')  : (string) ($p['doc'] ?? ''),
            'email'    => (string) ($p['email'] ?? ''),
            'telefone' => (string) ($p['telefone'] ?? ''),
        ];
    }

    /**
     * Recalcula quem precisa assinar a partir dos dados gravados do pedido. Mantém o status de
     * quem já existe; só remove quem deixou de ser necessário se ainda estiver pendente.
     */
    private static function sincronizarAssinaturas(PDO $pdo, string $pedidoId, string $fatNumero): void
    {
        $desejados = []; // "papel|origem" => dados

        // Vendedor
        $q = $pdo->prepare('SELECT u.nome, u.email FROM nf_fat_pedidos p JOIN usuarios u ON u.id = p.vendedor_id WHERE p.id = ?');
        $q->execute([$pedidoId]);
        if ($v = $q->fetch(PDO::FETCH_ASSOC)) {
            $desejados['vendedor|'] = ['nome' => $v['nome'], 'doc' => '', 'email' => (string) $v['email'], 'telefone' => ''];
        }

        // Gerente: gestor do grupo (equipe) em que o vendedor está
        $q = $pdo->prepare(
            'SELECT u.nome, u.email
               FROM nf_fat_pedidos p
               JOIN nf_grupo_membros m ON m.usuario_id = p.vendedor_id
               JOIN nf_grupos g ON g.id = m.grupo_id AND g.gestor_id IS NOT NULL AND g.ativo = 1
               JOIN usuarios u ON u.id = g.gestor_id
              WHERE p.id = ? AND u.id <> p.vendedor_id
              ORDER BY u.nome LIMIT 1'
        );
        $q->execute([$pedidoId]);
        if ($g = $q->fetch(PDO::FETCH_ASSOC)) {
            $desejados['gerente|'] = ['nome' => $g['nome'], 'doc' => '', 'email' => (string) $g['email'], 'telefone' => ''];
        }

        // Cliente
        $q = $pdo->prepare('SELECT nome, doc, email, telefone FROM nf_fat_clientes WHERE pedido_id = ?');
        $q->execute([$pedidoId]);
        if (($c = $q->fetch(PDO::FETCH_ASSOC)) && trim((string) $c['nome']) !== '') {
            $desejados['cliente|'] = [
                'nome' => (string) $c['nome'], 'doc' => (string) $c['doc'],
                'email' => (string) $c['email'], 'telefone' => (string) $c['telefone'],
            ];
        }

        // Terceiros vindos das formas de pagamento (E6)
        foreach (self::pagamentosDoPedido($pedidoId) as $pg) {
            $d = $pg['dados'] ?? [];
            if ($pg['tipo'] === 'Pagamento de terceiro' && !empty($d['pagador'])) {
                $sig = self::signatarioDePessoa($d['pagador']);
                if ($sig['nome'] !== '') {
                    $desejados['terceiro_pagamento|' . $pg['id']] = $sig;
                }
            }
            if ($pg['tipo'] === 'Troca' && ($d['veiculo']['em_nome_terceiro'] ?? '') === '1') {
                $sig = self::signatarioDePessoa($d['veiculo']['dono'] ?? []);
                if ($sig['nome'] !== '') {
                    $desejados['terceiro_veiculo|' . $pg['id']] = $sig;
                }
            }
        }

        $q = $pdo->prepare('SELECT id, papel, origem_id, status FROM nf_fat_assinaturas WHERE pedido_id = ?');
        $q->execute([$pedidoId]);
        $existentes = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $existentes[$e['papel'] . '|' . $e['origem_id']] = $e;
        }

        $ins = $pdo->prepare('INSERT INTO nf_fat_assinaturas (id, pedido_id, fat_numero, papel, origem_id, nome, doc, email, telefone) VALUES (?,?,?,?,?,?,?,?,?)');
        $upd = $pdo->prepare('UPDATE nf_fat_assinaturas SET nome = ?, doc = ?, email = ?, telefone = ? WHERE id = ?');
        foreach ($desejados as $chave => $s) {
            [$papel, $origem] = explode('|', $chave, 2);
            if (isset($existentes[$chave])) {
                $upd->execute([$s['nome'], $s['doc'], $s['email'], $s['telefone'], $existentes[$chave]['id']]);
            } else {
                $ins->execute([self::uuid(), $pedidoId, $fatNumero, $papel, $origem, $s['nome'], $s['doc'], $s['email'], $s['telefone']]);
            }
        }

        $del = $pdo->prepare("DELETE FROM nf_fat_assinaturas WHERE id = ? AND status = 'pendente'");
        foreach ($existentes as $chave => $e) {
            if (!isset($desejados[$chave])) {
                $del->execute([$e['id']]);
            }
        }
    }

    /** Tipo de anexo do comprovante de cada forma de pagamento (E3). */
    public const ANEXO_COMPROVANTE = 'comprovante_pagamento';

    /**
     * Tipos de anexo do grupo G (chave => rótulo): os fixos do fluxo mais os exigidos em
     * nf_fat_requisitos. O comprovante de pagamento fica fora — é anexado em cada linha de E.
     *
     * @return array<string,string>
     */
    public static function tiposAnexo(): array
    {
        $tipos = [
            'cnh_cliente'          => 'CNH do cliente',
            'comprovante_endereco' => 'Comprovante de endereço',
            'pedido_assinado'      => 'Pedido assinado',
            'proposta_assinada'    => 'Proposta assinada',
        ];
        $q = Database::getInstance()->query("SELECT chave, nome FROM nf_fat_requisitos WHERE tipo = 'anexo' AND ativo = 1 ORDER BY ordem");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['chave'] !== self::ANEXO_COMPROVANTE) {
                $tipos[$r['chave']] = $r['nome'];
            }
        }
        return $tipos;
    }

    /** @return array<int, array> anexos do pedido (mais antigos primeiro) */
    public static function anexosDoPedido(string $pedidoId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT a.id, a.tipo, a.pagamento_id, a.nome_original, a.criado_em, u.nome AS enviado_por_nome
               FROM nf_fat_anexos a LEFT JOIN usuarios u ON u.id = a.enviado_por
              WHERE a.pedido_id = ? ORDER BY a.criado_em, a.id'
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function buscarAnexo(string $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM nf_fat_anexos WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Guarda o arquivo já gravado em disco por Upload::salvar e registra o anexo.
     *
     * @throws InvalidArgumentException tipo ou forma de pagamento inválidos
     */
    public static function registrarAnexo(array $pedido, string $tipo, ?string $pagamentoId, array $meta, string $usuarioId): string
    {
        $pdo = Database::getInstance();

        if ($tipo !== self::ANEXO_COMPROVANTE && !isset(self::tiposAnexo()[$tipo])) {
            throw new InvalidArgumentException('Tipo de anexo inválido.');
        }
        if ($pagamentoId !== null) {
            if ($tipo !== self::ANEXO_COMPROVANTE) {
                throw new InvalidArgumentException('Só o comprovante pode ser ligado a uma forma de pagamento.');
            }
            $q = $pdo->prepare('SELECT 1 FROM nf_fat_pagamentos WHERE id = ? AND pedido_id = ?');
            $q->execute([$pagamentoId, $pedido['id']]);
            if (!$q->fetchColumn()) {
                throw new InvalidArgumentException('Forma de pagamento inválida.');
            }
        }

        $relativo = ltrim(str_replace(str_replace('\\', '/', STORAGE), '', str_replace('\\', '/', $meta['caminho'])), '/');
        $id       = self::uuid();
        $pdo->prepare(
            'INSERT INTO nf_fat_anexos (id, pedido_id, fat_numero, pagamento_id, tipo, nome_original, caminho, enviado_por)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$id, $pedido['id'], $pedido['numero'], $pagamentoId, $tipo, $meta['nome_original'], $relativo, $usuarioId]);

        self::historico($pdo, $pedido['id'], $pedido['numero'], 'anexo', null, null, $usuarioId, 'Anexo enviado: ' . $meta['nome_original']);
        return $id;
    }

    /** Caminho absoluto do arquivo do anexo, só se estiver dentro da pasta do próprio pedido. */
    public static function caminhoDoAnexo(array $anexo): ?string
    {
        $base = realpath(STORAGE . '/processos/faturamento/' . $anexo['pedido_id']);
        $abs  = realpath(STORAGE . '/' . $anexo['caminho']);
        if ($base === false || $abs === false || !str_starts_with($abs, $base . DIRECTORY_SEPARATOR) || !is_file($abs)) {
            return null;
        }
        return $abs;
    }

    public static function removerAnexo(array $pedido, array $anexo, string $usuarioId): void
    {
        $pdo = Database::getInstance();
        $abs = self::caminhoDoAnexo($anexo);
        $pdo->prepare('DELETE FROM nf_fat_anexos WHERE id = ? AND pedido_id = ?')->execute([$anexo['id'], $pedido['id']]);
        if ($abs !== null) {
            @unlink($abs);
        }
        self::historico($pdo, $pedido['id'], $pedido['numero'], 'anexo', null, null, $usuarioId, 'Anexo removido: ' . $anexo['nome_original']);
    }

    public const TIPOS_PAGAMENTO = ['Financiamento', 'Entrada', 'Troca', 'Consórcio', 'Boleto', 'Pagamento de terceiro'];

    private const CHAVES_PESSOA = ['tipo_pessoa', 'nome', 'doc', 'relacao', 'rep_nome', 'rep_cpf', 'telefone', 'email'];

    /** @return array<int, array> linhas de pagamento (E) do pedido, com `dados` já decodificado */
    public static function pagamentosDoPedido(string $pedidoId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, ordem, tipo, valor, data, dados FROM nf_fat_pagamentos WHERE pedido_id = ? ORDER BY ordem, id'
        );
        $stmt->execute([$pedidoId]);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($linhas as &$l) {
            $l['dados'] = $l['dados'] ? (json_decode((string) $l['dados'], true) ?: []) : [];
        }
        unset($l);
        return $linhas;
    }

    /** Campos de uma pessoa (pagador / dono do veículo) — só as chaves conhecidas, textos curtos. */
    private static function limparPessoa(mixed $p): array
    {
        $p   = is_array($p) ? $p : [];
        $out = [];
        foreach (self::CHAVES_PESSOA as $k) {
            $v = mb_substr(trim(strip_tags((string) ($p[$k] ?? ''))), 0, 150);
            if ($k === 'tipo_pessoa') {
                $v = $v === 'pj' ? 'pj' : 'pf';
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Valida as linhas de pagamento vindas do formulário (JSON). Linhas totalmente
     * vazias são descartadas. Troca guarda só o veículo entregue; os demais tipos, só o pagador.
     *
     * @return array<int, array{id:string, tipo:string, valor:?float, data:?string, dados:?array}>|null
     *         null = o formulário não enviou pagamentos (não mexer nos existentes)
     */
    private static function normalizarPagamentos(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $brutas = json_decode($json, true);
        if (!is_array($brutas)) {
            throw new InvalidArgumentException('Formas de pagamento inválidas.');
        }

        $linhas = [];
        foreach ($brutas as $i => $b) {
            if (!is_array($b)) {
                continue;
            }
            $n     = count($linhas) + 1;
            $tipo  = trim((string) ($b['tipo'] ?? ''));
            if (!in_array($tipo, self::TIPOS_PAGAMENTO, true)) {
                throw new InvalidArgumentException("Forma de pagamento {$n}: tipo inválido.");
            }
            $valor = self::dinheiro((string) ($b['valor'] ?? ''), "Forma de pagamento {$n} (valor)");

            $data = trim((string) ($b['data'] ?? ''));
            if ($data !== '') {
                $dt = DateTime::createFromFormat('Y-m-d', $data);
                if (!$dt || $dt->format('Y-m-d') !== $data) {
                    throw new InvalidArgumentException("Forma de pagamento {$n}: data inválida.");
                }
            }

            $d = is_array($b['dados'] ?? null) ? $b['dados'] : [];
            if ($tipo === 'Troca') {
                $v   = is_array($d['veiculo'] ?? null) ? $d['veiculo'] : [];
                $dados = ['veiculo' => [
                    'placa'            => mb_substr(trim(strip_tags((string) ($v['placa'] ?? ''))), 0, 10),
                    'modelo'           => mb_substr(trim(strip_tags((string) ($v['modelo'] ?? ''))), 0, 100),
                    'avaliacao'        => self::dinheiro((string) ($v['avaliacao'] ?? ''), "Forma de pagamento {$n} (avaliação)"),
                    'em_nome_terceiro' => (string) ($v['em_nome_terceiro'] ?? '') === '1' ? '1' : '',
                    'dono'             => self::limparPessoa($v['dono'] ?? []),
                ]];
                $vazio = $dados['veiculo']['placa'] === '' && $dados['veiculo']['modelo'] === '' && $dados['veiculo']['avaliacao'] === null;
            } else {
                $dados = ['pagador' => self::limparPessoa($d['pagador'] ?? [])];
                $vazio = $dados['pagador']['nome'] === '' && $dados['pagador']['doc'] === ''
                      && $dados['pagador']['telefone'] === '' && $dados['pagador']['email'] === '';
            }

            if ($valor === null && $data === '' && $vazio) {
                continue; // linha em branco
            }
            $linhas[] = [
                'id'    => trim((string) ($b['id'] ?? '')),
                'tipo'  => $tipo,
                'valor' => $valor,
                'data'  => $data !== '' ? $data : null,
                'dados' => $vazio ? null : $dados,
            ];
        }
        return $linhas;
    }

    /** Sincroniza as linhas do pedido: atualiza as que têm id, insere as novas, apaga as removidas. */
    private static function gravarPagamentos(PDO $pdo, string $pedidoId, string $fatNumero, array $linhas): void
    {
        $q = $pdo->prepare('SELECT id FROM nf_fat_pagamentos WHERE pedido_id = ?');
        $q->execute([$pedidoId]);
        $existentes = $q->fetchAll(PDO::FETCH_COLUMN);

        $mantidos = [];
        $upd = $pdo->prepare('UPDATE nf_fat_pagamentos SET ordem = ?, tipo = ?, valor = ?, data = ?, dados = ? WHERE id = ? AND pedido_id = ?');
        $ins = $pdo->prepare('INSERT INTO nf_fat_pagamentos (id, pedido_id, fat_numero, ordem, tipo, valor, data, dados) VALUES (?,?,?,?,?,?,?,?)');

        foreach ($linhas as $i => $l) {
            $json  = $l['dados'] === null ? null : json_encode($l['dados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ordem = $i + 1;
            if ($l['id'] !== '' && in_array($l['id'], $existentes, true)) {
                $upd->execute([$ordem, $l['tipo'], $l['valor'], $l['data'], $json, $l['id'], $pedidoId]);
                $mantidos[] = $l['id'];
            } else {
                $novoId = self::uuid();
                $ins->execute([$novoId, $pedidoId, $fatNumero, $ordem, $l['tipo'], $l['valor'], $l['data'], $json]);
                $mantidos[] = $novoId;
            }
        }

        $del = $pdo->prepare('DELETE FROM nf_fat_pagamentos WHERE id = ? AND pedido_id = ?');
        foreach (array_diff($existentes, $mantidos) as $idRemovido) {
            $del->execute([$idRemovido, $pedidoId]);
        }
    }

    /** Valida e normaliza os campos do formulário do pedido (grupos A–D). */
    private static function normalizar(array $p): array
    {
        $txt = static fn(string $k, int $max) => mb_substr(trim(strip_tags((string) ($p[$k] ?? ''))), 0, $max);

        $tipo = $txt('tipo_venda', 30);
        if ($tipo !== '' && !in_array($tipo, self::TIPOS_VENDA, true)) {
            throw new InvalidArgumentException('Tipo de venda inválido.');
        }

        $filial = $txt('filial_id', 36);
        if ($filial !== '') {
            $q = Database::getInstance()->prepare('SELECT 1 FROM filiais WHERE id = ? AND ativo = 1');
            $q->execute([$filial]);
            if (!$q->fetchColumn()) {
                throw new InvalidArgumentException('Empresa / Filial inválida.');
            }
        }

        $vv = self::dinheiro((string) ($p['valor_veiculo'] ?? ''), 'Valor do veículo');
        $op = self::dinheiro((string) ($p['valor_opcionais'] ?? ''), 'Opcionais');
        $fr = self::dinheiro((string) ($p['valor_frete'] ?? ''), 'Frete');
        $total = ($vv === null && $op === null && $fr === null) ? null : round(($vv ?? 0) + ($op ?? 0) + ($fr ?? 0), 2);

        $modelo = $txt('veiculo_modelo', 100);

        return [
            'tipo_venda'            => $tipo !== '' ? $tipo : null,
            'pedido_apollo'         => ($pa = $txt('pedido_apollo', 30)) !== '' ? $pa : null,
            'filial_id'             => $filial !== '' ? $filial : null,
            'cliente_nome'          => $txt('cliente_nome', 150),
            'cliente_doc'           => $txt('cliente_doc', 20),
            'cliente_endereco'      => $txt('cliente_endereco', 200),
            'cliente_numero'        => $txt('cliente_numero', 20),
            'cliente_cidade'        => $txt('cliente_cidade', 100),
            'cliente_uf'            => strtoupper(mb_substr($txt('cliente_uf', 2), 0, 2)),
            'cliente_telefone'      => $txt('cliente_telefone', 30),
            'cliente_email'         => $txt('cliente_email', 150),
            'veiculo_codigo'        => $txt('veiculo_codigo', 40),
            'veiculo_desc'          => $modelo,
            'veiculo_modelo'        => $modelo,
            'veiculo_cor'           => $txt('veiculo_cor', 40),
            'veiculo_ano_fab_mod'   => $txt('veiculo_ano_fab_mod', 9),
            'veiculo_combustivel'   => $txt('veiculo_combustivel', 30),
            'valor_veiculo'         => $vv,
            'valor_opcionais'       => $op,
            'valor_frete'           => $fr,
            'valor_total'           => $total,
        ];
    }

    /**
     * Grava o formulário do pedido (grupos A–D). Sem $id cria o pedido (número FAT
     * gerado); com $id atualiza, se o usuário puder editar na fase atual.
     *
     * @return string id do pedido
     * @throws InvalidArgumentException dado inválido
     * @throws RuntimeException sem permissão / pedido inexistente
     */
    public static function salvar(?string $id, array $post, string $usuarioId, string $perfil): string
    {
        $d            = self::normalizar($post);
        $pagamentos   = self::normalizarPagamentos($post['pagamentos'] ?? null);
        $vendedorPost = trim((string) ($post['vendedor_id'] ?? ''));
        $podeTrocar   = self::podeTrocarVendedor($usuarioId, $perfil);

        if ($id === null) {
            $vendedor = ($podeTrocar && $vendedorPost !== '') ? $vendedorPost : $usuarioId;
            if ($vendedor !== $usuarioId && !in_array($vendedor, array_column(self::vendedores(), 'id'), true)) {
                throw new InvalidArgumentException('Vendedor inválido.');
            }
            $novoId = self::criar($d + ['vendedor_id' => $vendedor], $usuarioId);
            if ($pagamentos) {
                $numero = (string) Database::getInstance()->query('SELECT numero FROM nf_fat_pedidos WHERE id = ' . Database::getInstance()->quote($novoId))->fetchColumn();
                self::gravarPagamentos(Database::getInstance(), $novoId, $numero, $pagamentos);
            }
            self::sincronizarAssinaturas(Database::getInstance(), $novoId, (string) Database::getInstance()->query('SELECT numero FROM nf_fat_pedidos WHERE id = ' . Database::getInstance()->quote($novoId))->fetchColumn());
            return $novoId;
        }

        $pedido = self::buscar($id);
        if (!$pedido || !self::podeEditar($pedido, $usuarioId, $perfil)) {
            throw new RuntimeException('Você não pode editar este pedido na fase atual.');
        }

        $vendedor = $pedido['vendedor_id'];
        $obs      = 'Dados do pedido salvos';
        if ($podeTrocar && $vendedorPost !== '' && $vendedorPost !== $pedido['vendedor_id']) {
            if (!in_array($vendedorPost, array_column(self::vendedores($pedido['vendedor_id']), 'id'), true)) {
                throw new InvalidArgumentException('Vendedor inválido.');
            }
            $nomes = Database::getInstance()->prepare('SELECT nome FROM usuarios WHERE id = ?');
            $nomes->execute([$pedido['vendedor_id']]);
            $de = (string) $nomes->fetchColumn();
            $nomes->execute([$vendedorPost]);
            $para = (string) $nomes->fetchColumn();
            $vendedor = $vendedorPost;
            $obs      = "Vendedor alterado: {$de} → {$para}";
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE nf_fat_pedidos
                    SET tipo_venda = ?, pedido_apollo = ?, filial_id = ?, vendedor_id = ?,
                        valor_veiculo = ?, valor_opcionais = ?, valor_frete = ?, valor_total = ?
                  WHERE id = ?'
            )->execute([
                $d['tipo_venda'], $d['pedido_apollo'], $d['filial_id'], $vendedor,
                $d['valor_veiculo'], $d['valor_opcionais'], $d['valor_frete'], $d['valor_total'], $id,
            ]);

            $pdo->prepare(
                'INSERT INTO nf_fat_clientes (id, pedido_id, fat_numero, nome, doc, endereco, numero, cidade, uf, telefone, email)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE nome = VALUES(nome), doc = VALUES(doc), endereco = VALUES(endereco),
                                         numero = VALUES(numero), cidade = VALUES(cidade), uf = VALUES(uf),
                                         telefone = VALUES(telefone), email = VALUES(email)'
            )->execute([
                self::uuid(), $id, $pedido['numero'],
                $d['cliente_nome'], $d['cliente_doc'], $d['cliente_endereco'],
                $d['cliente_numero'], $d['cliente_cidade'], $d['cliente_uf'],
                $d['cliente_telefone'], $d['cliente_email'],
            ]);

            $pdo->prepare(
                'INSERT INTO nf_fat_veiculos (id, pedido_id, fat_numero, codigo, descricao, modelo, cor, ano_fab_mod, combustivel)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE codigo = VALUES(codigo), descricao = VALUES(descricao), modelo = VALUES(modelo),
                                         cor = VALUES(cor), ano_fab_mod = VALUES(ano_fab_mod), combustivel = VALUES(combustivel)'
            )->execute([
                self::uuid(), $id, $pedido['numero'],
                $d['veiculo_codigo'], $d['veiculo_desc'], $d['veiculo_modelo'],
                $d['veiculo_cor'], $d['veiculo_ano_fab_mod'], $d['veiculo_combustivel'],
            ]);

            if ($pagamentos !== null) {
                self::gravarPagamentos($pdo, $id, $pedido['numero'], $pagamentos);
            }

            self::sincronizarAssinaturas($pdo, $id, $pedido['numero']);

            self::historico($pdo, $id, $pedido['numero'], 'edicao', null, null, $usuarioId, $obs);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /**
     * Cria pedido na primeira fase.
     *
     * @param array{vendedor_id:string, tipo_venda?:?string, filial_id?:?string, cliente_nome?:?string,
     *              cliente_doc?:?string, veiculo_codigo?:?string, veiculo_desc?:?string,
     *              apollo_ref?:?string, dados?:array, data_inicio?:string} $d
     * @return string id do pedido
     */
    public static function criar(array $d, string $criadoPor): string
    {
        $pdo   = Database::getInstance();
        $fases = self::fases();
        if (!$fases) {
            throw new RuntimeException('Nenhuma fase de faturamento cadastrada.');
        }

        $pdo->beginTransaction();
        try {
            $id     = self::uuid();
            $ano    = (int) date('Y');
            $seq    = self::proximoSeq($pdo, $ano);
            $numero = sprintf('FAT-%d-%06d', $ano, $seq);

            $pdo->prepare(
                'INSERT INTO nf_fat_pedidos
                   (id, numero, ano, seq, fase_id, tipo_venda, pedido_apollo, filial_id, vendedor_id, dados, apollo_ref, data_inicio, criado_por,
                    valor_veiculo, valor_opcionais, valor_frete, valor_total)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $id, $numero, $ano, $seq, $fases[0]['id'],
                $d['tipo_venda'] ?? null, $d['pedido_apollo'] ?? null, $d['filial_id'] ?? null, $d['vendedor_id'],
                isset($d['dados']) ? json_encode($d['dados'], JSON_UNESCAPED_UNICODE) : null,
                $d['apollo_ref'] ?? null,
                $d['data_inicio'] ?? date('Y-m-d'),
                $criadoPor,
                $d['valor_veiculo'] ?? null, $d['valor_opcionais'] ?? null,
                $d['valor_frete'] ?? null, $d['valor_total'] ?? null,
            ]);

            // Cliente e veículo do FAT: uma linha por FAT, com a chave do FAT
            $pdo->prepare(
                'INSERT INTO nf_fat_clientes
                   (id, pedido_id, fat_numero, nome, doc, endereco, numero, cidade, uf, telefone, email, estado_civil, nacionalidade)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                self::uuid(), $id, $numero,
                $d['cliente_nome'] ?? null, $d['cliente_doc'] ?? null, $d['cliente_endereco'] ?? null,
                $d['cliente_numero'] ?? null, $d['cliente_cidade'] ?? null, $d['cliente_uf'] ?? null,
                $d['cliente_telefone'] ?? null, $d['cliente_email'] ?? null,
                $d['cliente_estado_civil'] ?? null, $d['cliente_nacionalidade'] ?? null,
            ]);
            $pdo->prepare(
                'INSERT INTO nf_fat_veiculos
                   (id, pedido_id, fat_numero, codigo, descricao, modelo, cor, ano_fab_mod, combustivel, chassi, placa)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                self::uuid(), $id, $numero,
                $d['veiculo_codigo'] ?? null, $d['veiculo_desc'] ?? null, $d['veiculo_modelo'] ?? null,
                $d['veiculo_cor'] ?? null, $d['veiculo_ano_fab_mod'] ?? null, $d['veiculo_combustivel'] ?? null,
                $d['veiculo_chassi'] ?? null, $d['veiculo_placa'] ?? null,
            ]);

            self::historico($pdo, $id, $numero, 'criado', null, $fases[0]['id'], $criadoPor, null);
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Dá o OK e avança para a próxima fase.
     *
     * @return array{ok:bool, mensagem:string}
     */
    public static function avancar(string $pedidoId, string $usuarioId, string $perfil): array
    {
        $pedido = self::buscar($pedidoId);
        if (!$pedido) {
            return ['ok' => false, 'mensagem' => 'Pedido não encontrado.'];
        }
        if (!self::podeDarOk($pedido, $usuarioId, $perfil)) {
            return ['ok' => false, 'mensagem' => 'Você não pode dar o OK nesta fase.'];
        }
        $prox = self::proximaFase($pedido['fase_id']);
        if (!$prox) {
            return ['ok' => false, 'mensagem' => 'O pedido já está na última fase.'];
        }
        $sit = self::situacao($pedido);
        if ($sit['pendentes_fase'] > 0) {
            return ['ok' => false, 'mensagem' => 'Existem ' . $sit['pendentes_fase'] . ' pendência(s) desta fase. Conclua antes de avançar.'];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            // Guarda contra duplo clique / OK concorrente: só avança se ainda estiver na fase lida
            $stmt = $pdo->prepare('UPDATE nf_fat_pedidos SET fase_id = ? WHERE id = ? AND fase_id = ?');
            $stmt->execute([$prox['id'], $pedidoId, $pedido['fase_id']]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'mensagem' => 'O pedido já foi avançado por outra pessoa.'];
            }
            self::historico($pdo, $pedidoId, $pedido['numero'], 'ok', $pedido['fase_id'], $prox['id'], $usuarioId, null);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'mensagem' => 'Pedido avançado para ' . $prox['nome'] . '.'];
    }

    /** Fase ativa imediatamente anterior à informada, ou null se já é a primeira. */
    public static function faseAnterior(string $faseId): ?array
    {
        $fases = self::fases();
        foreach ($fases as $i => $f) {
            if ($f['id'] === $faseId) {
                return $i > 0 ? $fases[$i - 1] : null;
            }
        }
        return null;
    }

    /**
     * Devolve o pedido à fase anterior (quem tem a vez — mesma permissão do OK — e com motivo).
     *
     * @return array{ok:bool, mensagem:string}
     */
    public static function devolver(string $pedidoId, string $usuarioId, string $perfil, string $motivo): array
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            return ['ok' => false, 'mensagem' => 'Informe o motivo da devolução.'];
        }
        $pedido = self::buscar($pedidoId);
        if (!$pedido) {
            return ['ok' => false, 'mensagem' => 'Pedido não encontrado.'];
        }
        if (!self::podeDarOk($pedido, $usuarioId, $perfil)) {
            return ['ok' => false, 'mensagem' => 'Você não pode devolver o pedido nesta fase.'];
        }
        $ant = self::faseAnterior($pedido['fase_id']);
        if (!$ant) {
            return ['ok' => false, 'mensagem' => 'O pedido já está na primeira fase.'];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE nf_fat_pedidos SET fase_id = ? WHERE id = ? AND fase_id = ?');
            $stmt->execute([$ant['id'], $pedidoId, $pedido['fase_id']]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'mensagem' => 'O pedido já foi movido por outra pessoa.'];
            }
            self::historico($pdo, $pedidoId, $pedido['numero'], 'devolucao', $pedido['fase_id'], $ant['id'], $usuarioId, mb_substr($motivo, 0, 500));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'mensagem' => 'Pedido devolvido para ' . $ant['nome'] . '.'];
    }

    public static function historicoDoPedido(string $pedidoId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT h.*, fa.nome AS de_nome, fp.nome AS para_nome, u.nome AS usuario_nome
               FROM nf_fat_historico h
               LEFT JOIN nf_fat_fases fa ON fa.id = h.fase_de
               LEFT JOIN nf_fat_fases fp ON fp.id = h.fase_para
               LEFT JOIN usuarios u ON u.id = h.usuario_id
              WHERE h.pedido_id = ? ORDER BY h.id DESC'
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // Configuração (tela de controle)
    // ─────────────────────────────────────────

    public const TIPOS_VENDA = ['Novos', 'Seminovos', 'Repasse', 'Venda Direta', 'Corsia'];

    /** Rótulos dos campos que podem virar requisito (chave → rótulo). */
    public static function catalogoCampos(): array
    {
        return [
            'tipo_venda' => 'Tipo de venda', 'filial_id' => 'Empresa / Filial',
            'cliente_nome' => 'Cliente — nome', 'cliente_doc' => 'Cliente — CPF/CNPJ',
            'cliente_endereco' => 'Cliente — endereço', 'cliente_numero' => 'Cliente — número',
            'cliente_cidade' => 'Cliente — cidade', 'cliente_uf' => 'Cliente — UF',
            'cliente_telefone' => 'Cliente — telefone', 'cliente_email' => 'Cliente — e-mail',
            'veiculo_codigo' => 'Veículo — unidade específica', 'veiculo_desc' => 'Veículo — descrição',
            'veiculo_modelo' => 'Veículo — modelo', 'veiculo_cor' => 'Veículo — cor',
            'veiculo_ano_fab_mod' => 'Veículo — ano fab/mod', 'veiculo_combustivel' => 'Veículo — combustível',
            'veiculo_chassi' => 'Veículo — chassi', 'veiculo_placa' => 'Veículo — placa',
        ];
    }

    /** Grupos que participam do fluxo (os de papel do Faturamento). */
    public static function gruposPapel(): array
    {
        $nomes = array_merge(self::GRUPOS_VISAO_TOTAL, ['Vendedores', 'Gerentes de Vendas']);
        $in    = implode(',', array_fill(0, count($nomes), '?'));
        $stmt  = Database::getInstance()->prepare("SELECT id, nome FROM nf_grupos WHERE nome IN ($in) AND ativo = 1 ORDER BY nome");
        $stmt->execute($nomes);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Todas as fases (inclusive inativas) com contagem de pedidos e permissões por grupo. */
    public static function fasesConfig(): array
    {
        $pdo   = Database::getInstance();
        $fases = $pdo->query(
            'SELECT f.*, (SELECT COUNT(*) FROM nf_fat_pedidos p WHERE p.fase_id = f.id) AS qtd_pedidos
               FROM nf_fat_fases f ORDER BY f.ordem'
        )->fetchAll(PDO::FETCH_ASSOC);
        $perms = [];
        foreach ($pdo->query('SELECT fase_id, grupo_id, pode_editar, pode_ok FROM nf_fat_fase_grupos') as $r) {
            $perms[$r['fase_id']][$r['grupo_id']] = $r;
        }
        foreach ($fases as &$f) {
            $f['perms'] = $perms[$f['id']] ?? [];
        }
        return $fases;
    }

    public static function salvarFase(?string $id, string $nome, int $ordem, bool $ativo): string
    {
        $pdo = Database::getInstance();
        if ($id === null) {
            $id     = self::uuid();
            $codigo = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $nome))), '_');
            $codigo = $codigo !== '' ? $codigo : 'fase';
            $existe = $pdo->prepare('SELECT COUNT(*) FROM nf_fat_fases WHERE codigo = ?');
            $base = $codigo; $n = 2;
            while (true) {
                $existe->execute([$codigo]);
                if (!(int) $existe->fetchColumn()) break;
                $codigo = $base . '_' . $n++;
            }
            $pdo->prepare('INSERT INTO nf_fat_fases (id, codigo, nome, ordem, ativo) VALUES (?,?,?,?,?)')
                ->execute([$id, $codigo, $nome, $ordem, (int) $ativo]);
            return $id;
        }
        $pdo->prepare('UPDATE nf_fat_fases SET nome = ?, ordem = ?, ativo = ? WHERE id = ?')
            ->execute([$nome, $ordem, (int) $ativo, $id]);
        return $id;
    }

    /** Fase com pedidos não pode ser desativada (o pedido ficaria órfão no fluxo). */
    public static function faseTemPedidos(string $faseId): bool
    {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM nf_fat_pedidos WHERE fase_id = ?');
        $stmt->execute([$faseId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Regrava as permissões da fase.
     *
     * @param array<string,array{editar?:bool,ok?:bool}> $porGrupo grupo_id => flags
     */
    public static function salvarPermissoesFase(string $faseId, array $porGrupo): void
    {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM nf_fat_fase_grupos WHERE fase_id = ?')->execute([$faseId]);
            $ins = $pdo->prepare('INSERT INTO nf_fat_fase_grupos (fase_id, grupo_id, pode_editar, pode_ok) VALUES (?,?,?,?)');
            foreach ($porGrupo as $grupoId => $fl) {
                $ed = !empty($fl['editar']) || !empty($fl['ok']); // quem dá OK precisa poder ver/editar a fase
                $ok = !empty($fl['ok']);
                if ($ed || $ok) {
                    $ins->execute([$faseId, $grupoId, (int) $ed, (int) $ok]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function requisitosConfig(): array
    {
        return Database::getInstance()->query(
            'SELECT r.*, f.nome AS fase_nome FROM nf_fat_requisitos r
               JOIN nf_fat_fases f ON f.id = r.fase_id
              ORDER BY f.ordem, r.ordem, r.nome'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array{nome:string,tipo:string,chave:string,fase_id:string,condicao_campo:?string,condicao_op:?string,condicao_valores:?string,ordem:int,ativo:bool} $d */
    public static function salvarRequisito(?string $id, array $d): void
    {
        $pdo = Database::getInstance();
        $vals = [$d['nome'], $d['tipo'], $d['chave'], $d['fase_id'], $d['condicao_campo'], $d['condicao_op'],
                 $d['condicao_valores'], $d['ordem'], (int) $d['ativo']];
        if ($id === null) {
            $pdo->prepare(
                'INSERT INTO nf_fat_requisitos (nome, tipo, chave, fase_id, condicao_campo, condicao_op, condicao_valores, ordem, ativo, id)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute(array_merge($vals, [self::uuid()]));
            return;
        }
        $pdo->prepare(
            'UPDATE nf_fat_requisitos SET nome=?, tipo=?, chave=?, fase_id=?, condicao_campo=?, condicao_op=?,
                    condicao_valores=?, ordem=?, ativo=? WHERE id=?'
        )->execute(array_merge($vals, [$id]));
    }

    private static function historico(PDO $pdo, string $pedidoId, string $fatNumero, string $acao, ?string $de, ?string $para, ?string $usuarioId, ?string $obs): void
    {
        $pdo->prepare(
            'INSERT INTO nf_fat_historico (pedido_id, fat_numero, acao, fase_de, fase_para, usuario_id, observacao) VALUES (?,?,?,?,?,?,?)'
        )->execute([$pedidoId, $fatNumero, $acao, $de, $para, $usuarioId, $obs]);
    }
}
