<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Models\Ofp;
use App\Models\Usuario;
use App\Support\Mailer;
use DateTimeImmutable;

/** E-mail diário com OFPs vencidas, vencendo em até 3 dias ou paradas (spec, seção 9). */
class AlertaService
{
    /**
     * @param array $ofps     linhas com id, unidade_id, status, responsavel_email, responsavel_nome e, opcional, responsavel_unidades (?string[])
     * @param array $gerentes [{email, nome, unidades: ?string[]}] — null = todas as unidades
     * @param array $atores   [{email, nome, perfil, unidades: ?string[]}] — recebem as OFPs da etapa em que atuam
     * @return array<string, array{nome: string, ofps: array}>
     */
    public static function agrupar(array $ofps, array $gerentes, array $atores = []): array
    {
        $saida = [];
        $incluir = function (string $email, ?string $nome, array $o) use (&$saida): void {
            $saida[$email]['nome'] ??= (string)$nome;
            $saida[$email]['ofps'][$o['id']] = $o;
        };
        $naUnidade = fn(?array $unidades, array $o) => $unidades === null || in_array($o['unidade_id'], $unidades, true);
        foreach ($ofps as $o) {
            // Responsável que perdeu acesso à unidade não recebe a OFP (chave ausente = sem checagem).
            $enxerga = !array_key_exists('responsavel_unidades', $o) || $naUnidade($o['responsavel_unidades'], $o);
            if (!empty($o['responsavel_email']) && $enxerga) {
                $incluir($o['responsavel_email'], $o['responsavel_nome'], $o);
            }
        }
        foreach ($atores as $a) {
            foreach ($ofps as $o) {
                if (in_array($a['perfil'], StatusService::perfisQueAgem((string)($o['status'] ?? '')), true) && $naUnidade($a['unidades'], $o)) {
                    $incluir($a['email'], $a['nome'], $o);
                }
            }
        }
        foreach ($gerentes as $g) {
            foreach ($ofps as $o) {
                if ($naUnidade($g['unidades'], $o)) {
                    $incluir($g['email'], $g['nome'], $o);
                }
            }
        }
        foreach ($saida as &$grupo) {
            $grupo['ofps'] = array_values($grupo['ofps']);
        }
        return $saida;
    }

    /** Texto da situação da OFP no e-mail: prazo (se vence em até 3 dias) ou tempo parada. */
    public static function situacao(array $o, DateTimeImmutable $hoje): string
    {
        if (!empty($o['prazo_data'])) {
            $dias = PrazoService::diasRestantes((string)$o['prazo_data'], $hoje);
            if ($dias <= PrazoService::DIAS_AMARELO) {
                return $dias < 0 ? abs($dias) . ' dia(s) atrasado' : ($dias === 0 ? 'vence hoje' : "vence em {$dias} dia(s)");
            }
        }
        return 'sem movimento há ' . (int)($o['dias_parada'] ?? 0) . ' dias';
    }

    /** @return array{enviados: int, falhas: int} e-mails enviados (ou gravados no log) e que falharam */
    public function executar(): array
    {
        if (strtolower(trim((string)($_ENV['MAIL_MODE'] ?? 'log'))) === 'smtp') {
            foreach (['MAIL_FROM_ADDRESS', 'APP_URL'] as $chave) {
                if (trim((string)($_ENV[$chave] ?? '')) === '') {
                    throw new \RuntimeException("{$chave} não configurado (obrigatório com MAIL_MODE=smtp)");
                }
            }
        }
        $diasParada = max(1, (int)($_ENV['DIAS_PARADA'] ?? 15));
        $st = Database::getInstance()->prepare(
            'SELECT * FROM (
                SELECT o.id, o.numero_ofp, o.desenho, o.status, o.prazo_tipo, o.prazo_data, o.unidade_id, u.nome AS unidade_nome,
                       r.id AS responsavel_id, r.perfil AS responsavel_perfil, r.email AS responsavel_email, r.nome AS responsavel_nome,
                       DATEDIFF(CURDATE(), DATE(COALESCE((SELECT MAX(e.created_at) FROM ofp_eventos e WHERE e.ofp_id = o.id), o.updated_at))) AS dias_parada
                FROM ofps o JOIN unidades u ON u.id = o.unidade_id LEFT JOIN usuarios r ON r.id = o.responsavel_id AND r.ativo = 1
                WHERE o.status NOT IN (' . Ofp::sqlInativos() . ')
             ) x
             WHERE (x.prazo_data IS NOT NULL AND x.prazo_data <= DATE_ADD(CURDATE(), INTERVAL ' . PrazoService::DIAS_AMARELO . ' DAY))
                OR x.dias_parada > ?
             ORDER BY x.prazo_data IS NULL, x.prazo_data, x.dias_parada DESC'
        );
        $st->execute([$diasParada]);
        $ofps = $st->fetchAll();
        if (!$ofps) {
            return ['enviados' => 0, 'falhas' => 0];
        }
        $usuarios = new Usuario();
        $escopos = [];
        foreach ($ofps as &$o) {
            if (!empty($o['responsavel_id'])) {
                $escopos[$o['responsavel_id']] ??= AuthService::escopoUnidades((string)$o['responsavel_perfil'], $usuarios->unidadesIds($o['responsavel_id']));
                $o['responsavel_unidades'] = $escopos[$o['responsavel_id']];
            }
        }
        unset($o);
        $gerentes = [];
        foreach ($usuarios->gerentesAtivos() as $g) {
            $gerentes[] = ['email' => $g['email'], 'nome' => $g['nome'], 'unidades' => $usuarios->unidadesIds($g['id']) ?: null];
        }
        $atores = [];
        foreach ($usuarios->atoresAtivos() as $a) {
            $escopo = AuthService::escopoUnidades($a['perfil'], $usuarios->unidadesIds($a['id']));
            if ($escopo !== []) {
                $atores[] = ['email' => $a['email'], 'nome' => $a['nome'], 'perfil' => $a['perfil'], 'unidades' => $escopo];
            }
        }
        $enviados = 0;
        $falhas = 0;
        foreach (self::agrupar($ofps, $gerentes, $atores) as $email => $grupo) {
            $assunto = '[OFP] ' . count($grupo['ofps']) . ' OFP(s) precisam de atenção';
            if (Mailer::enviar($email, $assunto, $this->corpo((string)$grupo['nome'], $grupo['ofps']))) {
                $enviados++;
            } else {
                $falhas++;
            }
        }
        return ['enviados' => $enviados, 'falhas' => $falhas];
    }

    private function corpo(string $nome, array $ofps): string
    {
        $hoje = new DateTimeImmutable('today');
        $linhas = '';
        foreach ($ofps as $o) {
            $acao = $o['prazo_tipo'] ? (PrazoService::ROTULOS[$o['prazo_tipo']] ?? $o['prazo_tipo'])
                : ($o['status'] === 'aguardando_coleta' ? 'Informar a data da solicitação de coleta' : 'Atualizar a OFP');
            $linhas .= '<tr><td><a href="' . e(url('/ofps/' . $o['id'])) . '">' . e($o['numero_ofp'] ?? 'sem nº') . '</a></td>'
                . '<td>' . e($o['unidade_nome']) . '</td><td>' . e($o['desenho']) . '</td><td>' . e(status_label($o['status'])) . '</td>'
                . '<td>' . e($acao) . '</td><td>' . e(self::situacao($o, $hoje)) . '</td></tr>';
        }
        return '<p>Olá, ' . e($nome) . '.</p><p>Estas OFPs precisam de atenção:</p>'
            . '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px">'
            . '<tr style="background:#606060;color:#fff"><th>Nº OFP</th><th>Unidade</th><th>Desenho</th><th>Status</th><th>Próxima ação</th><th>Situação</th></tr>'
            . $linhas . '</table><p style="color:#888">Exemplo OFP — e-mail automático.</p>';
    }
}
