<?php
// @versao Tab_11 V_04_05/10/2026
declare(strict_types=1);

/**
 * PIS / PASEP / NIT do fornecedor prestador de serviço.
 *
 * Regra de negócio (obrigação legal):
 *   Nota de SERVIÇO prestada por pessoa física (CPF, sem CNPJ) exige o NIT do
 *   prestador — é ele que vai na GPS/DCTFWeb da retenção de INSS. Sem o
 *   número, o processo não pode ser aberto.
 *
 *   MEI (CNPJ optante) só exige PIS/NIT quando o SERVIÇO da nota é um dos
 *   itens da Lei 8.212/91 art. 31 sujeitos a INSS patronal mesmo sendo MEI —
 *   decisão do usuário em 28/09/2026, com a lista fechada em
 *   self::CODIGOS_INSS_MEI. Fora dessa lista, MEI não exige mais nada (antes
 *   exigia sempre). O código do serviço vem do <cTribNac> (6 dígitos) da
 *   NFS-e Nacional já salva no storage — sem XML/código legível, não dá pra
 *   confirmar e a checagem vira AVISO, nunca bloqueio (ver
 *   codigoServicoExigeInssMei()).
 *
 * O número fica no cadastro do fornecedor (`fornecedores.pis_nit`, migration
 * 031) e é cobrado na ABERTURA do processo, não no cadastro.
 *
 * "É serviço" quando qualquer um dos dois for verdadeiro:
 *   • a nota capturada vinculada é NFS-e (`nf_notas_capturadas.tipo_documento`);
 *   • o processo foi aberto com Tipo de nota = "Serviço" (etapa 1 do wizard).
 */
class PisNit
{
    /** Pesos do DV (mod 11) sobre os 10 primeiros dígitos. */
    private const PESOS = [3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /** Nome do item da lista "Tipos de nota" que caracteriza serviço. */
    private const TIPO_NOTA_SERVICO = 'servico';

    /**
     * Códigos de Tributação Nacional (cTribNac, 6 dígitos) cujo serviço exige
     * INSS patronal mesmo quando o prestador é MEI (Lei 8.212/91 art. 31 —
     * cessão de mão de obra/empreitada). Tabela oficial: gov.br/nfse. Lista
     * fechada por decisão do usuário em 28/09/2026:
     *   140101 · Manutenção/conserto de veículos                  (LC116 14.01)
     *   140501 · Restauração/pintura/lavagem de objetos            (LC116 14.05)
     *   141201 · Funilaria e lanternagem                           (LC116 14.12.01)
     *   070201 · Execução de obra por administração                (LC116 7.02)
     *   070202 · Execução de obra por empreitada/subempreitada     (LC116 7.02)
     *   070501 · Reparação/reforma de edifícios                    (LC116 7.05)
     */
    private const CODIGOS_INSS_MEI = ['140101', '140501', '141201', '070201', '070202', '070501'];

    public static function digitos(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v) ?? '';
    }

    /** 11 dígitos + dígito verificador mod 11. Rejeita sequências repetidas. */
    public static function valido(?string $v): bool
    {
        $d = self::digitos($v);
        if (strlen($d) !== 11)              return false;
        if (preg_match('/^(\d)\1{10}$/', $d)) return false;

        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += ((int) $d[$i]) * self::PESOS[$i];
        }
        $resto = $soma % 11;
        $dv    = $resto < 2 ? 0 : 11 - $resto;

        return $dv === (int) $d[10];
    }

    /** 000.00000.00-0 — vazio vira '—'. */
    public static function formatar(?string $v): string
    {
        $d = self::digitos($v);
        if (strlen($d) !== 11) return $d === '' ? '—' : $d;
        return substr($d, 0, 3) . '.' . substr($d, 3, 5) . '.' . substr($d, 8, 2) . '-' . substr($d, 10, 1);
    }

    /**
     * Por que este fornecedor precisa de PIS em nota de serviço?
     *
     * @param array $forn linha de `fornecedores`
     * @return string|null 'MEI', 'pessoa física' ou null (não exige)
     */
    public static function motivoExigencia(array $forn): ?string
    {
        $doc = self::digitos((string) ($forn['cnpj'] ?? ''));
        if (strlen($doc) === 11)                        return 'pessoa física';
        if ((int) ($forn['optante_mei'] ?? 0) === 1)    return 'MEI';
        return null;
    }

    /** CNPJ que nunca passou pela consulta da Receita — não dá para afirmar se é MEI. */
    public static function meiDesconhecido(array $forn): bool
    {
        return strlen(self::digitos((string) ($forn['cnpj'] ?? ''))) === 14
            && ($forn['optante_mei'] ?? null) === null;
    }

    /**
     * O serviço desta nota exige INSS patronal mesmo com fornecedor MEI?
     * Lê o <cTribNac> (6 dígitos) da NFS-e Nacional já salva no storage.
     *
     * @return bool|null true = exige · false = código lido e fora da lista,
     *         não exige · null = não deu para confirmar (XML antigo/ABRASF,
     *         sem código de 6 dígitos, ou arquivo ausente) — trate como incerto.
     */
    public static function codigoServicoExigeInssMei(?string $chaveNf, ?string $processoId): ?bool
    {
        $pdo    = Database::getInstance();
        $where  = [];
        $params = [];
        $chave  = self::digitos($chaveNf);
        if ($processoId !== null && $processoId !== '') {
            $where[]  = 'processo_id = ?';
            $params[] = $processoId;
        }
        if ($chave !== '') {
            $where[]  = 'chave_nf = ?';
            $params[] = $chave;
        }
        if (!$where) return null;

        $stmt = $pdo->prepare(
            "SELECT chave_nf FROM nf_notas_capturadas
              WHERE tipo_documento = 'NFSe' AND (" . implode(' OR ', $where) . ")
              LIMIT 1"
        );
        $stmt->execute($params);
        $chaveReal = (string) ($stmt->fetchColumn() ?: '');
        if ($chaveReal === '') return null;

        $base = defined('STORAGE') ? STORAGE : dirname(__DIR__, 2) . '/storage';
        $path = $base . '/nfse_xml/' . $chaveReal . '.xml';
        if (!is_file($path)) return null;

        $xml = (string) @file_get_contents($path);
        if (trim($xml) === '') return null;

        require_once SRC . '/Helpers/XmlDfeParser.php';
        try {
            $det = XmlDfeParser::detalhes($xml, 'NFSe');
        } catch (Throwable $e) {
            return null;
        }

        $codigo = self::digitos((string) ($det['nfse']['servico']['cTribNac'] ?? ''));
        if (strlen($codigo) !== 6) return null;

        return in_array($codigo, self::CODIGOS_INSS_MEI, true);
    }

    /**
     * A nota é de serviço?
     *
     * @param string|null $tipoNotaId  nf_lista_itens.id escolhido na etapa 1
     * @param string|null $chaveNf     chave da NF digitada/importada
     * @param string|null $processoId  processo já existente (abertura via captura)
     */
    public static function notaEhServico(?string $tipoNotaId, ?string $chaveNf, ?string $processoId): bool
    {
        if (self::tipoNotaEhServico($tipoNotaId)) return true;

        $chave = self::digitos($chaveNf);
        if ($processoId === null && $chave === '') return false;

        $pdo    = Database::getInstance();
        $where  = [];
        $params = [];
        if ($processoId !== null && $processoId !== '') {
            $where[]  = 'processo_id = ?';
            $params[] = $processoId;
        }
        if ($chave !== '') {
            $where[]  = 'chave_nf = ?';
            $params[] = $chave;
        }
        if (!$where) return false;

        $stmt = $pdo->prepare(
            "SELECT 1 FROM nf_notas_capturadas
              WHERE tipo_documento = 'NFSe' AND (" . implode(' OR ', $where) . ")
              LIMIT 1"
        );
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /** Compara o nome do tipo de nota sem acento e sem caixa. */
    private static function tipoNotaEhServico(?string $tipoNotaId): bool
    {
        if ($tipoNotaId === null || $tipoNotaId === '') return false;

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT valor FROM nf_lista_itens WHERE id = ? LIMIT 1");
        $stmt->execute([$tipoNotaId]);
        $nome = (string) ($stmt->fetchColumn() ?: '');

        return self::semAcento($nome) === self::TIPO_NOTA_SERVICO;
    }

    private static function semAcento(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        return strtr($s, [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','ê'=>'e','è'=>'e','í'=>'i','î'=>'i',
            'ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'ú'=>'u','ü'=>'u','ç'=>'c',
        ]);
    }

    /**
     * Verificação da abertura do processo.
     *
     * @param array{fornecedor_id:?string, fornecedor_doc:?string,
     *              tipo_nota_id:?string, chave_nf:?string, processo_id:?string} $ctx
     * @return array{bloqueio:?string, aviso:?string, servico:bool}
     */
    public static function verificarAbertura(array $ctx): array
    {
        $vazio = ['bloqueio' => null, 'aviso' => null, 'servico' => false];

        if (!self::notaEhServico(
            $ctx['tipo_nota_id'] ?? null,
            $ctx['chave_nf']     ?? null,
            $ctx['processo_id']  ?? null
        )) {
            return $vazio;
        }

        require_once SRC . '/Models/Supplier.php';
        $forn = null;
        if (!empty($ctx['fornecedor_id'])) {
            $forn = Supplier::findById($ctx['fornecedor_id']);
        }
        if ($forn === null && !empty($ctx['fornecedor_doc'])) {
            $forn = Supplier::findByDoc((string) $ctx['fornecedor_doc']);
        }
        if ($forn === null) return ['bloqueio' => null, 'aviso' => null, 'servico' => true];

        $motivo = self::motivoExigencia($forn);

        // MEI só exige PIS/NIT quando o serviço da própria nota está na lista
        // de códigos sujeitos a INSS patronal (self::CODIGOS_INSS_MEI) — fora
        // dela, não exige mais nada. Quando não dá pra confirmar o código
        // (XML antigo/ABRASF), não bloqueia: vira aviso.
        // 05/10/2026: a trava de MEI foi RETIRADA — a checagem de código ainda
        // dispara exigências indevidas. MEI nunca bloqueia a abertura; no máximo
        // avisa (etapa 2: lista editável de serviços que exigem INSS).
        if ($motivo === 'MEI') {
            $motivo = null;
            if (!self::valido($forn['pis_nit'] ?? null)) {
                $exigeInss = self::codigoServicoExigeInssMei($ctx['chave_nf'] ?? null, $ctx['processo_id'] ?? null);
                if ($exigeInss !== false) {
                    return [
                        'bloqueio' => null,
                        'aviso'    => 'Nota de serviço de MEI'
                                    . ($exigeInss === true
                                        ? ' com serviço sujeito a INSS patronal'
                                        : ' (não foi possível confirmar o código do serviço)')
                                    . ': confira se o PIS/NIT de "' . $forn['razao_social']
                                    . '" é necessário em Listas → Fornecedores. A abertura não está bloqueada.',
                        'servico'  => true,
                    ];
                }
            }
        }

        if ($motivo !== null && !self::valido($forn['pis_nit'] ?? null)) {
            // 05/10/2026: pessoa física também deixou de bloquear — só avisa.
            return [
                'bloqueio' => null,
                'aviso'    => 'Nota de serviço de ' . $motivo . ': o cadastro de "'
                            . $forn['razao_social'] . '" está sem o PIS/NIT, usado na '
                            . 'retenção de INSS. Preencha em Listas → Fornecedores. A abertura não está bloqueada.',
                'servico'  => true,
            ];
        }

        if ($motivo === null && self::meiDesconhecido($forn) && !self::valido($forn['pis_nit'] ?? null)) {
            return [
                'bloqueio' => null,
                'aviso'    => 'Nota de serviço: o CNPJ de "' . $forn['razao_social'] . '" nunca foi '
                            . 'consultado na Receita. Se for MEI, o PIS/NIT é obrigatório — confira em '
                            . 'Listas → Fornecedores.',
                'servico'  => true,
            ];
        }

        return ['bloqueio' => null, 'aviso' => null, 'servico' => true];
    }
}
