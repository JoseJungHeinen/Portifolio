<?php
// @versao Ver.002.301026

namespace App\Services;

use App\Config\Database;
use App\Models\Fornecedor;
use App\Services\PlanilhaReader as P;

/**
 * Importação de fornecedores por planilha (spec 2026-10-30; base: analisarImport,
 * impNovo e impAtualiza do protótipo). analisar() é pura: monta a prévia sobre um
 * mapa de fornecedores já cadastrados; aplicar() grava a prévia numa transação.
 */
class ImportacaoFornecedoresService
{
    /** Colunas de texto e tamanho no banco (fornecedores, varchar em caracteres). */
    public const LIMITES = [
        'razao_social'  => ['Razão social', 200],
        'nome_fantasia' => ['Nome fantasia', 200],
        'cidade'        => ['Cidade', 100],
        'telefone'      => ['Telefone', 60],   // R004 ampliou de 20 para 60 (Fone / Celular juntos)
    ];
    /** observacoes é TEXT: 65.535 bytes. */
    public const LIMITE_OBS_BYTES = 65535;
    public const LIMITE_EMAIL = 150;

    public const UFS = ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR',
                        'RJ','RN','RO','RR','RS','SC','SE','SP','TO'];

    /** Rótulos dos campos nas mudanças "antes → depois". */
    public const ROTULOS = [
        'razao_social' => 'Razão social', 'nome_fantasia' => 'Nome fantasia', 'cidade' => 'Cidade', 'estado' => 'UF',
        'email' => 'E-mail', 'telefone' => 'Telefone', 'regime_tributario' => 'Regime', 'regime_confirmado_em' => 'Regime confirmado em',
        'mei' => 'MEI', 'cnd_federal_situacao' => 'CND Federal', 'cnd_federal_validade' => 'Validade CND Federal',
        'situacao' => 'Situação', 'observacoes' => 'Observações',
    ];

    public const TIPOS = [
        'novo'        => 'Novo',
        'atualizar'   => 'Atualizar',
        'sem_mudanca' => 'Sem mudança',
        'ignorado'    => 'Ignorado',
        'erro'        => 'Erro',
    ];

    /**
     * @param list<array<string, mixed>> $linhas saída de PlanilhaReader::ler()['linhas']
     * @param array{existentes: array<string, array>, modo: string, confirmacao: string, hoje?: string} $opcoes
     *        existentes = cnpj (14 dígitos) => linha de fornecedores
     * @return array{itens: list<array>, totais: array<string, int>}
     */
    public static function analisar(array $linhas, array $opcoes): array
    {
        $exist = $opcoes['existentes'] ?? [];
        $modo  = ($opcoes['modo'] ?? 'atualizar') === 'manter' ? 'manter' : 'atualizar';
        $conf  = (string)($opcoes['confirmacao'] ?? date('Y-m-d'));
        $hoje  = (string)($opcoes['hoje'] ?? date('Y-m-d'));

        $vistos = [];
        $itens  = [];
        foreach ($linhas as $r) {
            $g  = fn(string $k) => trim((string)($r[$k] ?? ''));
            $it = ['linha' => (int)($r['linha'] ?? 0), 'razao' => $g('razao'), 'fantasia' => $g('fantasia'),
                   'cnpj' => '', 'cnpj_bruto' => $g('cnpj'), 'erros' => [], 'avisos' => [], 'herdou' => false,
                   'tipo' => 'erro', 'id' => null, 'dados' => [], 'mudancas' => [], 'regime' => ''];

            // Excel guarda CNPJ numérico sem o zero à esquerda: completa (o DV confere depois).
            $d = preg_replace('/\D/', '', $it['cnpj_bruto']);
            if (preg_match('/^\d{12,13}$/', $it['cnpj_bruto'])) {
                $d = str_pad($d, 14, '0', STR_PAD_LEFT);
            }
            $it['cnpj'] = $d;

            if ($it['razao'] === '') $it['erros'][] = 'sem razão social';
            if ($d === '') {
                $it['erros'][] = 'sem CNPJ';
            } elseif (!P::cnpjValido($d)) {
                $it['erros'][] = 'CNPJ inválido';
            } elseif (isset($vistos[$d])) {
                $it['erros'][] = 'CNPJ repetido na planilha (linha ' . $vistos[$d] . ')';
            } else {
                $vistos[$d] = $it['linha'];
            }

            $regime = P::regime($g('regime'));
            if ($regime === null) {
                $it['avisos'][] = 'regime "' . $g('regime') . '" não reconhecido — fica em branco';
                $regime = '';
            }
            $it['regime_informado'] = $regime;

            $cnd = P::cnd($g('cnd_federal'));
            if ($cnd === null) {
                $it['avisos'][] = 'CND Federal "' . $g('cnd_federal') . '" não reconhecida — fica em branco';
                $cnd = '';
            }
            $it['cnd'] = $cnd;

            $val = P::data($g('cnd_federal_validade'));
            if ($val === null) {
                $it['avisos'][] = 'validade da CND Federal "' . $g('cnd_federal_validade') . '" não reconhecida (use dd/mm/aaaa)';
                $val = '';
            } elseif ($val !== '' && $val < $hoje) {
                $it['avisos'][] = 'CND Federal vencida em ' . date('d/m/Y', strtotime($val));
            }
            $it['validade'] = $val;
            if ($cnd === 'positiva') $it['avisos'][] = 'CND Federal positiva';

            $it['situacao'] = P::situacao($g('status'), $g('classificacao'));

            $email = $g('email');
            if ($email !== '' && (mb_strlen($email) > self::LIMITE_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $it['avisos'][] = 'e-mail "' . $email . '" inválido — não gravado';
                $email = '';
            }
            $it['email'] = $email;

            $uf = mb_strtoupper($g('uf'));
            if ($uf !== '' && !in_array($uf, self::UFS, true)) {
                $it['avisos'][] = 'UF "' . $g('uf') . '" inválida — não gravada';
                $uf = '';
            }
            $it['uf'] = $uf;

            $it['texto'] = [
                'razao_social'  => $it['razao'],
                'nome_fantasia' => $it['fantasia'],
                'cidade'        => $g('cidade'),
                'telefone'      => implode(' / ', array_filter([$g('fone'), $g('celular')], fn($v) => $v !== '')),
            ];
            foreach (self::LIMITES as $k => [$rot, $max]) {
                if (mb_strlen($it['texto'][$k]) > $max) {
                    $it['texto'][$k] = mb_substr($it['texto'][$k], 0, $max);
                    $it['avisos'][] = $rot . ' maior que ' . $max . ' caracteres — cortado';
                }
            }

            $it['obs_partes'] = array_values(array_filter([
                $g('setor') !== '' ? 'Departamento/Setor: ' . $g('setor') : '',
                $g('obs'),
            ], fn($v) => $v !== ''));
            $it['cnpj_matriz'] = preg_replace('/\D/', '', $g('cnpj_matriz'));
            if ($it['cnpj_matriz'] === '' && preg_match('/matriz\D{0,12}(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})/i', $g('obs'), $m)) {
                $it['cnpj_matriz'] = preg_replace('/\D/', '', $m[1]);
            }

            $it['existente'] = ($d !== '' && isset($exist[$d])) ? $exist[$d] : null;
            $itens[] = $it;
        }

        // Regime efetivo (planilha, ou o já cadastrado) para a herança.
        $validos = [];
        foreach ($itens as $i => $it) {
            if (!$it['erros']) $validos[$it['cnpj']] = $i;
        }
        $porRaizExist = [];
        foreach ($exist as $c => $f) {
            if (Fornecedor::ehMatriz($c)) $porRaizExist[substr($c, 0, 8)] = $f;
        }
        $regimeDe = function (string $cnpj) use (&$itens, $validos, $exist): string {
            if (isset($validos[$cnpj])) {
                $x = $itens[$validos[$cnpj]];
                if ($x['regime_informado'] !== '') return $x['regime_informado'];
            }
            return (string)($exist[$cnpj]['regime_tributario'] ?? '');
        };

        foreach ($itens as &$it) {
            if ($it['erros']) continue;
            $ex     = $it['existente'];
            $regime = $it['regime_informado'];
            $filial = !Fornecedor::ehMatriz($it['cnpj']);
            $temReg = $ex && trim((string)($ex['regime_tributario'] ?? '')) !== '';

            if ($regime === '' && !$temReg) {
                $mat = '';
                if ($it['cnpj_matriz'] !== '' && $it['cnpj_matriz'] !== $it['cnpj']
                    && (isset($validos[$it['cnpj_matriz']]) || isset($exist[$it['cnpj_matriz']]))) {
                    $mat = $it['cnpj_matriz'];
                } elseif ($filial) {
                    $raiz = substr($it['cnpj'], 0, 8);
                    foreach ($validos as $c => $_) {
                        if (str_starts_with($c, $raiz) && Fornecedor::ehMatriz($c)) { $mat = $c; break; }
                    }
                    if ($mat === '' && isset($porRaizExist[$raiz])) $mat = (string)$porRaizExist[$raiz]['cnpj'];
                }
                if ($mat !== '' && ($rm = $regimeDe($mat)) !== '') {
                    $regime = $rm;
                    $it['herdou'] = true;
                    $it['avisos'][] = 'regime herdado da matriz ' . self::formatarCnpj($mat);
                } else {
                    $it['avisos'][] = $filial ? 'filial sem regime e matriz não encontrada — completar depois'
                                              : 'sem regime tributário — completar depois';
                }
            }
            $it['regime'] = $regime;
        }
        unset($it);

        foreach ($itens as &$it) {
            $ex = $it['existente'];
            if ($it['erros']) {
                $it['tipo'] = 'erro';
            } elseif ($ex && $modo === 'manter') {
                $it['tipo'] = 'ignorado';
                $it['id']   = $ex['id'];
            } elseif ($ex) {
                $it['id'] = $ex['id'];
                self::mudancas($it, $ex, $conf);
                $it['tipo'] = $it['mudancas'] ? 'atualizar' : 'sem_mudanca';
            } else {
                $it['tipo']  = 'novo';
                $obs = $it['obs_partes'];
                if ($it['herdou']) $obs[] = 'Regime herdado da matriz na importação.';
                $it['dados'] = [
                    'razao_social'         => $it['texto']['razao_social'],
                    'nome_fantasia'        => $it['texto']['nome_fantasia'] ?: null,
                    'cnpj'                 => $it['cnpj'],
                    'email'                => $it['email'] ?: null,
                    'telefone'             => $it['texto']['telefone'] ?: null,
                    'cidade'               => $it['texto']['cidade'] ?: null,
                    'estado'               => $it['uf'] ?: null,
                    'regime_tributario'    => $it['regime'] ?: null,
                    'regime_confirmado_em' => $it['regime_informado'] !== '' ? $conf : null,
                    'mei'                  => $it['regime'] === 'mei' ? 1 : 0,
                    'cnd_federal_situacao' => $it['cnd'] ?: null,
                    'cnd_federal_validade' => $it['validade'] ?: null,
                    'situacao'             => $it['situacao'] ?: 'pendente',
                    'observacoes'          => $obs ? self::cortarObs(implode("\n", $obs), $it) : null,
                    'ativo'                => 1,
                    'para_compras'         => 1,
                ];
            }
            unset($it['existente'], $it['texto'], $it['obs_partes']);
        }
        unset($it);

        $totais = ['linhas' => count($itens)] + array_fill_keys(array_keys(self::TIPOS), 0);
        foreach ($itens as $it) $totais[$it['tipo']]++;

        return ['itens' => $itens, 'totais' => $totais];
    }

    /** Campos que mudam no existente. Célula vazia não apaga. */
    private static function mudancas(array &$it, array $ex, string $conf): void
    {
        $novo = [
            'razao_social'         => $it['texto']['razao_social'],
            'nome_fantasia'        => $it['texto']['nome_fantasia'],
            'cidade'               => $it['texto']['cidade'],
            'estado'               => $it['uf'],
            'email'                => $it['email'],
            'telefone'             => $it['texto']['telefone'],
            'regime_tributario'    => $it['regime'],
            'regime_confirmado_em' => $it['regime_informado'] !== '' ? $conf : '',
            'mei'                  => $it['regime'] === 'mei' ? '1' : '',
            'cnd_federal_situacao' => $it['cnd'],
            'cnd_federal_validade' => $it['validade'],
            'situacao'             => $it['situacao'],
        ];
        foreach ($novo as $k => $v) {
            $antes = $ex[$k] ?? null;
            if ($v === '' || (string)$v === (string)($antes ?? '')) continue;
            $v = $k === 'mei' ? 1 : $v;
            $it['dados'][$k]    = $v;
            $it['mudancas'][$k] = ['antes' => $antes, 'depois' => $v];
        }

        $obsAntes = (string)($ex['observacoes'] ?? '');
        $add = array_filter($it['obs_partes'], fn($p) => !str_contains($obsAntes, $p));
        if ($it['herdou'] && !str_contains($obsAntes, 'Regime herdado da matriz')) $add[] = 'Regime herdado da matriz na importação.';
        if ($add) {
            $obs = self::cortarObs(trim($obsAntes . "\n" . implode("\n", $add)), $it);
            if ($obs !== $obsAntes) {
                $it['dados']['observacoes']    = $obs;
                $it['mudancas']['observacoes'] = ['antes' => $ex['observacoes'] ?? null, 'depois' => $obs];
            }
        }
    }

    private static function cortarObs(string $obs, array &$it): string
    {
        if (strlen($obs) <= self::LIMITE_OBS_BYTES) return $obs;
        $it['avisos'][] = 'Observações maiores que o limite do cadastro — cortadas';
        return mb_strcut($obs, 0, self::LIMITE_OBS_BYTES, 'UTF-8');
    }

    public static function formatarCnpj(string $c): string
    {
        $d = preg_replace('/\D/', '', $c);
        return strlen($d) === 14 ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d) : $c;
    }

    /** Fornecedores cadastrados por CNPJ (para analisar). */
    public static function existentes(?\PDO $db = null): array
    {
        $db ??= Database::getInstance();
        $map = [];
        foreach ($db->query('SELECT * FROM fornecedores')->fetchAll(\PDO::FETCH_ASSOC) as $f) {
            $map[(string)$f['cnpj']] = $f;
        }
        return $map;
    }

    /**
     * Grava a prévia numa transação, com auditoria por fornecedor. Linha cujo
     * cadastro mudou depois da prévia (ou CNPJ criado por outra pessoa) fica de fora.
     *
     * @return array{criados: int, atualizados: int, erros: list<string>}
     */
    public static function aplicar(array $previa, array $usuario, ?\PDO $db = null): array
    {
        $db ??= Database::getInstance();
        $aud   = new AuditoriaService();
        $model = new Fornecedor();
        $res   = ['criados' => 0, 'atualizados' => 0, 'erros' => []];

        $db->beginTransaction();
        try {
            foreach ($previa['itens'] ?? [] as $it) {
                if ($it['tipo'] === 'novo') {
                    $st = $db->prepare('SELECT id FROM fornecedores WHERE cnpj = ? LIMIT 1');
                    $st->execute([$it['dados']['cnpj']]);
                    if ($st->fetchColumn()) {
                        $res['erros'][] = 'Linha ' . $it['linha'] . ': CNPJ ' . self::formatarCnpj($it['cnpj']) . ' foi cadastrado depois da prévia — não importado.';
                        continue;
                    }
                    $id = $model->create($it['dados']);
                    $aud->log($usuario['id'] ?? null, 'fornecedor', $id, 'fornecedor.importado', null,
                        $it['dados'] + ['linha_planilha' => $it['linha'], 'avisos' => $it['avisos']]);
                    $res['criados']++;
                } elseif ($it['tipo'] === 'atualizar') {
                    $st = $db->prepare('SELECT * FROM fornecedores WHERE id = ? FOR UPDATE');
                    $st->execute([$it['id']]);
                    $atual = $st->fetch(\PDO::FETCH_ASSOC);
                    $mudou = !$atual || $atual['cnpj'] !== $it['cnpj'];
                    foreach ($it['mudancas'] as $k => $m) {
                        if ($mudou) break;
                        if ((string)($atual[$k] ?? '') !== (string)($m['antes'] ?? '')) $mudou = true;
                    }
                    if ($mudou) {
                        $res['erros'][] = 'Linha ' . $it['linha'] . ': o cadastro de ' . self::formatarCnpj($it['cnpj']) . ' mudou depois da prévia — não atualizado.';
                        continue;
                    }
                    $model->update($it['id'], $it['dados']);
                    $aud->log($usuario['id'] ?? null, 'fornecedor', $it['id'], 'fornecedor.atualizado_planilha',
                        array_map(fn($m) => $m['antes'], $it['mudancas']),
                        array_map(fn($m) => $m['depois'], $it['mudancas']) + ['linha_planilha' => $it['linha']]);
                    $res['atualizados']++;
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return $res;
    }
}
