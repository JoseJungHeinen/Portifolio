<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Models\Motivo;
use App\Models\Ofp;
use App\Support\RegraException;
use App\Support\ValidacaoException;
use DateTimeImmutable;

/** Sugere OFPs provavelmente perdidas (prazo vencido) para o gerente confirmar com motivo. */
class PerdaService
{
    /** Dias de tolerância depois do prazo de abertura antes de sugerir "perdida no prazo". */
    public const TOLERANCIA_ABERTURA = 5;

    /** Status de perda real (dinheiro que não volta). Cancelada, estornada e peça vendida ficam de fora. */
    public const STATUS_PERDA = ['rejeitada_final', 'perdida_prazo', 'encerrada'];

    /** Máximo de OFPs confirmadas por vez. */
    public const MAX_LOTE = 200;

    public static function sqlStatusPerda(): string
    {
        return "'" . implode("','", self::STATUS_PERDA) . "'";
    }

    /** Regra pura: status de perda sugerido ou null. */
    public static function sugerir(array $o, DateTimeImmutable $hoje): ?string
    {
        if (empty($o['prazo_data'])) {
            return null;
        }
        $atraso = -PrazoService::diasRestantes((string)$o['prazo_data'], $hoje);
        return match ($o['status'] ?? '') {
            'registrada'                  => $atraso > self::TOLERANCIA_ABERTURA ? 'perdida_prazo' : null,
            'aprovada', 'aguardando_tp41' => $atraso > 0 ? 'encerrada' : null,
            default                       => null,
        };
    }

    /**
     * Marca/desmarca perda_sugerida nas OFPs ativas. Rotina de sistema (cron): varre todas as unidades.
     * @return int OFPs com sugestão
     */
    public function atualizarSugestoes(): int
    {
        $db = Database::getInstance();
        $hoje = new DateTimeImmutable('today');
        $atualizar = $db->prepare('UPDATE ofps SET perda_sugerida = ? WHERE id = ? AND status = ?');
        $total = 0;
        foreach ($db->query('SELECT id, status, prazo_data, perda_sugerida FROM ofps WHERE status NOT IN (' . Ofp::sqlInativos() . ')')->fetchAll() as $o) {
            $sugestao = self::sugerir($o, $hoje);
            if ($sugestao !== $o['perda_sugerida']) {
                $atualizar->execute([$sugestao, $o['id'], $o['status']]);
            }
            if ($sugestao !== null) {
                $total++;
            }
        }
        return $total;
    }

    /** Confirma em lote as perdas sugeridas. @return array{ok: int, erros: string[]} */
    public function confirmar(array $ids, string $motivoId, string $observacao, array $usuario): array
    {
        $ok = 0;
        $erros = [];
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (count($ids) > self::MAX_LOTE) {
            return ['ok' => 0, 'erros' => ['Selecione no máximo ' . self::MAX_LOTE . ' OFPs por vez.']];
        }
        // Motivo e observação valem para o lote todo: uma checagem só (por destino distinto), um erro só.
        $motivo = (new Motivo())->find($motivoId) ?: null;
        $destinos = [];
        foreach ($ids as $id) {
            $o = (new Ofp())->find($id);
            if ($o && unidade_permitida($o['unidade_id']) && !empty($o['perda_sugerida'])) {
                $destinos[$o['perda_sugerida']] = true;
            }
        }
        foreach (array_keys($destinos) as $destino) {
            $e = StatusService::validarMotivo($motivo, $destino, $observacao);
            if ($e) {
                return ['ok' => 0, 'erros' => [implode(' ', $e)]];
            }
        }
        $status = new StatusService();
        $hoje = new DateTimeImmutable('today');
        foreach ($ids as $id) {
            $o = (new Ofp())->find((string)$id);
            if (!$o || !unidade_permitida($o['unidade_id']) || empty($o['perda_sugerida'])) {
                $erros[] = 'Uma das OFPs selecionadas não está mais com perda sugerida.';
                continue;
            }
            $rotulo = 'OFP ' . ($o['numero_ofp'] ?? 'sem nº') . ': ';
            $atual = self::sugerir($o, $hoje);
            if ($atual !== $o['perda_sugerida']) {
                // O prazo mudou depois da sugestão: atualiza a marca (só se a OFP continua na mesma etapa) e pula.
                Database::getInstance()->prepare('UPDATE ofps SET perda_sugerida = ? WHERE id = ? AND status = ?')->execute([$atual, $o['id'], $o['status']]);
                $erros[] = $rotulo . 'prazo mudou; sugestão não vale mais.';
                continue;
            }
            try {
                $status->transitar($o['id'], $o['perda_sugerida'], ['motivo_id' => $motivoId, 'observacao' => $observacao], $usuario);
                $ok++;
            } catch (ValidacaoException $e) {
                $erros[] = $rotulo . implode(' ', $e->erros);
            } catch (RegraException $e) {
                $erros[] = $rotulo . $e->getMessage();
            }
        }
        return ['ok' => $ok, 'erros' => $erros];
    }
}
