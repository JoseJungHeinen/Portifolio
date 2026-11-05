<?php
// @versao Ace_09 V_01_12/08/2026
declare(strict_types=1);

/**
 * Horários de acesso — jornada em que cada usuário pode entrar no sistema.
 *
 * Desenho e decisões: docs/IDEIA-CONTROLE-HORARIO-ACESSO.md
 *
 * Regras que valem para o sistema todo:
 *
 *   - Usuário SEM horário atribuído não tem limite. Ausência de linha em
 *     nf_usuario_horario é a regra, não um horário "24h" cadastrado.
 *   - super_admin nunca é barrado. Sem isso, um erro de cadastro tranca todo
 *     mundo para fora — inclusive quem consertaria.
 *   - A comparação usa o relógio do PHP (America/Sao_Paulo, definido em
 *     config/constants.php), NUNCA o NOW() do MySQL. O banco do Server-APP
 *     esteve em UTC: um NOW() diria 12h quando são 9h e barraria a empresa.
 */
class AccessSchedule
{
    /** date('w') → rótulo, na ordem em que a tela mostra. */
    public const DIAS = [
        1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta',
        5 => 'Sexta',   6 => 'Sábado', 0 => 'Domingo',
    ];

    // ─────────────────────────────────────────
    // A pergunta que o Auth faz
    // ─────────────────────────────────────────

    /**
     * O usuário pode estar no sistema neste instante?
     *
     * @param int|null $ts instante a avaliar (default: agora). Existe para o teste.
     * @return array{liberado: bool, motivo: string, horario: string, janela: string, volta: string}
     */
    public static function avaliar(string $usuarioId, ?int $ts = null): array
    {
        $ts    = $ts ?? time();
        $livre = ['liberado' => true, 'motivo' => '', 'horario' => '', 'janela' => '', 'volta' => ''];

        if ($usuarioId === '') {
            return $livre;
        }

        $horario = self::doUsuario($usuarioId);
        if (!$horario) {
            return $livre;                 // sem horário atribuído = sem limite
        }
        if ((int) $horario['ativo'] !== 1) {
            return $livre;                 // horário desativado não barra ninguém
        }

        if (self::temExcecao($usuarioId, $ts)) {
            return ['liberado' => true, 'motivo' => 'excecao', 'horario' => $horario['nome'],
                    'janela' => '', 'volta' => ''];
        }

        $faixa = self::faixaDoDia($horario['id'], (int) date('w', $ts));
        $agora = date('H:i:s', $ts);
        $tol   = (int) $horario['tolerancia_minutos'];

        foreach ([['t1_inicio', 't1_fim'], ['t2_inicio', 't2_fim']] as [$ini, $fim]) {
            if (empty($faixa[$ini]) || empty($faixa[$fim])) {
                continue;
            }
            $limite = self::somaMinutos((string) $faixa[$fim], $tol);
            if ($agora >= $faixa[$ini] && $agora <= $limite) {
                return ['liberado' => true, 'motivo' => '', 'horario' => $horario['nome'],
                        'janela' => substr((string) $faixa[$ini], 0, 5) . '–' . substr((string) $faixa[$fim], 0, 5),
                        'volta' => ''];
            }
        }

        return [
            'liberado' => false,
            'motivo'   => 'fora_do_horario',
            'horario'  => (string) $horario['nome'],
            'janela'   => self::descreveDia($faixa),
            'volta'    => self::proximaAbertura($horario['id'], $ts),
        ];
    }

    /** Atalho de sessão usado pelo Auth. */
    public static function avaliarSessao(?int $ts = null): array
    {
        $uid = (string) ($_SESSION['usuario_id'] ?? '');
        return self::avaliar($uid, $ts);
    }

    /**
     * Minutos que faltam para a janela fechar, ou null se não houver limite.
     * É o que alimenta o aviso prévio — a política é solicitar exceção ANTES
     * de ser bloqueado, e sem aviso a pessoa só descobre quando já não entra.
     */
    public static function minutosParaFechar(string $usuarioId, ?int $ts = null): ?int
    {
        $ts      = $ts ?? time();
        $horario = self::doUsuario($usuarioId);
        if (!$horario || (int) $horario['ativo'] !== 1) {
            return null;
        }

        $faixa = self::faixaDoDia($horario['id'], (int) date('w', $ts));
        $agora = date('H:i:s', $ts);

        foreach ([['t1_inicio', 't1_fim'], ['t2_inicio', 't2_fim']] as [$ini, $fim]) {
            if (empty($faixa[$ini]) || empty($faixa[$fim])) {
                continue;
            }
            if ($agora >= $faixa[$ini] && $agora <= $faixa[$fim]) {
                return (int) floor((strtotime(date('Y-m-d', $ts) . ' ' . $faixa[$fim]) - $ts) / 60);
            }
        }
        return null;
    }

    // ─────────────────────────────────────────
    // Consultas
    // ─────────────────────────────────────────

    /**
     * Horário do usuário, ou null se ele não tem (= sem limite).
     *
     * Blindado de propósito: isto roda no AuthMiddleware, em TODA requisição.
     * Se as tabelas ainda não existirem (código publicado antes da migration),
     * uma exceção aqui derrubaria o sistema inteiro para todo mundo. Sem tabela
     * = sem limite, que é o comportamento de antes da funcionalidade existir.
     * Mesmo princípio do FilialScope.
     */
    public static function doUsuario(string $usuarioId): ?array
    {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT h.id, h.nome, h.ativo, h.tolerancia_minutos
                   FROM nf_usuario_horario uh
                   JOIN nf_horarios_acesso h ON h.id = uh.horario_id
                  WHERE uh.usuario_id = ?
                  LIMIT 1'
            );
            $stmt->execute([$usuarioId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('[AccessSchedule::doUsuario] ' . $e->getMessage());
            return null;
        }
    }

    /** @return array<string, string|null> as 4 colunas de hora do dia pedido */
    public static function faixaDoDia(string $horarioId, int $diaSemana): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT t1_inicio, t1_fim, t2_inicio, t2_fim
               FROM nf_horario_faixas
              WHERE horario_id = ? AND dia_semana = ?
              LIMIT 1'
        );
        $stmt->execute([$horarioId, $diaSemana]);
        return $stmt->fetch(PDO::FETCH_ASSOC)
            ?: ['t1_inicio' => null, 't1_fim' => null, 't2_inicio' => null, 't2_fim' => null];
    }

    /** Exceção aprovada valendo neste instante. */
    public static function temExcecao(string $usuarioId, int $ts): bool
    {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT 1 FROM nf_horario_excecoes
                  WHERE usuario_id = ? AND data = ? AND hora_inicio <= ? AND hora_fim >= ?
                  LIMIT 1'
            );
            $stmt->execute([$usuarioId, date('Y-m-d', $ts), date('H:i:s', $ts), date('H:i:s', $ts)]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            // Na dúvida, LIBERA: um erro aqui não pode transformar exceção
            // aprovada em bloqueio para quem tinha autorização.
            error_log('[AccessSchedule::temExcecao] ' . $e->getMessage());
            return true;
        }
    }

    /**
     * O template publicado que serve para pedir exceção (flag ligado).
     * Null quando ainda não existe — a tela do Meu Perfil precisa saber disso
     * para não oferecer um link quebrado.
     *
     * @return array{template_id: string, versao_id: string}|null
     */
    public static function templateDeSolicitacao(): ?array
    {
        try {
            $row = Database::getInstance()->query(
                "SELECT tv.template_id, tv.id AS versao_id
                   FROM nf_template_versoes tv
                  WHERE tv.libera_excecao_horario = 1
                    AND tv.status = 'publicado'
                  ORDER BY tv.publicado_em DESC
                  LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Exceções concedidas, com o processo que as autorizou.
     *
     * @param string $usuarioId filtra por pessoa ('' = todas)
     * @param string $quando    'vigentes' (de hoje em diante) | 'todas'
     */
    public static function listarExcecoes(string $usuarioId = '', string $quando = 'vigentes'): array
    {
        $where  = [];
        $params = [];

        if ($usuarioId !== '') {
            $where[]  = 'e.usuario_id = ?';
            $params[] = $usuarioId;
        }
        if ($quando === 'vigentes') {
            $where[] = 'e.data >= CURDATE()';
        }
        $sql = 'SELECT e.*, u.nome AS usuario_nome, u.email, p.codigo AS processo_codigo, p.status AS processo_status
                  FROM nf_horario_excecoes e
                  JOIN usuarios u ON u.id = e.usuario_id
             LEFT JOIN nf_processos p ON p.id = e.processo_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY e.data DESC, e.hora_inicio DESC LIMIT 500';

        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** vigente | hoje | vencida — a vencida grava mas não libera (decisão 10). */
    public static function situacaoExcecao(array $e): string
    {
        $hoje = date('Y-m-d');
        if ($e['data'] > $hoje) return 'vigente';
        if ($e['data'] < $hoje) return 'vencida';
        return date('H:i:s') <= $e['hora_fim'] ? 'hoje' : 'vencida';
    }

    public static function listar(): array
    {
        return Database::getInstance()->query(
            'SELECT h.*, (SELECT COUNT(*) FROM nf_usuario_horario uh WHERE uh.horario_id = h.id) AS usuarios
               FROM nf_horarios_acesso h
              ORDER BY h.nome'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(string $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM nf_horarios_acesso WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $h = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$h) {
            return null;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM nf_horario_faixas WHERE horario_id = ?'
        );
        $stmt->execute([$id]);

        $faixas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $faixas[(int) $f['dia_semana']] = $f;
        }
        $h['faixas'] = $faixas;
        return $h;
    }

    /** Usuários de um horário, para a tela mostrar quem é afetado. */
    public static function usuariosDoHorario(string $horarioId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT u.id, u.nome, u.email, u.perfil
               FROM nf_usuario_horario uh
               JOIN usuarios u ON u.id = uh.usuario_id
              WHERE uh.horario_id = ? AND u.ativo = 1
              ORDER BY u.nome'
        );
        $stmt->execute([$horarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─────────────────────────────────────────
    // Escrita
    // ─────────────────────────────────────────

    /**
     * Cria ou atualiza um horário com as 7 linhas de faixa de uma vez.
     *
     * @param array<int, array{t1_inicio?:string,t1_fim?:string,t2_inicio?:string,t2_fim?:string}> $faixas
     */
    public static function salvar(array $dados, array $faixas, ?string $id = null): string
    {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            if ($id === null) {
                $id   = self::uuid();
                $stmt = $pdo->prepare(
                    'INSERT INTO nf_horarios_acesso (id, nome, descricao, tolerancia_minutos, ativo)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$id, $dados['nome'], $dados['descricao'] ?: null,
                                (int) $dados['tolerancia_minutos'], (int) $dados['ativo']]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE nf_horarios_acesso
                        SET nome = ?, descricao = ?, tolerancia_minutos = ?, ativo = ?
                      WHERE id = ?'
                );
                $stmt->execute([$dados['nome'], $dados['descricao'] ?: null,
                                (int) $dados['tolerancia_minutos'], (int) $dados['ativo'], $id]);
            }

            // Regrava as 7 linhas: mais simples e sem estado órfão que fazer
            // diff dia a dia. São 7 registros.
            $pdo->prepare('DELETE FROM nf_horario_faixas WHERE horario_id = ?')->execute([$id]);

            $ins = $pdo->prepare(
                'INSERT INTO nf_horario_faixas (horario_id, dia_semana, t1_inicio, t1_fim, t2_inicio, t2_fim)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach (array_keys(self::DIAS) as $dia) {
                $f = $faixas[$dia] ?? [];
                $ins->execute([
                    $id, $dia,
                    self::hora($f['t1_inicio'] ?? null), self::hora($f['t1_fim'] ?? null),
                    self::hora($f['t2_inicio'] ?? null), self::hora($f['t2_fim'] ?? null),
                ]);
            }

            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function excluir(string $id): bool
    {
        // As faixas caem por ON DELETE CASCADE; o vínculo com usuário também.
        $stmt = Database::getInstance()->prepare('DELETE FROM nf_horarios_acesso WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Atribui (ou remove, com $horarioId null) o horário de um usuário. */
    public static function atribuir(string $usuarioId, ?string $horarioId, string $porUsuarioId = ''): void
    {
        $pdo = Database::getInstance();

        if ($horarioId === null || $horarioId === '') {
            $pdo->prepare('DELETE FROM nf_usuario_horario WHERE usuario_id = ?')->execute([$usuarioId]);
            return;
        }

        $pdo->prepare(
            'INSERT INTO nf_usuario_horario (usuario_id, horario_id, criado_por)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE horario_id = VALUES(horario_id), criado_por = VALUES(criado_por)'
        )->execute([$usuarioId, $horarioId, $porUsuarioId ?: null]);
    }

    /**
     * Chamado quando um processo é aprovado no fim do fluxo. Se o template
     * tiver o flag `libera_excecao_horario`, lê o formulário e grava a exceção
     * para quem abriu o processo.
     *
     * Silencioso e sem exceção de propósito: isto roda dentro da transação de
     * aprovação, e uma falha aqui não pode derrubar a aprovação em si. O que
     * garante que os campos existem é a validação na PUBLICAÇÃO do template —
     * é lá que o erro é barrado, não aqui.
     *
     * @return string|null id da exceção criada, ou null se não se aplica
     */
    public static function registrarDeProcesso(string $processoId): ?string
    {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT p.criado_por, p.dados_formulario, tv.libera_excecao_horario
                   FROM nf_processos p
                   JOIN nf_template_versoes tv ON tv.id = p.template_versao_id
                  WHERE p.id = ? LIMIT 1'
            );
            $stmt->execute([$processoId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || (int) $row['libera_excecao_horario'] !== 1) {
                return null;
            }

            $d    = json_decode((string) $row['dados_formulario'], true) ?: [];
            $data = trim((string) ($d['data_acesso'] ?? ''));
            $ini  = self::horaDoCampo((string) ($d['hora_inicio'] ?? ''));
            $fim  = self::horaDoCampo((string) ($d['hora_fim'] ?? ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $ini === null || $fim === null || $fim <= $ini) {
                error_log("[AccessSchedule] processo {$processoId} tem o flag de excecao mas o formulario "
                        . 'nao traz data_acesso/hora_inicio/hora_fim validos — excecao NAO gravada.');
                return null;
            }

            return self::registrarExcecao((string) $row['criado_por'], $data, $ini, $fim, $processoId);
        } catch (Throwable $e) {
            error_log('[AccessSchedule::registrarDeProcesso] ' . $e->getMessage());
            return null;
        }
    }

    /** Grava a exceção aprovada. Chamado pela conclusão do processo. */
    public static function registrarExcecao(
        string $usuarioId, string $data, string $horaInicio, string $horaFim, ?string $processoId = null
    ): string {
        $id = self::uuid();
        Database::getInstance()->prepare(
            'INSERT INTO nf_horario_excecoes (id, usuario_id, data, hora_inicio, hora_fim, processo_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $usuarioId, $data, $horaInicio, $horaFim, $processoId]);
        return $id;
    }

    // ─────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────

    /**
     * Hora vinda de um campo do formulário.
     *
     * Campo de lista grava o **id do item**, não o texto — e campo de lista com
     * opções embutidas grava o texto. Aceita os dois: se não parecer hora,
     * tenta resolver como item de lista antes de desistir.
     */
    private static function horaDoCampo(string $bruto): ?string
    {
        $bruto = trim($bruto);
        if ($bruto === '') {
            return null;
        }
        if (($h = self::hora($bruto)) !== null) {
            return $h;
        }

        $stmt = Database::getInstance()->prepare('SELECT valor FROM nf_lista_itens WHERE id = ? LIMIT 1');
        $stmt->execute([$bruto]);
        $valor = $stmt->fetchColumn();

        return $valor === false ? null : self::hora((string) $valor);
    }

    /** "07:45" → "07:45:00"; vazio → null (turno fechado). */
    private static function hora(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '' || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v)) {
            return null;
        }
        return strlen($v) === 5 ? $v . ':00' : $v;
    }

    private static function somaMinutos(string $hora, int $minutos): string
    {
        if ($minutos <= 0) {
            return $hora;
        }
        $t = strtotime('1970-01-01 ' . $hora . ' UTC') + ($minutos * 60);
        // Estourou a meia-noite: a tolerância não empurra para o dia seguinte.
        return $t >= 86400 ? '23:59:59' : gmdate('H:i:s', $t);
    }

    /** "07:45–12:00 e 13:30–19:00", ou "fechado". */
    private static function descreveDia(array $faixa): string
    {
        $partes = [];
        foreach ([['t1_inicio', 't1_fim'], ['t2_inicio', 't2_fim']] as [$i, $f]) {
            if (!empty($faixa[$i]) && !empty($faixa[$f])) {
                $partes[] = substr((string) $faixa[$i], 0, 5) . '–' . substr((string) $faixa[$f], 0, 5);
            }
        }
        return $partes ? implode(' e ', $partes) : 'fechado';
    }

    /**
     * Quando o acesso volta. Varre o próprio dia (turno 2 ainda por vir) e os
     * 7 dias seguintes — a tela de bloqueio precisa dizer isso, não só "negado".
     */
    private static function proximaAbertura(string $horarioId, int $ts): string
    {
        $agora = date('H:i:s', $ts);

        for ($i = 0; $i <= 7; $i++) {
            $dia   = strtotime("+{$i} day", $ts);
            $faixa = self::faixaDoDia($horarioId, (int) date('w', $dia));

            foreach (['t1_inicio', 't2_inicio'] as $ini) {
                if (empty($faixa[$ini])) {
                    continue;
                }
                if ($i > 0 || $faixa[$ini] > $agora) {
                    $quando = $i === 0 ? 'hoje' : ($i === 1 ? 'amanhã' : self::DIAS[(int) date('w', $dia)]);
                    return $quando . ' às ' . substr((string) $faixa[$ini], 0, 5);
                }
            }
        }
        return '';
    }

    private static function uuid(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
