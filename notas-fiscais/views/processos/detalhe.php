<?php
// @versao Proc_02 V_42_30/09/2026
/**
 * views/processos/detalhe.php
 * Detalhe de Processo de NF — Layout Workspace Split
 *
 * Variáveis esperadas:
 *   $processo          array       dados do processo + etapas, historico, anexos, parcelas
 *   $etapaAprovavel    array|null  etapa que o usuário pode aprovar (null se não pode)
 *   $etapaAdmin        array|null  etapa ativa p/ reatribuição administrativa (tarefas.reatribuir_admin), quando o usuário não é o dono
 *   $membrosReatribuicaoAdmin array candidatos para a reatribuição administrativa
 *   $currentUser       array       [id, nome, perfil]
 *   $observacoes       array       lista de observações do processo
 *   $observacoesCount  int         contagem de observações
 *   $checklistObrig    array       exigências do fluxo [origem,label,etapa,ok]
 *   $flash             array|null
 */

$processo          = $processo          ?? [];
$etapaAprovavel    = $etapaAprovavel    ?? null;
$observacoes          = $observacoes          ?? [];
$observacoesCount     = $observacoesCount     ?? 0;
$membrosReatribuicao  = $membrosReatribuicao  ?? [];
$etapaAdmin           = $etapaAdmin           ?? null;
$membrosReatribuicaoAdmin = $membrosReatribuicaoAdmin ?? [];
$checklistObrig       = $checklistObrig       ?? [];
$fornecedorCad        = $fornecedorCad        ?? null;
$tiposDespesaProc     = $tiposDespesaProc     ?? [];
$embed                = $embed                ?? false;
// Propagado em todo <form action="..."> desta tela para sobreviver ao
// redirect pós-POST e manter o modo embutido (iframe do modal) ativo.
$embedFormQS          = $embed ? '&amp;embed=1' : '';

/**
 * Cartão que ocupa o lugar do preview quando o anexo é vídeo.
 *
 * O visualizador NÃO reproduz: mostra um cartão padrão com o nome, o tamanho e
 * o botão de download. É decisão de produto — reproduzir dentro da tela faria o
 * servidor transmitir o arquivo inteiro a cada clique, e o que se precisa do
 * vídeo aqui é guardá-lo e baixá-lo, não assisti-lo no meio da aprovação.
 *
 * A mesma marcação existe em JS (`ws_cartaoVideoHtml`) para a troca de anexo
 * sem recarregar a página — as duas precisam andar juntas.
 */
if (!function_exists('ws_cartaoVideo')) {
    function ws_cartaoVideo(string $nome, int $bytes, string $urlDownload): string
    {
        $tamanho = $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', '.') . ' MB'
            : number_format(max($bytes, 0) / 1024, 0, ',', '.') . ' KB';

        ob_start(); ?>
<div id="viewer-content" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;
     width:100%;height:100%;background:var(--color-surface);border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.10);padding:32px;text-align:center">
  <div style="width:88px;height:88px;border-radius:12px;background:#EEF2FD;display:flex;align-items:center;justify-content:center">
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#2347C8" stroke-width="1.5"
         stroke-linecap="round" stroke-linejoin="round" style="display:block">
      <rect x="2" y="4" width="14" height="16" rx="2"/>
      <path d="M16 9l6-3v12l-6-3"/>
      <polygon points="7 9 11 12 7 15" fill="#2347C8" stroke="none"/>
    </svg>
  </div>
  <div>
    <div style="font-family:var(--font-bold);font-size:15px;color:var(--color-dark);word-break:break-all;max-width:340px"><?= h($nome) ?></div>
    <div style="font-size:12px;color:var(--color-gray-medium);margin-top:4px">Vídeo · <?= h($tamanho) ?> · pré-visualização não disponível</div>
  </div>
  <a href="<?= h($urlDownload) ?>"
     style="display:inline-flex;align-items:center;gap:8px;background:#2347C8;color:#FFFFFF;text-decoration:none;
            border-radius:4px;padding:10px 20px;font-family:var(--font-bold);font-size:13px"
     onmouseover="this.style.background='#1B38A0'" onmouseout="this.style.background='#2347C8'">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         stroke-linecap="round" style="display:block"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Baixar vídeo
  </a>
</div>
<?php
        return (string) ob_get_clean();
    }
}

// Origem/voltar do botão "Voltar" — resolvido no controller (resolverVoltarProcesso).
// Também propagado em todo <form action="..."> para sobreviver ao redirect
// pós-POST e o botão continuar voltando para a tela (e filtros) corretos.
$voltar               = $voltar               ?? ['url' => '?module=processos&action=index', 'label' => 'Processos'];
$backFormQS           = ($origemGet ?? '') !== '' ? '&amp;origem=' . urlencode($origemGet) : '';
$backFormQS          .= ($voltarGet ?? '') !== '' ? '&amp;voltar=' . urlencode($voltarGet)  : '';

// ── Status map (Design System Exemplo) ──
$statusMap = [
    'aberto'                  => ['bg' => '#E3F0FF', 'color' => '#1565C0', 'label' => 'Aberto'],
    'aprovado'                => ['bg' => '#D8F5E5', 'color' => '#00A960', 'label' => 'Finalizado'],
    'devolvido'               => ['bg' => '#FFF3E0', 'color' => '#FF8A2D', 'label' => 'Devolvido'],
    'cancelado'               => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Cancelado'],
    'cancelamento_solicitado' => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Canc. Solicitado'],
    'encerrado_apollo'        => ['bg' => '#D8D4E4', 'color' => '#606060', 'label' => 'Encerrado'],
];
$statusAtual = $statusMap[$processo['status']] ?? ['bg' => '#EAEAEA', 'color' => '#888', 'label' => $processo['status']];

// ── Modo da tela ──
$isAprovador   = $etapaAprovavel !== null;

// Reatribuição administrativa: cobre a etapa ativa mesmo quando quem está
// vendo a tela não é o dono (uso: cobrir férias/afastamento sem logar como
// a pessoa). $etapaAprovavel e $etapaAdmin nunca vêm preenchidos junto —
// o controller só calcula $etapaAdmin quando o usuário logado NÃO é dono.
$isReatribuicaoAdmin   = $etapaAprovavel === null && $etapaAdmin !== null;
$etapaParaReatribuir   = $etapaAprovavel ?? $etapaAdmin;
$membrosParaReatribuir = $isReatribuicaoAdmin ? $membrosReatribuicaoAdmin : $membrosReatribuicao;
// Etapa atual ainda é da FILA DE GRUPO: exige "Assumir" antes de agir, para que
// só um usuário trabalhe o processo por vez (evita lançamento/ligação em duplicidade).
$isGrupoEtapa  = $isAprovador && ($etapaAprovavel['tipo_aprovador'] ?? '') === 'grupo';
$isConcluido   = $processo['status'] === 'aprovado';
$isCancelado   = $processo['status'] === 'cancelado';
$isCancSolic   = $processo['status'] === 'cancelamento_solicitado';
// Encerramento terminal sem aprovação (Apollo / encerramento em lote): o processo
// não está mais em andamento, mas também não é um "concluído · todas aprovadas".
$isEncerrado   = $processo['status'] === 'encerrado_apollo';
$isTeste       = (int)($processo['is_teste'] ?? 0) === 1;
$isDevolvido   = $processo['status'] === 'devolvido';
$temFinanceiro = !empty($processo['tem_financeiro']);

// Cancelar depende da permissão de CANCELAR — não da de criar, como estava, e
// não mais de "ser o dono": controlar o botão é o ponto.
//
// Duas liberações distintas: `cancelar` vale em qualquer etapa do fluxo;
// `cancelar_abertura` só enquanto o processo está na etapa 1, antes de
// qualquer aprovação — é a de quem só precisa desfazer o próprio lançamento.
$naAberturaProc = (int) ($processo['etapa_atual'] ?? 1) <= 1;
$podeCancelar = in_array($processo['status'], ['aberto', 'devolvido'], true)
             && (Permissions::can('processos', 'cancelar')
                 || ($naAberturaProc && Permissions::can('processos', 'cancelar_abertura')));

// "Cancelamento solicitado" é um TERCEIRO estado, não um processo em andamento:
// ele saiu de aberto/devolvido e espera alguém confirmar ou desfazer. Por isso
// tem gate próprio — amarrar os botões desse estado a $podeCancelar (que exige
// aberto/devolvido) deixava o processo preso: nem confirmava, nem voltava.
//
// Aqui vale só a liberação de cancelar em QUALQUER etapa: a de abertura não
// alcança processo que já andou no fluxo, que é de onde vem a solicitação.
$podeResolverCancSolic = $isCancSolic && Permissions::can('processos', 'cancelar');

// Botões de etapa: ser o aprovador designado deixou de bastar. A permissão vale
// junto, para o Controle de Acesso poder tirar a ação de alguém sem precisar
// mexer no desenho do fluxo.
$podeAprovar  = Permissions::can('processos', 'aprovar');
$podeDevolver = Permissions::can('processos', 'devolver');
$podeAssumir  = Permissions::can('processos', 'assumir');
$podeDescartarTeste = Permissions::can('processos', 'descartar_teste');
$podeSolicitarCanc  = Permissions::can('processos', 'solicitar_cancelamento');
$podeGerenciarAnexos = Permissions::can('processos', 'gerenciar_anexos');

// Alterar fluxo (processo encaminhado errado): depende de permissão própria,
// de o processo estar em andamento e de haver outros templates publicados.
// Só na PRIMEIRA etapa — depois que o fluxo andou, trocar o template faria o
// processo voltar ao início descartando aprovações já dadas.
$templatesFluxo    = $templatesFluxo ?? [];
$podeAlterarFluxo  = !empty($templatesFluxo)
                  && in_array($processo['status'], ['aberto', 'devolvido'], true)
                  && (int)($processo['etapa_atual'] ?? 1) <= 1
                  && Permissions::can('processos', 'alterar_fluxo');
$isAdmin             = Auth::perfil() === 'super_admin';
$isSolicitante       = ($processo['criado_por'] ?? '') === Auth::id();
$temParcelasAtivas   = !empty($parcelas) && $temFinanceiro;
$podeCancelarParcelas = $isAdmin;

// ── Dados derivados ──
$anexos     = $processo['anexos']    ?? [];
$parcelas   = $processo['parcelas']  ?? [];
$etapas     = $processo['etapas']    ?? [];
$historico  = $processo['historico']  ?? [];
$dadosForm  = is_array($processo['dados_formulario'] ?? null) ? $processo['dados_formulario'] : [];
// Campos adicionais configurados no fluxo — só os preenchidos. A aba
// "Campos" só existe quando sobra algo para mostrar.
// Campo de lista guarda o ID do item, não o texto — rotularCampos resolve o id
// e troca a chave interna pelo label do template antes de a tela imprimir.
$camposProcesso = Process::rotularCampos($dadosForm, $processo['template_campos'] ?? '[]');
$totalParcelas = 0;
foreach ($parcelas as $p) { $totalParcelas += (float)$p['valor']; }

// Etapa ativa info
$etapaAtualNome = '—';
$etapaAtualNum  = (int)($processo['etapa_atual'] ?? 1);
$totalEtapas    = count($etapas);
foreach ($etapas as $et) {
    if ((int)$et['numero_etapa'] === $etapaAtualNum) {
        $etapaAtualNome = $et['nome_etapa'];
        break;
    }
}

// Contador "Etapa X de Y" — as puladas não entram na conta.
//
// Um fluxo com decisão pode ter 17 etapas no template e só 5 no caminho que
// ESTE processo seguiu. Contando as descartadas, o processo mostraria "5 de 17"
// e concluiria — como se tivesse pulado dois terços do fluxo. Contam as etapas
// que este processo realmente percorre: as não puladas.
$etapasContadas = array_values(array_filter(
    $etapas,
    fn($e) => $e['status'] !== 'pulado'
));
$totalEtapasVisiveis = count($etapasContadas);
$posicaoEtapaAtual   = 0;
foreach ($etapasContadas as $i => $e) {
    if ((int) $e['numero_etapa'] <= $etapaAtualNum) {
        $posicaoEtapaAtual = $i + 1;
    }
}
if ($posicaoEtapaAtual === 0) { $posicaoEtapaAtual = 1; }

// ── Completar/editar dados na abertura (etapa 1) ──
// Só quem tem processos.editar — o atalho do dono saiu junto com o do cancelar.
$podeEditarAbertura = Permissions::can('processos', 'editar')
                   && $etapaAtualNum <= 1
                   && in_array($processo['status'], ['aberto', 'devolvido'], true);
$editUrl = '?module=processos&amp;action=editar&amp;id=' . h($processo['id']) . $embedFormQS . $backFormQS;

// ── Editar campos em QUALQUER etapa (processos.editar_dados) ──
// Vale enquanto o processo está vivo: depois de cancelado ou encerrado não há
// o que corrigir, e mexer em processo pago reescreveria história do financeiro.
$podeEditarDados = Permissions::can('processos', 'editar_dados')
                && !in_array($processo['status'], ['cancelado', 'encerrado_apollo'], true);
$editDadosUrl = '?module=processos&amp;action=editar_dados&amp;id=' . h($processo['id']) . $embedFormQS . $backFormQS;

// ── Preencher os campos que a ETAPA ATUAL exige ──
// Não depende de processos.editar_dados: quem responde pela etapa tem de poder
// preencher o que ela pede, senão não haveria como passar pelo gate da
// aprovação. Só aparece quando o template amarrou algum campo a esta etapa.
$podePreencherEtapa = $isAprovador
                   && !empty($camposDaEtapaAtual)
                   && in_array($processo['status'], ['aberto', 'devolvido'], true);
$preencherEtapaUrl = '?module=processos&amp;action=preencher_etapa&amp;id=' . h($processo['id']) . $embedFormQS . $backFormQS;

// ── Checklist de exigências do fluxo (vem do Model, mesma regra dos gates) ──
// Separado em: o que trava a aprovação DESTA etapa, o que ainda será cobrado
// mais adiante e o que já está atendido. Alimenta o aviso acima da barra de
// ações — o usuário enxerga o que falta sem precisar clicar em "Aprovar".
$checkBloqueia = [];  // pendente e exigido nesta etapa → trava a aprovação
$checkDepois   = [];  // pendente, exigido em outra etapa (ou sem etapa fixa)
$checkOk       = [];  // já atendido
foreach ($checklistObrig as $itemChk) {
    if (!empty($itemChk['ok']))                        { $checkOk[]       = $itemChk; }
    elseif ((int) $itemChk['etapa'] === $etapaAtualNum) { $checkBloqueia[] = $itemChk; }
    else                                               { $checkDepois[]   = $itemChk; }
}
$checkTotal    = count($checklistObrig);
$temChecklist  = $checkTotal > 0 && !$isConcluido && !$isCancelado && !$isEncerrado;
// Trava só faz sentido para quem vai aprovar agora
$bloqueiaAprovacao = $isAprovador && !empty($checkBloqueia);

// Motivo de devolução mais recente
$motivoDevolucao = '';
if ($isDevolvido) {
    foreach (array_reverse($historico) as $hi) {
        if (($hi['acao'] ?? '') === 'processo_devolvido' && !empty($hi['descricao'])) {
            $motivoDevolucao = $hi['descricao'];
            break;
        }
    }
}

// Parcela status map
$parcelaStatusMap = [
    'previsto'  => ['bg' => '#EAEAEA', 'color' => '#888888', 'label' => 'Previsto'],
    'agendado'  => ['bg' => '#E3F0FF', 'color' => '#1565C0', 'label' => 'Agendado'],
    'agvenc'    => ['bg' => '#FFF3E0', 'color' => '#FF8A2D', 'label' => 'Ag. Venc.'],
    'vencido'   => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Vencido'],
    'pago'      => ['bg' => '#D8F5E5', 'color' => '#00A960', 'label' => 'Pago'],
    'cancelado' => ['bg' => '#FFE8EE', 'color' => '#B3261E', 'label' => 'Cancelado'],
];

// Etapa status icone map
$etapaStatusIcone = [
    'pendente'  => ['icon' => 'clock',         'color' => '#1565C0', 'bg' => '#E3F0FF'],
    'aprovado'  => ['icon' => 'check-circle',  'color' => '#00A960', 'bg' => '#D8F5E5'],
    'devolvido' => ['icon' => 'corner-up-left','color' => '#FF8A2D', 'bg' => '#FFF3E0'],
    'pulado'    => ['icon' => 'skip-forward',  'color' => '#888888', 'bg' => '#EAEAEA'],
    'aguardando_assinatura' => ['icon' => 'pen-tool', 'color' => '#2347C8', 'bg' => '#EEF2FD'],
];

// Assinaturas por etapa (vem do controller). Sem a variável, a tela funciona
// igual — só não mostra o bloco.
$assinaturasPorEtapa = $assinaturasPorEtapa ?? [];

/** Rótulo humano do status de um signatário. */
$asgStatusLabel = [
    'assinado'    => ['txt' => 'assinou',      'cor' => '#00A960'],
    'visualizado' => ['txt' => 'visualizou',   'cor' => '#FF8A2D'],
    'notificado'  => ['txt' => 'convite enviado', 'cor' => '#888888'],
    'pendente'    => ['txt' => 'aguardando',   'cor' => '#888888'],
    'recusado'    => ['txt' => 'recusou',      'cor' => '#B3261E'],
];

// ── Cruzar tipos_anexo do template com anexos existentes (múltiplos por tipo) ──
$tiposAnexoTemplate = json_decode($processo['template_tipos_anexo'] ?? '[]', true) ?: [];
$anexosPorTipo = [];
foreach ($anexos as $a) {
    $anexosPorTipo[$a['tipo_anexo_nome']][] = $a;
}

$documentos = [];
foreach ($tiposAnexoTemplate as $tipo) {
    $nome  = $tipo['nome'] ?? '';
    $lista = $anexosPorTipo[$nome] ?? [];

    $documentos[] = [
        'tipo_nome'    => $nome,
        'obrigatorio'  => !empty($tipo['obrigatorio']),
        'tipo_doc'     => $tipo['tipo_doc'] ?? 'qualquer',
        'etapa_ordem'  => (int) ($tipo['etapa_ordem'] ?? 0),
        'anexos'       => $lista,
        'anexo'        => $lista[0] ?? null,
        'pendente'     => empty($lista),
    ];
    unset($anexosPorTipo[$nome]);
}
// Anexos extras (tipos não mapeados no template)
foreach ($anexosPorTipo as $nome => $lista) {
    $documentos[] = [
        'tipo_nome'   => $nome,
        'obrigatorio' => false,
        'tipo_doc'    => 'qualquer',
        'etapa_ordem' => 0,
        'anexos'      => $lista,
        'anexo'       => $lista[0] ?? null,
        'pendente'    => empty($lista),
    ];
}

// Ordenação fixa: DANFE/DACTE/DANFSe sempre primeiro, Nota Fiscal (ou NF de
// Serviço / NFS-e) em seguida, depois XML, Boleto e demais na ordem do
// template. O XML é só o dado bruto — quem abre o processo deve ver a Nota
// primeiro. O nome do slot vem do template (ver Template::tipoAnexoDaNota):
// compara sem acento e por palavra, pois "NFe" não pode casar com "nfse" nem
// "xml" com "nota fiscal".
$prioridadeDoc = function (array $d): int {
    $n = mb_strtolower(strtr($d['tipo_nome'], [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c',
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I',
        'Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C',
    ]));
    if (str_contains($n, 'xml'))         return 2;
    if (str_contains($n, 'danfse') || str_contains($n, 'danfe') || str_contains($n, 'dacte')) return 0;
    if (str_contains($n, 'nota fiscal') || str_contains($n, 'nota de servico')
        || preg_match('/(^| )(nf|nfe|nfse|nf-e|nfs-e)( |$)/', $n)) return 1;
    if (str_contains($n, 'boleto'))      return 3;
    return 4;
};
usort($documentos, fn($a, $b) => $prioridadeDoc($a) <=> $prioridadeDoc($b));

$totalAnexos = count($anexos);
$totalDisp   = count(array_filter($documentos, fn($d) => !$d['pendente']));
$totalPend   = count(array_filter($documentos, fn($d) => $d['pendente']));

// Primeiro documento disponível como selecionado
$primeiroAnexo = null;
foreach ($documentos as $d) {
    if ($d['anexo']) { $primeiroAnexo = $d['anexo']; break; }
}
?>

<!-- Flash message fixa (sobreposta ao workspace) -->
<?php if (!empty($_SESSION['flash'])): ?>
<?php
$_flash = $_SESSION['flash'];
$_flashType = $_flash['type'] ?? 'info';
$_flashMsg  = $_flash['message'] ?? '';
unset($_SESSION['flash']);
$_flashColors = [
    'success' => ['bg' => '#D8F5E5', 'border' => '#00A960', 'color' => '#00703C', 'icon' => 'check-circle'],
    'danger'  => ['bg' => '#FFE8EE', 'border' => '#B3261E', 'color' => '#C0044D', 'icon' => 'x-circle'],
    'warning' => ['bg' => '#FFF3E0', 'border' => '#FF8A2D', 'color' => '#C75B00', 'icon' => 'alert-triangle'],
    'info'    => ['bg' => '#E3F0FF', 'border' => '#1565C0', 'color' => '#1565C0', 'icon' => 'info'],
];
$_fc = $_flashColors[$_flashType] ?? $_flashColors['info'];
?>
<div id="ws-flash" style="position:fixed;top:72px;left:50%;transform:translateX(-50%);z-index:10000;
  background:<?= $_fc['bg'] ?>;border:1.5px solid <?= $_fc['border'] ?>;border-radius:8px;
  padding:12px 20px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 16px rgba(0,0,0,0.12);
  max-width:600px;min-width:320px;animation:wsFlashIn 0.3s ease">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="<?= $_fc['color'] ?>" stroke-width="2" style="display:inline-block;flex-shrink:0">
    <?php if ($_flashType === 'success'): ?><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
    <?php elseif ($_flashType === 'danger'): ?><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
    <?php else: ?><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    <?php endif; ?>
  </svg>
  <span style="font-size:13.5px;font-weight:700;font-family:var(--font-bold);color:<?= $_fc['color'] ?>;flex:1"><?= h($_flashMsg) ?></span>
</div>
<style>@keyframes wsFlashIn{from{opacity:0;transform:translateX(-50%) translateY(-10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}</style>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     WORKSPACE SPLIT: doc-pane (55%) + ctx-pane (45%)
     ═══════════════════════════════════════════════════════════ -->
<div class="ws">

  <!-- ── PAINEL DE DOCUMENTOS (esquerda, 55%) ─────────────── -->
  <div class="ws-doc">

    <!-- Seção de cards de anexos -->
    <div class="ws-att">
      <div class="ws-att-header">
        <span class="ws-att-label">Documentos Anexos</span>
      </div>

      <?php
      // Controle de acesso aos anexos, por etapa:
      //  · substituir/excluir — só o responsável pela etapa ativa (ou admin) e
      //    só com o processo em andamento; encerrado, ninguém mais altera.
      //  · anexar — participantes durante o andamento (anexos validam a
      //    execução das tarefas); com o processo encerrado, só admin.
      $emAndamento     = !$isConcluido && !$isCancelado && !$isCancSolic && !$isEncerrado;
      $canAlterarAnexo = ($isAprovador || $isAdmin) && $emAndamento;
      $canAnexar       = $emAndamento
                       ? ($isAprovador || $isDevolvido || $isSolicitante || $isAdmin)
                       : $isAdmin;
      $docsComAnexo = array_filter($documentos, fn($d) => $d['anexo'] !== null);
      ?>
      <div class="ws-att-nav">
      <button type="button" id="ws-att-arrow-left" class="ws-att-arrow" onclick="scrollAnexos(-1)" aria-label="Rolar para a esquerda" style="display:none">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:block"><polyline points="15 18 9 12 15 6"/></svg>
      </button>
      <div class="ws-att-scroll" id="ws-att-scroll">
        <?php
        // Um bullet por arquivo (múltiplos por tipo — ex.: Boleto, Boleto (2))
        $isFirstAvailable = true;
        foreach ($docsComAnexo as $doc):
          $isObrig = $doc['obrigatorio'];
          $qtd     = count($doc['anexos']);
          foreach ($doc['anexos'] as $ix => $anexo):
            $ext    = strtoupper(pathinfo($anexo['nome_original'], PATHINFO_EXTENSION));
            $rotulo = $doc['tipo_nome'] . ($qtd > 1 ? ' (' . ($ix + 1) . ')' : '');
            $bulletClass = 'ws-att-bullet ws-att-bullet--uploaded' . ($isFirstAvailable ? ' active' : '');
            $isFirstAvailable = false;
        ?>
        <div class="<?= $bulletClass ?>"
             data-anexo-id="<?= h($anexo['id']) ?>"
             data-nome="<?= h($anexo['nome_original']) ?>"
             data-tipo="<?= h($doc['tipo_nome']) ?>"
             data-ext="<?= h($ext) ?>"
             data-tamanho="<?= (int) $anexo['tamanho_bytes'] ?>"
             data-url="?module=processos&amp;action=download_anexo&amp;processo_id=<?= h($processo['id']) ?>&amp;anexo_id=<?= h($anexo['id']) ?>&amp;inline=1"
             data-download-url="?module=processos&amp;action=download_anexo&amp;processo_id=<?= h($processo['id']) ?>&amp;anexo_id=<?= h($anexo['id']) ?>"
             onclick="selecionarAnexo(this)">
          <span class="ws-att-bullet__name"><?= h($rotulo) ?><?php if ($isObrig && $ix === 0): ?> *<?php endif; ?></span>
          <span style="display:inline-flex;align-items:center;gap:2px;margin-left:4px;flex-shrink:0">
            <a title="Baixar este arquivo"
               href="?module=processos&amp;action=download_anexo&amp;processo_id=<?= h($processo['id']) ?>&amp;anexo_id=<?= h($anexo['id']) ?>"
               onclick="event.stopPropagation()"
               style="width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;border:none;border-radius:4px;background:rgba(255,255,255,0.20);color:#fff;cursor:pointer;padding:0;text-decoration:none"
               onmouseover="this.style.background='rgba(255,255,255,0.38)'" onmouseout="this.style.background='rgba(255,255,255,0.20)'">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="display:block"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </a>
            <?php if ($canAlterarAnexo): ?>
            <button type="button" title="Substituir este arquivo"
                    data-anexo-id="<?= h($anexo['id']) ?>" data-tipo="<?= h($doc['tipo_nome']) ?>" data-tipodoc="<?= h($doc['tipo_doc']) ?>"
                    onclick="event.stopPropagation(); subDoBullet(this)"
                    style="width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;border:none;border-radius:4px;background:rgba(255,255,255,0.20);color:#fff;cursor:pointer;padding:0"
                    onmouseover="this.style.background='rgba(255,255,255,0.38)'" onmouseout="this.style.background='rgba(255,255,255,0.20)'">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="display:block"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
            </button>
            <button type="button" title="Excluir este arquivo"
                    data-anexo-id="<?= h($anexo['id']) ?>" data-rotulo="<?= h($rotulo) ?>"
                    onclick="event.stopPropagation(); excluirDoBullet(this)"
                    style="width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;border:none;border-radius:4px;background:rgba(255,255,255,0.20);color:#fff;cursor:pointer;padding:0"
                    onmouseover="this.style.background='rgba(255,255,255,0.38)'" onmouseout="this.style.background='rgba(255,255,255,0.20)'">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="display:block"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
            </button>
            <?php endif; ?>
          </span>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>

        <?php
        // Tipos previstos no fluxo e ainda SEM arquivo: continuam na faixa, em
        // tracejado, para que um obrigatório não passe despercebido até o
        // usuário clicar em "Aprovar" e levar o erro do gate.
        $docsPendentes = array_filter($documentos, fn($d) => $d['anexo'] === null);
        foreach ($docsPendentes as $doc):
          $isObrig    = $doc['obrigatorio'];
          $etapaExige = (int) $doc['etapa_ordem'];
          $travaAgora = $isObrig && $etapaExige === $etapaAtualNum;
          $tituloPend = $isObrig
              ? 'Anexo obrigatório' . ($etapaExige > 0 ? ' na etapa ' . $etapaExige : '') . ' — ainda não enviado'
              : 'Anexo opcional — ainda não enviado';
        ?>
        <div class="ws-att-bullet <?= $isObrig ? 'ws-att-bullet--req-pending' : 'ws-att-bullet--opt-pending' ?>"
             title="<?= h($tituloPend) ?><?= $canAnexar ? ' · clique para anexar' : '' ?>"
             data-tipo="<?= h($doc['tipo_nome']) ?>"
             style="<?= $travaAgora ? 'box-shadow:0 0 0 3px rgba(35,71,200,0.14);' : '' ?><?= $canAnexar ? '' : 'cursor:default' ?>"
             <?= $canAnexar ? 'onclick="anexarTipoPendente(this)"' : '' ?>>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="ws-att-bullet__icon" style="display:block;flex-shrink:0">
            <?php if ($isObrig): ?><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            <?php else: ?><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><?php endif; ?>
          </svg>
          <span class="ws-att-bullet__name"><?= h($doc['tipo_nome']) ?><?= $isObrig ? ' *' : '' ?></span>
          <span class="ws-att-bullet__ext"><?= $travaAgora ? 'exigido agora' : 'pendente' ?></span>
        </div>
        <?php endforeach; ?>

        <?php if (empty($documentos)): ?>
        <div style="padding:8px 4px;font-size:12.5px;color:var(--color-gray-medium);align-self:center">
          Nenhum documento anexado ainda.
        </div>
        <?php endif; ?>
      </div><!-- /ws-att-scroll -->

      <button type="button" id="ws-att-arrow-right" class="ws-att-arrow" onclick="scrollAnexos(1)" aria-label="Rolar para a direita" style="display:none">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:block"><polyline points="9 18 15 12 9 6"/></svg>
      </button>

        <?php if ($canAnexar && !empty($tiposAnexoTemplate)): ?>
        <button type="button" onclick="abrirModalAnexar()"
                style="display:inline-flex;align-items:center;gap:6px;flex-shrink:0;align-self:center;
                       background:transparent;color:var(--color-primary);border:1.5px dashed var(--color-primary);
                       border-radius:8px;padding:8px 14px;font-size:11.5px;font-family:var(--font-bold);cursor:pointer"
                onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='transparent'">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Anexar
        </button>
        <?php endif; ?>
      </div><!-- /ws-att-nav -->
    </div>

    <!-- Viewer head -->
    <div class="ws-viewer-head" id="viewer-head">
      <?php if ($primeiroAnexo): ?>
      <span class="ws-viewer-head__name" id="viewer-fname"><?= h($primeiroAnexo['nome_original']) ?></span>
      <div class="ws-viewer-head__sep"></div>
      <a class="ws-viewer-head__dl" id="viewer-dl-btn"
         href="?module=processos&amp;action=download_anexo&amp;processo_id=<?= h($processo['id']) ?>&amp;anexo_id=<?= h($primeiroAnexo['id']) ?>">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline-block"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Baixar
      </a>
      <?php else: ?>
      <span class="ws-viewer-head__name">Nenhum documento</span>
      <?php endif; ?>
    </div>

    <!-- Viewer canvas -->
    <div class="ws-viewer-canvas" id="viewer-canvas">
      <?php if ($primeiroAnexo):
        $firstUrl = '?module=processos&action=download_anexo&processo_id=' . urlencode($processo['id']) . '&anexo_id=' . urlencode($primeiroAnexo['id']) . '&inline=1';
        $firstExt = strtolower(pathinfo($primeiroAnexo['nome_original'], PATHINFO_EXTENSION));
        $firstIsImage = in_array($firstExt, ['jpg','jpeg','png','gif','webp','bmp']);
        $firstIsVideo = in_array($firstExt, VIDEO_EXTENSIONS, true);
        $firstDlUrl   = '?module=processos&action=download_anexo&processo_id=' . urlencode($processo['id']) . '&anexo_id=' . urlencode($primeiroAnexo['id']);
      ?>
        <?php if ($firstIsVideo): ?>
        <?= ws_cartaoVideo($primeiroAnexo['nome_original'], (int) $primeiroAnexo['tamanho_bytes'], $firstDlUrl) ?>
        <?php elseif ($firstIsImage): ?>
        <img src="<?= h($firstUrl) ?>" alt="<?= h($primeiroAnexo['nome_original']) ?>" id="viewer-content">
        <?php else: ?>
        <iframe src="<?= h($firstUrl) ?>" id="viewer-content"  style="width:100%;height:100%"></iframe>
        <?php endif; ?>
      <?php else: ?>
      <div class="ws-viewer-empty" id="viewer-empty">
        <div class="ws-viewer-empty__icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="1.5" style="display:inline-block"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        </div>
        <div style="font-size:14px;font-family:var(--font-bold);color:var(--color-dark)">Nenhum documento selecionado</div>
        <div style="font-size:13.5px;color:var(--color-gray-medium);line-height:1.5;max-width:240px">Selecione um documento na barra acima ou envie os anexos pendentes.</div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /ws-doc -->

  <!-- ── PAINEL CONTEXTUAL (direita, 45%) ────────────────── -->
  <div class="ws-ctx">

    <!-- Barra de identificação do processo -->
    <div class="ws-proc">
      <div class="ws-proc__row1">
        <?php if (!$embed): ?>
        <a href="<?= h($voltar['url']) ?>" class="ws-proc__back">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><polyline points="15 18 9 12 15 6"/></svg>
          <?= h($voltar['label']) ?>
        </a>
        <div class="ws-proc__sep"></div>
        <?php endif; ?>
        <span class="ws-proc__id"><?= h($processo['codigo']) ?></span>
        <span class="ws-proc__badge" style="background:<?= $statusAtual['bg'] ?>;color:<?= $statusAtual['color'] ?>">
          <?= h($statusAtual['label']) ?>
        </span>
        <?php if ($isTeste): ?>
        <span class="ws-proc__badge" style="background:#FFF3CD;color:#856404">TESTE</span>
        <?php endif; ?>
      </div>
      <div class="ws-proc__meta">
        <div class="ws-proc__meta-line">
          <b><?= h($processo['template_nome'] ?? '—') ?></b> · <?= h($processo['filial_nome'] ?? '—') ?>
        </div>
        <div class="ws-proc__meta-line">
          <?= h($processo['criado_por_nome'] ?? '—') ?> · <?= date('d/m/Y', strtotime($processo['criado_em'])) ?>
        </div>
      </div>
    </div>

    <!-- Banners contextuais (apenas informações únicas, não duplicadas no action bar) -->
    <?php if ($isTeste && !$isConcluido && !$isCancelado): ?>
    <div class="ws-banner ws-banner--test">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <span><b>Processo de teste</b> · não afeta dados reais</span>
    </div>
    <?php endif; ?>

    <?php if ($isDevolvido && $motivoDevolucao): ?>
    <div class="ws-banner ws-banner--alert">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      <span>Devolvido — <?= h($motivoDevolucao) ?></span>
    </div>
    <?php endif; ?>

    <!-- Abas -->
    <div class="ws-tabs">
      <button class="ws-tab active" data-tab="dados" onclick="wsSetTab('dados')">Dados</button>
      <?php if (!empty($camposProcesso)): ?>
      <button class="ws-tab" data-tab="campos" onclick="wsSetTab('campos')">
        Formulário
        <span class="ws-tab__badge" style="background:var(--color-gray-light);color:var(--color-gray-dark)"><?= count($camposProcesso) ?></span>
      </button>
      <?php endif; ?>
      <button class="ws-tab" data-tab="fluxo" onclick="wsSetTab('fluxo')">
        Fluxo
        <span class="ws-tab__badge" style="background:var(--color-gray-light);color:var(--color-gray-dark)"><?= $posicaoEtapaAtual ?>/<?= $totalEtapasVisiveis ?></span>
      </button>
      <button class="ws-tab" data-tab="historico" onclick="wsSetTab('historico')">
        Histórico
        <?php if (!empty($historico)): ?>
        <span class="ws-tab__badge" style="background:var(--color-gray-light);color:var(--color-gray-dark)"><?= count($historico) ?></span>
        <?php endif; ?>
      </button>
    </div>

    <!-- Corpo das abas -->
    <div class="ws-body">

      <!-- ═══ ABA: DADOS ═══ -->
      <div class="ws-panel ws-panel--flex active" id="ws-tab-dados">
        <div class="ws-panel__inner">

          <!-- Card hero: Fornecedor + Valor -->
          <div class="ws-card" style="border-left:4px solid var(--color-primary)">
            <div style="padding:18px 20px;display:flex;align-items:flex-start;gap:20px;justify-content:space-between">
              <div style="flex:1;min-width:0">
                <span class="ws-card__lbl">Fornecedor</span>
                <?php if ($fornecedorCad): ?>
                <button type="button" onclick="abrirFornecedorView()" title="Ver dados do fornecedor"
                        class="ws-card__val ws-card__val--hero"
                        style="background:none;border:none;padding:0;text-align:left;cursor:pointer;color:var(--color-primary);text-decoration:underline;text-underline-offset:2px;font:inherit;display:flex;align-items:center;gap:6px">
                  <?= h($processo['fornecedor_nome']) ?>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <?php else: ?>
                <span class="ws-card__val ws-card__val--hero"><?= h($processo['fornecedor_nome']) ?></span>
                <?php endif; ?>
                <?php if (!empty($processo['fornecedor_doc'])): ?>
                <span class="ws-card__val ws-card__val--mono" style="margin-top:3px"><?= h(formatarDoc($processo['fornecedor_doc'] ?? '')) ?></span>
                <?php endif; ?>
              </div>
              <div style="text-align:right;flex-shrink:0">
                <span class="ws-card__lbl">Valor Total</span>
                <span class="ws-card__val ws-card__val--amount"><?= $processo['valor_nf'] === null ? '—' : 'R$ ' . number_format((float)$processo['valor_nf'], 2, ',', '.') ?></span>
              </div>
            </div>
          </div>

          <!-- Card: Rateio entre centros de custo -->
          <?php if (!empty($rateios)): ?>
          <div class="ws-card">
            <div class="ws-card__head">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-gray-medium)" stroke-width="2" stroke-linecap="round" style="display:inline-block"><path d="M21.21 15.89A10 10 0 118 2.83"/><path d="M22 12A10 10 0 0012 2v10z"/></svg>
              <span class="ws-card__title">Rateio entre centros de custo</span>
            </div>
            <div style="padding:0 20px 16px">
              <table style="width:100%; border-collapse:collapse; font-size:11.5px">
                <thead>
                  <tr style="border-bottom:1px solid var(--color-gray-light)">
                    <th style="text-align:left; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase">Centro de custo</th>
                    <th style="text-align:right; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase; width:90px">%</th>
                    <th style="text-align:right; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase; width:130px">Valor</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rateios as $r): ?>
                  <tr style="border-bottom:1px solid #F6F6F6">
                    <td style="padding:9px 0; color:var(--color-dark)">
                      <?php if (!empty($r['setor_codigo'])): ?>
                      <span style="font-family:Consolas,monospace; font-size:11px; font-weight:700; background:#F6F6F6; border:1px solid var(--color-gray-light); border-radius:4px; padding:1px 6px; margin-right:6px"><?= h($r['setor_codigo']) ?></span>
                      <?php endif; ?>
                      <?= h($r['setor_nome']) ?>
                    </td>
                    <td style="padding:9px 0; text-align:right; color:var(--color-gray-dark)"><?= number_format((float)$r['percentual'], 2, ',', '.') ?>%</td>
                    <td style="padding:9px 0; text-align:right; font-weight:700; color:var(--color-dark)">R$ <?= number_format((float)$r['valor'], 2, ',', '.') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

          <!-- Card: Tipo de despesa (classificação do gasto) -->
          <?php if (!empty($tiposDespesaProc)): ?>
          <div class="ws-card">
            <div class="ws-card__head">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-gray-medium)" stroke-width="2" stroke-linecap="round" style="display:inline-block"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
              <span class="ws-card__title">Tipo de despesa</span>
            </div>
            <div style="padding:0 20px 16px">
              <table style="width:100%; border-collapse:collapse; font-size:11.5px">
                <thead>
                  <tr style="border-bottom:1px solid var(--color-gray-light)">
                    <th style="text-align:left; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase">Tipo</th>
                    <th style="text-align:right; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase; width:90px">%</th>
                    <th style="text-align:right; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase; width:130px">Valor</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($tiposDespesaProc as $td): ?>
                  <tr style="border-bottom:1px solid #F6F6F6">
                    <td style="padding:9px 0; color:var(--color-dark)"><?= h($td['tipo_nome']) ?></td>
                    <td style="padding:9px 0; text-align:right; color:var(--color-gray-dark)"><?= number_format((float)$td['percentual'], 2, ',', '.') ?>%</td>
                    <td style="padding:9px 0; text-align:right; font-weight:700; color:var(--color-dark)">R$ <?= number_format((float)$td['valor'], 2, ',', '.') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

          <!-- Card: Dados Fiscais -->
          <div class="ws-card">
            <div class="ws-card__head">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-gray-medium)" stroke-width="2" stroke-linecap="round" style="display:inline-block"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <span class="ws-card__title">Dados Fiscais</span>
            </div>
            <div class="ws-card__grid3">
              <div class="ws-card__field">
                <span class="ws-card__lbl">Número da NF</span>
                <span class="ws-card__val ws-card__val--mono"><?= !empty($processo['sem_nota']) ? 'Sem nota fiscal' : (h($processo['numero_nf']) ?: '—') ?></span>
              </div>
              <div class="ws-card__field">
                <span class="ws-card__lbl">Data de emissão</span>
                <span class="ws-card__val"><?= !empty($processo['data_nf']) ? date('d/m/Y', strtotime($processo['data_nf'])) : '—' ?></span>
              </div>
              <div class="ws-card__field">
                <span class="ws-card__lbl">Filial</span>
                <span class="ws-card__val"><?= h($processo['filial_nome'] ?? '—') ?></span>
              </div>
            </div>
            <?php if (!empty($processo['chave_nf'])): ?>
            <div class="ws-card__field--full">
              <span class="ws-card__lbl">Chave NF-e</span>
              <code class="ws-card__code"><?= h($processo['chave_nf']) ?></code>
            </div>
            <?php endif; ?>
          </div>

          <!-- Card: Parcelas (antes era aba própria — trazida para Dados em
               formato de tabela compacta, uma linha por parcela). Só existe
               quando o template tem gestão financeira; sem financeiro o card
               inteiro some, em vez de mostrar estado vazio. -->
          <?php if ($temFinanceiro):
            $formasPag  = array_unique(array_filter(array_column($parcelas, 'forma_nome')));
            $formaLabel = !empty($formasPag) ? implode(', ', $formasPag) : '';
            // Coluna de agendamento só existe se alguma parcela tiver data —
            // sem isso a tabela fica com uma coluna vazia na maioria dos casos.
            $temAgendamento = (bool) array_filter(array_column($parcelas, 'data_agendamento'));
          ?>
          <div class="ws-card">
            <div class="ws-card__head">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-gray-medium)" stroke-width="2" stroke-linecap="round" style="display:inline-block"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
              <span class="ws-card__title">
                <?= empty($parcelas) ? 'Parcelas' : count($parcelas) . ' ' . (count($parcelas) === 1 ? 'Parcela' : 'Parcelas') ?><?= $formaLabel ? ' · ' . h($formaLabel) : '' ?>
              </span>
            </div>
            <?php if (empty($parcelas)): ?>
            <div style="padding:14px 20px;font-size:13.5px;color:var(--color-gray-medium)">
              Sem parcelas — serão lançadas quando o processo completar os dados.
            </div>
            <?php else: ?>
            <div style="padding:0 20px 4px">
              <table style="width:100%; border-collapse:collapse; font-size:11.5px">
                <thead>
                  <tr style="border-bottom:1px solid var(--color-gray-light)">
                    <th style="text-align:left; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase; width:52px">Parc.</th>
                    <th style="text-align:left; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase">Vencimento</th>
                    <?php if ($temAgendamento): ?>
                    <th style="text-align:left; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase">Agendamento</th>
                    <?php endif; ?>
                    <th style="text-align:right; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase; width:130px">Valor</th>
                    <th style="text-align:right; padding:8px 0; color:var(--color-gray-medium); font-size:10.5px; text-transform:uppercase; width:110px">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($parcelas as $p):
                    $ps       = $parcelaStatusMap[$p['status']] ?? ['bg' => '#EAEAEA', 'color' => '#888', 'label' => $p['status']];
                    $isAgVenc = $p['status'] === 'agvenc';
                  ?>
                  <tr style="border-bottom:1px solid #F6F6F6;<?= $isAgVenc ? 'background:#FFFAF5' : '' ?>">
                    <td style="padding:9px 0; font-family:var(--font-bold); color:<?= $isAgVenc ? 'var(--color-warning)' : 'var(--color-gray-dark)' ?>">
                      <?= (int)$p['numero_parcela'] ?>/<?= (int)$p['total_parcelas'] ?>
                    </td>
                    <td style="padding:9px 0; font-family:var(--font-bold); color:var(--color-dark)"><?= date('d/m/Y', strtotime($p['vencimento'])) ?></td>
                    <?php if ($temAgendamento): ?>
                    <td style="padding:9px 0; color:<?= $isAgVenc ? 'var(--color-warning)' : 'var(--color-dark)' ?>">
                      <?php if (!empty($p['data_agendamento'])): ?>
                      <?= date('d/m/Y', strtotime($p['data_agendamento'])) ?>
                      <?php if ($isAgVenc): ?>
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;vertical-align:-1px" aria-label="Agendamento vencido"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                      <?php endif; ?>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td style="padding:9px 0; text-align:right; font-weight:700; color:var(--color-dark)">R$ <?= number_format((float)$p['valor'], 2, ',', '.') ?></td>
                    <td style="padding:9px 0; text-align:right">
                      <span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:10.5px;font-family:var(--font-bold);background:<?= $ps['bg'] ?>;color:<?= $ps['color'] ?>"><?= h($ps['label']) ?></span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div style="padding:0 20px 16px">
              <div style="background:#D8F5E5;border:1px solid #A8E6C1;border-radius:8px;padding:10px 16px;display:flex;align-items:center;justify-content:space-between">
                <span style="font-size:12px;font-family:var(--font-bold);color:#00A960;text-transform:uppercase;letter-spacing:0.04em">Total</span>
                <span style="font-size:16px;font-family:var(--font-bold);color:#00703C">R$ <?= number_format($totalParcelas, 2, ',', '.') ?></span>
              </div>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- Card: Observações (antes era aba própria — trazida para Dados no
               mesmo formato de Parcelas). Nasce RECOLHIDA: o título mostra a
               quantidade e o botão abre.

               O card aparece mesmo com zero observações, ao contrário do que
               Parcelas faz, por um motivo prático: o campo de escrever mora
               aqui dentro. Escondendo o card no processo sem nenhuma nota,
               ninguém conseguiria escrever a primeira. Vazio ele é só a linha
               do título, sem ocupar espaço. -->
          <?php $composerDisabled = $isCancelado || $isCancSolic; ?>
          <div class="ws-card" id="obs-card">
            <div class="ws-card__head" onclick="obsToggle()" style="cursor:pointer;user-select:none">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-gray-medium)" stroke-width="2" stroke-linecap="round" style="display:inline-block"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
              <span class="ws-card__title">
                <?= $observacoesCount === 1 ? 'Observação' : 'Observações' ?>
              </span>
              <?php if ($observacoesCount > 0): ?>
              <span style="background:var(--color-primary);color:#fff;font-size:11px;font-family:var(--font-bold);border-radius:99px;padding:1px 8px;margin-left:6px"><?= $observacoesCount ?></span>
              <?php endif; ?>
              <button type="button" id="obs-chevron" aria-expanded="false" aria-controls="obs-corpo"
                      title="Expandir observações"
                      style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--color-gray-medium);display:flex;align-items:center;padding:2px;transition:transform 0.15s">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block"><polyline points="6 9 12 15 18 9"/></svg>
              </button>
            </div>

            <div id="obs-corpo" style="display:none">
              <?php if ($observacoesCount > 0): ?>
              <div id="obs-scroll" style="max-height:320px;overflow-y:auto;padding:14px 20px;background:#FAFAFA;border-top:1px solid #F0F0F0">
                <?php foreach ($observacoes as $obs):
                  $partes   = explode(' ', $obs['autor_nome'] ?? $obs['autor_nome_real'] ?? '?');
                  $iniciais = strtoupper(mb_substr($partes[0], 0, 1) . (isset($partes[1]) ? mb_substr($partes[1], 0, 1) : ''));
                  $isMe     = ($obs['autor_id'] === $currentUser['id']);
                ?>
                <div style="padding:10px 12px;background:#fff;border:1px solid #EAEAEA;border-radius:8px;margin-bottom:8px">
                  <div style="display:flex;align-items:center;gap:6px;margin-bottom:5px">
                    <div style="width:22px;height:22px;border-radius:50%;background:<?= $isMe ? '#EEF2FD' : '#E3F0FF' ?>;color:<?= $isMe ? 'var(--color-primary)' : '#1565C0' ?>;font-size:13.5px;font-family:var(--font-bold);display:flex;align-items:center;justify-content:center;flex-shrink:0"><?= h($iniciais) ?></div>
                    <span style="font-size:13.5px;font-family:var(--font-bold);color:var(--color-dark)"><?= h($obs['autor_nome'] ?? $obs['autor_nome_real'] ?? '—') ?></span>
                    <?php if ($isMe): ?>
                    <span style="font-size:13.5px;font-family:var(--font-bold);color:var(--color-primary);background:#EEF2FD;border-radius:8px;padding:1px 6px">você</span>
                    <?php endif; ?>
                    <span style="font-size:13.5px;color:var(--color-gray-medium);margin-left:auto"><?= date('d/m/Y H:i', strtotime($obs['criado_em'])) ?></span>
                  </div>
                  <div style="font-size:13.5px;color:var(--color-dark);line-height:1.55"><?= nl2br(h($obs['texto'])) ?></div>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <div style="padding:12px 20px 14px;border-top:1px solid #F0F0F0;<?= $composerDisabled ? 'opacity:0.5;pointer-events:none;' : '' ?>">
                <form method="post" action="?module=processos&amp;action=criar_observacao<?= $embedFormQS . $backFormQS ?>" id="form-observacao">
                  <?= Csrf::field() ?>
                  <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
                  <textarea name="texto" id="obs-textarea" rows="2" required minlength="3"
                            placeholder="<?= $composerDisabled ? 'Observações desabilitadas para este processo.' : 'Adicionar observação…' ?>"
                            <?= $composerDisabled ? 'disabled' : '' ?>
                            style="width:100%;border:1.5px solid var(--color-gray-light);border-radius:6px;padding:9px 12px;font-size:13.5px;font-family:inherit;resize:none;outline:none;color:var(--color-dark);background:#F9F9F9;transition:border-color 0.15s,background 0.15s;box-sizing:border-box"
                            onfocus="this.style.borderColor='var(--color-primary)';this.style.background='#fff'"
                            onblur="this.style.borderColor='var(--color-gray-light)';this.style.background='#F9F9F9'"></textarea>
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px">
                    <span style="font-size:13.5px;color:var(--color-gray-medium)"><?= $composerDisabled ? 'Processo encerrado' : 'Visível para todos os participantes' ?></span>
                    <button type="submit" id="obs-submit-btn" <?= $composerDisabled ? 'disabled' : '' ?>
                            style="padding:6px 14px;background:#fff;color:var(--color-primary);border:1.5px solid var(--color-primary);border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;transition:all 0.15s"
                            onmouseover="this.style.background='#EEF2FD'" onmouseout="this.style.background='#fff'">
                      Adicionar
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>

        </div>
      </div><!-- /ws-tab-dados -->

      <!-- ═══ ABA: CAMPOS (adicionais, configurados no fluxo) ═══ -->
      <?php if (!empty($camposProcesso)): ?>
      <div class="ws-panel ws-panel--flex" id="ws-tab-campos">
        <div class="ws-panel__inner">
          <div class="ws-card">
            <div class="ws-card__head">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-gray-medium)" stroke-width="2" stroke-linecap="round" style="display:inline-block"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
              <span class="ws-card__title">Formulário do processo</span>
            </div>
            <?php foreach ($camposProcesso as $campo): ?>
            <div class="ws-card__row">
              <span class="ws-card__row-key"><?= h($campo['label']) ?></span>
              <span class="ws-card__row-val"><?= h($campo['valor']) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div><!-- /ws-tab-campos -->
      <?php endif; ?>

      <!-- ═══ ABA: FLUXO ═══ -->
      <div class="ws-panel ws-panel--flex" id="ws-tab-fluxo">
        <div style="flex:1;overflow-y:auto;padding:16px 20px;min-height:0;background:var(--color-bg)">

          <?php if ($isCancSolic): ?>
          <div style="display:flex;align-items:center;gap:8px;background:#FFF3E0;border:1px solid #FFE0B2;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13.5px;color:#C75B00;font-family:var(--font-bold)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Fluxo suspenso · cancelamento aguardando confirmação
          </div>
          <?php endif; ?>

          <!-- Header -->
          <div class="ws-card" style="margin-bottom:12px">
            <div style="padding:12px 16px;display:flex;align-items:center;gap:10px">
              <div style="width:32px;height:32px;border-radius:8px;background:#EEF2FD;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2" style="display:inline-block"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
              </div>
              <span class="ws-card__title">Fluxo de aprovação · Etapa <?= $posicaoEtapaAtual ?> de <?= $totalEtapasVisiveis ?></span>
              <?php if ($podeAlterarFluxo): ?>
              <button type="button" onclick="abrirModalAlterarFluxo()"
                      style="display:inline-flex;align-items:center;gap:5px;margin-left:auto;padding:5px 12px;border-radius:4px;background:#fff;border:1.5px solid var(--color-gray-light);color:var(--color-gray-dark);font-size:13px;font-family:var(--font-bold);cursor:pointer;transition:all 0.15s"
                      onmouseover="this.style.borderColor='var(--color-primary)';this.style.color='var(--color-primary)'" onmouseout="this.style.borderColor='var(--color-gray-light)';this.style.color='var(--color-gray-dark)'">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:inline-block"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                Alterar fluxo
              </button>
              <?php endif; ?>
            </div>
          </div>

          <!-- Timeline -->
          <div style="display:flex;flex-direction:column;gap:8px">
            <?php foreach ($etapas as $etapa):
              $num = (int)$etapa['numero_etapa'];
              // Duas coisas diferentes usam o status 'pulado':
              //   - etapa de OBSERVAÇÃO, que nasce pulada só para dar
              //     visibilidade ao responsável, sem nunca pedir ação;
              //   - etapa de um RAMO NÃO SEGUIDO, descartada por uma etapa de
              //     decisão quando o processo tomou o outro caminho.
              // Em ambos o fluxo passa direto, mas o motivo é outro e a tela
              // precisa dizer qual foi — senão o usuário lê "observação" numa
              // etapa que na verdade deixou de existir para este processo.
              $isPulado = $etapa['status'] === 'pulado';
              $isObs    = $isPulado && (int) ($etapa['somente_observacao'] ?? 0) === 1;
              $isRamoMorto = $isPulado && !$isObs;
              $isDone   = $etapa['status'] === 'aprovado';
              $isActive = !$isPulado && $num === $etapaAtualNum && !$isConcluido && !$isCancelado && !$isEncerrado;
              $isPending = !$isPulado && !$isDone && !$isActive;
              $isLast   = $num === $totalEtapas;
              $etapaInfo = $etapaStatusIcone[$etapa['status']] ?? $etapaStatusIcone['pendente'];

              if ($isConcluido && !$isPulado) { $isDone = true; $isActive = false; $isPending = false; }

              // Devolução se enxerga na cor: laranja (#FF8A2D, o mesmo do badge
              // "Devolvido") tanto em quem está com a bola agora — em geral o
              // solicitante — quanto na etapa que devolveu, que ficou para trás
              // esperando o processo voltar. Vermelho aqui diria só "é a etapa
              // ativa" e esconderia que o processo voltou.
              $isDevolveu  = $etapa['status'] === 'devolvido';
              $isAguardandoCorrecao = $isActive && $isDevolvido;

              $markerBg    = $isDone ? '#00A960'
                           : ($isAguardandoCorrecao ? '#FF8A2D'
                           : ($isActive ? '#2347C8' : ($isDevolveu ? '#FFF3E0' : '#fff')));
              $markerColor = ($isDone || $isActive) ? '#fff' : '#888888';
              $markerBorder = $isDevolveu && !$isActive ? '2px solid #FF8A2D'
                            : ($isPending ? '2px solid #D0D0D0' : 'none');
              $connBg      = $isDone ? '#A8E6C1' : '#E0E0E0';
              $cardBorder  = $isDone ? '#00A960'
                           : ($isAguardandoCorrecao ? '#FF8A2D'
                           : ($isActive ? '#2347C8' : ($isDevolveu ? '#FF8A2D' : '#EAEAEA')));
              $cardBorderW = $isActive ? '4px' : '3px';
              $opacity     = ($isCancSolic || $isCancelado) && ($isActive || $isPending) ? '0.4' : '1';

              $isVoce = $isActive && $isAprovador;

              // Etapa de assinatura: existe para esperar gente de fora assinar.
              $isAssinatura = $etapa['status'] === 'aguardando_assinatura';
              $asg = $assinaturasPorEtapa[$num] ?? null;
              if ($isAssinatura) { $isVoce = false; }
            ?>
            <div style="display:flex;gap:12px;align-items:flex-start;opacity:<?= $opacity ?>">
              <!-- Marcador + conector -->
              <div style="display:flex;flex-direction:column;align-items:center;width:32px;flex-shrink:0;align-self:stretch">
                <div style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:<?= $markerBg ?>;border:<?= $markerBorder ?>">
                  <?php if ($isDone): ?>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" style="display:inline-block"><polyline points="20 6 9 17 4 12"/></svg>
                  <?php elseif ($isAguardandoCorrecao): ?>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
                  <?php elseif ($isActive): ?>
                  <svg width="8" height="8" viewBox="0 0 24 24" fill="#fff" style="display:inline-block"><circle cx="12" cy="12" r="7"/></svg>
                  <?php elseif ($isDevolveu): ?>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#FF8A2D" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
                  <?php endif; ?>
                </div>
                <?php if (!$isLast): ?>
                <div style="width:2px;flex:1;min-height:8px;margin:4px 0;background:<?= $connBg ?>"></div>
                <?php endif; ?>
              </div>
              <!-- Card da etapa -->
              <div style="flex:1;min-width:0;background:#fff;border:1px solid #E0E0E0;border-left:<?= $cardBorderW ?> solid <?= $cardBorder ?>;border-radius:8px;padding:8px 16px 10px;box-shadow:0 1px 3px rgba(0,0,0,0.04)">
                <div style="font-size:13.5px;font-weight:700;font-family:var(--font-bold);color:<?= $isActive ? 'var(--color-dark)' : ($isDone ? 'var(--color-dark)' : 'var(--color-gray-medium)') ?>">
                  <?= h($etapa['nome_etapa']) ?>
                  <?php if ($isVoce): ?>
                  <span style="display:inline-flex;align-items:center;font-size:13.5px;font-family:var(--font-bold);background:#EEF2FD;color:var(--color-primary);border-radius:10px;padding:2px 8px;margin-left:6px;vertical-align:middle">você</span>
                  <?php endif; ?>
                  <?php if ($isObs): ?>
                  <span style="display:inline-flex;align-items:center;font-size:13.5px;font-family:var(--font-bold);background:var(--color-tag-bg);color:var(--color-primary);border-radius:10px;padding:2px 8px;margin-left:6px;vertical-align:middle"
                        title="Etapa de observação: dá acesso ao processo sem exigir ação">observação</span>
                  <?php elseif ($isRamoMorto): ?>
                  <span style="display:inline-flex;align-items:center;font-size:13.5px;font-family:var(--font-bold);background:var(--color-gray-light);color:var(--color-gray-medium);border-radius:10px;padding:2px 8px;margin-left:6px;vertical-align:middle"
                        title="Uma etapa de decisão mandou o processo por outro caminho — esta etapa não roda neste processo">ramo não seguido</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:13.5px;color:<?= $isAguardandoCorrecao ? '#FF8A2D' : ($isActive ? 'var(--color-primary)' : 'var(--color-gray-medium)') ?>;margin-top:3px">
                  <?php if ($isObs): ?>
                  Acompanha sem precisar agir · <?= h($etapa['aprovador_nome'] ?? '—') ?>
                  <?php elseif ($isRamoMorto): ?>
                  Descartada pela decisão do fluxo
                  <?php elseif ($isDone && !empty($etapa['atuado_por_nome'])): ?>
                  <?= h($etapa['atuado_por_nome']) ?> · <?= date('d/m/Y H:i', strtotime($etapa['atuado_em'])) ?>
                  <?php elseif ($isAguardandoCorrecao): ?>
                  Devolvido — aguardando correção <?= $isVoce ? '(você)' : 'de ' . h($etapa['aprovador_nome'] ?? '—') ?>
                  <?php elseif ($isAssinatura): ?>
                  <?php /* Nunca dizer "aguardando <aprovador>": esta etapa não é
                          aprovada por pessoa nenhuma — quem a fecha é o retorno
                          do provedor. O responsável só acompanha. */ ?>
                  Aguardando assinatura<?= $asg ? ' · ' . (int) $asg['assinados'] . ' de ' . (int) $asg['total'] . ' assinaram' : '' ?>
                  <?php elseif ($isActive): ?>
                  Aguardando <?= $isVoce ? 'sua aprovação' : h($etapa['aprovador_nome'] ?? '—') ?>
                  <?php elseif ($isDevolveu): ?>
                  Devolveu o processo<?= !empty($etapa['atuado_por_nome']) ? ' · ' . h($etapa['atuado_por_nome']) : '' ?> · aprova de novo na volta
                  <?php elseif ($isPending && $num > $etapaAtualNum): ?>
                  Aguardando etapas anteriores
                  <?php else: ?>
                  <?= h($etapa['aprovador_nome'] ?? '—') ?>
                  <?php endif; ?>
                </div>
                <?php if ($asg && !empty($asg['signatarios'])): ?>
                <?php
                  $asgConcluida = $asg['status'] === 'certificated';
                  $asgFalhou    = in_array($asg['status'], ['rejected_by_signer','rejected_by_user','expired','failed'], true);
                  $asgBorda     = $asgConcluida ? '#00A960' : ($asgFalhou ? '#B3261E' : '#2347C8');
                  $asgFundo     = $asgConcluida ? '#F3FBF6' : ($asgFalhou ? '#FFF5F8' : '#FFF8F8');
                  $urlVerif     = Assinatura::urlVerificacao($asg['hash_verificacao'] ?? null);
                ?>
                <div style="margin-top:8px;border:1px solid #EAEAEA;border-left:3px solid <?= $asgBorda ?>;background:<?= $asgFundo ?>;border-radius:6px;padding:8px 12px">
                  <div style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:<?= $asgBorda ?>;margin-bottom:6px">
                    <i data-lucide="pen-tool" width="13" height="13"></i>
                    Assinatura eletrônica
                    <?php if ($asg['status'] === 'a_enviar'): ?>
                      <span style="font-weight:400;color:#888">· na fila de envio</span>
                    <?php elseif ($asgConcluida): ?>
                      <span style="font-weight:400;color:#00A960">· concluída</span>
                    <?php endif; ?>
                  </div>

                  <?php foreach ($asg['signatarios'] as $s):
                    $st = $asgStatusLabel[$s['status']] ?? ['txt' => $s['status'], 'cor' => '#888888'];
                    $ehCopia = ($s['natureza'] ?? 'assina') === 'copia';
                  ?>
                  <div style="display:flex;gap:8px;padding:6px 0;border-top:1px solid #F0F0F0">
                    <span style="width:7px;height:7px;border-radius:50%;background:<?= $st['cor'] ?>;flex-shrink:0;margin-top:5px"></span>
                    <div style="min-width:0;flex:1">
                      <!-- Uma informação por linha: nomes compostos e e-mails
                           longos quebravam feio quando tudo dividia a mesma
                           linha, e o papel ficava espremido. -->
                      <div style="font-size:12px;font-weight:700;color:#141922;line-height:1.5">
                        <?= h($s['papel']) ?>
                      </div>
                      <div style="font-size:12.5px;color:#141922;line-height:1.5">
                        <?= h($s['nome']) ?>
                      </div>
                      <div style="font-size:11.5px;color:#888888;line-height:1.5;word-break:break-all">
                        <?= h($s['email']) ?>
                      </div>
                      <div style="font-size:11.5px;font-weight:700;color:<?= $st['cor'] ?>;line-height:1.5">
                        <?= $ehCopia ? 'recebe cópia' : h($st['txt']) ?>
                        <?php if ($s['status'] === 'assinado' && !empty($s['assinado_em'])): ?>
                          <span style="font-weight:400;color:#888">· <?= date('d/m/Y H:i', strtotime((string) $s['assinado_em'])) ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if (($s['entrega_status'] ?? '') === 'failed'): ?>
                      <div style="font-size:11.5px;color:#B3261E;line-height:1.5">
                        O convite não foi entregue<?= !empty($s['entrega_erro']) ? ': ' . h($s['entrega_erro']) : '' ?>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php endforeach; ?>

                  <?php if (!empty($asg['motivo_recusa'])): ?>
                  <div style="font-size:12px;color:#B3261E;background:#FFE8EE;border-radius:4px;padding:4px 8px;margin-top:6px">
                    <?= h($asg['motivo_recusa']) ?>
                  </div>
                  <?php endif; ?>

                  <?php if (!empty($asg['erro']) && !$asgConcluida): ?>
                  <div style="font-size:11.5px;color:#FF8A2D;margin-top:6px">
                    Última tentativa falhou: <?= h($asg['erro']) ?>
                  </div>
                  <?php endif; ?>

                  <?php if ($urlVerif !== null): ?>
                  <div style="margin-top:6px;font-size:11.5px">
                    <a href="<?= h($urlVerif) ?>" target="_blank" rel="noopener noreferrer"
                       style="color:#00A960;font-weight:700;text-decoration:none">
                      Verificar autenticidade do documento &rsaquo;
                    </a>
                    <span style="color:#BBB">· confere direto no provedor, sem depender deste sistema</span>
                  </div>
                  <?php elseif ($asgConcluida): ?>
                  <div style="margin-top:6px;font-size:11.5px;color:#888">
                    O link de verificação está impresso na última página do PDF assinado.
                  </div>
                  <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($etapa['motivo_devolucao'])): ?>
                <div style="font-size:13.5px;color:#FF8A2D;background:#FFF3E0;border-radius:4px;padding:4px 8px;margin-top:6px">
                  Motivo: <?= h($etapa['motivo_devolucao']) ?>
                </div>
                <?php endif; ?>
                <?php
                $isEtapaReatribuivelSelf  = $isVoce && !empty($membrosReatribuicao) && Permissions::can('tarefas', 'reatribuir');
                $isEtapaReatribuivelAdmin = $isActive && $isReatribuicaoAdmin && $etapaAdmin && $etapa['id'] === $etapaAdmin['id'] && !empty($membrosReatribuicaoAdmin);
                ?>
                <?php if ($isEtapaReatribuivelSelf || $isEtapaReatribuivelAdmin): ?>
                <button type="button" onclick="abrirModalReatribuir()"
                        style="display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:4px 12px;border-radius:4px;background:#fff;border:1.5px solid var(--color-gray-light);color:var(--color-gray-dark);font-size:13.5px;font-family:var(--font-bold);cursor:pointer;transition:all 0.15s"
                        onmouseover="this.style.borderColor='var(--color-primary)';this.style.color='var(--color-primary)'" onmouseout="this.style.borderColor='var(--color-gray-light)';this.style.color='var(--color-gray-dark)'">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="display:inline-block"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                  Reatribuir responsável<?= $isEtapaReatribuivelAdmin ? ' (admin)' : '' ?>
                </button>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

        </div>
      </div><!-- /ws-tab-fluxo -->

      <!-- ═══ ABA: HISTÓRICO ═══ -->
      <div class="ws-panel ws-panel--flex" id="ws-tab-historico">
        <div class="ws-panel__inner">
          <?php if (empty($historico)): ?>
          <div style="text-align:center;padding:32px;color:var(--color-gray-medium);font-size:13.5px">Nenhum histórico registrado até o momento.</div>
          <?php else: ?>
          <div style="font-size:13.5px;font-family:var(--font-bold);color:var(--color-gray-medium);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:12px;display:flex;align-items:center;gap:8px">
            Histórico do processo
            <span style="flex:1;height:1px;background:#F0F0F0"></span>
          </div>
          <?php
          // Mapa de nomes de etapa por numero_etapa
          $etapaNomeMap = [];
          foreach ($etapas as $_et) {
              $etapaNomeMap[(int)$_et['numero_etapa']] = $_et['nome_etapa'];
          }

          $acaoIconMap = [
              'processo_aberto'      => ['bg' => '#F6F6F6',  'color' => '#888888', 'icon' => 'file'],
              'processo_criado'      => ['bg' => '#F6F6F6',  'color' => '#888888', 'icon' => 'file'],
              'etapa_aprovada'       => ['bg' => '#D8F5E5',  'color' => '#00A960', 'icon' => 'check'],
              'processo_devolvido'   => ['bg' => '#FFE8EE',  'color' => '#B3261E', 'icon' => 'return'],
              'processo_aprovado'    => ['bg' => '#D8F5E5',  'color' => '#00A960', 'icon' => 'check-double'],
              'processo_reenviado'   => ['bg' => '#E3F0FF',  'color' => '#1565C0', 'icon' => 'arrow'],
              'processo_cancelado'   => ['bg' => '#F6F6F6',  'color' => '#888888', 'icon' => 'x'],
              'arquivo_substituido'  => ['bg' => '#FFF3E0',  'color' => '#FF8A2D', 'icon' => 'replace'],
              'arquivo_enviado'      => ['bg' => '#E3F0FF',  'color' => '#1565C0', 'icon' => 'upload'],
              'arquivo_excluido'     => ['bg' => '#FFE8EE',  'color' => '#B3261E', 'icon' => 'x'],
              'etapa_avancada'       => ['bg' => '#E3F0FF',  'color' => '#1565C0', 'icon' => 'arrow'],
              'etapa_reaberta'       => ['bg' => '#FFF3E0',  'color' => '#FF8A2D', 'icon' => 'return'],
              'responsavel_reatribuido' => ['bg' => '#E3F0FF', 'color' => '#1565C0', 'icon' => 'users'],
              'cancelamento_desfeito'  => ['bg' => '#E3F0FF', 'color' => '#1565C0', 'icon' => 'arrow'],
              'cancelamento_solicitado' => ['bg' => '#FFF3E0', 'color' => '#FF8A2D', 'icon' => 'x'],
              'processo_visualizado'    => ['bg' => '#F6F6F6', 'color' => '#888888', 'icon' => 'dot'],
              'fluxo_alterado'          => ['bg' => '#EEF2FD', 'color' => '#2347C8', 'icon' => 'arrow'],
              'processo_encerrado_apollo' => ['bg' => '#D8D4E4', 'color' => '#606060', 'icon' => 'check-double'],
          ];

          // Determinar se precisa de evento "Aguardando" no fim
          $showAguardando = !$isConcluido && !$isCancelado && !$isCancSolic && !$isEncerrado;

          foreach ($historico as $i => $hi):
            $acao = $hi['acao'] ?? '';
            $hi_s = $acaoIconMap[$acao] ?? ['bg' => '#F6F6F6', 'color' => '#888888', 'icon' => 'dot'];
            $isLast = $i === count($historico) - 1 && !$showAguardando;

            // Nome da etapa para enriquecer label
            $hiEtapaNome = '';
            if (!empty($hi['numero_etapa']) && isset($etapaNomeMap[(int)$hi['numero_etapa']])) {
                $hiEtapaNome = $etapaNomeMap[(int)$hi['numero_etapa']];
            }

            // Ações que usam label fixo (detalhe exibido como nota separada)
            $usarDetalheComoLabel = !empty($hi['detalhe'])
                && $acao !== 'processo_visualizado'
                && $acao !== 'responsavel_reatribuido'
                && $acao !== 'processo_devolvido'
                && $acao !== 'arquivo_substituido'
                && $acao !== 'arquivo_enviado'
                && $acao !== 'cancelamento_solicitado'
                && $acao !== 'etapa_avancada'
                && $acao !== 'etapa_reaberta';

            $label = ($usarDetalheComoLabel ? $hi['detalhe'] : null) ?: match(true) {
                $acao === 'processo_aberto'       => 'Processo aberto',
                $acao === 'processo_criado'       => 'Processo aberto',
                $acao === 'etapa_aprovada'        => 'Aprovado' . ($hiEtapaNome ? ' — ' . $hiEtapaNome : ''),
                $acao === 'processo_devolvido'    => 'Devolvido' . ($hiEtapaNome ? ' — ' . $hiEtapaNome : ''),
                $acao === 'processo_aprovado'     => 'Processo concluído',
                $acao === 'processo_reenviado'    => 'Processo reenviado',
                $acao === 'processo_cancelado'    => 'Processo cancelado',
                $acao === 'arquivo_substituido'   => 'Arquivo substituído',
                $acao === 'arquivo_enviado'       => 'Arquivo enviado',
                $acao === 'etapa_avancada'        => 'Encaminhado' . ($hiEtapaNome ? ' para ' . $hiEtapaNome : ''),
                $acao === 'etapa_reaberta'        => 'Retornou' . ($hiEtapaNome ? ' para ' . $hiEtapaNome : ''),
                $acao === 'cancelamento_desfeito'  => 'Solicitação de cancelamento desfeita',
                $acao === 'cancelamento_solicitado' => 'Cancelamento solicitado',
                $acao === 'responsavel_reatribuido' => 'Responsável reatribuído',
                $acao === 'fluxo_alterado'        => 'Fluxo alterado',
                $acao === 'processo_visualizado'  => 'Visualizado',
                $acao === 'processo_encerrado_apollo' => 'Processo encerrado',
                default => $acao,
            };

            $labelColor = match(true) {
                str_contains($acao, 'aprovad') || $acao === 'processo_aprovado' => '#00A960',
                str_contains($acao, 'devolvid') => '#B3261E',
                $acao === 'etapa_reaberta' => '#FF8A2D',
                $acao === 'processo_cancelado' => '#888888',
                default => 'var(--color-dark)',
            };
          ?>
          <div style="display:flex;gap:10px;padding:8px 0;<?= !$isLast ? 'border-bottom:1px solid #F6F6F6;' : '' ?>">
            <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0">
              <div style="width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:<?= $hi_s['bg'] ?>;color:<?= $hi_s['color'] ?>;flex-shrink:0">
                <?php if ($hi_s['icon'] === 'check' || $hi_s['icon'] === 'check-double'): ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" style="display:inline-block"><polyline points="20 6 9 17 4 12"/></svg>
                <?php elseif ($hi_s['icon'] === 'return'): ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="display:inline-block"><path d="M9 14l-4-4 4-4"/><path d="M5 10h11a4 4 0 010 8h-1"/></svg>
                <?php elseif ($hi_s['icon'] === 'x'): ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="display:inline-block"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <?php elseif ($hi_s['icon'] === 'file'): ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <?php elseif ($hi_s['icon'] === 'replace' || $hi_s['icon'] === 'users'): ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="display:inline-block"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                <?php elseif ($hi_s['icon'] === 'upload' || $hi_s['icon'] === 'arrow'): ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="display:inline-block"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <?php else: ?>
                <svg width="8" height="8" viewBox="0 0 24 24" fill="currentColor" style="display:inline-block"><circle cx="12" cy="12" r="5"/></svg>
                <?php endif; ?>
              </div>
              <?php if (!$isLast): ?>
              <div style="width:2px;flex:1;background:#F0F0F0;min-height:8px;margin-top:2px"></div>
              <?php endif; ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13.5px;font-family:var(--font-bold);color:<?= $labelColor ?>"><?= h($label) ?></div>
              <div style="font-size:13.5px;color:var(--color-gray-medium);margin-top:2px">
                <?= h($hi['ator_nome'] ?? $hi['ator_nome_real'] ?? '—') ?> ·
                <?= date('d/m/Y H:i', strtotime($hi['criado_em'])) ?>
              </div>
              <?php if ($acao === 'processo_devolvido' && !empty($hi['detalhe'])): ?>
              <div style="font-size:13.5px;color:var(--color-warning);background:#FFF3E0;border-radius:4px;padding:4px 8px;margin-top:5px;line-height:1.4">
                <?= h($hi['detalhe']) ?>
              </div>
              <?php endif; ?>
              <?php if (($acao === 'arquivo_substituido' || $acao === 'arquivo_enviado') && !empty($hi['detalhe'])): ?>
              <div style="font-size:13.5px;color:var(--color-dark);background:#F6F6F6;border-radius:4px;padding:3px 8px;margin-top:4px;display:inline-flex;align-items:center;gap:4px">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                <?= h($hi['detalhe']) ?>
              </div>
              <?php endif; ?>
              <?php if ($acao === 'responsavel_reatribuido' && !empty($hi['detalhe'])): ?>
              <div style="font-size:13.5px;color:#1565C0;background:#E3F0FF;border-radius:4px;padding:4px 8px;margin-top:4px">
                <?= h($hi['detalhe']) ?>
              </div>
              <?php endif; ?>
              <?php if ($acao === 'cancelamento_solicitado' && !empty($hi['detalhe'])): ?>
              <div style="font-size:13.5px;color:var(--color-warning);background:#FFF3E0;border-radius:4px;padding:4px 8px;margin-top:5px;line-height:1.4">
                <?= h($hi['detalhe']) ?>
              </div>
              <?php endif; ?>
              <?php if ($acao === 'etapa_avancada' && !empty($hi['detalhe'])): ?>
              <div style="font-size:13.5px;color:var(--color-gray-dark);background:#F6F6F6;border-radius:4px;padding:3px 8px;margin-top:4px">
                <?= h($hi['detalhe']) ?>
              </div>
              <?php endif; ?>
              <?php if ($acao === 'etapa_reaberta' && !empty($hi['detalhe'])): ?>
              <div style="font-size:13.5px;color:var(--color-warning);background:#FFF3E0;border-radius:4px;padding:4px 8px;margin-top:5px;line-height:1.4">
                <?= h($hi['detalhe']) ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>

          <?php if ($showAguardando): ?>
          <div style="display:flex;gap:10px;padding:8px 0">
            <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0">
              <div style="width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#EEF2FD;flex-shrink:0">
                <svg width="8" height="8" viewBox="0 0 24 24" fill="var(--color-primary)" style="display:inline-block"><circle cx="12" cy="12" r="5"/></svg>
              </div>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13.5px;font-family:var(--font-bold);color:var(--color-primary)">Aguardando — <?= h($etapaAtualNome) ?></div>
              <div style="font-size:13.5px;color:var(--color-gray-medium);margin-top:2px">Desde <?= date('d/m/Y', strtotime($historico[0]['criado_em'] ?? $processo['criado_em'])) ?></div>
            </div>
          </div>
          <?php endif; ?>

          <?php endif; ?>
        </div>
      </div><!-- /ws-tab-historico -->

      <!-- ═══ ABA: OBSERVAÇÕES ═══ -->

    </div><!-- /ws-body -->

    <?php
    // ── Checklist de exigências do fluxo ──
    // Só aparece quando FALTA alguma coisa. Com tudo atendido o bloco some:
    // aviso verde de "está tudo certo" é ruído — a ausência já diz isso.
    $chkOkCount   = count($checkOk);
    $chkPendCount = $checkTotal - $chkOkCount;
    ?>
    <?php if ($temChecklist && $chkPendCount > 0): ?>
    <?php
    // Aberto automaticamente quando algum item trava a aprovação desta etapa;
    // caso contrário fica recolhido, só com o placar (atendidos/total).
    if (!empty($checkBloqueia)) {
        $chkCor    = ['bg' => '#FFE8EE', 'border' => '#B3261E', 'txt' => '#C0044D'];
        $chkTitulo = count($checkBloqueia) === 1
            ? 'Falta 1 item obrigatório para aprovar esta etapa'
            : 'Faltam ' . count($checkBloqueia) . ' itens obrigatórios para aprovar esta etapa';
    } else {
        $chkCor    = ['bg' => '#FFF3E0', 'border' => '#FF8A2D', 'txt' => '#C75B00'];
        $chkTitulo = 'Requisitos do fluxo — ' . $chkPendCount . ' pendente(s)';
    }
    $chkLista = array_merge($checkBloqueia, $checkDepois, $checkOk);
    ?>
    <div style="margin:0 20px 12px">
      <details id="ws-checklist" <?= !empty($checkBloqueia) ? 'open' : '' ?>
               style="background:<?= $chkCor['bg'] ?>;border-left:4px solid <?= $chkCor['border'] ?>;border-radius:8px">
        <summary style="list-style:none;cursor:pointer;padding:11px 14px;display:flex;align-items:center;gap:10px">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="<?= $chkCor['border'] ?>" stroke-width="2.2" style="display:block;flex-shrink:0">
            <?php if (!empty($checkBloqueia)): ?><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            <?php else: ?><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/><?php endif; ?>
          </svg>
          <span style="flex:1;font-size:13px;font-family:var(--font-bold);color:<?= $chkCor['txt'] ?>;line-height:1.4"><?= h($chkTitulo) ?></span>
          <span style="font-size:11.5px;font-family:var(--font-bold);color:<?= $chkCor['txt'] ?>;opacity:0.85;flex-shrink:0"><?= $chkOkCount ?>/<?= $checkTotal ?></span>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="<?= $chkCor['txt'] ?>" stroke-width="2.5" style="display:block;flex-shrink:0"><polyline points="6 9 12 15 18 9"/></svg>
        </summary>

        <div style="padding:2px 14px 12px 14px;display:flex;flex-direction:column;gap:5px">
          <?php foreach ($chkLista as $itemChk):
            $chkFeito  = !empty($itemChk['ok']);
            $chkTrava  = !$chkFeito && (int) $itemChk['etapa'] === $etapaAtualNum;
            $chkEtapa  = (int) $itemChk['etapa'];
            $chkIconeC = $chkFeito ? '#00A960' : ($chkTrava ? '#B3261E' : '#FF8A2D');
          ?>
          <div style="display:flex;align-items:center;gap:8px;border-radius:6px;padding:7px 10px;background:<?= $chkTrava ? 'rgba(248,12,91,0.10)' : 'rgba(255,255,255,0.65)' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="<?= $chkIconeC ?>" stroke-width="2.4" style="display:block;flex-shrink:0">
              <?php if ($chkFeito): ?><polyline points="20 6 9 17 4 12"/>
              <?php else: ?><circle cx="12" cy="12" r="9"/><?php endif; ?>
            </svg>
            <span style="flex:1;font-size:12.5px;color:#141922;<?= $chkFeito ? 'opacity:0.65' : 'font-family:var(--font-bold)' ?>">
              <?= h($itemChk['label']) ?>
              <span style="font-size:11px;color:var(--color-gray-medium);font-family:var(--font-regular)">
                · <?= $itemChk['origem'] === 'anexo' ? 'anexo' : 'dados' ?><?= $chkEtapa > 0 ? ' · etapa ' . $chkEtapa : ' · sem etapa definida' ?>
              </span>
            </span>
            <?php if ($chkTrava): ?>
            <span style="flex-shrink:0;font-size:10px;font-family:var(--font-bold);color:#C0044D;background:#FFD6E2;border-radius:8px;padding:2px 8px;letter-spacing:0.03em">EXIGIDO AGORA</span>
            <?php endif; ?>
            <?php if (!$chkFeito && $itemChk['origem'] === 'anexo' && $canAnexar): ?>
            <button type="button" data-tipo="<?= h($itemChk['label']) ?>" onclick="anexarTipoPendente(this)"
                    style="flex-shrink:0;background:#fff;border:1.5px solid var(--color-primary);color:var(--color-primary);border-radius:4px;padding:3px 10px;font-size:11px;font-family:var(--font-bold);cursor:pointer">
              Anexar
            </button>
            <?php elseif (!$chkFeito && $itemChk['origem'] === 'dados' && $podeEditarAbertura): ?>
            <a href="<?= $editUrl ?>"
               style="flex-shrink:0;background:#fff;border:1.5px solid var(--color-primary);color:var(--color-primary);border-radius:4px;padding:3px 10px;font-size:11px;font-family:var(--font-bold);text-decoration:none">
              Completar
            </a>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </details>
    </div>
    <?php endif; ?>

    <!-- ── ACTION BAR (sempre visível, conteúdo contextual) ── -->
    <div class="ws-action-bar" style="<?= ($isConcluido || $isCancelado || $isEncerrado || (!$isAprovador && !$podeCancelar && !$isCancSolic)) ? 'background:#FAFAFA;' : '' ?>">

      <?php
      // Info da etapa / estado.
      //
      // A barra só fala quando tem o que dizer. Texto de etapa saiu inclusive
      // para o APROVADOR (13/08): "Etapa 1 · Ger. TI — sua aprovação" e "Analise
      // os documentos e decida" repetiam o topo da tela e a aba Fluxo, e os
      // próprios botões já dizem o que fazer — só custavam altura.
      //
      // Continuam aqui as duas coisas que NÃO estão em outro lugar da barra:
      // o estado terminal (explica por que não há botão) e o aviso de bloqueio
      // (diz o que falta para conseguir aprovar).
      $barraLabel = '';
      $barraSub   = '';
      if ($isConcluido) {
          $barraLabel = 'Processo concluído · todas as etapas aprovadas';
      } elseif ($isEncerrado) {
          $barraLabel = 'Processo encerrado · não há mais etapas a cumprir';
      } elseif ($isCancelado) {
          $barraLabel = 'Processo cancelado';
      } elseif ($isCancSolic) {
          $barraLabel = 'Cancelamento solicitado · aguardando confirmação';
      }
      if ($bloqueiaAprovacao) {
          $barraSub = '';   // o bloqueio é mostrado em destaque, abaixo
      }
      $temTextoBarra = ($barraLabel !== '' || $bloqueiaAprovacao);
      ?>
      <?php if ($temTextoBarra): ?>
      <div class="ws-action-bar__step">
        <div class="ws-action-bar__dot" style="background:<?php
          if ($isConcluido) echo '#00A960';
          elseif ($isEncerrado) echo 'var(--color-gray-dark)';
          elseif ($isCancelado) echo 'var(--color-gray-medium)';
          elseif ($isCancSolic) echo 'var(--color-warning)';
          elseif ($isAprovador) echo 'var(--color-primary)';
          elseif ($isTeste) echo '#856404';
          else echo 'var(--color-gray-medium)';
        ?>"></div>
        <div class="ws-action-bar__info">
          <?php // title: com o texto truncando em uma linha, o conteúdo inteiro
                // continua acessível no hover. ?>
          <?php if ($barraLabel !== ''): ?>
          <div class="ws-action-bar__label" title="<?= h($barraLabel) ?>"><?= h($barraLabel) ?></div>
          <?php endif; ?>
          <?php if ($bloqueiaAprovacao): ?>
          <div class="ws-action-bar__sub">
            <span style="color:#C0044D;font-family:var(--font-bold)">Falta para aprovar: <?= h(implode(' · ', array_column($checkBloqueia, 'label'))) ?></span>
          </div>
          <?php elseif ($barraSub !== ''): ?>
          <div class="ws-action-bar__sub"><?= h($barraSub) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Botões de ação (contextuais) -->
      <div class="ws-action-bar__btns">

        <?php /* Ações secundárias: só ícone + tooltip (data-tip). O rótulo vai
                 no aria-label para leitor de tela — sem ele o botão fica mudo. */ ?>
        <?php if ($podeEditarAbertura): ?>
        <a href="<?= $editUrl ?>" class="ws-btn ws-btn--icon ws-btn--secondary"
           style="text-decoration:none; border-color:var(--color-primary); color:var(--color-primary)"
           aria-label="Completar dados"
           data-tip="Completar dados — parcelas, forma de pagamento, tipo de despesa e centro de custo">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </a>
        <?php endif; ?>

        <?php if ($podeEditarDados): ?>
        <a href="<?= $editDadosUrl ?>" class="ws-btn ws-btn--icon ws-btn--secondary"
           style="text-decoration:none"
           aria-label="Editar campos"
           data-tip="Editar campos — dados da nota, campos do fluxo e parcelas; fica registrado no histórico">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
        </a>
        <?php endif; ?>

        <?php if ($podePreencherEtapa): ?>
        <a href="<?= $preencherEtapaUrl ?>" class="ws-btn ws-btn--icon ws-btn--secondary"
           style="text-decoration:none"
           aria-label="Preencher dados desta etapa"
           data-tip="Preencher dados desta etapa — campos que o fluxo pede aqui, antes de aprovar">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
        </a>
        <?php endif; ?>

        <?php if ($podeResolverCancSolic): ?>
        <button type="button" class="ws-btn ws-btn--icon ws-btn--devolver" onclick="abrirModalDesfazerCanc()"
                style="border-color:var(--color-warning);color:var(--color-warning)"
                aria-label="Desfazer solicitação de cancelamento"
                data-tip="Desfazer solicitação de cancelamento">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
        </button>
        <?php endif; ?>

        <?php
        // Com solicitação pendente o mesmo botão CONFIRMA o cancelamento pedido —
        // é a contrapartida do "desfazer" ao lado. ($podeCancelar já é falso
        // nesse estado, e $podeResolverCancSolic só é verdadeiro nele.)
        $rotuloCancelar = $isCancSolic ? 'Confirmar cancelamento solicitado' : 'Cancelar processo';
        ?>
        <?php if (($podeCancelar || $podeResolverCancSolic) && !$isCancelado && !$isConcluido): ?>
        <button type="button" class="ws-btn ws-btn--icon ws-btn--cancelar" onclick="abrirModalCancelamento()"
                aria-label="<?= h($rotuloCancelar) ?>" data-tip="<?= h($rotuloCancelar) ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
        </button>
        <?php endif; ?>

        <?php if ($isTeste && $isAprovador && $podeDescartarTeste && !$isConcluido && !$isCancelado): ?>
        <button type="button" class="ws-btn ws-btn--icon ws-btn--ghost" onclick="abrirModalDescartar()"
                aria-label="Descartar teste" data-tip="Descartar teste">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
        </button>
        <?php endif; ?>

        <?php if ($isGrupoEtapa && $podeAssumir && !$isCancSolic && !$isCancelado): ?>
        <!-- Etapa de grupo: primeiro é preciso ASSUMIR (tira o processo da fila
             do grupo e passa para o usuário), só então liberam-se as ações. -->
        <form method="post" action="?module=tarefas&amp;action=assumir<?= $embedFormQS . $backFormQS ?>" style="display:inline" id="form-assumir"
              onsubmit="return confirmarAcaoComToast(this, 'Tarefa assumida. O processo agora é seu.')">
          <?= Csrf::field() ?>
          <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
          <input type="hidden" name="etapa_id" value="<?= h($etapaAprovavel['id']) ?>">
          <button type="submit" class="ws-btn ws-btn--aprovar" title="Assume o processo para você — sai da fila do grupo e ninguém mais pega em paralelo">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Assumir tarefa
          </button>
        </form>
        <?php elseif ($isAprovador && !$isCancSolic && !$isCancelado): ?>
        <?php if ($podeDevolver): ?>
        <button type="button" class="ws-btn ws-btn--icon ws-btn--devolver" onclick="abrirModalDevolucao()"
                aria-label="Devolver" data-tip="Devolver — o processo volta para correção">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block"><path d="M9 14l-4-4 4-4"/><path d="M5 10h11a4 4 0 010 8h-1"/></svg>
        </button>
        <?php endif; ?>
        <?php if ($podeAprovar): ?>
        <?php
        // Última etapa: o processo é concluído, não "encaminhado pra próxima etapa"
        if ($etapaAtualNum >= $totalEtapas) {
            $msgAprovar = $temFinanceiro
                ? 'Etapa aprovada com sucesso. Processo concluído — encaminhado para o Controle Financeiro.'
                : 'Etapa aprovada com sucesso. Processo concluído.';
        } else {
            $msgAprovar = 'Etapa aprovada com sucesso. O processo foi encaminhado para a próxima etapa.';
        }
        ?>
        <?php if ($bloqueiaAprovacao): ?>
        <!-- Etapa com item obrigatório em falta: o gate no servidor recusaria a
             aprovação, então o botão já avisa o que falta em vez de deixar o
             usuário descobrir pelo erro. -->
        <button type="button" class="ws-btn ws-btn--aprovar" onclick="avisarPendenciasEtapa()"
                title="Há item obrigatório pendente nesta etapa — veja o checklist acima"
                style="background:#9AD9BB;border-color:#9AD9BB;cursor:not-allowed">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block"><polyline points="20 6 9 17 4 12"/></svg>
          Aprovar
        </button>
        <?php else: ?>
        <form method="post" action="?module=processos&amp;action=aprovar<?= $embedFormQS . $backFormQS ?>" style="display:inline" id="form-aprovar" onsubmit="return confirmarAcaoComToast(this, '<?= h(addslashes($msgAprovar)) ?>')">
          <?= Csrf::field() ?>
          <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
          <input type="hidden" name="etapa_id" value="<?= h($etapaAprovavel['id']) ?>">
          <button type="submit" class="ws-btn ws-btn--aprovar">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block"><polyline points="20 6 9 17 4 12"/></svg>
            Aprovar
          </button>
        </form>
        <?php endif; ?>
        <?php endif; /* podeAprovar */ ?>
        <?php endif; ?>

      </div>
    </div>

  </div><!-- /ws-ctx -->

</div><!-- /ws -->

<!-- ═══ MODAL: DEVOLUÇÃO ═══ -->
<?php if ($etapaAprovavel): ?>
<div id="modal-devolucao" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:440px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0 0 4px">Devolver processo</h3>
    <p style="font-size:13.5px;color:var(--color-gray-medium);margin:0 0 16px;line-height:1.5">Escolha para quem o processo deve voltar para correção.</p>

    <form method="post" action="?module=processos&amp;action=devolver<?= $embedFormQS . $backFormQS ?>" id="form-devolucao">
      <?= Csrf::field() ?>
      <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
      <input type="hidden" name="etapa_id" value="<?= h($etapaAprovavel['id']) ?>">

      <?php
      // Destinos = pessoas que já agiram no fluxo (montadas no controller).
      // O value é o ID da pessoa: o model é quem traduz para o número da
      // etapa, então a etapa nunca chega pelo formulário.
      $pessoasDevolucao = $pessoasDevolucao ?? [];
      ?>
      <?php if ($pessoasDevolucao): ?>
      <label style="display:block;font-size:13.5px;font-family:var(--font-bold);color:var(--color-gray-dark);margin-bottom:6px">
        Devolver para <span style="color:var(--color-danger)">*</span>
      </label>
      <select name="usuario_destino" required
              style="width:100%;height:40px;border:1px solid #DFDFDF;border-radius:4px;padding:0 12px;font-size:13.5px;color:var(--color-dark);background:#fff;margin-bottom:14px;box-sizing:border-box">
        <?php foreach ($pessoasDevolucao as $i => $pd): ?>
        <option value="<?= h($pd['usuario_id']) ?>" <?= $i === 0 ? 'selected' : '' ?>>
          <?= h($pd['nome']) ?><?= $pd['origem'] === 'solicitante' ? ' · solicitante' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
      <p style="font-size:11px;color:var(--color-gray-medium);margin:-8px 0 14px">
        O processo volta para essa pessoa e refaz o caminho a partir dali — quem estiver no meio aprova de novo.
      </p>
      <?php endif; ?>

      <label style="display:block;font-size:13.5px;font-family:var(--font-bold);color:var(--color-gray-dark);margin-bottom:6px">
        Motivo da Devolução <span style="color:var(--color-danger)">*</span>
      </label>
      <textarea name="motivo" id="motivo_devolucao" rows="4" required minlength="10"
                placeholder="Descreva o que precisa ser corrigido..."
                style="width:100%;border:1px solid #DFDFDF;border-radius:4px;padding:10px 12px;font-size:13.5px;font-family:inherit;color:var(--color-dark);box-sizing:border-box;outline:none;resize:vertical;transition:border-color 0.15s"
                onfocus="this.style.borderColor='var(--color-primary)'" onblur="this.style.borderColor='#DFDFDF'"></textarea>
      <div id="motivo-erro" style="display:none;font-size:13.5px;color:var(--color-danger);margin-top:4px"></div>

      <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
        <button type="button" onclick="fecharModalDevolucao()" style="padding:7px 16px;background:#fff;border:1.5px solid #D0D0D0;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:var(--color-dark)">Cancelar</button>
        <button type="button" onclick="submeterDevolucao()" style="padding:7px 18px;border:none;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:#fff;background:var(--color-warning);transition:background 0.15s">Confirmar devolução</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL: SUBSTITUIR/ENVIAR DOCUMENTO ═══ -->
<div id="modal-substituir" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:400px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0 0 4px;display:flex;align-items:center;gap:8px">
      <span style="width:28px;height:28px;border-radius:50%;background:#EEF2FD;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2.5" style="display:inline-block"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      </span>
      <span id="sub-modal-title">Substituir documento</span>
    </h3>
    <p style="font-size:13.5px;color:var(--color-gray-medium);margin:4px 0 16px;line-height:1.5" id="sub-modal-sub"><span id="sub-modal-sub-prefix">Substituindo:</span> <b id="sub-doc-name">—</b></p>

    <form method="post" action="?module=processos&amp;action=substituir_anexo<?= $embedFormQS . $backFormQS ?>" id="form-substituir" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
      <input type="hidden" name="tipo_anexo_nome" id="sub-tipo-nome" value="">
      <input type="hidden" name="modo" id="sub-modo" value="adicionar">
      <input type="hidden" name="anexo_id" id="sub-anexo-id" value="">

      <!-- Seleção do tipo (modo "Anexar" — oculto quando vem de substituição direta) -->
      <div id="sub-tipo-wrap" style="display:none;margin-bottom:12px">
        <label style="display:block;font-size:11.5px;font-family:var(--font-bold);color:var(--color-gray-dark);margin-bottom:6px">
          Tipo de documento <span style="color:#B3261E">*</span>
        </label>
        <select id="sub-tipo-select" onchange="subTipoEscolhido(this)"
                style="width:100%;height:40px;padding:0 12px;border:1px solid #DFDFDF;border-radius:4px;font-size:12.5px;background:#fff">
          <option value="">Selecione o tipo...</option>
          <?php foreach ($documentos as $doc): ?>
          <option value="<?= h($doc['tipo_nome']) ?>"
                  data-tipodoc="<?= h($doc['tipo_doc'] ?? 'qualquer') ?>"
                  data-pendente="<?= $doc['pendente'] ? '1' : '0' ?>">
            <?= h($doc['tipo_nome']) ?><?= $doc['obrigatorio'] ? ' *' : '' ?><?= $doc['pendente'] ? ' — pendente' : ' — ' . count($doc['anexos']) . ' anexado(s)' ?><?= !empty($doc['etapa_ordem']) && $doc['obrigatorio'] ? ' · exigido na etapa ' . (int)$doc['etapa_ordem'] : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Dropzone -->
      <div id="sub-dropzone"
           style="border:2px dashed #DFDFDF;border-radius:8px;padding:24px 16px;text-align:center;cursor:pointer;transition:border-color 0.15s,background 0.15s;background:#FAFAFA;margin-bottom:12px"
           onclick="document.getElementById('sub-file-input').click()">
        <input type="file" name="arquivo[]" id="sub-file-input" multiple style="display:none" onchange="subFileSelected(this)">
        <div style="width:36px;height:36px;border-radius:50%;background:#EEF2FD;display:flex;align-items:center;justify-content:center;margin:0 auto 8px">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2.5" style="display:inline-block"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        </div>
        <div style="font-size:13.5px;font-weight:700;font-family:var(--font-bold);color:var(--color-dark);margin-bottom:3px">Clique ou arraste o arquivo</div>
        <div style="font-size:13.5px;color:var(--color-gray-medium)" id="sub-hint">PDF, JPG, PNG · máx. 10 MB</div>
      </div>

      <!-- Preview do arquivo selecionado -->
      <div id="sub-file-preview" style="display:none;align-items:center;gap:10px;padding:10px 12px;background:#D8F5E5;border:1px solid #A8E6C1;border-radius:8px;margin-bottom:12px">
        <div style="width:24px;height:24px;border-radius:6px;background:#A8E6C1;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#00A960" stroke-width="2.5" style="display:inline-block"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <span style="font-size:13.5px;font-family:var(--font-bold);color:#00703C;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" id="sub-fp-name">—</span>
        <span style="font-size:13.5px;color:var(--color-gray-medium);flex-shrink:0" id="sub-fp-size"></span>
        <button type="button" onclick="subClearFile()" style="width:20px;height:20px;border-radius:50%;border:none;background:#A8E6C1;color:#00A960;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12.5px" title="Remover">✕</button>
      </div>

      <div style="display:flex;align-items:flex-start;gap:6px;padding:8px 10px;background:#E3F0FF;border-radius:6px;font-size:13.5px;color:#1565C0;line-height:1.5;margin-bottom:12px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span id="sub-info-text">O envio ficará registrado no histórico do processo.</span>
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" onclick="fecharModalSubstituir()" style="padding:7px 16px;background:#fff;border:1.5px solid #D0D0D0;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:var(--color-dark)">Cancelar</button>
        <button type="submit" id="sub-confirm-btn" disabled style="padding:7px 18px;border:none;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:#fff;background:var(--color-primary);transition:background 0.15s;opacity:0.45">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="display:inline-block;margin-right:4px"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          <span id="sub-confirm-label">Confirmar</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Form oculto: excluir um anexo específico -->
<form method="post" action="?module=processos&amp;action=excluir_anexo<?= $embedFormQS . $backFormQS ?>" id="form-excluir-anexo" style="display:none">
  <?= Csrf::field() ?>
  <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
  <input type="hidden" name="anexo_id" id="excluir-anexo-id" value="">
</form>

<!-- ═══ MODAL 1: CANCELAMENTO SIMPLES (sem parcelas) ═══ -->
<?php if ($podeCancelar || $podeResolverCancSolic): ?>
<div id="modal-cancelar-simples" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:440px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0 0 4px"><?= $isCancSolic ? 'Confirmar cancelamento' : 'Cancelar processo' ?></h3>
    <p style="font-size:13.5px;color:var(--color-gray-medium);margin:0 0 16px;line-height:1.5">
      <?php if ($isCancSolic): ?>
      O cancelamento de <b><?= h($processo['codigo']) ?></b> foi solicitado e será efetivado agora. Informe o motivo.
      <?php else: ?>
      O processo <b><?= h($processo['codigo']) ?></b> será cancelado permanentemente. Informe o motivo.
      <?php endif; ?>
    </p>
    <form method="post" action="?module=processos&amp;action=cancelar<?= $embedFormQS . $backFormQS ?>" id="form-cancelar">
      <?= Csrf::field() ?>
      <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
      <textarea name="motivo_cancelamento" rows="3" placeholder="Descreva o motivo do cancelamento..."
                style="width:100%;border:1px solid #DFDFDF;border-radius:4px;padding:10px 12px;font-size:13.5px;font-family:inherit;color:var(--color-dark);box-sizing:border-box;outline:none;resize:vertical;transition:border-color 0.15s"
                onfocus="this.style.borderColor='var(--color-primary)'" onblur="this.style.borderColor='#DFDFDF'"></textarea>
      <div style="display:flex;align-items:flex-start;gap:6px;padding:8px 10px;background:#FFE8EE;border-radius:6px;font-size:13.5px;color:var(--color-danger);line-height:1.5;margin-top:10px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Esta ação é irreversível e não pode ser desfeita.
      </div>
      <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
        <button type="button" onclick="fecharTodosModaisCancelamento()" style="padding:7px 16px;background:#fff;border:1.5px solid #D0D0D0;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:var(--color-dark)">Voltar</button>
        <button type="submit" style="padding:7px 18px;border:none;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:#fff;background:var(--color-danger);transition:background 0.15s">Cancelar Processo</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ MODAL 2: CANCELAMENTO BLOQUEADO (com parcelas, sem permissão) ═══ -->
<div id="modal-cancelar-bloqueado" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:400px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0 0 8px;display:flex;align-items:center;gap:10px">
      <span style="width:32px;height:32px;border-radius:50%;background:#FFE8EE;border:2px solid var(--color-danger);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--color-danger)" stroke-width="2.5" style="display:inline-block"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </span>
      Cancelamento bloqueado
    </h3>
    <p style="font-size:13.5px;color:var(--color-gray-medium);margin:0 0 14px;line-height:1.5">
      Este processo possui parcelamento financeiro e requer permissão especial para cancelamento.
    </p>
    <div style="background:#FFF8E1;border:1.5px solid #FFE082;border-radius:6px;padding:10px 14px;margin-bottom:12px">
      <div style="font-size:13.5px;font-family:var(--font-bold);color:#856404;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px">
        Contate o administrador
      </div>
      <div style="font-size:13.5px;color:#6D5100;line-height:1.5">
        Somente um administrador pode cancelar processos com parcelamento ativo.
      </div>
    </div>
    <div style="display:flex;align-items:flex-start;gap:6px;padding:8px 10px;background:#E3F0FF;border-radius:6px;font-size:13.5px;color:#1565C0;line-height:1.5">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Verifique com o financeiro o status das parcelas antes de solicitar o cancelamento.
    </div>
    <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
      <button type="button" onclick="fecharTodosModaisCancelamento()" style="padding:7px 18px;border:none;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:#fff;background:var(--color-primary);transition:background 0.15s">Entendi</button>
    </div>
  </div>
</div>

<!-- ═══ MODAL 3: CANCELAMENTO COM IMPACTO FINANCEIRO (admin) ═══ -->
<?php if ($podeCancelarParcelas): ?>
<div id="modal-cancelar-parcelas" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:460px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0 0 4px;display:flex;align-items:center;gap:8px">
      <span style="width:28px;height:28px;border-radius:50%;background:#FFE8EE;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--color-danger)" stroke-width="2.5" style="display:inline-block"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      </span>
      Cancelar processo — impacto financeiro
    </h3>
    <p style="font-size:13.5px;color:var(--color-gray-medium);margin:4px 0 14px;line-height:1.5">
      Verifique o impacto nas parcelas antes de solicitar o cancelamento de <b><?= h($processo['codigo']) ?></b>.
    </p>

    <!-- Resumo das parcelas -->
    <?php if (!empty($parcelas)):
      $parcelasPagas = array_filter($parcelas, fn($p) => $p['status'] === 'pago');
      $parcelasAbertas = array_filter($parcelas, fn($p) => in_array($p['status'], ['previsto','agendado','agvenc','vencido']));
      $totalPagas = array_sum(array_map(fn($p) => (float)$p['valor'], $parcelasPagas));
      $totalAbertas = array_sum(array_map(fn($p) => (float)$p['valor'], $parcelasAbertas));
    ?>
    <div style="border:1px solid var(--color-gray-light);border-radius:6px;overflow:hidden;margin-bottom:12px">
      <div style="background:#FAFAFA;padding:7px 12px;font-size:13.5px;font-family:var(--font-bold);color:var(--color-gray-medium);text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid var(--color-gray-light)">
        Impacto nas parcelas (<?= count($parcelas) ?> total)
      </div>
      <?php if (!empty($parcelasPagas)): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #FFF3E0;background:#FFFAF5">
        <span style="width:18px;height:18px;border-radius:50%;background:#FFF3E0;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="var(--color-warning)" stroke-width="2.5" style="display:inline-block"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        </span>
        <div style="flex:1">
          <div style="font-size:13.5px;font-family:var(--font-bold);color:#C75B00"><?= count($parcelasPagas) ?> <?= count($parcelasPagas) === 1 ? 'parcela paga' : 'parcelas pagas' ?> — requer revisão manual</div>
          <div style="font-size:13.5px;color:var(--color-gray-medium);margin-top:1px">Não cancelada automaticamente · notifique o financeiro</div>
        </div>
        <span style="font-size:13.5px;font-family:var(--font-bold);color:#C75B00">R$ <?= number_format($totalPagas, 2, ',', '.') ?></span>
      </div>
      <?php endif; ?>
      <?php if (!empty($parcelasAbertas)): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px">
        <span style="width:18px;height:18px;border-radius:50%;background:#FFE8EE;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="var(--color-danger)" stroke-width="2.5" style="display:inline-block"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </span>
        <div style="flex:1">
          <div style="font-size:13.5px;font-family:var(--font-bold);color:var(--color-dark)"><?= count($parcelasAbertas) ?> <?= count($parcelasAbertas) === 1 ? 'parcela em aberto' : 'parcelas em aberto' ?> — canceladas automaticamente</div>
          <div style="font-size:13.5px;color:var(--color-gray-medium);margin-top:1px">Status alterado para Cancelado pelo sistema</div>
        </div>
        <span style="font-size:13.5px;font-family:var(--font-bold);color:var(--color-gray-dark)">R$ <?= number_format($totalAbertas, 2, ',', '.') ?></span>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="post" action="?module=processos&amp;action=solicitar_cancelamento<?= $embedFormQS . $backFormQS ?>" id="form-solicitar-canc">
      <?= Csrf::field() ?>
      <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
      <textarea name="motivo_cancelamento" rows="3" placeholder="Descreva o motivo do cancelamento (obrigatório)..."
                style="width:100%;border:1px solid #DFDFDF;border-radius:4px;padding:10px 12px;font-size:13.5px;font-family:inherit;color:var(--color-dark);box-sizing:border-box;outline:none;resize:vertical;transition:border-color 0.15s"
                onfocus="this.style.borderColor='var(--color-primary)'" onblur="this.style.borderColor='#DFDFDF'"></textarea>

      <div style="display:flex;align-items:flex-start;gap:6px;padding:8px 10px;background:#FFE8EE;border-radius:6px;font-size:13.5px;color:var(--color-danger);line-height:1.5;margin-top:10px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Confirmo que verifiquei com o financeiro o status das parcelas pagas.
      </div>

      <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
        <button type="button" onclick="fecharTodosModaisCancelamento()" style="padding:7px 16px;background:#fff;border:1.5px solid #D0D0D0;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:var(--color-dark)">Voltar</button>
        <button type="submit" style="padding:7px 18px;border:none;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:#fff;background:var(--color-danger);transition:background 0.15s">Solicitar cancelamento</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ═══ MODAL: DESCARTAR TESTE ═══ -->
<?php if ($isTeste && $isAprovador): ?>
<div id="modal-descartar" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:400px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0 0 4px">Descartar processo de teste</h3>
    <p style="font-size:13.5px;color:var(--color-gray-medium);margin:0 0 16px;line-height:1.5">
      O processo <b><?= h($processo['codigo']) ?></b> e todos os dados vinculados serão removidos.
    </p>

    <div style="display:flex;align-items:flex-start;gap:6px;padding:8px 10px;background:#FFE8EE;border-radius:6px;font-size:13.5px;color:var(--color-danger);line-height:1.5">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Esta ação não pode ser desfeita.
    </div>

    <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
      <button type="button" onclick="fecharModalDescartar()" style="padding:7px 16px;background:#fff;border:1.5px solid #D0D0D0;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:var(--color-dark)">Voltar</button>
      <form method="post" action="?module=processos&amp;action=cancelar<?= $embedFormQS . $backFormQS ?>" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
        <button type="submit" style="padding:7px 18px;border:none;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:#fff;background:var(--color-danger);transition:background 0.15s">Descartar</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL: DESFAZER CANCELAMENTO ═══ -->
<?php if ($podeResolverCancSolic): ?>
<div id="modal-desfazer-canc" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:400px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0 0 4px;display:flex;align-items:center;gap:8px">
      <span style="width:28px;height:28px;border-radius:50%;background:#FFF3E0;border:1.5px solid #FFE0B2;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--color-warning)" stroke-width="2.5" style="display:inline-block"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
      </span>
      Desfazer solicitação
    </h3>
    <p style="font-size:13.5px;color:var(--color-gray-medium);margin:4px 0 16px;line-height:1.5">
      O processo voltará ao estado <b>Em andamento</b> e a solicitação de cancelamento será descartada.
    </p>

    <div style="display:flex;align-items:flex-start;gap:6px;padding:8px 10px;background:#E3F0FF;border-radius:6px;font-size:13.5px;color:#1565C0;line-height:1.5;margin-bottom:12px">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Os participantes serão notificados que a solicitação foi desfeita.
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button type="button" onclick="fecharModalDesfazerCanc()" style="padding:7px 16px;background:#fff;border:1.5px solid #D0D0D0;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:var(--color-dark)">Voltar</button>
      <form method="post" action="?module=processos&amp;action=desfazer_cancelamento<?= $embedFormQS . $backFormQS ?>" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
        <button type="submit" style="padding:7px 18px;border:none;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:#fff;background:var(--color-primary);transition:background 0.15s">Confirmar</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL: FORNECEDOR (somente visualização) ═══ -->
<?php if ($fornecedorCad):
  $fcLinhas = [
      'Razão social'   => $fornecedorCad['razao_social']  ?? '',
      'Nome fantasia'  => $fornecedorCad['nome_fantasia'] ?? '',
      'CNPJ / CPF'     => formatarDoc((string)($fornecedorCad['cnpj'] ?? '')),
      'Endereço'       => $fornecedorCad['endereco'] ?? '',
      'Cidade / UF'    => trim(($fornecedorCad['cidade'] ?? '') . (!empty($fornecedorCad['estado']) ? ' / ' . $fornecedorCad['estado'] : '')),
      'E-mail'         => $fornecedorCad['email'] ?? '',
      'Telefone'       => $fornecedorCad['telefone'] ?? '',
      'Data de abertura' => !empty($fornecedorCad['data_abertura'])
                            ? date('d/m/Y', strtotime((string)$fornecedorCad['data_abertura'])) : '',
      'Situação cadastral' => $fornecedorCad['situacao_cadastral'] ?? '',
      'Optante MEI'    => isset($fornecedorCad['optante_mei']) && $fornecedorCad['optante_mei'] !== null
                            ? ((int)$fornecedorCad['optante_mei'] === 1 ? 'Sim' : 'Não') : '',
      'Simples Nacional' => isset($fornecedorCad['optante_simples']) && $fornecedorCad['optante_simples'] !== null
                            ? ((int)$fornecedorCad['optante_simples'] === 1 ? 'Sim' : 'Não') : '',
  ];
?>
<div id="modal-forn-view" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:520px;margin:20px;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:4px">
      <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0;display:flex;align-items:center;gap:8px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="2" stroke-linecap="round" style="flex-shrink:0"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/></svg>
        Dados do fornecedor
      </h3>
      <?php if (isset($fornecedorCad['ativo'])): ?>
      <span style="font-size:10px;font-family:var(--font-bold);text-transform:uppercase;padding:4px 10px;border-radius:8px;white-space:nowrap;background:<?= (int)$fornecedorCad['ativo'] === 1 ? '#D8F5E5' : '#EAEAEA' ?>;color:<?= (int)$fornecedorCad['ativo'] === 1 ? '#00A960' : '#888888' ?>">
        <?= (int)$fornecedorCad['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
      </span>
      <?php endif; ?>
    </div>
    <p style="font-size:12px;color:var(--color-gray-medium);margin:0 0 16px">Somente visualização — para alterar, use Listas → Fornecedores.</p>

    <table style="width:100%;border-collapse:collapse;font-size:13.5px">
      <tbody>
        <?php foreach ($fcLinhas as $rotulo => $valor): ?>
        <tr style="border-bottom:1px solid #F6F6F6">
          <td style="padding:9px 0;color:var(--color-gray-medium);width:44%;vertical-align:top"><?= h($rotulo) ?></td>
          <td style="padding:9px 0;color:var(--color-dark);font-family:var(--font-bold);word-break:break-word"><?= $valor !== '' ? h($valor) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="display:flex;justify-content:flex-end;margin-top:16px">
      <button type="button" onclick="fecharFornecedorView()" style="padding:7px 18px;background:#fff;border:1.5px solid #D0D0D0;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:var(--color-dark)">Fechar</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL: ALTERAR FLUXO ═══ -->
<?php if ($podeAlterarFluxo): ?>
<div id="modal-alterar-fluxo" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:440px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0 0 4px">Alterar fluxo do processo</h3>
    <p style="font-size:13.5px;color:var(--color-gray-medium);margin:0 0 16px;line-height:1.5">
      Use quando o processo foi encaminhado para o fluxo errado. Fluxo atual: <b><?= h($processo['template_nome'] ?? '—') ?></b>.
    </p>

    <form method="post" action="?module=processos&amp;action=alterar_fluxo<?= $embedFormQS . $backFormQS ?>" id="form-alterar-fluxo">
      <?= Csrf::field() ?>
      <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">

      <label style="display:block;font-size:13.5px;font-family:var(--font-bold);color:var(--color-gray-dark);margin-bottom:6px">
        Novo fluxo (template)
      </label>
      <select name="template_id" required
              style="width:100%;height:40px;padding:0 12px;border:1px solid #DFDFDF;border-radius:4px;font-size:13.5px;font-family:inherit;color:var(--color-dark);outline:none;background:#fff;transition:border-color 0.15s"
              onfocus="this.style.borderColor='var(--color-primary)'" onblur="this.style.borderColor='#DFDFDF'">
        <option value="">— Selecione o fluxo correto —</option>
        <?php foreach ($templatesFluxo as $tf): ?>
        <?php if (($tf['versao_id'] ?? '') === ($processo['template_versao_id'] ?? '')) continue; ?>
        <option value="<?= h($tf['id']) ?>"><?= h($tf['nome']) ?></option>
        <?php endforeach; ?>
      </select>

      <label style="display:block;font-size:13.5px;font-family:var(--font-bold);color:var(--color-gray-dark);margin:14px 0 6px">
        Motivo <span style="color:#B3261E">*</span>
      </label>
      <textarea name="motivo" required rows="3" maxlength="500"
                placeholder="Ex.: Nota de veículo encaminhada para o fluxo de peças."
                style="width:100%;padding:10px 12px;border:1px solid #DFDFDF;border-radius:4px;font-size:13.5px;font-family:inherit;color:var(--color-dark);outline:none;box-sizing:border-box;resize:vertical;transition:border-color 0.15s"
                onfocus="this.style.borderColor='var(--color-primary)'" onblur="this.style.borderColor='#DFDFDF'"></textarea>

      <div style="display:flex;align-items:flex-start;gap:6px;padding:8px 10px;background:#FFF3E0;border-radius:6px;font-size:13.5px;color:#C75B00;line-height:1.5;margin-top:12px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span>O processo volta para o <b>início</b> do novo fluxo — aprovações já feitas precisarão ser refeitas nas etapas do novo fluxo. Todo o histórico é preservado na aba Histórico.</span>
      </div>

      <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
        <button type="button" onclick="fecharModalAlterarFluxo()" style="padding:7px 16px;background:#fff;border:1.5px solid #D0D0D0;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:var(--color-dark)">Cancelar</button>
        <button type="submit" style="padding:7px 18px;border:none;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:#fff;background:var(--color-primary);transition:background 0.15s">Confirmar alteração</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL: REATRIBUIÇÃO ═══ -->
<?php if (($isAprovador && !empty($membrosReatribuicao) && Permissions::can('tarefas', 'reatribuir'))
       || ($isReatribuicaoAdmin && !empty($membrosReatribuicaoAdmin))): ?>
<div id="modal-reatribuir" class="modal-overlay" role="dialog" aria-modal="true">
  <div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:400px;margin:20px;box-shadow:0 20px 60px rgba(0,0,0,0.2)">
    <h3 style="font-size:16px;font-family:var(--font-bold);color:var(--color-dark);margin:0 0 4px">Reatribuir responsável<?= $isReatribuicaoAdmin ? ' (admin)' : '' ?></h3>
    <p style="font-size:13.5px;color:var(--color-gray-medium);margin:0 0 16px;line-height:1.5">
      Selecione quem assumirá a etapa <b><?= h($etapaAtualNome) ?></b><?php if ($isReatribuicaoAdmin && !empty($etapaParaReatribuir['aprovador_nome'])): ?> — atualmente com <b><?= h($etapaParaReatribuir['aprovador_nome']) ?></b><?php endif; ?>.
    </p>
    <?php if ($isReatribuicaoAdmin): ?>
    <div style="display:flex;align-items:flex-start;gap:6px;padding:8px 10px;background:#FFF3E0;border-radius:6px;font-size:13.5px;color:#FF8A2D;line-height:1.5;margin:0 0 16px">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      Ação administrativa: você não é o responsável atual desta etapa. A troca fica registrada no histórico do processo.
    </div>
    <?php endif; ?>

    <form method="post" action="?module=processos&amp;action=reatribuir<?= $embedFormQS . $backFormQS ?>" id="form-reatribuir">
      <?= Csrf::field() ?>
      <input type="hidden" name="processo_id" value="<?= h($processo['id']) ?>">
      <input type="hidden" name="etapa_id" value="<?= h($etapaParaReatribuir['id']) ?>">

      <label style="display:block;font-size:13.5px;font-family:var(--font-bold);color:var(--color-gray-dark);margin-bottom:6px">
        Novo responsável
      </label>
      <select name="novo_aprovador_id" id="reatribuir-select" required
              style="width:100%;height:40px;padding:0 12px;border:1px solid #DFDFDF;border-radius:4px;font-size:13.5px;font-family:inherit;color:var(--color-dark);outline:none;background:#fff;transition:border-color 0.15s"
              onfocus="this.style.borderColor='var(--color-primary)'" onblur="this.style.borderColor='#DFDFDF'">
        <option value="">— Selecione <?= $isReatribuicaoAdmin && ($etapaParaReatribuir['tipo_aprovador'] ?? '') !== 'grupo' ? 'um usuário' : 'um membro' ?> —</option>
        <?php foreach ($membrosParaReatribuir as $membro): ?>
        <?php if ($membro['id'] !== $currentUser['id']): ?>
        <option value="<?= h($membro['id']) ?>"><?= h($membro['nome']) ?></option>
        <?php endif; ?>
        <?php endforeach; ?>
      </select>

      <div style="display:flex;align-items:flex-start;gap:6px;padding:8px 10px;background:#E3F0FF;border-radius:6px;font-size:13.5px;color:#1565C0;line-height:1.5;margin-top:12px">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        O novo responsável receberá uma notificação.
      </div>

      <div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
        <button type="button" onclick="fecharModalReatribuir()" style="padding:7px 16px;background:#fff;border:1.5px solid #D0D0D0;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:var(--color-dark)">Cancelar</button>
        <button type="submit" style="padding:7px 18px;border:none;border-radius:4px;font-size:13.5px;font-family:var(--font-bold);cursor:pointer;color:#fff;background:var(--color-primary);transition:background 0.15s">Confirmar reatribuição</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// ── Abas do workspace ──
function wsSetTab(tab) {
  document.querySelectorAll('.ws-tab').forEach(function(t) { t.classList.remove('active'); });
  document.querySelectorAll('.ws-panel').forEach(function(p) { p.classList.remove('active'); });
  var tabBtn = document.querySelector('.ws-tab[data-tab="' + tab + '"]');
  var tabPanel = document.getElementById('ws-tab-' + tab);
  if (tabBtn) tabBtn.classList.add('active');
  if (tabPanel) tabPanel.classList.add('active');
}

// ── Card de Observações (recolhido por padrão) ──
function obsToggle(forcar) {
  var corpo = document.getElementById('obs-corpo');
  var chev  = document.getElementById('obs-chevron');
  if (!corpo) return;
  var abrir = (typeof forcar === 'boolean') ? forcar : (corpo.style.display === 'none');
  corpo.style.display = abrir ? 'block' : 'none';
  if (chev) {
    chev.style.transform = abrir ? 'rotate(180deg)' : '';
    chev.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    chev.title = abrir ? 'Recolher observações' : 'Expandir observações';
  }
  // Aberto, mostra a observação mais recente — que é a que interessa.
  if (abrir) {
    var scroll = document.getElementById('obs-scroll');
    if (scroll) scroll.scrollTop = scroll.scrollHeight;
  }
}

// Abrir aba via URL (?aba=observacoes)
(function() {
  var params = new URLSearchParams(window.location.search);
  var aba = params.get('aba');
  if (aba === 'atividade') aba = 'historico';
  // Parcelas deixou de ser aba — virou card dentro de Dados. Links antigos
  // com ?aba=parcelas continuam funcionando, caindo em Dados.
  if (aba === 'parcelas') aba = 'dados';
  // Observações também deixou de ser aba. Link antigo (?aba=observacoes) cai em
  // Dados com o card JÁ ABERTO — senão a pessoa clicava no link e não via nada.
  var abrirObs = (aba === 'observacoes');
  if (abrirObs) aba = 'dados';
  if (aba && document.querySelector('.ws-tab[data-tab="' + aba + '"]')) {
    wsSetTab(aba);
  }
  if (abrirObs) obsToggle(true);

  // Auto-redirect após mostrar flash message (3 segundos)
  var redirectAfter = params.get('redirect_after');
  if (redirectAfter) {
    var destino = {
      'tarefas': '?module=tarefas&action=index',
      'processos': '?module=processos&action=index',
    };
    var url = destino[redirectAfter];
    if (url) {
      setTimeout(function() {
        window.location.href = url;
      }, 3000);
    }
  }
})();

// ── Toast de sucesso antes do submit (mostra na tela de detalhe, depois envia) ──
function mostrarToastESumbeter(form, mensagem) {
  // Criar toast fixo
  var old = document.getElementById('ws-flash');
  if (old) old.remove();
  var toast = document.createElement('div');
  toast.id = 'ws-flash';
  toast.style.cssText = 'position:fixed;top:72px;left:50%;transform:translateX(-50%);z-index:10000;background:#D8F5E5;border:1.5px solid #00A960;border-radius:8px;padding:14px 24px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 16px rgba(0,0,0,0.15);max-width:600px;min-width:320px;animation:wsFlashIn 0.3s ease';
  toast.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#00703C" stroke-width="2" style="display:inline-block;flex-shrink:0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><span style="font-size:13.5px;font-weight:700;color:#00703C">' + mensagem + '</span>';
  document.body.appendChild(toast);

  // Desabilitar botões para evitar duplo-clique
  document.querySelectorAll('.ws-action-bar__btns button, .ws-action-bar__btns [type=submit]').forEach(function(b) { b.disabled = true; b.style.opacity = '0.5'; });

  // Submeter após 2.5 segundos
  setTimeout(function() { form.submit(); }, 2500);
}

function confirmarAcaoComToast(form, mensagem) {
  mostrarToastESumbeter(form, mensagem);
  return false; // previne submit imediato
}

// ── Seletor de anexo no viewer (seguro — sem innerHTML) ──
function selecionarAnexo(card) {
  document.querySelectorAll('.ws-att-bullet').forEach(function(c) { c.classList.remove('active'); });
  card.classList.add('active');

  var url  = card.dataset.url;
  var nome = card.dataset.nome;
  var ext  = (card.dataset.ext || '').toLowerCase();
  var dlUrl = card.dataset.downloadUrl;
  var isImage = ['jpg','jpeg','png','gif','webp','bmp'].indexOf(ext) !== -1;
  var isVideo = ['mp4','webm','mov'].indexOf(ext) !== -1;

  var fnameEl = document.getElementById('viewer-fname');
  if (fnameEl) fnameEl.textContent = nome;
  var dlBtn = document.getElementById('viewer-dl-btn');
  if (dlBtn) dlBtn.href = dlUrl;

  var canvas = document.getElementById('viewer-canvas');
  if (!canvas) return;
  while (canvas.firstChild) canvas.removeChild(canvas.firstChild);

  if (isVideo) {
    canvas.appendChild(montarCartaoVideo(nome, parseInt(card.dataset.tamanho || '0', 10), dlUrl));
  } else if (isImage) {
    var img = document.createElement('img');
    img.id = 'viewer-content';
    img.src = url;
    img.alt = nome;
    img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;border-radius:4px;box-shadow:0 2px 12px rgba(0,0,0,0.10)';
    canvas.appendChild(img);
  } else {
    var iframe = document.createElement('iframe');
    iframe.id = 'viewer-content';
    iframe.src = url;
    iframe.style.cssText = 'width:100%;height:100%;border:none;background:#fff;border-radius:4px;box-shadow:0 2px 12px rgba(0,0,0,0.10)';
    canvas.appendChild(iframe);
  }
}

// ── Cartão de vídeo (espelho JS de ws_cartaoVideo, no PHP acima) ──
//
// O visualizador não reproduz vídeo: mostra este cartão com o botão de
// download. Se mexer aqui, mexa na função PHP também — a página nasce com o
// cartão renderizado no servidor e troca para este ao clicar noutro anexo.
//
// innerHTML só recebe marcação FIXA (os ícones). Nome e URL entram por
// textContent/setAttribute, como no resto desta tela.
function montarCartaoVideo(nome, bytes, dlUrl) {
  var tamanho = bytes >= 1048576
    ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB'
    : Math.round(Math.max(bytes, 0) / 1024) + ' KB';

  var box = document.createElement('div');
  box.id = 'viewer-content';
  box.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;'
                    + 'width:100%;height:100%;background:var(--color-surface);border-radius:12px;'
                    + 'box-shadow:0 2px 8px rgba(0,0,0,0.10);padding:32px;text-align:center';
  box.innerHTML =
    '<div style="width:88px;height:88px;border-radius:12px;background:#EEF2FD;display:flex;align-items:center;justify-content:center">'
    + '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#2347C8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block">'
    + '<rect x="2" y="4" width="14" height="16" rx="2"/><path d="M16 9l6-3v12l-6-3"/>'
    + '<polygon points="7 9 11 12 7 15" fill="#2347C8" stroke="none"/></svg></div>'
    + '<div><div class="js-video-nome" style="font-family:var(--font-bold);font-size:15px;color:var(--color-dark);word-break:break-all;max-width:340px"></div>'
    + '<div class="js-video-meta" style="font-size:12px;color:var(--color-gray-medium);margin-top:4px"></div></div>'
    + '<a class="js-video-dl" style="display:inline-flex;align-items:center;gap:8px;background:#2347C8;color:#FFFFFF;text-decoration:none;'
    + 'border-radius:4px;padding:10px 20px;font-family:var(--font-bold);font-size:13px">'
    + '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="display:block">'
    + '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
    + 'Baixar vídeo</a>';

  box.querySelector('.js-video-nome').textContent = nome;
  box.querySelector('.js-video-meta').textContent = 'Vídeo · ' + tamanho + ' · pré-visualização não disponível';
  var a = box.querySelector('.js-video-dl');
  a.setAttribute('href', dlUrl);
  a.onmouseover = function () { this.style.background = '#1B38A0'; };
  a.onmouseout  = function () { this.style.background = '#2347C8'; };
  return box;
}

// ── Navegação da barra de anexos (setas ‹ ›) ──
function scrollAnexos(dir) {
  var el = document.getElementById('ws-att-scroll');
  if (!el) return;
  var passo = Math.max(160, Math.round(el.clientWidth * 0.7));
  el.scrollBy({ left: dir * passo, behavior: 'smooth' });
}
function atualizarSetasAnexos() {
  var el = document.getElementById('ws-att-scroll');
  if (!el) return;
  var bL = document.getElementById('ws-att-arrow-left');
  var bR = document.getElementById('ws-att-arrow-right');
  var maxScroll = el.scrollWidth - el.clientWidth;
  var overflow = maxScroll > 2;
  if (bL) { bL.style.display = overflow ? '' : 'none'; bL.disabled = el.scrollLeft <= 1; }
  if (bR) { bR.style.display = overflow ? '' : 'none'; bR.disabled = el.scrollLeft >= maxScroll - 1; }
}
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('ws-att-scroll');
  if (!el) return;
  atualizarSetasAnexos();
  el.addEventListener('scroll', atualizarSetasAnexos, { passive: true });
  window.addEventListener('resize', atualizarSetasAnexos);
});

// ── Cancelamento — roteamento por permissão ──
var _temParcelasAtivas = <?= $temParcelasAtivas ? 'true' : 'false' ?>;
var _podeCancelarParcelas = <?= $podeCancelarParcelas ? 'true' : 'false' ?>;

function abrirModalCancelamento() {
  if (_temParcelasAtivas && !_podeCancelarParcelas) {
    var m = document.getElementById('modal-cancelar-bloqueado');
    if (m) m.classList.add('is-open');
  } else if (_temParcelasAtivas && _podeCancelarParcelas) {
    var m = document.getElementById('modal-cancelar-parcelas');
    if (m) m.classList.add('is-open');
  } else {
    var m = document.getElementById('modal-cancelar-simples');
    if (m) m.classList.add('is-open');
  }
}

function fecharTodosModaisCancelamento() {
  ['modal-cancelar-simples', 'modal-cancelar-bloqueado', 'modal-cancelar-parcelas'].forEach(function(id) {
    var m = document.getElementById(id);
    if (m) m.classList.remove('is-open');
  });
}

['modal-cancelar-simples', 'modal-cancelar-bloqueado', 'modal-cancelar-parcelas'].forEach(function(id) {
  var el = document.getElementById(id);
  // (sem fechar por clique-fora)
});

// ── Modal de descartar teste ──
function abrirModalDescartar() {
  var m = document.getElementById('modal-descartar');
  if (m) m.classList.add('is-open');
}
function fecharModalDescartar() {
  var m = document.getElementById('modal-descartar');
  if (m) m.classList.remove('is-open');
}
// Modais fecham apenas pelos botões (sem clique-fora).

// ── Modal desfazer cancelamento ──
function abrirModalDesfazerCanc() {
  var m = document.getElementById('modal-desfazer-canc');
  if (m) m.classList.add('is-open');
}
function fecharModalDesfazerCanc() {
  var m = document.getElementById('modal-desfazer-canc');
  if (m) m.classList.remove('is-open');
}


// ── Modal do fornecedor (somente visualização) ──
function abrirFornecedorView() {
  var m = document.getElementById('modal-forn-view');
  if (m) m.classList.add('is-open');
}
function fecharFornecedorView() {
  var m = document.getElementById('modal-forn-view');
  if (m) m.classList.remove('is-open');
}

// ── Modal de alteração de fluxo ──
function abrirModalAlterarFluxo() {
  var m = document.getElementById('modal-alterar-fluxo');
  if (m) m.classList.add('is-open');
}
function fecharModalAlterarFluxo() {
  var m = document.getElementById('modal-alterar-fluxo');
  if (m) m.classList.remove('is-open');
}

// ── Modal de reatribuição ──
function abrirModalReatribuir() {
  var m = document.getElementById('modal-reatribuir');
  if (m) m.classList.add('is-open');
}
function fecharModalReatribuir() {
  var m = document.getElementById('modal-reatribuir');
  if (m) m.classList.remove('is-open');
}


// ── Modal "Anexar": operador escolhe o tipo + arquivo ──
// tipoPre (opcional): já deixa o tipo escolhido — usado pelos atalhos "Anexar"
// do checklist e pelos bullets de documento pendente.
function abrirModalAnexar(tipoPre) {
  var m = document.getElementById('modal-substituir');
  if (!m) return;
  document.getElementById('sub-modo').value = 'adicionar';
  document.getElementById('sub-anexo-id').value = '';
  document.getElementById('sub-file-input').multiple = true;
  document.getElementById('sub-tipo-wrap').style.display = 'block';
  var sel = document.getElementById('sub-tipo-select');
  sel.value = '';
  document.getElementById('sub-tipo-nome').value = '';
  document.getElementById('sub-modal-title').textContent = 'Anexar documento';
  document.getElementById('sub-modal-sub-prefix').textContent = 'Anexando:';
  document.getElementById('sub-doc-name').textContent = 'selecione o tipo abaixo';
  document.getElementById('sub-confirm-label').textContent = 'Anexar';
  var info = document.getElementById('sub-info-text');
  if (info) info.textContent = 'Você pode selecionar vários arquivos para o mesmo tipo. Fica registrado no histórico.';
  subClearFile();
  if (tipoPre) {
    sel.value = tipoPre;
    if (sel.value === tipoPre) subTipoEscolhido(sel);
  }
  m.classList.add('is-open');
}

// Atalho a partir de um item pendente (bullet da faixa ou linha do checklist)
function anexarTipoPendente(el) {
  abrirModalAnexar(el.dataset.tipo || '');
}

// Botão "Aprovar" travado: mostra o que falta em vez de deixar o usuário
// descobrir pelo erro do servidor.
var WS_PENDENCIAS_ETAPA = <?= json_encode(array_column($checkBloqueia, 'label'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function avisarPendenciasEtapa() {
  var det = document.getElementById('ws-checklist');
  if (det) {
    det.open = true;
    det.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  var lista = WS_PENDENCIAS_ETAPA.map(function (nome) {
    var esc = document.createElement('div');
    esc.textContent = nome;
    return '<li style="margin-bottom:4px">' + esc.innerHTML + '</li>';
  }).join('');
  var msg = 'Esta etapa só pode ser aprovada depois de cumprir:<ul style="margin:8px 0 0 18px;padding:0">'
          + lista + '</ul>';
  if (typeof window.btModalErro === 'function') {
    window.btModalErro('Aprovação bloqueada', msg, 'warning');
  } else {
    alert('Falta cumprir: ' + WS_PENDENCIAS_ETAPA.join(' · '));
  }
}

// Substituir um arquivo específico (a partir do botão no bullet)
function subDoBullet(btn) {
  var m = document.getElementById('modal-substituir');
  if (!m) return;
  document.getElementById('sub-modo').value = 'substituir';
  document.getElementById('sub-anexo-id').value = btn.dataset.anexoId || '';
  document.getElementById('sub-tipo-nome').value = btn.dataset.tipo || '';
  document.getElementById('sub-file-input').multiple = false;
  document.getElementById('sub-tipo-wrap').style.display = 'none';
  document.getElementById('sub-modal-title').textContent = 'Substituir arquivo';
  document.getElementById('sub-modal-sub-prefix').textContent = 'Substituindo:';
  document.getElementById('sub-doc-name').textContent = btn.dataset.tipo || '';
  document.getElementById('sub-confirm-label').textContent = 'Substituir';
  var info = document.getElementById('sub-info-text');
  if (info) info.textContent = 'O arquivo atual será arquivado e trocado por este. Fica registrado no histórico.';
  subAplicarTipoDoc(btn.dataset.tipodoc || 'qualquer');
  subClearFile();
  m.classList.add('is-open');
}

// Excluir um arquivo específico (a partir do botão no bullet)
function excluirDoBullet(btn) {
  var id = btn.dataset.anexoId || '';
  var rotulo = btn.dataset.rotulo || 'este arquivo';
  if (!id) return;
  function doDelete() {
    document.getElementById('excluir-anexo-id').value = id;
    document.getElementById('form-excluir-anexo').submit();
  }
  if (typeof window.btModalConfirm === 'function') {
    window.btModalConfirm('Excluir anexo', 'Remover <b>' + rotulo + '</b>? O arquivo será arquivado e sairá da lista.', doDelete, { confirmLabel: 'Excluir', tipo: 'error' });
  } else if (confirm('Remover "' + rotulo + '"?')) {
    doDelete();
  }
}

function subTipoEscolhido(sel) {
  var opt = sel.selectedOptions[0];
  if (!opt || !opt.value) { document.getElementById('sub-tipo-nome').value = ''; subValidar(); return; }
  document.getElementById('sub-tipo-nome').value = opt.value;
  document.getElementById('sub-doc-name').textContent = opt.value;
  document.getElementById('sub-confirm-label').textContent = 'Anexar';
  subAplicarTipoDoc(opt.dataset.tipodoc || 'qualquer');
  subValidar();
}

function subValidar() {
  var temTipo    = document.getElementById('sub-tipo-nome').value !== '';
  var temArquivo = document.getElementById('sub-file-input').files.length > 0;
  var btn = document.getElementById('sub-confirm-btn');
  btn.disabled = !(temTipo && temArquivo);
  btn.style.opacity = btn.disabled ? '0.45' : '1';
}

function subAplicarTipoDoc(tipoDoc) {
  var extMap = {
    'qualquer': '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt,.csv',
    'pdf': '.pdf', 'imagem': '.jpg,.jpeg,.png,.webp',
    'pdf_imagem': '.pdf,.jpg,.jpeg,.png,.webp',
    'excel': '.xls,.xlsx', 'word': '.doc,.docx',
    'video': '.mp4,.webm,.mov,.ogg,.mp3,.m4a,.wav'
  };
  var hintMap = {
    'qualquer': 'Qualquer formato · máx. 10 MB',
    'pdf': 'Apenas PDF · máx. 10 MB', 'imagem': 'JPG, PNG, WEBP · máx. 10 MB',
    'pdf_imagem': 'PDF ou imagem · máx. 10 MB',
    'excel': 'XLS, XLSX · máx. 10 MB', 'word': 'DOC, DOCX · máx. 10 MB',
    'video': 'Áudio/Vídeo · máx. 5 MB'
  };
  document.getElementById('sub-file-input').accept = extMap[tipoDoc] || extMap['qualquer'];
  document.getElementById('sub-hint').textContent = hintMap[tipoDoc] || hintMap['qualquer'];
}

// ── Modal de substituição/envio de documento ──
function abrirModalSubstituir(tipoNome, isPendente, tipoDoc) {
  var m = document.getElementById('modal-substituir');
  if (!m) return;
  document.getElementById('sub-tipo-wrap').style.display = 'none';
  document.getElementById('sub-tipo-nome').value = tipoNome;
  document.getElementById('sub-doc-name').textContent = tipoNome;
  document.getElementById('sub-modal-title').textContent = isPendente ? 'Enviar documento' : 'Substituir documento';
  document.getElementById('sub-confirm-label').textContent = isPendente ? 'Enviar' : 'Substituir';

  var extMap = {
    'qualquer': '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt,.csv',
    'pdf': '.pdf', 'imagem': '.jpg,.jpeg,.png,.webp',
    'pdf_imagem': '.pdf,.jpg,.jpeg,.png,.webp',
    'excel': '.xls,.xlsx', 'word': '.doc,.docx',
    'video': '.mp4,.webm,.mov,.ogg,.mp3,.m4a,.wav'
  };
  var hintMap = {
    'qualquer': 'Qualquer formato · máx. 10 MB',
    'pdf': 'Apenas PDF · máx. 10 MB', 'imagem': 'JPG, PNG, WEBP · máx. 10 MB',
    'pdf_imagem': 'PDF ou imagem · máx. 10 MB',
    'excel': 'XLS, XLSX · máx. 10 MB', 'word': 'DOC, DOCX · máx. 10 MB',
    'video': 'Áudio/Vídeo · máx. 5 MB'
  };
  document.getElementById('sub-file-input').accept = extMap[tipoDoc] || extMap['qualquer'];
  document.getElementById('sub-hint').textContent = hintMap[tipoDoc] || hintMap['qualquer'];

  subClearFile();
  m.classList.add('is-open');
}

function fecharModalSubstituir() {
  var m = document.getElementById('modal-substituir');
  if (m) m.classList.remove('is-open');
}

function subFileSelected(input) {
  var files = input.files;
  if (!files || files.length === 0) return;
  document.getElementById('sub-file-preview').style.display = 'flex';
  document.getElementById('sub-dropzone').style.display = 'none';
  var total = 0;
  for (var i = 0; i < files.length; i++) total += files[i].size;
  document.getElementById('sub-fp-name').textContent = files.length === 1 ? files[0].name : files.length + ' arquivos selecionados';
  document.getElementById('sub-fp-size').textContent = (total / 1024).toFixed(1) + ' KB';
  subValidar(); // habilita só com tipo + arquivo definidos
}

function subClearFile() {
  document.getElementById('sub-file-input').value = '';
  document.getElementById('sub-file-preview').style.display = 'none';
  document.getElementById('sub-dropzone').style.display = '';
  var btn = document.getElementById('sub-confirm-btn');
  btn.disabled = true;
  btn.style.opacity = '0.45';
}

// ═══ REVISÃO SEQUENCIAL (?seq=1) ══════════════════════════════════════════
// Chegou por "Sequencial" em Minhas Tarefas: a fila de processos vive em
// sessionStorage e esta tela é só mais uma parada dela.
//
// O avanço não precisa de código novo no servidor: aprovar/devolver/cancelar já
// respeitam o parâmetro `voltar` para decidir onde largar o usuário depois do
// POST. Então basta apontar esse `voltar` para o PRÓXIMO processo da fila — a
// decisão grava e a tela seguinte já abre sozinha.
(function () {
  'use strict';
  var params = new URLSearchParams(location.search);
  if (params.get('seq') !== '1') return;

  var fila = [];
  try { fila = JSON.parse(sessionStorage.getItem('seqFila') || '[]'); } catch (e) { fila = []; }
  if (!Array.isArray(fila) || fila.length === 0) return;

  var atual = fila.indexOf(params.get('id'));
  if (atual === -1) return;

  var urlDoItem = function (i) {
    return location.pathname + '?module=processos&action=detalhe&id='
         + encodeURIComponent(fila[i]) + '&seq=1&origem=tarefas';
  };
  var urlTarefas = location.pathname + '?module=tarefas&action=index';
  var proximo    = atual + 1 < fila.length ? urlDoItem(atual + 1) : urlTarefas;

  // `voltar` nas ações: depois de decidir, o servidor manda para o próximo.
  ['form-aprovar', 'form-devolucao', 'form-cancelar'].forEach(function (id) {
    var f = document.getElementById(id);
    if (f) f.action += '&voltar=' + encodeURIComponent(proximo);
  });

  // Barra de progresso no topo da tela.
  var barra = document.createElement('div');
  barra.style.cssText = 'display:flex;align-items:center;gap:14px;background:#EEF2FD;'
    + 'border:1.5px solid #2347C8;border-radius:8px;padding:10px 16px;margin-bottom:16px';
  barra.innerHTML =
      '<span style="font-size:13px;font-weight:700;color:#2347C8;white-space:nowrap">'
    + 'Sequencial · ' + (atual + 1) + ' de ' + fila.length + '</span>'
    + '<div style="flex:1;height:4px;background:#FFD6D6;border-radius:2px;overflow:hidden">'
    + '<div style="height:100%;background:#2347C8;width:' + Math.round((atual / fila.length) * 100) + '%"></div></div>'
    + '<button type="button" id="seq-pular" style="font-size:12px;font-weight:700;color:#606060;'
    + 'background:#fff;border:1px solid #DFDFDF;border-radius:4px;padding:6px 14px;cursor:pointer">Pular</button>'
    + '<button type="button" id="seq-sair" style="font-size:12px;font-weight:700;color:#888;'
    + 'background:none;border:none;cursor:pointer;padding:4px 8px">Sair da sequência</button>';

  var alvo = document.querySelector('.ws');
  if (alvo && alvo.parentNode) alvo.parentNode.insertBefore(barra, alvo);
  else return;

  document.getElementById('seq-pular').addEventListener('click', function () {
    window.location = proximo;
  });
  document.getElementById('seq-sair').addEventListener('click', function () {
    sessionStorage.removeItem('seqFila');
    window.location = urlTarefas;
  });
})();




</script>
