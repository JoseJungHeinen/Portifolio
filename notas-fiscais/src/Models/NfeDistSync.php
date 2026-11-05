<?php
// @versao Cap_19 V_11_01/10/2026
declare(strict_types=1);

/**
 * Motor de sincronização direta com a distribuição de NF-e (SEFAZ/AN) — sem
 * Brobot. Mesmo desenho da AdnSync (cursor por filial, certificado único da
 * matriz em `nf_configuracoes` grupo 'dist' — cobre qualquer filial da mesma
 * raiz de CNPJ via a tag <CNPJ>, separada do certificado da conexão), com um
 * protocolo diferente (SOAP em vez de REST) e uma regra extra: a SEFAZ
 * já guarda o NSU esperado do lado dela, então qualquer cursor errado (local
 * desatualizado, primeiro run, etc.) vira bloqueio de 1h (cStat 656) — não um
 * erro comum que tentaria de novo no próximo agendamento em 5 min.
 *
 * Por isso, ao detectar 656: o run para IMEDIATAMENTE (não tenta de novo,
 * mesmo dentro do mesmo run) e, se a SEFAZ informou o `ultNSU` certo na
 * própria rejeição, o cursor local já é corrigido pra aquele valor — o
 * próximo agendamento (5, 30 min depois) já nasce certo, sem precisar de
 * intervenção manual nem de um novo bloqueio pra descobrir o valor certo.
 */
class NfeDistSync
{
    private const MAX_LOTES = 40;

    // cStat de rejeição DEFINITIVA da Ciência da Operação — reenviar não muda
    // o resultado (a nota já está cancelada/denegada, já foi manifestada por
    // fora, ou passou do prazo). Marcadas como 'erro_permanente' (migration
    // 062) pra sair do filtro de reenvio em reenviarManifestacoesPendentes();
    // qualquer outro cStat de rejeição fica como 'erro' comum (rede/schema/
    // instabilidade da SEFAZ) e continua sendo reenviado a cada run.
    //   650 = NF-e cancelada ou denegada
    //   655 = Ciência informada após a manifestação final do destinatário
    //   596 = evento fora do prazo permitido (10 dias)
    private const CSTAT_REJEICAO_PERMANENTE = ['650', '655', '596'];

    /** UF (sigla) → código IBGE, usado em cUFAutor. */
    private const COD_UF = [
        'RO'=>11,'AC'=>12,'AM'=>13,'RR'=>14,'PA'=>15,'AP'=>16,'TO'=>17,
        'MA'=>21,'PI'=>22,'CE'=>23,'RN'=>24,'PB'=>25,'PE'=>26,'AL'=>27,'SE'=>28,'BA'=>29,
        'MG'=>31,'ES'=>32,'RJ'=>33,'SP'=>35,
        'PR'=>41,'SC'=>42,'RS'=>43,
        'MS'=>50,'MT'=>51,'GO'=>52,'DF'=>53,
    ];

    /** @return array{status:string, importadas:int, ignoradas:int, atualizadas:int, detalhe:string} */
    public static function run(): array
    {
        $certPadraoPath  = (string) Settings::get('dist.cert_path', '');
        $certPadraoSenha = (string) Settings::get('dist.cert_senha', '');

        // Mesma janela de busca da AdnSync (filiais.dist_horario_inicio/fim) —
        // compartilhada entre NF-e e NFS-e: é "quando esta loja busca", não por
        // tipo de documento.
        $filiais = Database::getInstance()->query(
            "SELECT id, nome, cnpj, estado, dist_cert_path, dist_cert_senha, dist_intervalo_min
             FROM filiais
             WHERE ativo = 1 AND nfe_dist_ativo = 1
               AND (
                     (dist_horario_inicio <= dist_horario_fim
                        AND CURTIME() >= dist_horario_inicio AND CURTIME() < dist_horario_fim)
                  OR (dist_horario_inicio > dist_horario_fim
                        AND (CURTIME() >= dist_horario_inicio OR CURTIME() < dist_horario_fim))
                   )"
        )->fetchAll();

        if (empty($filiais)) {
            return ['status' => 'skip', 'importadas' => 0, 'ignoradas' => 0, 'atualizadas' => 0,
                    'detalhe' => 'Nenhuma filial ativa e dentro da janela de busca agora.'];
        }

        $totalImportadas = 0; $totalIgnoradas = 0; $totalAtualizadas = 0; $detalhes = [];
        $status = 'ok';

        foreach ($filiais as $filial) {
            // Intervalo mínimo opcional — ver AdnSync::ultimoRunRecente() pro
            // porquê. Pra NF-e importa AINDA MAIS: cada tentativa é uma
            // chamada real à SEFAZ, e é lá que vem o bloqueio de 1h.
            if (!empty($filial['dist_intervalo_min']) && self::ultimoRunRecente($filial['id'], (int) $filial['dist_intervalo_min'])) {
                continue;
            }

            // Resolução: certificado próprio da filial → certificado da
            // matriz (raiz de CNPJ) → padrão de reserva. Mesma regra da AdnSync.
            [$certPath, $certSenha] = CertificadoMatriz::resolverParaFilial($filial, $certPadraoPath, $certPadraoSenha);
            $r = self::processarFilial($certPath, $certSenha, $filial);
            $totalImportadas += $r['importadas'];
            $totalIgnoradas  += $r['ignoradas'];
            $totalAtualizadas += $r['atualizadas'];
            $detalhes[] = "{$filial['nome']}: {$r['detalhe']}";
            if ($r['status'] === 'erro' || $r['status'] === 'bloqueado') $status = 'parcial';
        }

        return ['status' => $status, 'importadas' => $totalImportadas,
                'ignoradas' => $totalIgnoradas, 'atualizadas' => $totalAtualizadas,
                'detalhe' => implode(' | ', $detalhes)];
    }

    /**
     * @param array{id:string, nome:string, cnpj:string, estado:?string} $filial
     * @return array{status:string, importadas:int, ignoradas:int, atualizadas:int, detalhe:string}
     */
    public static function processarFilial(string $certPath, string $certSenha, array $filial): array
    {
        $inicio = date('Y-m-d H:i:s');

        // "91" já foi tentado como fallback genérico e a SEFAZ rejeitou com
        // cStat 215 (falha de esquema) — não é um código nacional válido pra
        // este campo. Sem UF cadastrada na filial, é melhor recusar aqui
        // (sem gastar uma chamada real) do que mandar um valor que já sabemos
        // que falha. Achado real, filial B7 Participações, 17/09/2026.
        $uf = strtoupper((string) $filial['estado']);
        if (!isset(self::COD_UF[$uf])) {
            $r = ['status' => 'erro', 'importadas' => 0, 'ignoradas' => 0, 'atualizadas' => 0,
                  'detalhe' => "Filial sem UF cadastrada (ou UF '{$uf}' desconhecida) — cUFAutor não tem valor seguro."];
            self::registrarLog($filial['id'], $inicio, $r, null);
            return $r;
        }
        $cUfAutor  = (string) self::COD_UF[$uf];
        $filialCnpj = self::soDigitos($filial['cnpj']);
        $cliente   = new NfeDistClient($certPath, $certSenha, (string) $filial['cnpj'], $cUfAutor, false);

        if (!$cliente->certificadoValido()) {
            $r = ['status' => 'erro', 'importadas' => 0, 'ignoradas' => 0, 'atualizadas' => 0,
                  'detalhe' => 'Certificado inválido ou arquivo .pfx não encontrado.'];
            self::registrarLog($filial['id'], $inicio, $r, null);
            return $r;
        }

        // Reenvio de Ciência da Operação pendente/com erro — evento separado
        // (webservice e cota diferentes de nfeDistDFeInteresse), então roda
        // mesmo se o NSU desta filial estiver bloqueado (656) agora.
        self::reenviarManifestacoesPendentes($certPath, $certSenha, $filialCnpj);

        // Conferência de segurança: processos abertos ainda com o resNFe anexado
        // embora a captura já esteja completa (também não depende da SEFAZ).
        self::conferirAnexosResumo();

        $nsu =self::cursorAtual($filial['id']);
        $importadas = 0; $ignoradas = 0; $atualizadas = 0; $erro = null; $nsuFinal = $nsu; $bloqueado = false;

        for ($i = 0; $i < self::MAX_LOTES; $i++) {
            $lote = $cliente->baixarLote($nsuFinal);

            if ($lote['bloqueado']) {
                $bloqueado = true;
                $erro = $lote['erro'];
                // A SEFAZ costuma devolver o ultNSU certo até na rejeição —
                // adota já, pro próximo run não precisar descobrir de novo.
                if ($lote['ultNSU'] !== null) $nsuFinal = $lote['ultNSU'];
                break;
            }
            if (!$lote['ok']) {
                $erro = $lote['erro'] ?? 'falha desconhecida';
                break;
            }
            if ($lote['semNovidade']) {
                if ($lote['ultNSU'] !== null) $nsuFinal = $lote['ultNSU'];
                break;
            }

            foreach ($lote['docs'] as $doc) {
                $res = self::processarDoc($doc, $filial['id'], $filial['nome'], $filialCnpj, $certPath, $certSenha);
                if ($res === 'importada')       $importadas++;
                elseif ($res === 'atualizada')  $atualizadas++;
                else                             $ignoradas++;

                if ($doc['nsu'] > $nsuFinal) $nsuFinal = $doc['nsu'];
            }
        }

        if ($nsuFinal !== $nsu) {
            self::salvarCursor($filial['id'], $nsuFinal);
        }

        $status = $bloqueado ? 'bloqueado' : ($erro !== null ? ($importadas > 0 ? 'parcial' : 'erro') : 'ok');
        $detalhe = match (true) {
            $bloqueado    => "Bloqueado pela SEFAZ (consumo indevido) — {$erro} NSU corrigido para {$nsuFinal}.",
            $erro !== null => "Falha após {$importadas} importada(s): {$erro}",
            default        => "{$importadas} importada(s), {$atualizadas} completada(s) (resNFe→procNFe), "
                             . "{$ignoradas} fora de filtro/duplicada(s). NSU: {$nsu} → {$nsuFinal}.",
        };

        $r = ['status' => $status, 'importadas' => $importadas, 'ignoradas' => $ignoradas,
              'atualizadas' => $atualizadas, 'detalhe' => $detalhe];
        self::registrarLog($filial['id'], $inicio, $r, $nsuFinal !== $nsu ? $nsuFinal : null);
        return $r;
    }

    /**
     * Reenvia a Ciência da Operação de notas que já capturamos como resNFe
     * mas cuja manifestação ainda não foi confirmada (falha de rede/SOAP na
     * primeira tentativa, ou ainda nem tentamos). Limite de 20 por run —
     * evita martelar a SEFAZ se um dia acumular muita coisa pendente; o resto
     * espera o próximo run desta filial (5–30 min depois).
     *
     * Propositalmente NÃO inclui 'erro_permanente' — é rejeição definitiva da
     * SEFAZ (ver CSTAT_REJEICAO_PERMANENTE), reenviar não muda o resultado.
     */
    private static function reenviarManifestacoesPendentes(string $certPath, string $certSenha, string $filialCnpj): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            "SELECT id, chave_nf FROM nf_notas_capturadas
             WHERE destinatario_cnpj = ? AND xml_completo = 0
               AND manifestacao_status IN ('pendente', 'erro')
             LIMIT 20"
        );
        $stmt->execute([$filialCnpj]);
        foreach ($stmt->fetchAll() as $nota) {
            self::enviarCienciaDaOperacao((string) $nota['id'], (string) $nota['chave_nf'], $certPath, $certSenha, $filialCnpj);
        }
    }

    /**
     * A distribuição devolve 3 tipos de documento (identificados pelo atributo
     * `schema` do docZip — ver NfeDistClient::extrairRetorno), cada um com
     * regra própria:
     *   - procNFe: nota completa + protocolo. Layout padrão da NF-e — usa o
     *     parser 'NFe' de sempre, e o dest_cnpj do próprio XML tem que bater
     *     com a filial (checagem real, o XML pode teoricamente discordar).
     *   - resNFe: resumo (SEFAZ ainda não liberou a nota completa — falta
     *     manifestação do destinatário). Sem itens, sem protocolo, layout
     *     achatado. Como só chega no stream de quem TEM que receber, o
     *     destinatário é a própria filial consultada — não tem o que checar
     *     no XML porque o resNFe não traz essa informação.
     *   - procEventoNFe / resEvento: evento (manifestação, cancelamento,
     *     ciência da operação) — não é nota, não tem o que importar. Era
     *     exatamente isso que a versão anterior tentava (e falhava
     *     silenciosamente) parsear como se fosse NF-e.
     *
     * Quando o resNFe já foi capturado antes e agora chega o procNFe da
     * MESMA chave, não é duplicata — é a nota completa que a SEFAZ só
     * liberou depois da manifestação (ver enviarCienciaDaOperacao). Nesse
     * caso a nota existente é COMPLETADA (xml_completo 0 → 1, numero/cfop/
     * natureza/itens preenchidos, XML no storage sobrescrito), nunca
     * descartada como "já existe".
     *
     * @param array{nsu:int, chave:?string, tipo:string, xml:string} $doc
     * @return 'importada'|'atualizada'|'ignorada'
     */
    private static function processarDoc(
        array $doc, string $filialId, string $filialNome, string $filialCnpj,
        string $certPath, string $certSenha
    ): string {
        if (str_contains($doc['tipo'], 'Evento')) {
            return 'ignorada'; // manifestação/cancelamento/ciência — não é nota
        }
        if (!in_array($doc['tipo'], ['procNFe', 'resNFe'], true)) {
            error_log("NfeDistSync: tipo de documento desconhecido ({$doc['tipo']}) — descartado.");
            return 'ignorada';
        }

        $ehResumo = $doc['tipo'] === 'resNFe';
        $parsed   = XmlDfeParser::parse($doc['xml'], $ehResumo ? 'ResNFe' : 'NFe');

        $chave = self::soDigitos((string) ($doc['chave'] ?? self::chaveDoXml($doc['xml'])));
        if ($chave === '' || strlen($chave) < 44) {
            return 'ignorada'; // XML sem chave reconhecível
        }

        // Só nos interessa quando a filial é a DESTINATÁRIA (compra) — mesma
        // regra que já vale hoje pra Brobot em BrobotSync::processarDoc().
        // No resNFe não tem dest_cnpj pra checar: o próprio fato de o
        // documento aparecer no stream desta filial já garante isso.
        if (!$ehResumo && ($parsed['dest_cnpj'] === null || $parsed['dest_cnpj'] !== $filialCnpj)) {
            return 'ignorada';
        }

        $pdo = Database::getInstance();
        $existente = $pdo->prepare("SELECT id, xml_completo FROM nf_notas_capturadas WHERE chave_nf = ? LIMIT 1");
        $existente->execute([$chave]);
        $ja = $existente->fetch();

        if ($ja) {
            // Resumo repetido, ou nota que já tínhamos completa — duplicata
            // de verdade. Só vale a pena atualizar quando o que já existe é
            // resumo (xml_completo=0) e o que chegou agora é o procNFe.
            if ($ehResumo || (int) $ja['xml_completo'] === 1) {
                return 'ignorada';
            }
            self::completarComXmlCompleto((string) $ja['id'], $chave, $parsed, $doc['xml']);
            return 'atualizada';
        }

        $fornCnpj = $parsed['emit_cnpj'];
        $fornNome = $parsed['emit_nome'];
        if ($fornCnpj === null || $fornNome === null) {
            error_log("NfeDistSync: NF-e {$chave} sem emitente reconhecível no XML — descartada.");
            return 'ignorada';
        }

        $numero  = (string) ($parsed['numero'] ?? '');
        // resNFe (resumo) não traz nNF; o número está na própria chave (pos. 26–34).
        if ($numero === '' && strlen($chave) === 44 && ctype_digit($chave)) {
            $numero = ltrim(substr($chave, 25, 9), '0');
        }
        $valor   = (float) ($parsed['valor'] ?? 0);
        $emissao = $parsed['emissao'] ?? date('Y-m-d');

        $itensCodigos = null;
        if (!empty($parsed['itens'])) {
            $codigos = array_values(array_filter(array_map(
                fn($item) => trim((string) ($item['codigo'] ?? '')), $parsed['itens']
            )));
            $itensCodigos = json_encode($codigos, JSON_UNESCAPED_UNICODE);
        }

        $id = self::uuid();
        $stmt = $pdo->prepare(
            "INSERT INTO nf_notas_capturadas
                (id, chave_nf, tipo_documento, numero_nf, fornecedor_nome, fornecedor_cnpj,
                 destinatario_cnpj, filial_id, filial_nome, valor, data_emissao, cfop, itens_codigos,
                 natureza_operacao, origem, brobot_id, xml_disponivel, pdf_disponivel, status,
                 xml_completo, manifestacao_status, capturada_em)
             VALUES (?, ?, 'NFe', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'nfe_dist', ?, 1, 0, 'capturada', ?, ?, NOW())"
        );
        $stmt->execute([
            $id, $chave, $numero, $fornNome, $fornCnpj,
            $filialCnpj, $filialId, $filialNome, $valor, $emissao, $parsed['cfop'], $itensCodigos,
            $parsed['natureza_operacao'] !== null ? mb_substr($parsed['natureza_operacao'], 0, 120) : null,
            (string) $doc['nsu'],
            $ehResumo ? 0 : 1,
            $ehResumo ? 'pendente' : 'nao_aplica',
        ]);

        self::salvarXml($chave, $doc['xml']);

        if ($ehResumo) {
            self::enviarCienciaDaOperacao($id, $chave, $certPath, $certSenha, $filialCnpj);
        }

        return 'importada';
    }

    /** Preenche uma nota antes capturada como resNFe com os dados do procNFe que chegou depois. */
    private static function completarComXmlCompleto(string $id, string $chave, array $parsed, string $xmlCompleto): void
    {
        $itensCodigos = null;
        if (!empty($parsed['itens'])) {
            $codigos = array_values(array_filter(array_map(
                fn($item) => trim((string) ($item['codigo'] ?? '')), $parsed['itens']
            )));
            $itensCodigos = json_encode($codigos, JSON_UNESCAPED_UNICODE);
        }

        Database::getInstance()->prepare(
            "UPDATE nf_notas_capturadas
             SET numero_nf = ?, valor = ?, data_emissao = ?, cfop = ?, itens_codigos = ?,
                 natureza_operacao = ?, xml_completo = 1, manifestacao_status = 'nao_aplica'
             WHERE id = ?"
        )->execute([
            (string) ($parsed['numero'] ?? ''),
            (float) ($parsed['valor'] ?? 0),
            $parsed['emissao'] ?? date('Y-m-d'),
            $parsed['cfop'],
            $itensCodigos,
            $parsed['natureza_operacao'] !== null ? mb_substr($parsed['natureza_operacao'], 0, 120) : null,
            $id,
        ]);

        self::salvarXml($chave, $xmlCompleto); // sobrescreve o resNFe pelo procNFe no storage

        // Processo já vinculado quando só havia o resumo: leva o XML completo
        // (e o DANFE) para os anexos dele — sem isso o processo fica "pré".
        self::conferirAnexosResumo($chave);
    }

    /**
     * Conferência dos anexos de processos vinculados a notas cujo XML já está
     * completo mas cujo anexo XML ainda é o resNFe (vinculado antes do procNFe
     * chegar). Troca o anexo XML pelo procNFe do storage e, quando o processo
     * tem uma única nota, regenera o DANFE HTML (também nascido do resumo).
     * O arquivo antigo vai para `_bkp_resumo/` dentro da pasta do processo.
     *
     * Com $chave: confere só aquela nota, em qualquer status do processo.
     * Sem $chave: varredura de segurança nos processos `aberto`, limitada a
     * $limite por run — pega o que a completação imediata não alcançou.
     * Nunca lança: falha aqui vai para o log e não derruba o sync.
     *
     * @return int quantidade de processos atualizados
     */
    public static function conferirAnexosResumo(?string $chave = null, int $limite = 100): int
    {
        $atualizados = 0;
        try {
            require_once __DIR__ . '/../Helpers/XmlDfeParser.php';
            require_once __DIR__ . '/../Helpers/DfeHtml.php';

            $pdo  = Database::getInstance();
            $base = defined('STORAGE') ? STORAGE : dirname(__DIR__, 2) . '/storage';

            $sql = "SELECT c.*, p.id AS proc_id
                      FROM nf_notas_capturadas c
                      JOIN nf_processos p ON p.id = c.processo_id
                     WHERE p.deletado_em IS NULL AND c.tipo_documento = 'NFe'
                       AND c.xml_completo = 1 AND c.origem = 'nfe_dist'";
            $params = [];
            if ($chave !== null) {
                $sql .= " AND c.chave_nf = ?";
                $params[] = $chave;
            } else {
                $sql .= " AND p.status = 'aberto' ORDER BY c.capturada_em DESC LIMIT " . (int) $limite;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            foreach ($stmt->fetchAll() as $nota) {
                $ch  = (string) $nota['chave_nf'];
                $pid = (string) $nota['proc_id'];

                $anexo = $pdo->prepare(
                    "SELECT id, caminho FROM nf_processo_anexos
                      WHERE processo_id = ? AND tipo_anexo_nome = 'XML' AND deletado_em IS NULL
                        AND nome_original LIKE ? LIMIT 1"
                );
                $anexo->execute([$pid, '%' . $ch . '.xml']);
                $axml = $anexo->fetch();
                if (!$axml) continue;

                $atual = @file_get_contents($axml['caminho']);
                if ($atual === false || !str_contains($atual, '<resNFe')) continue; // já completo

                $xml = @file_get_contents($base . '/nfe_xml/' . $ch . '.xml');
                if ($xml === false || !str_contains($xml, '<nfeProc') || !str_contains($xml, '<det')) continue;

                $trocas = [[$axml, $xml]];

                $unica = $pdo->prepare("SELECT COUNT(*) FROM nf_notas_capturadas WHERE processo_id = ?");
                $unica->execute([$pid]);
                if ((int) $unica->fetchColumn() === 1) {
                    $dan = $pdo->prepare(
                        "SELECT id, caminho FROM nf_processo_anexos
                          WHERE processo_id = ? AND deletado_em IS NULL AND nome_original = ? LIMIT 1"
                    );
                    $dan->execute([$pid, 'DANFE_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $nota['numero_nf']) . '.html']);
                    if ($adan = $dan->fetch()) {
                        $det    = XmlDfeParser::detalhes($xml, 'NFe');
                        $trocas[] = [$adan, DfeHtml::gerar($nota, $det)];
                    }
                }

                $bkp = dirname($axml['caminho']) . '/_bkp_resumo';
                if (!is_dir($bkp)) @mkdir($bkp, 0750, true);
                foreach ($trocas as [$a, $novo]) {
                    if (is_file($a['caminho'])) @copy($a['caminho'], $bkp . '/' . basename($a['caminho']));
                    if (file_put_contents($a['caminho'], $novo) === false) continue 2;
                    $pdo->prepare("UPDATE nf_processo_anexos SET tamanho_bytes = ? WHERE id = ?")
                        ->execute([strlen($novo), $a['id']]);
                }
                $atualizados++;
            }
        } catch (Throwable $e) {
            error_log('NfeDistSync::conferirAnexosResumo — ' . $e->getMessage());
        }
        return $atualizados;
    }

    /**
     * Ciência da Operação (tpEvento 210210) — evento mínimo de manifestação
     * do destinatário, disparado assim que capturamos um resNFe. É o que faz
     * a SEFAZ liberar o procNFe completo no NSU seguinte; sem isso a nota
     * fica presa no resumo pra sempre (ver migration 061).
     */
    private static function enviarCienciaDaOperacao(
        string $notaId, string $chave, string $certPath, string $certSenha, string $filialCnpj
    ): void {
        require_once __DIR__ . '/../Helpers/NfeEventoClient.php';
        $cliente = new NfeEventoClient($certPath, $certSenha, $filialCnpj);
        $r = $cliente->cienciaDaOperacao($chave);

        $detalhe = $r['ok']
            ? 'cStat ' . $r['cStat'] . ' — ' . $r['xMotivo']
            : ($r['erro'] ?? 'falha desconhecida');

        $permanente = !$r['ok'] && $r['cStat'] !== null
            && in_array($r['cStat'], self::CSTAT_REJEICAO_PERMANENTE, true);
        $status = $r['ok'] ? 'enviada' : ($permanente ? 'erro_permanente' : 'erro');

        Database::getInstance()->prepare(
            "UPDATE nf_notas_capturadas
             SET manifestacao_status = ?, manifestacao_detalhe = ?, manifestacao_em = NOW()
             WHERE id = ?"
        )->execute([
            $status,
            mb_substr((string) $detalhe, 0, 255),
            $notaId,
        ]);
    }

    /**
     * procNFe: infNFe/@Id vem como "NFe" + 44 dígitos (mesmo padrão de
     * XmlDfeParser::fiscalNfe()). resNFe: a chave vem solta em <chNFe>.
     *
     * query() devolve um DOMNodeList mesmo vazio (objeto, sempre "truthy") —
     * não dá pra usar `?:` pra cair no fallback quando o 1º não acha nada,
     * precisa checar ->length explicitamente.
     */
    private static function chaveDoXml(string $xml): string
    {
        if (trim($xml) === '') return '';
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING)) return '';
        $xp = new DOMXPath($doc);

        $n = $xp->query('//*[local-name()="infNFe"]/@Id');
        if ($n === false || $n->length === 0) {
            $n = $xp->query('//*[local-name()="chNFe"]');
        }
        return ($n !== false && $n->length > 0) ? self::soDigitos((string) $n->item(0)->nodeValue) : '';
    }

    // ─────────────────────────────────────────────────────────────
    // Cursor / log
    // ─────────────────────────────────────────────────────────────

    /** true se o último run (log, qualquer status) desta filial foi há menos de $minutos. */
    private static function ultimoRunRecente(string $filialId, int $minutos): bool
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT TIMESTAMPDIFF(MINUTE, iniciado_em, NOW()) FROM nf_dist_log
             WHERE filial_id = ? ORDER BY iniciado_em DESC LIMIT 1"
        );
        $stmt->execute([$filialId]);
        $minutosDesde = $stmt->fetchColumn();
        return $minutosDesde !== false && (int) $minutosDesde < $minutos;
    }

    private static function cursorAtual(string $filialId): int
    {
        $stmt = Database::getInstance()->prepare("SELECT ultimo_nsu FROM nf_dist_nsu_cursor WHERE filial_id = ?");
        $stmt->execute([$filialId]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (int) $v : 0;
    }

    private static function salvarCursor(string $filialId, int $nsu): void
    {
        Database::getInstance()->prepare(
            "INSERT INTO nf_dist_nsu_cursor (filial_id, ultimo_nsu, atualizado_em)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE ultimo_nsu = VALUES(ultimo_nsu), atualizado_em = VALUES(atualizado_em)"
        )->execute([$filialId, $nsu]);
    }

    public static function registrarLog(string $filialId, string $inicio, array $r, ?int $nsuAte): void
    {
        try {
            $status = in_array($r['status'], ['ok', 'parcial', 'erro', 'bloqueado'], true) ? $r['status'] : 'ok';
            Database::getInstance()->prepare(
                "INSERT INTO nf_dist_log
                    (id, filial_id, status, iniciado_em, finalizado_em, docs_importadas, docs_atualizadas, docs_ignoradas, detalhe, nsu_ate)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)"
            )->execute([
                self::uuid(), $filialId, $status, $inicio,
                (int) $r['importadas'], (int) ($r['atualizadas'] ?? 0), (int) $r['ignoradas'],
                mb_substr($r['detalhe'], 0, 500), $nsuAte,
            ]);
        } catch (Throwable $e) {
            error_log('NfeDistSync: falha ao registrar log: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Auxiliares
    // ─────────────────────────────────────────────────────────────

    private static function salvarXml(string $chave, string $xml): void
    {
        if (trim($xml) === '') return;
        $base = (defined('STORAGE') ? STORAGE : ROOT . '/storage') . '/nfe_xml';
        if (!is_dir($base)) @mkdir($base, 0775, true);
        @file_put_contents($base . '/' . $chave . '.xml', $xml);
    }

    private static function soDigitos(string $v): string
    {
        return preg_replace('/\D+/', '', $v) ?? '';
    }

    private static function uuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
    }
}
