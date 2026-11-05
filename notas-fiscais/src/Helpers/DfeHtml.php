<?php
// @versao Cap_09 V_14_25/09/2026
declare(strict_types=1);

/**
 * Gera o HTML do DANFE/DACTE anexado ao processo quando uma nota capturada
 * é vinculada (manual ou pelo pré-fluxo).
 *
 * É a versão server-side do preview da tela de capturas: recebe a linha de
 * nf_notas_capturadas + o array de XmlDfeParser::detalhes() (pode ser null
 * quando o XML não está no storage — cai no resumo capturado).
 *
 * O arquivo resultante é standalone (sem assets externos) e é servido inline
 * no viewer do processo via iframe.
 */
class DfeHtml
{
    public static function gerar(array $nota, ?array $det): string
    {
        // NF-e com XML completo → DANFE no layout oficial (retrato, mod. 55)
        if (($nota['tipo_documento'] ?? '') === 'NFe' && !empty($det['nfe'])) {
            return self::gerarDanfe($nota, $det['nfe']);
        }

        // CT-e com XML completo → DACTE no layout oficial (mod. 57)
        if (in_array($nota['tipo_documento'] ?? '', ['CTe', 'CTOs'], true) && !empty($det['cte'])) {
            return self::gerarDacte($nota, $det);
        }

        // NFS-e com XML completo (Nacional ou ABRASF) → DANFSe unificado
        if (($nota['tipo_documento'] ?? '') === 'NFSe' && !empty($det['nfse'])) {
            return self::gerarDanfse($nota, $det['nfse']);
        }

        $isCte = in_array($nota['tipo_documento'] ?? '', ['CTe', 'CTOs'], true);
        $sigla = $isCte ? 'DACTE' : 'DANFE';
        $sub   = $isCte
            ? 'Documento Auxiliar do<br>Conhecimento de Transporte Eletrônico'
            : 'Documento Auxiliar da<br>Nota Fiscal Eletrônica';

        $partesHtml = '';
        if (!empty($det['partes'])) {
            $tomadorCnpj = $det['tomador']['cnpj'] ?? null;
            foreach ($det['partes'] as $p) {
                $selo = ($tomadorCnpj !== null && ($p['cnpj'] ?? null) === $tomadorCnpj)
                    ? ' <span style="background:#FFE8EE; color:#B3261E; font-size:9px; font-weight:700; padding:2px 6px; border-radius:6px">TOMADOR — paga o frete</span>'
                    : '';
                $local = implode('/', array_filter([$p['municipio'] ?? null, $p['uf'] ?? null]));
                $partesHtml .= self::caixa(
                    self::e($p['papel'] ?? '') . $selo,
                    '<div style="font-weight:700">' . self::e($p['nome'] ?? '—') . '</div>'
                    . '<div style="color:#606060">CNPJ: ' . self::e(self::cnpj($p['cnpj'] ?? null)) . '</div>'
                    . ($local !== '' ? '<div style="color:#888; font-size:12px">' . self::e($local) . '</div>' : '')
                );
            }
        } else {
            $partesHtml = self::caixa('Destinatário',
                '<div style="font-weight:700">' . self::e($nota['filial_nome'] ?? '—') . '</div>'
                . '<div style="color:#606060">CNPJ: ' . self::e(self::cnpj($nota['destinatario_cnpj'] ?? null)) . '</div>');
        }

        $trajetoHtml = '';
        if (!empty($det['trajeto'])) {
            $trajetoHtml = self::caixa('Trajeto',
                '<div style="font-weight:700">' . self::e($det['trajeto']['origem'] ?? '—')
                . ' &rarr; ' . self::e($det['trajeto']['destino'] ?? '—') . '</div>');
        }
        $emissaoHtml = self::caixa('Emissão / Valor total',
            '<div style="color:#606060">' . self::e(date('d/m/Y', strtotime((string) $nota['data_emissao']))) . '</div>'
            . '<div style="font-weight:700; font-size:18px; color:#2347C8">' . self::e(self::moeda($nota['valor'] ?? null)) . '</div>');

        $chavesHtml = '';
        if (!empty($det['nfe_chaves'])) {
            $linhas = '';
            foreach ($det['nfe_chaves'] as $c) {
                $linhas .= '<div style="font-family:monospace; font-size:12px; word-break:break-all">' . self::e($c) . '</div>';
            }
            $plural     = count($det['nfe_chaves']) > 1 ? 's' : '';
            $chavesHtml = self::caixa("NF-e transportada{$plural}", $linhas) . '<div style="margin-bottom:12px"></div>';
        }

        $veiculosHtml = '';
        if (!empty($det['veiculos'])) {
            $linhas = '';
            foreach ($det['veiculos'] as $v) {
                $linhas .= '<tr>'
                    . '<td style="padding:6px 8px; border-top:1px solid #EAEAEA; font-family:monospace">' . self::e($v['chassi'] ?? '—') . '</td>'
                    . '<td style="padding:6px 8px; border-top:1px solid #EAEAEA">' . self::e($v['modelo'] ?? '—') . '</td>'
                    . '<td style="padding:6px 8px; border-top:1px solid #EAEAEA">' . self::e($v['cor'] ?? '—') . '</td>'
                    . '<td style="padding:6px 8px; border-top:1px solid #EAEAEA; text-align:right">' . self::e(self::moeda($v['valor'] ?? null)) . '</td>'
                    . '<td style="padding:6px 8px; border-top:1px solid #EAEAEA; text-align:right">' . self::e(self::moeda($v['frete'] ?? null)) . '</td>'
                    . '</tr>';
            }
            $s = count($det['veiculos']) > 1 ? 's' : '';
            $veiculosHtml = self::caixa("Veículo{$s} transportado{$s}",
                '<table style="width:100%; border-collapse:collapse; font-size:12px">'
                . '<thead><tr style="color:#888; text-transform:uppercase; font-size:10px; text-align:left">'
                . '<th style="padding:4px 8px">Chassi</th><th style="padding:4px 8px">Modelo</th><th style="padding:4px 8px">Cor</th>'
                . '<th style="padding:4px 8px; text-align:right">Valor</th><th style="padding:4px 8px; text-align:right">Frete</th>'
                . '</tr></thead><tbody>' . $linhas . '</tbody></table>') . '<div style="margin-bottom:12px"></div>';
        }

        $prestHtml = '';
        if (!empty($det['prestacao']['componentes'])) {
            $comps = '';
            foreach ($det['prestacao']['componentes'] as $c) {
                $comps .= '<div style="display:flex; justify-content:space-between; color:#606060">'
                    . '<span>' . self::e($c['nome'] ?? '—') . '</span><span>' . self::e(self::moeda($c['valor'] ?? null)) . '</span></div>';
            }
            $prestHtml = self::caixa('Composição do frete', $comps
                . '<div style="display:flex; justify-content:space-between; font-weight:700; border-top:1px solid #EAEAEA; margin-top:4px; padding-top:4px">'
                . '<span>Total da prestação</span><span>' . self::e(self::moeda($det['prestacao']['total'] ?? null)) . '</span></div>')
                . '<div style="margin-bottom:12px"></div>';
        }

        $rodape = $det !== null
            ? 'Documento gerado a partir do XML autêntico capturado da SEFAZ.'
            : 'Documento gerado a partir dos dados capturados — XML completo indisponível para esta nota.';

        $numDoc = $isCte ? 'CT-e ' . self::e($nota['numero_nf'] ?? '') : 'NF ' . self::e($nota['numero_nf'] ?? '');

        return '<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<title>' . self::e($sigla) . ' — ' . $numDoc . '</title>
<style>body{font-family:\'Segoe UI\',Arial,sans-serif;color:#141922;margin:16px;background:#fff}</style>
</head><body>
<div style="border:2px solid #141922; border-radius:4px; padding:20px; font-size:13px; color:#141922; max-width:860px; margin:0 auto">
  <div style="display:flex; justify-content:space-between; border-bottom:2px solid #141922; padding-bottom:12px; margin-bottom:12px">
    <div>
      <div style="font-size:18px; font-weight:700">' . self::e($nota['fornecedor_nome'] ?? '') . '</div>
      <div style="color:#606060">CNPJ: ' . self::e(self::cnpj($nota['fornecedor_cnpj'] ?? null)) . '</div>
    </div>
    <div style="text-align:right">
      <div style="font-weight:700; font-size:15px">' . self::e($sigla) . '</div>
      <div style="color:#606060; font-size:11px">' . $sub . '</div>
      <div style="font-weight:700; margin-top:4px">Nº ' . self::e($nota['numero_nf'] ?? '') . '</div>
    </div>
  </div>
  <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px">' . $partesHtml . '</div>
  <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px">' . $trajetoHtml . $emissaoHtml . '</div>
  ' . $chavesHtml . $veiculosHtml . $prestHtml . '
  ' . self::caixa('Chave de acesso',
        '<div style="font-family:monospace; font-size:12px; word-break:break-all">' . self::e($nota['chave_nf'] ?? '') . '</div>') . '
  <div style="margin-top:12px; font-size:11px; color:#888; text-align:center">' . self::e($rodape) . '</div>
</div>
</body></html>';
    }

    // ─────────────────────────────────────────────────────────────
    // DACTE oficial (CT-e modelo 57, retrato)
    //
    // Segue o layout do Documento Auxiliar do CT-e: canhoto no topo, blocos
    // emitente/identificação, as quatro partes (remetente, destinatário,
    // expedidor, recebedor), tomador, carga OU detalhamento do complementado,
    // componentes do valor, imposto, documentos originários, observações e
    // dados do modal. Sem QR code — a conferência é pela chave no portal.
    // ─────────────────────────────────────────────────────────────

    private static function gerarDacte(array $nota, array $det): string
    {
        $c   = $det['cte'];
        $e   = fn($v) => self::e($v);
        $emi = $c['emitente'] ?? [];

        $chave    = preg_replace('/\D+/', '', (string) ($nota['chave_nf'] ?? ''));
        $chaveFmt = trim(chunk_split($chave, 4, ' '));

        $tiposCte = ['0' => 'Normal', '1' => 'Complemento de Valores', '2' => 'Anulação de Valores', '3' => 'Substituto'];
        $tiposSrv = ['0' => 'Normal', '1' => 'Subcontratação', '2' => 'Redespacho', '3' => 'Redespacho Intermediário', '4' => 'Serviço Vinculado a Multimodal'];
        $modais   = ['01' => 'Rodoviário', '02' => 'Aéreo', '03' => 'Aquaviário', '04' => 'Ferroviário', '05' => 'Dutoviário', '06' => 'Multimodal'];

        $tipoCte  = $tiposCte[$c['tipo_cte'] ?? ''] ?? '—';
        $tipoSrv  = $tiposSrv[$c['tipo_servico'] ?? ''] ?? '—';
        $modal    = $modais[$c['modal'] ?? ''] ?? '—';
        $ehCompl  = ($c['tipo_cte'] ?? '') === '1';
        $global   = ($c['globalizado'] ?? '') === '1';

        $protocolo = trim((string) ($c['protocolo'] ?? ''));
        if ($protocolo !== '' && !empty($c['prot_data'])) {
            $protocolo .= ' - ' . self::dataBr($c['prot_data']) . ' ' . self::horaBr($c['prot_data']);
        }

        // ── Partes: as quatro caixas, sempre presentes (vazias quando ausentes,
        //    como no DACTE oficial — o quadro existe mesmo sem expedidor) ──
        $porPapel = [];
        foreach ($det['partes'] ?? [] as $p) { $porPapel[$p['papel']] = $p; }

        $bloco = function (string $rotulo, ?array $p) use ($e): string {
            $p ??= [];
            $ender = trim(implode(', ', array_filter([$p['logradouro'] ?? null, $p['numero'] ?? null])));
            $compl = trim(implode(' - ', array_filter([$p['complemento'] ?? null, $p['bairro'] ?? null])));
            $mun   = trim(implode(' - ', array_filter([$p['municipio'] ?? null, $p['uf'] ?? null])));
            return '<div class="p-lbl">' . $e($rotulo) . ' <b>' . $e($p['nome'] ?? '') . '</b></div>'
                 . '<div class="p-lbl">ENDEREÇO <b>' . $e($ender) . '</b></div>'
                 . '<div class="p-ind"><b>' . $e($compl) . '</b></div>'
                 . '<div class="p-row"><span class="p-lbl">MUNICÍPIO <b>' . $e($mun) . '</b></span>'
                 . '<span class="p-lbl">CEP <b>' . $e(self::cep($p['cep'] ?? null)) . '</b></span></div>'
                 . '<div class="p-row"><span class="p-lbl">CNPJ/CPF <b>' . $e(self::docDacte($p['cnpj'] ?? null)) . '</b></span>'
                 . '<span class="p-lbl">INSCRIÇÃO ESTADUAL <b>' . $e($p['ie'] ?? '') . '</b></span></div>'
                 . '<div class="p-row"><span class="p-lbl">PAÍS <b>' . $e($p['pais'] ?? '') . '</b></span>'
                 . '<span class="p-lbl">FONE <b>' . $e(self::fone($p['fone'] ?? null)) . '</b></span></div>';
        };

        $tom      = $det['tomador'] ?? [];
        $tomEnder = trim(implode(', ', array_filter([$tom['logradouro'] ?? null, $tom['numero'] ?? null])));
        $tomCompl = trim(implode(' - ', array_filter([$tom['complemento'] ?? null, $tom['bairro'] ?? null])));

        // ── Carga (CT-e normal) ou detalhamento do complementado ──
        $med    = $c['carga']['medidas'] ?? [];
        $pegaM  = fn(string ...$chaves) => (function () use ($med, $chaves) {
            foreach ($chaves as $k) { if (isset($med[$k])) return self::num($med[$k], 3); }
            return '';
        })();

        if ($ehCompl) {
            $linhas = '';
            foreach ($c['complementados'] as $cp) {
                $linhas .= '<tr><td class="mono">' . $e($cp['chave']) . '</td>'
                         . '<td class="r b">' . $e(self::num($det['prestacao']['total'] ?? null)) . '</td>'
                         . '<td></td><td></td></tr>';
            }
            $blocoCarga = '<div class="sec">DETALHAMENTO DO CT-E COMPLEMENTADO</div>'
                . '<table class="grid"><tr class="h">'
                . '<td>CHAVE DO CT-E COMPLEMENTADO</td><td class="r">VALOR COMPLEMENTADO</td>'
                . '<td>CHAVE DO CT-E COMPLEMENTADO</td><td class="r">VALOR COMPLEMENTADO</td></tr>'
                . ($linhas ?: '<tr><td colspan="4">&nbsp;</td></tr>') . '</table>';
        } else {
            $blocoCarga = '<table class="grid">'
                . '<tr><td class="cap" style="width:50%">PRODUTO PREDOMINANTE<div class="v">' . $e($c['carga']['produto'] ?? '') . '</div></td>'
                . '<td class="cap" style="width:32%">OUTRAS CARACTERÍSTICAS DA CARGA<div class="v">' . $e($c['carga']['outras'] ?? '') . '</div></td>'
                . '<td class="cap">VALOR TOTAL DA CARGA<div class="v">' . $e(self::num($c['carga']['valor'] ?? null)) . '</div></td></tr></table>'
                . '<table class="grid"><tr>'
                . '<td class="cap ct">PESO BRUTO (KG)<div class="v">' . $e($pegaM('PESO BRUTO', 'PESO REAL')) . '</div></td>'
                . '<td class="cap ct">PESO BASE CÁLCULO (KG)<div class="v">' . $e($pegaM('PESO BASE CALCULO', 'PESO BASE DE CALCULO', 'PESO TAXADO')) . '</div></td>'
                . '<td class="cap ct">PESO AFERIDO (KG)<div class="v">' . $e($pegaM('PESO AFERIDO', 'PESO CUBADO')) . '</div></td>'
                . '<td class="cap ct">CUBAGEM(M3)<div class="v">' . $e($pegaM('CUBAGEM')) . '</div></td>'
                . '<td class="cap ct">QTDE(VOL)<div class="v">' . $e($pegaM('VOLUMES', 'QUANTIDADE')) . '</div></td>'
                . '</tr></table>';
        }

        // ── Componentes do valor: três pares NOME/VALOR por linha ──
        $comps = $det['prestacao']['componentes'] ?? [];
        $cels  = '';
        $total = max(3, (int) ceil(count($comps) / 3) * 3);
        for ($i = 0; $i < $total; $i++) {
            if ($i % 3 === 0) $cels .= '<tr>';
            $cp    = $comps[$i] ?? null;
            $cels .= '<td>' . $e($cp['nome'] ?? '') . '</td><td class="r">' . $e($cp ? self::num($cp['valor']) : '') . '</td>';
            if ($i % 3 === 2) $cels .= '</tr>';
        }

        // ── Documentos originários: duas notas por linha, como no DACTE ──
        // O total é sempre PAR (a linha tem dois pares de colunas) e no mínimo 2,
        // senão o CT-e sem nota transportada abriria um <tr> que nunca fecha.
        $docs = '';
        $dl   = $c['documentos'] ?? [];
        $celulas = max(2, (int) ceil(count($dl) / 2) * 2);
        for ($i = 0; $i < $celulas; $i++) {
            if ($i % 2 === 0) $docs .= '<tr>';
            $d     = $dl[$i] ?? null;
            $numer = $d ? trim((string) $d['serie']) . '/' . trim((string) $d['numero']) : '';
            $docs .= '<td>' . $e($d['tipo'] ?? '') . '</td><td class="mono">' . $e($d['chave'] ?? '') . '</td>'
                   . '<td>' . $e($numer) . '</td>';
            if ($i % 2 === 1) $docs .= '</tr>';
        }

        $imp    = $c['imposto'] ?? [];
        $obs    = implode(' ', $c['observacoes'] ?? []);
        // Canhoto usa o número com 9 dígitos e pontos, como no DACTE oficial
        $numFmt = trim(chunk_split(str_pad((string) ($c['numero'] ?? ''), 9, '0', STR_PAD_LEFT), 3, '.'), '.');

        return '<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<title>DACTE — CT-e ' . $e($c['numero'] ?? '') . '</title>
<style>
  @page{size:A4 portrait;margin:6mm}
  body{font-family:Arial,Helvetica,sans-serif;color:#000;margin:0;padding:6mm;background:#fff;font-size:7.5pt}
  .dacte{max-width:200mm;margin:0 auto}
  table{border-collapse:collapse;width:100%}
  .grid td{border:.5pt solid #000;padding:1.5pt 3pt;vertical-align:top}
  .cap{font-size:6pt;text-transform:uppercase}
  .v{font-size:8pt;font-weight:700;margin-top:1pt}
  .ct{text-align:center}
  .r{text-align:right}
  .b{font-weight:700}
  .mono{font-family:"Courier New",monospace;font-size:6.5pt;word-break:break-all}
  .sec{border:.5pt solid #000;border-bottom:0;text-align:center;font-size:6pt;text-transform:uppercase;padding:1pt}
  .grid tr.h td{font-size:6pt;text-transform:uppercase}
  .p-lbl{font-size:6pt;text-transform:uppercase;line-height:1.35}
  .p-lbl b{font-size:7.5pt;text-transform:none}
  .p-ind{font-size:7.5pt;font-weight:700;min-height:9pt}
  .p-row{display:flex;justify-content:space-between;gap:6pt}
  .canhoto{border:.5pt solid #000;border-bottom:1.2pt dashed #000}
  .tit{font-size:13pt;font-weight:700;text-align:center;letter-spacing:.5pt}
  .barras{display:flex;align-items:flex-end;height:13mm;gap:0}
  .barras i{display:block;height:100%}
  @media print{body{padding:0}}
</style></head><body><div class="dacte">

<table class="grid canhoto">
  <tr><td colspan="3" class="cap" style="text-align:center">DECLARO QUE RECEBI OS VOLUMES DESTE CONHECIMENTO EM PERFEITO ESTADO PELO QUE DOU POR CUMPRIDO O PRESENTE CONTRATO DE TRANSPORTE</td>
      <td rowspan="3" class="ct" style="width:32mm"><div style="font-weight:700;font-size:9pt">CT-E</div>
        <div style="font-size:10pt;font-weight:700;margin-top:3pt">Nº. ' . $e($numFmt) . '</div>
        <div style="font-size:9pt">Série ' . $e(str_pad((string) ($c['serie'] ?? ''), 3, '0', STR_PAD_LEFT)) . '</div></td></tr>
  <tr><td class="cap" style="width:52mm;height:9mm">NOME</td>
      <td rowspan="2" class="cap" style="width:46mm">ASSINATURA / CARIMBO</td>
      <td class="cap">TÉRMINO DA PRESTAÇÃO - DATA/HORA</td></tr>
  <tr><td class="cap" style="height:9mm">RG</td><td class="cap">INÍCIO DA PRESTAÇÃO - DATA/HORA</td></tr>
</table>

<table class="grid" style="margin-top:2mm">
  <tr>
    <td style="width:62mm" rowspan="2">
      <div style="font-size:9pt;font-weight:700">' . $e($emi['nome'] ?? '') . '</div>
      <div style="text-align:center;line-height:1.4">' . $e(trim(implode(', ', array_filter([$emi['logradouro'] ?? null, $emi['numero'] ?? null])))) . '<br>'
        . $e(trim(implode(' - ', array_filter([$emi['bairro'] ?? null, self::cep($emi['cep'] ?? null), $emi['municipio'] ?? null, $emi['uf'] ?? null])))) . '<br>'
        . 'Fone/Fax: ' . $e(self::fone($emi['fone'] ?? null)) . '</div>
      <div style="margin-top:2pt">CNPJ/CPF: ' . $e(self::docDacte($emi['cnpj'] ?? null)) . '&nbsp;&nbsp;Insc.Estadual: ' . $e($emi['ie'] ?? '') . '</div>
    </td>
    <td colspan="6" style="border-bottom:0">
      <div class="tit">DACTE</div>
      <div style="text-align:center;font-size:7pt">Documento Auxiliar do Conhecimento de Transporte Eletrônico</div>
    </td>
    <td class="cap ct" style="width:26mm">MODAL<div class="v">' . $e($modal) . '</div></td>
  </tr>
  <tr>
    <td class="cap ct">MODELO<div class="v">' . $e($c['modelo'] ?? '') . '</div></td>
    <td class="cap ct">SÉRIE<div class="v">' . $e($c['serie'] ?? '') . '</div></td>
    <td class="cap ct">NÚMERO<div class="v">' . $e($c['numero'] ?? '') . '</div></td>
    <td class="cap ct">FL<div class="v">1/1</div></td>
    <td class="cap ct" style="width:32mm">DATA E HORA DE EMISSÃO<div class="v">' . $e(self::dataBr($c['emissao'] ?? null) . ' ' . self::horaBr($c['emissao'] ?? null)) . '</div></td>
    <td class="cap">INSC.SUF.DO DEST</td>
    <td rowspan="1" class="ct" style="width:26mm">' . self::barrasCode128($chave) . '</td>
  </tr>
</table>

<table class="grid">
  <tr>
    <td class="cap ct" style="width:31mm">TIPO DO CTE<div class="v">' . $e($tipoCte) . '</div></td>
    <td class="cap ct" style="width:31mm">TIPO DO SERVIÇO<div class="v">' . $e($tipoSrv) . '</div></td>
    <td rowspan="2">
      <div class="cap">CHAVE DE ACESSO</div>
      <div style="text-align:center;font-weight:700;font-size:8pt;letter-spacing:.3pt">' . $e($chaveFmt) . '</div>
      <div style="text-align:center;margin-top:3pt">Consulta em http://www.cte.fazenda.gov.br/portal</div>
    </td>
  </tr>
  <tr>
    <td class="cap">IND.DO CT-E GLOBALIZADO
      <div class="v" style="text-align:center">' . ($global ? '<b>X</b> SIM &nbsp; ☐ NÃO' : '☐ SIM &nbsp; <b>X</b> NÃO') . '</div></td>
    <td class="cap">INF.DO CT-E GLOBALIZADO<div class="v">&nbsp;</div></td>
  </tr>
</table>

<table class="grid">
  <tr><td class="cap" style="width:50%">CFOP - NATUREZA DA PRESTAÇÃO<div class="v">' . $e(trim(($c['cfop'] ?? '') . ' - ' . ($c['natureza'] ?? ''), ' -')) . '</div></td>
      <td class="cap">PROTOCOLO DE AUTORIZAÇÃO DE USO<div class="v" style="text-align:center">' . $e($protocolo) . '</div></td></tr>
  <tr><td class="cap">INÍCIO DA PRESTAÇÃO<div class="v">' . $e($det['trajeto']['origem'] ?? '') . '</div></td>
      <td class="cap">TÉRMINO DA PRESTAÇÃO<div class="v">' . $e($det['trajeto']['destino'] ?? '') . '</div></td></tr>
</table>

<table class="grid">
  <tr><td style="width:50%">' . $bloco('REMETENTE', $porPapel['Remetente'] ?? null) . '</td>
      <td>' . $bloco('DESTINATÁRIO', $porPapel['Destinatário'] ?? null) . '</td></tr>
  <tr><td>' . $bloco('EXPEDIDOR', $porPapel['Expedidor'] ?? null) . '</td>
      <td>' . $bloco('RECEBEDOR', $porPapel['Recebedor'] ?? null) . '</td></tr>
</table>

<table class="grid">
  <tr><td colspan="2">
    <div class="p-row"><span class="p-lbl">TOMADOR DO SERVIÇO <b>' . $e($tom['nome'] ?? '') . '</b></span>
      <span class="p-lbl">MUNICÍPIO <b>' . $e($tom['municipio'] ?? '') . '</b> &nbsp; UF <b>' . $e($tom['uf'] ?? '') . '</b>
      &nbsp; CEP <b>' . $e(self::cep($tom['cep'] ?? null)) . '</b></span></div>
    <div class="p-lbl">ENDEREÇO <b>' . $e(trim($tomEnder . ($tomCompl !== '' ? ' - ' . $tomCompl : ''))) . '</b></div>
    <div class="p-row"><span class="p-lbl">CNPJ/CPF <b>' . $e(self::docDacte($tom['cnpj'] ?? null)) . '</b></span>
      <span class="p-lbl">INSCRIÇÃO ESTADUAL <b>' . $e($tom['ie'] ?? '') . '</b></span>
      <span class="p-lbl">PAÍS <b>' . $e($tom['pais'] ?? '') . '</b></span>
      <span class="p-lbl">FONE <b>' . $e(self::fone($tom['fone'] ?? null)) . '</b></span></div>
  </td></tr>
</table>

' . $blocoCarga . '

<div class="sec">COMPONENTES DO VALOR DA PRESTAÇÃO DO SERVIÇO</div>
<table class="grid">
  <tr class="h"><td>NOME</td><td class="r">VALOR</td><td>NOME</td><td class="r">VALOR</td><td>NOME</td><td class="r">VALOR</td>
      <td rowspan="' . (max(1, (int) ceil(max(3, count($comps)) / 3)) + 1) . '" class="cap ct" style="width:34mm">
        VALOR TOTAL DO SERVIÇO<div class="v" style="font-size:10pt">' . $e(self::num($det['prestacao']['total'] ?? null)) . '</div>
        <div style="border-top:.5pt solid #000;margin-top:3pt;padding-top:3pt">VALOR A RECEBER
        <div class="v" style="font-size:10pt">' . $e(self::num($det['prestacao']['receber'] ?? $det['prestacao']['total'] ?? null)) . '</div></div>
      </td></tr>
  ' . $cels . '
</table>

<div class="sec">INFORMAÇÕES RELATIVAS AO IMPOSTO</div>
<table class="grid">
  <tr class="h"><td>SITUAÇÃO TRIBUTÁRIA</td><td>BASE DE CALCULO</td><td>ALÍQ ICMS</td><td>VALOR ICMS</td><td>% RED. BC ICMS</td><td>VALOR ICMS ST</td></tr>
  <tr><td class="b">' . $e(self::cstIcmsCte($imp['cst'] ?? null)) . '</td>
      <td class="r">' . $e(self::num($imp['bc'] ?? null)) . '</td>
      <td class="r">' . $e($imp['aliquota'] !== null ? self::num($imp['aliquota']) . '%' : '') . '</td>
      <td class="r">' . $e(self::num($imp['valor'] ?? null)) . '</td>
      <td class="r">' . $e($imp['reducao'] !== null ? self::num($imp['reducao']) . '%' : '') . '</td>
      <td class="r">' . $e(self::num($imp['st'] ?? null)) . '</td></tr>
</table>

' . ($ehCompl ? '' : '<div class="sec">DOCUMENTOS ORIGINÁRIOS</div>
<table class="grid">
  <tr class="h"><td>TIPO DOC</td><td>CNPJ/CHAVE/OBS</td><td>SÉRIE/NRO. DOCUMENTO</td>
      <td>TIPO DOC</td><td>CNPJ/CHAVE/OBS</td><td>SÉRIE/NRO. DOCUMENTO</td></tr>
  ' . $docs . '
</table>') . '

<div class="sec">OBSERVAÇÕES GERAIS</div>
<table class="grid"><tr><td style="height:12mm">' . $e($obs) . '</td></tr></table>

' . (!empty($c['rntrc']) ? '<div class="sec">DADOS ESPECÍFICOS DO MODAL RODOVIÁRIO</div>
<table class="grid"><tr>
  <td class="cap" style="width:34mm">RNTRC DA EMPRESA<div class="v">' . $e($c['rntrc']) . '</div></td>
  <td class="cap ct" style="vertical-align:middle">ESTE CONHECIMENTO DE TRANSPORTE ATENDE À LEGISLAÇÃO DE TRANSPORTE RODOVIÁRIO EM VIGOR</td>
</tr></table>' : '') . '

<table class="grid">
  <tr><td class="cap ct" style="width:66%">USO EXCLUSIVO DO EMISSOR DO CT-E</td><td class="cap ct">RESERVADO AO FISCO</td></tr>
  <tr><td style="height:10mm">&nbsp;</td><td>&nbsp;</td></tr>
</table>

<div style="margin-top:2mm;font-size:6pt;color:#333">Documento gerado pelo Exemplo financeiro a partir do XML autêntico capturado da SEFAZ.</div>
</div></body></html>';
    }

    // ─────────────────────────────────────────────────────────────
    // DANFSe (NFS-e Nacional, layout próprio — sem modelo oficial fixo do
    // governo pra impressão, mas segue a mesma lógica de blocos que os
    // DANFE/DACTE: prestador, tomador, serviço, valores e tributos.
    // ─────────────────────────────────────────────────────────────

    /**
     * DANFSe único para NFS-e Nacional e ABRASF (municipal) — mesmo modelo,
     * no formato da NFS-e emitida pelas prefeituras no Padrão Nacional
     * (ex.: Farroupilha/RS): cabeçalho com nº da NFS-e, DPS, competência e
     * emissão; prestador com regime e exigibilidade; tomador; discriminação;
     * demonstrativo de cálculo; retenções federais; IBS/CBS; informações
     * adicionais; autenticidade. Sem brasão nem QR code — a conferência é
     * pela chave no portal.
     *
     * O que muda entre as fontes é só o dado disponível: campo que a fonte
     * não traz aparece como "-" (ex.: ABRASF não tem chave nacional nem
     * IBS/CBS; a Nacional não tem código de verificação municipal).
     */
    private static function gerarDanfse(array $nota, array $n): string
    {
        $e     = fn($v) => self::e($v);
        $p     = $n['prestador'] ?? [];
        $tom   = $n['tomador'] ?? [];
        $srv   = $n['servico'] ?? [];
        $val   = $n['valores'] ?? [];
        $tr    = $n['tributacao'] ?? [];
        $ibs   = $tr['ibs_cbs'] ?? [];
        $fonte = $n['fonte'] ?? 'nacional';

        $chave = preg_replace('/\D+/', '', (string) ($n['chave'] ?? ($fonte === 'nacional' ? ($nota['chave_nf'] ?? '') : '')));
        $chaveFmt = $chave !== '' ? trim(chunk_split($chave, 4, ' ')) : '';

        // Dado ausente vira "-", como no documento de referência.
        $t   = fn($v) => ($v === null || trim((string) $v) === '') ? '-' : $e($v);
        $din = fn($v) => $v === null ? '-' : $e(self::num($v));
        $pct = fn($v) => $v === null ? '-' : $e(self::num($v)) . '%';
        $cb  = fn(string $rot, string $val, string $extra = '') => '<td class="cb" ' . $extra . '><div class="rot">' . $rot . '</div><div class="val">' . $val . '</div></td>';
        $cbR = fn(string $rot, string $val, string $extra = '') => '<td class="cb" style="text-align:right" ' . $extra . '><div class="rot">' . $rot . '</div><div class="val">' . $val . '</div></td>';

        // Emissão: o dhEmi do DPS às vezes vem 00:00:00 — nesse caso a hora
        // real está no dhProc (processamento pelo ambiente nacional).
        $dhEmi = $n['dhEmi'] ?? null;
        if ($dhEmi === null || self::horaBr($dhEmi) === '' || self::horaBr($dhEmi) === '00:00:00') {
            $dhEmi = $n['dhProc'] ?? $dhEmi;
        }
        $emissao = trim(self::dataBr($dhEmi ?? $nota['data_emissao'] ?? null) . ' ' . substr(self::horaBr($dhEmi), 0, 5));
        $compet  = self::dataBr($n['dCompet'] ?? null);

        $numero  = $n['numero'] ?? $nota['numero_nf'] ?? null;
        $dpsNum  = $n['dps_numero'] ?? null;
        $dpsSer  = $n['dps_serie'] ?? null;
        $dpsTxt  = ($dpsNum !== null || $dpsSer !== null) ? ($dpsNum ?? '-') . ' / ' . ($dpsSer ?? '-') : null;

        $homolog    = ($n['ambiente'] ?? '') === '2';

        // Prestador e tomador usam o mesmo bloco — só o dado muda.
        $pessoaHtml = function (array $pessoa, string $fallbackNome, ?string $fallbackDoc) use ($t, $cb, $e): string {
            $doc = ($pessoa['cnpj'] ?? null) ? self::cnpj($pessoa['cnpj']) : self::docDacte($pessoa['cpf'] ?? null);
            if ($doc === '' || $doc === '—') $doc = self::cnpj($fallbackDoc);
            $logr     = trim(implode(', ', array_filter([$pessoa['logradouro'] ?? null, $pessoa['numero'] ?? null, $pessoa['complemento'] ?? null])));
            $endereco = trim(implode(' - ', array_filter([$logr, $pessoa['bairro'] ?? null])));
            if (!empty($pessoa['cep'])) $endereco .= ($endereco !== '' ? ', ' : '') . 'CEP ' . self::cep($pessoa['cep']);
            // Sem o nome do município no XML, mostra o código IBGE em vez de omitir.
            $mun   = $pessoa['municipio'] ?? (!empty($pessoa['municipio_cod']) ? 'IBGE ' . $pessoa['municipio_cod'] : null);
            $munUf = trim(implode(' / ', array_filter([$mun, $pessoa['uf'] ?? null])));
            return '<table>
  <tr>
    ' . $cb('CNPJ/CPF', $t($doc !== '' && $doc !== '—' ? $doc : null), 'style="width:28%"') . '
    ' . $cb('NOME/RAZÃO SOCIAL', $t($pessoa['nome'] ?? $fallbackNome ?? null)) . '
    ' . $cb('INSCRIÇÃO MUNICIPAL', $t($pessoa['inscricao_municipal'] ?? null), 'style="width:22%"') . '
  </tr>
  <tr>
    ' . $cb('ENDEREÇO', $t($endereco), 'colspan="2"') . '
    ' . $cb('MUNICÍPIO / UF', $t($munUf)) . '
  </tr>
  <tr>
    ' . $cb('E-MAIL', $t($pessoa['email'] ?? null), 'colspan="2"') . '
    ' . $cb('TELEFONE', $t(self::fone($pessoa['fone'] ?? null) ?: null)) . '
  </tr>
</table>';
        };

        // Exigibilidade do ISSQN (tribISSQN). Retenção: quem recolhe o imposto.
        $exigib = [
            '1' => 'Normal', '2' => 'Não incidência', '3' => 'Isenção', '4' => 'Exportação',
            '5' => 'Imunidade', '6' => 'Suspensa - decisão judicial', '7' => 'Suspensa - processo administrativo',
        ][$tr['issqn_situacao'] ?? ''] ?? null;
        $responsab = ($tr['issqn_retido'] ?? false) ? 'Tomador de Serviço' : 'Prestador de Serviço';
        $localTrib = $srv['municipio_incidencia'] ?? $srv['municipio_prestacao'] ?? null;
        $regime    = $p['regime'] ?? null;

        // Demonstrativo: "Valor Aprox. de Tributos" é a soma dos três entes.
        $aprox = ($tr['tot_trib_fed'] ?? 0) + ($tr['tot_trib_est'] ?? 0) + ($tr['tot_trib_mun'] ?? 0);

        $valorServico = $nota['valor'] ?? $val['servico'] ?? null;
        $valorLiquido = $val['liquido'] ?? $valorServico;

        $nbs = trim(implode(' - ', array_filter([$srv['cNBS'] ?? null, $srv['xNBS'] ?? null])));
        $classTrib = trim(implode(' - ', array_filter([$ibs['cClassTrib'] ?? null])));

        $infoAdic = trim(implode("\n", array_filter([
            $srv['informacoes_complementares'] ?? null,
            $n['outras_info'] ?? null,
        ])));

        return '<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<title>NFS-e ' . $e($numero ?? '') . '</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; margin: 14px auto; max-width: 820px; font-size: 11px; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  td { border: 1px solid #000; padding: 3px 5px; vertical-align: top; word-wrap: break-word; }
  .rot { font-size: 8px; color: #444; text-transform: uppercase; }
  .val { font-size: 11.5px; font-weight: bold; min-height: 13px; }
  .sec { border: 1px solid #000; border-bottom: 0; text-align: center; font-size: 10.5px; font-weight: bold; text-transform: uppercase; padding: 3px 4px; margin-top: 6px; background: #F3F3F3; }
  .cab-tit { vertical-align: middle; padding: 10px 12px; }
  .cab-tit .m { font-size: 13px; font-weight: bold; }
  .cab-tit .s { font-size: 11px; font-weight: bold; margin-top: 6px; }
  .total { border: 1px solid #000; border-top: 0; text-align: right; font-size: 13px; font-weight: bold; padding: 5px 8px; }
  .desc { font-size: 10.5px; white-space: pre-wrap; min-height: 60px; }
  .info { border: 1px solid #000; padding: 6px 8px; font-size: 10.5px; white-space: pre-wrap; min-height: 60px; }
  .aut { border: 1px solid #000; padding: 6px 8px; text-align: center; font-size: 10.5px; line-height: 1.5; }
  .homolog { border: 2px solid #B00; color: #B00; font-weight: bold; text-align: center; padding: 4px; margin-bottom: 6px; }
  @media print { body { margin: 0; max-width: none; } }
</style>
</head><body>
' . ($homolog ? '<div class="homolog">NOTA EMITIDA EM AMBIENTE DE HOMOLOGAÇÃO — SEM VALOR FISCAL</div>' : '') . '
<table>
  <tr>
    <td class="cab-tit" style="width:62%">
      <div class="s" style="margin-top:0">NOTA FISCAL DE SERVIÇOS ELETRÔNICA - NFS-e</div>
    </td>
    <td style="padding:0">
      <table style="margin:0">
        <tr>' . $cb('NÚMERO NFS-e' . ($fonte === 'nacional' ? ' NACIONAL' : ''), $t($numero), 'style="text-align:center"') . '</tr>
        <tr>' . ($fonte === 'nacional'
            ? $cb('NÚMERO DPS / SÉRIE DPS', $t($dpsTxt), 'style="text-align:center"')
            : $cb('CÓDIGO DE VERIFICAÇÃO', $t($n['codigo_verificacao'] ?? null), 'style="text-align:center"')) . '</tr>
        <tr>' . $cb('DATA COMPETÊNCIA', $t($compet !== '' ? $compet : null), 'style="text-align:center"') . '</tr>
        <tr>' . $cb('DATA DE EMISSÃO', $t($emissao), 'style="text-align:center"') . '</tr>
      </table>
    </td>
  </tr>
</table>

<div class="sec">Prestador de Serviços</div>
' . $pessoaHtml($p, (string) ($nota['fornecedor_nome'] ?? ''), $nota['fornecedor_cnpj'] ?? null) . '
<table style="margin-top:-1px">
  <tr>
    ' . $cb('LOCAL DE TRIBUTAÇÃO', $t($localTrib)) . '
    ' . $cb('RESPONSABILIDADE PELO ISSQN', $t($responsab)) . '
    ' . $cb('REGIME TRIBUTÁRIO', $t($regime)) . '
    ' . $cb('EXIGIBILIDADE DO ISSQN', $t($exigib)) . '
  </tr>
</table>

<div class="sec">Tomador de Serviços</div>
' . $pessoaHtml($tom, (string) ($nota['filial_nome'] ?? ''), $nota['destinatario_cnpj'] ?? null) . '

<div class="sec">Discriminação dos Serviços</div>
<table>
  <tr>
    ' . $cb('DESCRIÇÃO', '<div class="desc">' . nl2br($e($srv['discriminacao'] ?? '')) . '</div>', 'style="width:46%"') . '
    ' . $cb('L.C. 116', $t($srv['item_lc116'] ?? $srv['cTribNac'] ?? null)) . '
    ' . $cbR('ALÍQUOTA (%)', $pct($tr['issqn_aliquota'] ?? null)) . '
    ' . $cbR('DESCONTO INC.', $din($val['desconto_incond'] ?? null)) . '
    ' . $cbR('VALOR SERVIÇO', $din($val['servico'] ?? $valorServico)) . '
    ' . $cbR('ISSQN', $din($tr['valor_iss'] ?? null)) . '
  </tr>
</table>
<div class="total">VALOR TOTAL DA NOTA FISCAL: R$ ' . $e(self::num($valorServico)) . '</div>

<div class="sec">Demonstrativo de Cálculo</div>
<table>
  <tr>
    ' . $cbR('VALOR DE SERVIÇO', $din($val['servico'] ?? $valorServico)) . '
    ' . $cbR('DESCONTO CONDICIONAL', $din($val['desconto_cond'] ?? null)) . '
    ' . $cbR('DEDUÇÕES', $din($val['deducoes'] ?? null)) . '
  </tr>
  <tr>
    ' . $cbR('VALOR APROX. DE TRIBUTOS', $din($aprox > 0 ? $aprox : null)) . '
    ' . $cbR('BASE DE CÁLCULO', $din($tr['base_calculo'] ?? null)) . '
    ' . $cbR('ISSQN', $din($tr['valor_iss'] ?? null)) . '
  </tr>
</table>

<div class="sec">Retenções Federais</div>
<table>
  <tr>
    ' . $cbR('INSS (R$)', $din($tr['ret_inss'] ?? null)) . '
    ' . $cbR('IR (R$)', $din($tr['ret_irrf'] ?? null)) . '
    ' . $cbR('CSLL (R$)', $din($tr['ret_csll'] ?? null)) . '
    ' . $cbR('PIS/PASEP (R$)', $din($tr['ret_pis'] ?? null)) . '
    ' . $cbR('COFINS (R$)', $din($tr['ret_cofins'] ?? null)) . '
  </tr>
</table>
<div class="total">VALOR LÍQUIDO DA NOTA FISCAL: R$ ' . $e(self::num($valorLiquido)) . '</div>

<div class="sec">IBS / CBS</div>
<table>
  <tr>
    ' . $cb('MUNICÍPIO DE INCIDÊNCIA', $t($ibs['municipio_incidencia'] ?? null)) . '
    ' . $cbR('BASE DE CÁLCULO', $din($ibs['base'] ?? null)) . '
    ' . $cbR('IBS MUNICÍPIO', $din($ibs['ibs_mun'] ?? null)) . '
    ' . $cbR('IBS ESTADUAL', $din($ibs['ibs_uf'] ?? null)) . '
    ' . $cbR('VALOR CBS', $din($ibs['cbs'] ?? null)) . '
  </tr>
  <tr>
    ' . $cb('NBS', $t($nbs), 'colspan="2"') . '
    ' . $cb('CLASSIFICAÇÃO TRIBUTÁRIA', $t($classTrib), 'colspan="2"') . '
    ' . $cb('INDICADOR OPERAÇÃO', $t($ibs['cIndOp'] ?? null)) . '
  </tr>
</table>

<div class="sec">Informações Adicionais</div>
<div class="info">' . nl2br($e($infoAdic)) . '</div>

<div class="sec">Autenticidade</div>
<div class="aut">A autenticidade desta Nota Fiscal de Serviços Eletrônica - NFS-e pode ser verificada no ' .
    ($fonte === 'nacional' ? 'Portal Nacional da NFS-e (www.nfse.gov.br), informando a chave de acesso.' : 'portal da prefeitura emissora.') .
    ($chaveFmt !== '' ? '<br><b>Chave de Acesso NFS-e Nacional:</b> ' . $e($chaveFmt) : '') . '</div>

<div style="font-size:8px; color:#444; margin-top:8px">Nota de Serviço gerada pelo Exemplo financeiro a partir do XML autêntico capturado ('
    . $e($fonte === 'nacional' ? 'Portal Nacional — www.nfse.gov.br' : 'prefeitura emissora') . ') · Impresso em ' . $e(date('d/m/Y H:i:s')) . '</div>

</body></html>';
    }

    /** Descrição da situação tributária do ISSQN (tribISSQN da NFS-e Nacional). */
    private static function situacaoIssqn(?string $cod): string
    {
        if ($cod === null || $cod === '') return '—';
        $mapa = [
            '1' => 'Operação tributável', '2' => 'Imune', '3' => 'Exportação de serviço',
            '4' => 'Não incidência', '5' => 'Isenção', '6' => 'Operação sujeita a substituição tributária do ISSQN',
        ];
        return isset($mapa[$cod]) ? "{$cod} - {$mapa[$cod]}" : $cod;
    }

    /**
     * CNPJ (14) ou CPF (11) formatado; vazio quando não há documento.
     *
     * self::cnpj() devolve '—' para nulo, o que no DACTE polui os quadros de
     * Expedidor/Recebedor — que existem no formulário mesmo quando o CT-e não
     * tem essas partes, e no modelo aparecem em branco. E o CT-e aceita CPF
     * (tomador pessoa física), que cnpj() não mascara.
     */
    private static function docDacte(?string $v): string
    {
        $d = preg_replace('/\D+/', '', (string) $v);
        if (strlen($d) === 14) {
            return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
        }
        if (strlen($d) === 11) {
            return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2);
        }
        return $d;
    }

    /** Descrição da situação tributária do ICMS no CT-e. */
    private static function cstIcmsCte(?string $cst): string
    {
        if ($cst === null || $cst === '') return '';
        $mapa = [
            '00' => 'ICMS normal', '20' => 'ICMS com BC reduzida', '40' => 'ICMS isenção',
            '41' => 'ICMS não tributado', '45' => 'ICMS isento/não trib./diferido',
            '51' => 'ICMS diferido', '60' => 'ICMS cobrado por ST', '90' => 'ICMS outros',
        ];
        return isset($mapa[$cst]) ? "{$cst} - {$mapa[$cst]}" : $cst;
    }

    /**
     * Código de barras Code128-C da chave de acesso (44 dígitos → 22 pares).
     *
     * Desenhado com <i> de larguras proporcionais — sem imagem, sem dependência.
     * A tabela é validada em tempo de execução: todo símbolo do Code128 tem
     * exatamente 11 módulos, então um padrão digitado errado é detectado e o
     * código simplesmente não é desenhado, em vez de sair um borrão que parece
     * certo e não lê no leitor.
     */
    private static function barrasCode128(string $chave): string
    {
        $chave = preg_replace('/\D+/', '', $chave);
        if ($chave === '' || strlen($chave) % 2 !== 0) {
            return '';
        }

        $padroes = self::CODE128_PADROES;

        // Integridade da tabela: 11 módulos por símbolo (o STOP tem 13).
        foreach ($padroes as $i => $p) {
            $soma = array_sum(str_split($p));
            if (($i < 106 && $soma !== 11) || ($i === 106 && $soma !== 13)) {
                return '';   // tabela corrompida — não desenha nada
            }
        }

        $valores = [105];                                   // START C
        foreach (str_split($chave, 2) as $par) {
            $valores[] = (int) $par;
        }
        $soma = 105;
        foreach (array_slice($valores, 1) as $i => $v) {
            $soma += ($i + 1) * $v;
        }
        $valores[] = $soma % 103;                           // dígito verificador
        $valores[] = 106;                                   // STOP

        $html = '';
        foreach ($valores as $v) {
            $preto = true;
            foreach (str_split($padroes[$v]) as $largura) {
                $html .= '<i style="width:' . ((int) $largura) . 'px;background:'
                       . ($preto ? '#000' : '#fff') . '"></i>';
                $preto = !$preto;
            }
        }
        return '<div class="barras">' . $html . '</div>';
    }

    /** Padrões de barras/espaços do Code128 (larguras em módulos). */
    private const CODE128_PADROES = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112',
    ];

    // ─────────────────────────────────────────────────────────────
    // DANFE oficial (NF-e modelo 55, retrato)
    // ─────────────────────────────────────────────────────────────

    private static function gerarDanfe(array $nota, array $nfe): string
    {
        $e     = fn($v) => self::e($v);
        $emit  = $nfe['emit'] ?? [];
        $dest  = $nfe['dest'] ?? [];
        $tot   = $nfe['totais'] ?? [];
        $tr    = $nfe['transp'] ?? [];

        $numFmt   = self::numeroNf($nfe['numero'] ?? $nota['numero_nf'] ?? '');
        $chaveFmt = trim(chunk_split(preg_replace('/\D+/', '', (string) ($nota['chave_nf'] ?? '')), 4, ' '));
        $emissao  = self::dataBr($nfe['emissao'] ?? $nota['data_emissao'] ?? null);
        $horaEmissao = self::horaBr($nfe['emissao'] ?? null);
        $saida    = self::dataBr($nfe['saida'] ?? null);
        $horaSaida = self::horaBr($nfe['saida'] ?? null);
        $protocolo = trim(($nfe['protocolo'] ?? '') . ' ' .
            (($nfe['prot_data'] ?? null) ? self::dataBr($nfe['prot_data']) . ' ' . self::horaBr($nfe['prot_data']) : ''));

        $endEmit = trim(implode(', ', array_filter([
            trim(($emit['logradouro'] ?? '') . ' ' . ($emit['numero'] ?? '')),
            $emit['bairro'] ?? null,
        ])));
        $munEmit = trim(($emit['cep'] ? self::cep($emit['cep']) . ' - ' : '') . ($emit['municipio'] ?? '') . ' - ' . ($emit['uf'] ?? ''), ' -');

        $modFreteTxt = match ($tr['modFrete'] ?? '') {
            '0' => '0-Emitente', '1' => '1-Destinatário', '2' => '2-Terceiros',
            '3' => '3-Próprio remet.', '4' => '4-Próprio dest.', '9' => '9-Sem Frete',
            default => $tr['modFrete'] ?? '',
        };

        // Fatura (nFat + valores) — o DANFE trazia só as duplicatas, e é a
        // fatura que o fornecedor cobra por telefone.
        $fat     = $nfe['fatura'] ?? null;
        $fatHtml = '';
        if (is_array($fat)) {
            $fatHtml = '<span class="dup">FATURA <b>' . $e($fat['numero'] ?? '') . '</b></span>'
                . '<span class="dup">Valor original <b>' . $e(self::num($fat['valor_original'] ?? null)) . '</b></span>'
                . '<span class="dup">Desconto <b>' . $e(self::num($fat['desconto'] ?? null)) . '</b></span>'
                . '<span class="dup">Valor líquido <b>' . $e(self::num($fat['valor_liquido'] ?? null)) . '</b></span>';
        }

        // Duplicatas (fatura)
        $dupsHtml = '';
        foreach ($nfe['duplicatas'] ?? [] as $d) {
            $dupsHtml .= '<span class="dup"><b>' . $e($d['numero'] ?? '') . '</b> '
                . $e(self::dataBr($d['vencimento'] ?? null)) . ' <b>' . $e(self::num($d['valor'] ?? null)) . '</b></span>';
        }

        // Itens
        $itensHtml = '';
        foreach ($nfe['itens'] ?? [] as $it) {
            $itensHtml .= '<tr>'
                . '<td>' . $e($it['codigo'] ?? '') . '</td>'
                . '<td class="tl">' . $e($it['descricao'] ?? '') . '</td>'
                . '<td>' . $e($it['ncm'] ?? '') . '</td>'
                . '<td>' . $e($it['cst'] ?? '') . '</td>'
                . '<td>' . $e($it['cfop'] ?? '') . '</td>'
                . '<td>' . $e($it['unidade'] ?? '') . '</td>'
                . '<td class="tr">' . $e(self::num($it['qtd'] ?? null, 4)) . '</td>'
                . '<td class="tr">' . $e(self::num($it['vUnit'] ?? null, 4)) . '</td>'
                . '<td class="tr">' . $e(self::num($it['vTotal'] ?? null)) . '</td>'
                . '<td class="tr">' . $e(self::num($it['vDesc'] ?? null)) . '</td>'
                . '<td class="tr">' . $e(self::num($it['vBC'] ?? null)) . '</td>'
                . '<td class="tr">' . $e(self::num($it['vICMS'] ?? null)) . '</td>'
                . '<td class="tr">' . $e(self::num($it['pICMS'] ?? null)) . '</td>'
                . '</tr>';
        }

        $infoAdic = trim(implode("\n", array_filter([$nfe['info_compl'] ?? null, $nfe['info_fisco'] ?? null])));

        $cb = fn(string $rot, string $val, string $extra = '') => '<td class="cb" ' . $extra . '><div class="rot">' . $rot . '</div><div class="val">' . $val . '</div></td>';

        return '<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<title>DANFE — NF ' . $e($nfe['numero'] ?? '') . '</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; margin: 12px auto; max-width: 800px; font-size: 10px; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  td { border: 1px solid #000; padding: 2px 4px; vertical-align: top; word-wrap: break-word; }
  .rot { font-size: 6.5px; text-transform: uppercase; }
  .val { font-size: 10px; font-weight: bold; min-height: 11px; }
  .sec { font-size: 7.5px; font-weight: bold; text-transform: uppercase; margin: 6px 0 1px; }
  .nb td { border: none; }
  .tl { text-align: left } .tr { text-align: right } .tc { text-align: center }
  .canhoto td { font-size: 8px; }
  .corte { border-top: 1px dashed #000; margin: 8px 0; }
  .danfe-box { text-align: center; }
  .danfe-box .t { font-size: 14px; font-weight: bold; }
  .danfe-box .s { font-size: 7px; }
  .danfe-box .n { font-size: 10px; font-weight: bold; margin-top: 2px; }
  .emit-box .nome { font-size: 12px; font-weight: bold; }
  .emit-box .end { font-size: 8.5px; }
  .chave { font-size: 10px; font-weight: bold; letter-spacing: 0.5px; }
  .itens td { font-size: 8px; text-align: center; padding: 1px 2px; }
  .itens thead td { font-size: 6.5px; font-weight: bold; text-transform: uppercase; background: #f2f2f2; }
  .dup { display: inline-block; margin-right: 14px; font-size: 9px; }
  .adic { font-size: 8px; white-space: pre-wrap; min-height: 70px; }
  @media print { body { margin: 0; max-width: none; } }
</style>
</head><body>

<!-- Canhoto -->
<table class="canhoto">
  <tr>
    <td style="width:82%">RECEBEMOS DE <b>' . $e($emit['nome'] ?? $nota['fornecedor_nome'] ?? '') . '</b> OS PRODUTOS CONSTANTES DA NOTA FISCAL INDICADA AO LADO</td>
    <td rowspan="2" class="danfe-box" style="width:18%">
      <div class="t">NF-e</div>
      <div class="n">Nº ' . $e($numFmt) . '</div>
      <div class="n">Série ' . $e($nfe['serie'] ?? '') . '</div>
    </td>
  </tr>
  <tr><td>
    <table class="nb" style="table-layout:fixed"><tr>
      <td style="width:30%; border-right:1px solid #000; padding-left:0"><span class="rot">DATA DE RECEBIMENTO</span><br>&nbsp;</td>
      <td><span class="rot">IDENTIFICAÇÃO E ASSINATURA DO RECEBEDOR</span><br>&nbsp;</td>
    </tr></table>
  </td></tr>
</table>
<div class="corte"></div>

<!-- Cabeçalho -->
<table>
  <tr>
    <td rowspan="2" class="emit-box" style="width:41%">
      <div class="rot">IDENTIFICAÇÃO DO EMITENTE</div>
      <div class="nome">' . $e($emit['nome'] ?? $nota['fornecedor_nome'] ?? '') . '</div>
      <div class="end">' . $e($endEmit) . '<br>' . $e($munEmit) . '<br>'
        . ($emit['fone'] ? 'Telefone: ' . $e(self::fone($emit['fone'])) : '') . '</div>
    </td>
    <td rowspan="2" class="danfe-box" style="width:17%">
      <div class="t">DANFE</div>
      <div class="s">Documento Auxiliar da<br>Nota Fiscal Eletrônica</div>
      <div class="s tl" style="margin:3px 0 0 8px">0 - Entrada<br>1 - Saída</div>
      <div style="border:1px solid #000; width:22px; margin:1px auto; font-size:11px; font-weight:bold">' . $e($nfe['tp_nf'] ?? '') . '</div>
      <div class="n">Nº ' . $e($numFmt) . '</div>
      <div class="n">Série ' . $e($nfe['serie'] ?? '') . ' &nbsp; FL 1/1</div>
    </td>
    <td style="width:42%">
      <div class="rot">CHAVE DE ACESSO</div>
      <div class="chave">' . $e($chaveFmt) . '</div>
    </td>
  </tr>
  <tr>
    <td style="font-size:8px">Consulta de autenticidade no portal nacional da NF-e<br><b>www.nfe.fazenda.gov.br/portal</b> ou no site da Sefaz Autorizadora</td>
  </tr>
  <tr>
    ' . $cb('NATUREZA DA OPERAÇÃO', $e($nfe['nat_op'] ?? ''), 'colspan="2"') . '
    ' . $cb('PROTOCOLO DE AUTORIZAÇÃO DE USO', $e($protocolo)) . '
  </tr>
  <tr>
    ' . $cb('INSCRIÇÃO ESTADUAL', $e($emit['ie'] ?? '')) . '
    ' . $cb('INSC. ESTADUAL DO SUBST. TRIBUTÁRIO', '') . '
    ' . $cb('CNPJ', $e(self::cnpj($emit['cnpj'] ?? $nota['fornecedor_cnpj'] ?? null))) . '
  </tr>
</table>

<div class="sec">Destinatário / Remetente</div>
<table>
  <colgroup>' . str_repeat('<col style="width:5%">', 20) . '</colgroup>
  <tr>
    ' . $cb('NOME / RAZÃO SOCIAL', $e($dest['nome'] ?? $nota['filial_nome'] ?? ''), 'colspan="11"') . '
    ' . $cb('CNPJ / CPF', $e(self::cnpj($dest['cnpj'] ?? $nota['destinatario_cnpj'] ?? null)), 'colspan="5"') . '
    ' . $cb('DATA E HORA DA EMISSÃO', $e(trim($emissao . ' ' . $horaEmissao)), 'colspan="4"') . '
  </tr>
  <tr>
    ' . $cb('ENDEREÇO', $e(trim(($dest['logradouro'] ?? '') . ', ' . ($dest['numero'] ?? ''), ', ')), 'colspan="9"') . '
    ' . $cb('BAIRRO / DISTRITO', $e($dest['bairro'] ?? ''), 'colspan="4"') . '
    ' . $cb('CEP', $e(self::cep($dest['cep'] ?? null)), 'colspan="3"') . '
    ' . $cb('DATA DA ENTRADA/SAÍDA', $e($saida), 'colspan="4"') . '
  </tr>
  <tr>
    ' . $cb('MUNICÍPIO', $e($dest['municipio'] ?? ''), 'colspan="6"') . '
    ' . $cb('FONE / FAX', $e(self::fone($dest['fone'] ?? null)), 'colspan="4"') . '
    ' . $cb('UF', $e($dest['uf'] ?? ''), 'colspan="1"') . '
    ' . $cb('INSCRIÇÃO ESTADUAL', $e($dest['ie'] ?? ''), 'colspan="5"') . '
    ' . $cb('HORA DE SAÍDA', $e($horaSaida), 'colspan="4"') . '
  </tr>
</table>

' . ($fatHtml !== '' || $dupsHtml !== '' ? '<div class="sec">Fatura / Duplicatas</div>
<table>' . ($fatHtml !== '' ? '<tr><td style="padding:4px">' . $fatHtml . '</td></tr>' : '') . '
      ' . ($dupsHtml !== '' ? '<tr><td style="padding:4px">' . $dupsHtml . '</td></tr>' : '') . '</table>' : '') . '

<div class="sec">Cálculo do Imposto</div>
<table>
  <tr>
    ' . $cb('BASE DE CÁLCULO DE ICMS', $e(self::num($tot['vBC'] ?? null))) . '
    ' . $cb('VALOR DO ICMS', $e(self::num($tot['vICMS'] ?? null))) . '
    ' . $cb('BASE DE CÁLC. ICMS SUBST.', $e(self::num($tot['vBCST'] ?? null))) . '
    ' . $cb('VALOR DO ICMS SUBST.', $e(self::num($tot['vST'] ?? null))) . '
    ' . $cb('VALOR TOTAL DOS PRODUTOS', $e(self::num($tot['vProd'] ?? null))) . '
  </tr>
  <tr>
    ' . $cb('VALOR DO FRETE', $e(self::num($tot['vFrete'] ?? null))) . '
    ' . $cb('VALOR DO SEGURO', $e(self::num($tot['vSeg'] ?? null))) . '
    ' . $cb('DESCONTO', $e(self::num($tot['vDesc'] ?? null))) . '
    ' . $cb('OUTRAS DESPESAS', $e(self::num($tot['vOutro'] ?? null))) . '
    ' . $cb('VALOR DO IPI', $e(self::num($tot['vIPI'] ?? null))) . '
  </tr>
  <tr>
    ' . $cb('VALOR APROX. TRIBUTOS', $e(self::num($tot['vTotTrib'] ?? null)), 'colspan="4"') . '
    ' . $cb('VALOR TOTAL DA NOTA', '<span style="font-size:12px">' . $e(self::num($tot['vNF'] ?? $nota['valor'] ?? null)) . '</span>') . '
  </tr>
</table>

<div class="sec">Transportador / Volumes Transportados</div>
<table>
  <tr>
    ' . $cb('RAZÃO SOCIAL', $e($tr['nome'] ?? ''), 'colspan="2" style="width:40%"') . '
    ' . $cb('FRETE POR CONTA', $e($modFreteTxt)) . '
    ' . $cb('CÓDIGO ANTT', $e($tr['rntc'] ?? '')) . '
    ' . $cb('PLACA DO VEÍCULO', $e($tr['placa'] ?? '')) . '
    ' . $cb('UF', $e($tr['placa_uf'] ?? ''), 'style="width:6%"') . '
    ' . $cb('CNPJ / CPF', $e(self::cnpj($tr['cnpj'] ?? null))) . '
  </tr>
  <tr>
    ' . $cb('ENDEREÇO', $e($tr['endereco'] ?? ''), 'colspan="3"') . '
    ' . $cb('MUNICÍPIO', $e($tr['municipio'] ?? ''), 'colspan="2"') . '
    ' . $cb('UF', $e($tr['uf'] ?? '')) . '
    ' . $cb('INSCRIÇÃO ESTADUAL', $e($tr['ie'] ?? '')) . '
  </tr>
  <tr>
    ' . $cb('QUANTIDADE', $e($tr['qVol'] ?? '')) . '
    ' . $cb('ESPÉCIE', $e($tr['especie'] ?? '')) . '
    ' . $cb('MARCA', $e($tr['marca'] ?? '')) . '
    ' . $cb('NUMERAÇÃO', $e($tr['numeracao'] ?? '')) . '
    ' . $cb('PESO BRUTO', $e(self::num(self::flt($tr['pesoB'] ?? null), 3)), 'colspan="2"') . '
    ' . $cb('PESO LÍQUIDO', $e(self::num(self::flt($tr['pesoL'] ?? null), 3))) . '
  </tr>
</table>

<div class="sec">Dados do Produto / Serviços</div>
<table class="itens">
  <thead><tr>
    <td style="width:8%">Cód. Prod.</td>
    <td style="width:26%">Descrição do Produto / Serviço</td>
    <td style="width:7%">NCM</td>
    <td style="width:5%">CST</td>
    <td style="width:5%">CFOP</td>
    <td style="width:4%">UN</td>
    <td style="width:7%">Quant.</td>
    <td style="width:8%">V. Unitário</td>
    <td style="width:8%">V. Total</td>
    <td style="width:7%">V. Desc.</td>
    <td style="width:8%">BC. ICMS</td>
    <td style="width:7%">V. ICMS</td>
    <td style="width:5%">% ICMS</td>
  </tr></thead>
  <tbody>' . $itensHtml . '</tbody>
</table>

<div class="sec">Cálculo do ISSQN</div>
<table>
  <tr>
    ' . $cb('INSCRIÇÃO MUNICIPAL', '') . '
    ' . $cb('VALOR TOTAL DOS SERVIÇOS', '') . '
    ' . $cb('BASE DE CÁLCULO DO ISSQN', '') . '
    ' . $cb('VALOR DO ISSQN', '') . '
  </tr>
</table>

<div class="sec">Dados Adicionais</div>
<table>
  <tr>
    <td style="width:65%"><div class="rot">INFORMAÇÕES COMPLEMENTARES</div><div class="adic">' . $e($infoAdic) . '</div></td>
    <td><div class="rot">RESERVADO AO FISCO</div><div class="adic"></div></td>
  </tr>
</table>

<div style="font-size:7.5px; color:#444; margin-top:4px">DANFE gerado pelo Exemplo financeiro a partir do XML autêntico capturado da SEFAZ · Impresso em ' . $e(date('d/m/Y H:i:s')) . '</div>

</body></html>';
    }

    private static function numeroNf($n): string
    {
        $d = preg_replace('/\D+/', '', (string) $n);
        if ($d === '') return (string) $n;
        return trim(chunk_split(str_pad($d, 9, '0', STR_PAD_LEFT), 3, '.'), '.');
    }

    private static function dataBr($v): string
    {
        if (!$v) return '';
        $ts = strtotime((string) $v);
        return $ts ? date('d/m/Y', $ts) : '';
    }

    private static function horaBr($v): string
    {
        if (!$v || !preg_match('/T\d{2}:\d{2}/', (string) $v)) return '';
        $ts = strtotime((string) $v);
        return $ts ? date('H:i:s', $ts) : '';
    }

    private static function cep($v): string
    {
        $d = preg_replace('/\D+/', '', (string) $v);
        return strlen($d) === 8 ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '-' . substr($d, 5) : (string) $v;
    }

    private static function fone($v): string
    {
        $d = preg_replace('/\D+/', '', (string) $v);
        if (strlen($d) === 10) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
        if (strlen($d) === 11) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7);
        return (string) $v;
    }

    /** Número no formato brasileiro; vazio quando null. */
    private static function num($v, int $dec = 2): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) return '';
        return number_format((float) $v, $dec, ',', '.');
    }

    private static function flt($v): ?float
    {
        return ($v === null || $v === '' || !is_numeric($v)) ? null : (float) $v;
    }

    private static function caixa(string $rotulo, string $conteudo): string
    {
        return '<div style="border:1px solid #EAEAEA; border-radius:4px; padding:10px; margin-bottom:0">'
            . '<div style="font-size:10px; font-weight:700; color:#888; text-transform:uppercase; margin-bottom:4px">' . $rotulo . '</div>'
            . $conteudo . '</div>';
    }

    private static function e($v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private static function cnpj(?string $c): string
    {
        $d = preg_replace('/\D+/', '', (string) $c);
        if (strlen($d) !== 14) return $c !== null && $c !== '' ? $c : '—';
        return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2);
    }

    private static function moeda($v): string
    {
        if ($v === null || $v === '' || !is_numeric($v)) return '—';
        return 'R$ ' . number_format((float) $v, 2, ',', '.');
    }
}
