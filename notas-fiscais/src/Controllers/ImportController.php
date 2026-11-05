<?php
// @versao Imp_03 V_06_01/10/2026
declare(strict_types=1);

/**
 * Controller da importação de PDFs em lote (fatura / NFS-e).
 *
 * Roteamento: ?module=importar&action=X
 *
 * Três passos, e nada é criado antes da conferência:
 *   index    → escolhe departamento, setor e os PDFs
 *   analisar → lê cada PDF, sugere o template e monta a tela de conferência
 *   criar    → grava um processo por linha confirmada, com o PDF anexado
 *
 * O template deixou de ser único do lote: o setor recorta a lista e cada linha
 * escolhe o seu, com uma sugestão automática por filial e faixa de valor. Um
 * lote de notas do mesmo setor raramente é do mesmo fluxo — antes disso era
 * preciso separar os PDFs em vários envios.
 *
 * A leitura é uma sugestão: todo campo é editável na conferência. Vinte
 * processos errados custam muito mais que vinte linhas revisadas.
 */

Auth::check();
Permissions::require('processos', 'importar_pdfs');

require_once SRC . '/Helpers/PdfExtractor.php';
require_once SRC . '/Helpers/Upload.php';
require_once SRC . '/Models/Process.php';
require_once SRC . '/Models/CapturedNote.php';
require_once SRC . '/Models/Template.php';

$action      = Sanitize::fromGet(['action'])['action'] ?: 'index';
$usuarioId   = Auth::id();
$currentUser = Auth::user();

/** Departamentos ativos (lista de referência `departamentos`). */
function imp_departamentos(): array
{
    return Database::getInstance()->query(
        "SELECT i.id, i.valor AS nome
           FROM nf_lista_itens i
           JOIN nf_listas l ON l.id = i.lista_id
          WHERE l.slug = 'departamentos' AND i.ativo = 1 AND l.ativo = 1
          ORDER BY i.valor"
    )->fetchAll();
}

/** Setores ativos com o departamento a que pertencem (alimenta o combo em cascata). */
function imp_setores(): array
{
    return Database::getInstance()->query(
        "SELECT s.id, s.nome, s.departamento_id
           FROM setores s
          WHERE s.ativo = 1
          ORDER BY s.nome"
    )->fetchAll();
}

/** Formas de pagamento ativas (lista pré-configurada). */
function imp_formasPagamento(): array
{
    return Database::getInstance()->query(
        "SELECT i.id, i.valor AS nome
           FROM nf_lista_itens i
           JOIN nf_listas l ON l.id = i.lista_id
          WHERE l.slug = 'formas-pagamento' AND i.ativo = 1 AND l.ativo = 1
          ORDER BY i.valor"
    )->fetchAll();
}

/**
 * Templates publicados do setor que ESTE usuário pode usar.
 *
 * O filtro de sigilo não é cosmético: sem ele um template confidencial (RH,
 * Jurídico) apareceria na lista para qualquer um que abrisse a importação,
 * driblando pela porta dos fundos a restrição que vale nas outras telas.
 *
 * @return array<string,array> template_id => linha
 */
function imp_templatesDoSetor(string $setorId, string $usuarioId): array
{
    $stmt = Database::getInstance()->prepare(
        "SELECT t.id, t.nome, v.id AS versao_id, v.etapas, v.tem_financeiro,
                v.tipos_anexo, v.filial_id, v.valor_min, v.valor_max
           FROM nf_templates t
           JOIN nf_template_versoes v ON v.id = t.versao_publicada_id
          WHERE v.status = 'publicado' AND v.setor_id = ?
          ORDER BY t.nome"
    );
    $stmt->execute([$setorId]);

    $out = [];
    foreach ($stmt->fetchAll() as $t) {
        if (!TemplateSigilo::podeVerTemplate((string) $t['id'], $usuarioId)) {
            continue;
        }
        $out[(string) $t['id']] = $t;
    }
    return $out;
}

/**
 * Sugere o template da linha entre os do setor.
 *
 * Descarta o que é incompatível (template preso a outra filial, ou valor fora
 * da faixa) e, entre os que sobram, prefere o mais específico: filial exata
 * vale mais que template genérico, faixa de valor declarada vale mais que
 * template sem faixa. Empate ou nenhum candidato → sem sugestão, para a pessoa
 * escolher em vez de conferir um chute.
 *
 * @param array<string,array> $templates
 */
function imp_sugerirTemplate(array $templates, ?string $filialId, ?float $valor): ?string
{
    $candidatos = [];

    foreach ($templates as $id => $t) {
        $nota = 0;

        $tplFilial = $t['filial_id'] ?? null;
        if ($tplFilial !== null && $tplFilial !== '') {
            if ($filialId === null || $tplFilial !== $filialId) {
                continue;                 // template preso a outra filial
            }
            $nota += 2;
        }

        $min = $t['valor_min'] !== null ? (float) $t['valor_min'] : null;
        $max = $t['valor_max'] !== null ? (float) $t['valor_max'] : null;
        if ($min !== null || $max !== null) {
            if ($valor === null) {
                continue;                 // faixa declarada e valor ilegível
            }
            if (($min !== null && $valor < $min) || ($max !== null && $valor > $max)) {
                continue;                 // fora da faixa
            }
            $nota += 2;
        }

        $candidatos[(string) $id] = $nota;
    }

    if (!$candidatos) {
        return null;                      // nada compatível — a pessoa escolhe
    }
    if (count($candidatos) === 1) {
        return (string) array_key_first($candidatos);
    }

    arsort($candidatos);
    $ids   = array_keys($candidatos);
    $notas = array_values($candidatos);

    // Empate no topo: dois fluxos igualmente plausíveis. Sugerir um seria
    // apostar, e a conferência viraria carimbo.
    return $notas[0] > $notas[1] ? (string) $ids[0] : null;
}

/** Filial pelo CNPJ lido do PDF (só dígitos). */
function imp_filialPorCnpj(?string $cnpj): ?array
{
    if (!$cnpj) return null;
    $stmt = Database::getInstance()->prepare(
        "SELECT id, nome, REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') AS cnpj
           FROM filiais
          WHERE REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') = ? LIMIT 1"
    );
    $stmt->execute([$cnpj]);
    return $stmt->fetch() ?: null;
}

/** Fornecedor já cadastrado (o cadastro automático só acontece ao confirmar). */
function imp_fornecedorPorCnpj(?string $cnpj): ?array
{
    if (!$cnpj) return null;
    $stmt = Database::getInstance()->prepare(
        "SELECT id, razao_social, nome_fantasia FROM fornecedores WHERE cnpj = ? LIMIT 1"
    );
    $stmt->execute([$cnpj]);
    return $stmt->fetch() ?: null;
}

function imp_render(string $view, array $vars): void
{
    $currentUser  = Auth::user();
    $pageTitle    = 'Importar PDFs';
    $activeModule = 'importar';
    extract($vars, EXTR_SKIP);

    ob_start();
    require VIEWS . '/' . $view . '.php';
    $content = ob_get_clean();
    require VIEWS . '/layout/base.php';
}

switch ($action) {

    // ── GET: seleção de departamento, setor e arquivos ────
    case 'index':
        unset($_SESSION['import_pdf']);
        imp_render('importar/index', [
            'etapa'         => 'selecao',
            'departamentos' => imp_departamentos(),
            'setores'       => imp_setores(),
            'extratorOk'    => PdfExtractor::disponivel(),
            'flash'         => (function () { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; })(),
        ]);
        break;

    // ── POST: lê os PDFs e monta a conferência ────────────
    case 'analisar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=importar&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        try {
            $setorId = Sanitize::uuid($_POST['setor_id'] ?? '');
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Selecione o departamento e o setor.'];
            header('Location: ?module=importar&action=index');
            exit;
        }

        $setor = null;
        foreach (imp_setores() as $s) {
            if ($s['id'] === $setorId) { $setor = $s; break; }
        }
        if (!$setor) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Setor inválido ou inativo.'];
            header('Location: ?module=importar&action=index');
            exit;
        }

        $templates = imp_templatesDoSetor($setorId, $usuarioId);
        if (!$templates) {
            $_SESSION['flash'] = [
                'type'    => 'danger',
                'message' => 'O setor ' . $setor['nome'] . ' não tem template publicado disponível para você.',
            ];
            header('Location: ?module=importar&action=index');
            exit;
        }

        $arquivos = $_FILES['pdfs'] ?? null;
        if (!$arquivos || !is_array($arquivos['name']) || $arquivos['name'][0] === '') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Selecione ao menos um PDF.'];
            header('Location: ?module=importar&action=index');
            exit;
        }

        $linhas = [];
        $total  = count($arquivos['name']);
        for ($i = 0; $i < $total; $i++) {
            $nome = (string) $arquivos['name'][$i];
            $item = [
                'nome_arquivo' => $nome, 'meta' => null, 'erro' => null, 'aviso' => null,
                'tipo' => '—', 'fornecedor_cnpj' => '', 'fornecedor_nome' => '',
                'fornecedor_id' => null, 'filial_id' => null, 'filial_nome' => null,
                'destinatario_cnpj' => '', 'template_id' => null,
                'numero' => '', 'data' => '', 'valor' => '', 'chave' => '', 'duplicado' => null,
                'vencimento' => '', 'forma_pagamento_id' => '',
            ];

            try {
                $file = [
                    'name'     => $nome,
                    'type'     => $arquivos['type'][$i],
                    'tmp_name' => $arquivos['tmp_name'][$i],
                    'error'    => $arquivos['error'][$i],
                    'size'     => $arquivos['size'][$i],
                ];
                // Mesmo caminho do wizard: sobe para a área temporária da sessão
                // e só vira anexo do processo quando o operador confirmar.
                $item['meta'] = Upload::salvar($file, 'temp_' . session_id(), false);
            } catch (Throwable $e) {
                $item['erro'] = $e->getMessage();
                $linhas[] = $item;
                continue;
            }

            // Upload::salvar devolve o caminho ABSOLUTO (STORAGE/...), não relativo.
            $lido = PdfExtractor::extrair((string) $item['meta']['caminho']);
            if (!$lido['ok']) {
                $item['erro'] = $lido['erro'];
                $linhas[] = $item;
                continue;
            }

            $item['tipo']              = $lido['tipo'];
            $item['fornecedor_cnpj']   = $lido['fornecedor_cnpj'] ?? '';
            $item['destinatario_cnpj'] = $lido['filial_cnpj'] ?? '';
            $item['numero']            = $lido['numero'] ?? '';
            $item['chave']             = $lido['chave'] ?? '';
            $item['valor']             = $lido['valor'] !== null ? number_format($lido['valor'], 2, ',', '.') : '';
            $item['data']              = $lido['data'] ?? '';
            // Vencimento lido do documento (fatura/boleto) é um bom palpite de
            // data de pagamento; NFS-e normalmente não traz, e a linha vem vazia.
            $item['vencimento']        = $lido['vencimento'] ?? '';

            $filial = imp_filialPorCnpj($lido['filial_cnpj']);
            if ($filial) {
                $item['filial_id']   = $filial['id'];
                $item['filial_nome'] = $filial['nome'];
            } else {
                $item['aviso'] = 'Destinatário não corresponde a nenhuma filial — selecione abaixo.';
            }

            $forn = imp_fornecedorPorCnpj($lido['fornecedor_cnpj']);
            if ($forn) {
                $item['fornecedor_id']   = $forn['id'];
                $item['fornecedor_nome'] = $forn['razao_social'];
            } else {
                $item['fornecedor_nome'] = $lido['fornecedor_nome'] ?? '';
                if ($item['fornecedor_cnpj'] !== '') {
                    $item['aviso'] = trim(($item['aviso'] ?? '') . ' Fornecedor será cadastrado ao confirmar.');
                }
            }

            $item['template_id'] = imp_sugerirTemplate($templates, $item['filial_id'], $lido['valor']);

            if ($item['numero'] !== '') {
                $dup = Process::notaDuplicada(
                    (string) $item['numero'], null,
                    $item['fornecedor_cnpj'] ?: null,
                    $item['chave'] ?: null
                );
                if ($dup) {
                    $item['duplicado'] = $dup['codigo'] . ' (' . $dup['status'] . ')';
                }
            }

            $linhas[] = $item;
        }

        $_SESSION['import_pdf'] = ['setor' => $setor, 'templates' => $templates, 'linhas' => $linhas];

        imp_render('importar/index', [
            'etapa'      => 'conferencia',
            'setor'      => $setor,
            'templates'  => $templates,
            'linhas'     => $linhas,
            'formasPgto' => imp_formasPagamento(),
            'filiais'    => Database::getInstance()
                ->query("SELECT id, nome FROM filiais WHERE ativo = 1 ORDER BY nome")->fetchAll(),
        ]);
        break;

    // ── POST: cria um processo por linha confirmada ───────
    case 'criar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ?module=importar&action=index');
            exit;
        }
        Csrf::validate($_POST['csrf_token'] ?? '');

        $sessao = $_SESSION['import_pdf'] ?? null;
        if (!$sessao) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Sessão de importação expirada — recomece.'];
            header('Location: ?module=importar&action=index');
            exit;
        }

        // A lista permitida vem da sessão, não do POST: o template de cada linha
        // é escolhido na tela, mas só entre os que o setor e o sigilo liberaram.
        $templates  = $sessao['templates'];
        $formasIds  = array_column(imp_formasPagamento(), 'id');
        $result     = ['criados' => [], 'ignorados' => [], 'erros' => []];

        foreach ($sessao['linhas'] as $i => $linha) {
            if (($_POST['incluir'][$i] ?? '') !== '1') {
                $result['ignorados'][] = $linha['nome_arquivo'];
                continue;
            }

            try {
                $cnpj   = preg_replace('/\D/', '', (string) ($_POST['fornecedor_cnpj'][$i] ?? ''));
                $nome   = trim(strip_tags((string) ($_POST['fornecedor_nome'][$i] ?? '')));
                $numero = trim(strip_tags((string) ($_POST['numero'][$i] ?? '')));
                $dataBr = trim((string) ($_POST['data'][$i] ?? ''));
                $valorS = trim((string) ($_POST['valor'][$i] ?? ''));
                $chave  = preg_replace('/\D/', '', (string) ($_POST['chave'][$i] ?? ''));
                $filial = Sanitize::uuid((string) ($_POST['filial_id'][$i] ?? ''));

                $templateId = trim((string) ($_POST['template_id'][$i] ?? ''));
                $template   = $templates[$templateId] ?? null;
                if (!$template) throw new RuntimeException('Selecione o template desta linha.');

                // 11 dígitos = CPF: NFS-e de pessoa física é rotina em template de
                // RH, e o cadastro de fornecedores já aceita os dois formatos.
                if (!in_array(strlen($cnpj), [11, 14], true)) {
                    throw new RuntimeException('CNPJ/CPF do fornecedor inválido — informe 11 ou 14 dígitos.');
                }
                if ($nome === '')   throw new RuntimeException('Informe o nome do fornecedor.');
                if ($numero === '') throw new RuntimeException('Informe o número do documento.');
                if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $dataBr)) throw new RuntimeException('Data inválida (dd/mm/aaaa).');

                $valor = (float) str_replace(['.', ','], ['', '.'], $valorS);
                if ($valor <= 0) throw new RuntimeException('Valor inválido.');

                [$d, $m, $a] = explode('/', $dataBr);
                $dataIso = "{$a}-{$m}-{$d}";

                // Pagamento: só existe em template com financeiro, e aí é exigido —
                // nota que entra sem previsão de pagamento vira pendência invisível
                // para o financeiro.
                $parcelas = [];
                if ((int) $template['tem_financeiro'] === 1) {
                    $vencBr = trim((string) ($_POST['vencimento'][$i] ?? ''));
                    $formaId = trim((string) ($_POST['forma_pagamento_id'][$i] ?? ''));

                    if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $vencBr)) {
                        throw new RuntimeException('Informe a data de pagamento (dd/mm/aaaa).');
                    }
                    if (!in_array($formaId, $formasIds, true)) {
                        throw new RuntimeException('Selecione a forma de pagamento.');
                    }
                    [$vd, $vm, $va] = explode('/', $vencBr);
                    $parcelas[] = [
                        'vencimento'         => "{$va}-{$vm}-{$vd}",
                        'valor'              => $valor,
                        'forma_pagamento_id' => $formaId,
                    ];
                }

                $dup = Process::notaDuplicada($numero, null, $cnpj, $chave ?: null);
                if ($dup) {
                    throw new RuntimeException("Já existe o processo {$dup['codigo']} para este documento.");
                }

                $filialRow = Database::getInstance()->prepare(
                    "SELECT REPLACE(REPLACE(REPLACE(cnpj,'.',''),'/',''),'-','') AS cnpj FROM filiais WHERE id = ? LIMIT 1"
                );
                $filialRow->execute([$filial]);
                $filialDoc = (string) ($filialRow->fetchColumn() ?: '');

                // Reaproveita o auto-cadastro por CNPJ que a captura já usa.
                $fornecedorId = CapturedNote::garantirFornecedor($cnpj, $nome);

                $proc = Process::criar([
                    'template_versao_id' => $template['versao_id'],
                    'fornecedor_id'      => $fornecedorId,
                    'fornecedor_nome'    => $nome,
                    'fornecedor_doc'     => $cnpj,
                    'numero_nf'          => $numero,
                    'valor_nf'           => $valor,
                    'data_nf'            => $dataIso,
                    'chave_nf'           => $chave ?: null,
                    'filial_id'          => $filial,
                    'filial_doc'         => $filialDoc,
                    'dados_formulario'   => [],
                    'etapas_template'    => json_decode($template['etapas'], true) ?: [],
                    'tem_financeiro'     => (bool) $template['tem_financeiro'],
                    'parcelas'           => $parcelas,
                ], $usuarioId);

                // O PDF importado É o documento fiscal deste processo: entra no
                // tipo de anexo que o template chama de nota, não num rótulo
                // nosso — senão o template segue pedindo a nota que já está lá.
                // Vale para fatura e boleto também: o extrator devolve
                // 'generico' tanto para eles quanto para nota que ele não
                // conseguiu ler, e gatilhar pelo tipo lido deixaria a pendência
                // falsa justamente onde a leitura falha.
                if (!empty($linha['meta'])) {
                    $tipoAnexo = Template::tipoAnexoDaNota($template['tipos_anexo'] ?? null);
                    Process::salvarAnexo($proc['id'], $linha['meta'], $tipoAnexo, $usuarioId, false);
                }

                AuditLog::registrar('nf_processo', $proc['id'], 'nf_processo.criado', null, [
                    'codigo'   => $proc['codigo'],
                    'origem'   => 'importacao_pdf',
                    'arquivo'  => $linha['nome_arquivo'],
                    'template' => $template['nome'],
                    'setor'    => $sessao['setor']['nome'] ?? null,
                ]);

                $result['criados'][] = [
                    'codigo'   => $proc['codigo'],
                    'id'       => $proc['id'],
                    'arquivo'  => $linha['nome_arquivo'],
                    'template' => $template['nome'],
                ];
            } catch (Throwable $e) {
                $result['erros'][] = ['arquivo' => $linha['nome_arquivo'], 'msg' => $e->getMessage()];
            }
        }

        unset($_SESSION['import_pdf']);

        imp_render('importar/index', [
            'etapa'     => 'resultado',
            'resultado' => $result,
            'setor'     => $sessao['setor'],
        ]);
        break;

    default:
        header('Location: ?module=importar&action=index');
        exit;
}
