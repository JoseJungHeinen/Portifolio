<?php
declare(strict_types=1);

/**
 * Requisitos: $mysqli, $erros (array), $mensagem (string), $acao (string|null), $templateSelecionado (array|null)
 * Session já iniciada e admin já validado no config_documentos.php
 */

require_once __DIR__ . '/globais_helpers.php';

// =========================
// MIGRAÇÃO LEVE: adiciona lista_id nas globais (se ainda não existir)
// =========================
try {
    $colRes = $mysqli->query("SHOW COLUMNS FROM documento_variaveis_globais LIKE 'lista_id'");
    if ($colRes && $colRes->num_rows === 0) {
        // lista_id referencia documento_listas (opcional). Se o seu MySQL/MariaDB não suportar FK aqui, pode remover o CONSTRAINT.
        $mysqli->query("ALTER TABLE documento_variaveis_globais ADD COLUMN lista_id INT NULL DEFAULT NULL");
    }
} catch (Throwable $e) {
    // não interrompe a tela por causa da migração
}


/* =========================
 * SALVAR / EDITAR GLOBAL
 * ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'salvar_global') {
    $rotulo = trim($_POST['global_rotulo'] ?? '');
    $chave  = strtoupper(trim($_POST['global_chave'] ?? ''));
    $tipo   = trim($_POST['global_tipo'] ?? 'texto_curto');

    // Lista reutilizável (opcional)
    $listaId = $_POST['global_lista_id'] ?? '';
    $listaId = ($listaId === '' ? null : (int)$listaId);

    $editId = isset($_POST['global_id']) ? (int)$_POST['global_id'] : 0;

    if ($rotulo === '') {
        $erros[] = 'Informe o rótulo da variável global.';
    }

    // Se não informar chave, gera a partir do rótulo
    if (empty($erros) && $chave === '') {
        $chave = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $rotulo)));
        $chave = trim(preg_replace('/_+/', '_', $chave), '_');
    }

    if (empty($erros) && !preg_match('/^[A-Z0-9_]+$/', $chave)) {
        $erros[] = 'Chave inválida. Use apenas letras, números e underline (_).';
    }

    if (empty($erros)) {
        if ($editId > 0) {
            $stmt = $mysqli->prepare("UPDATE documento_variaveis_globais SET chave=?, rotulo=?, tipo=?, lista_id=NULLIF(?,0) WHERE id=?");
            $listaBind = ($listaId === null ? 0 : (int)$listaId);
            $stmt->bind_param('sssii', $chave, $rotulo, $tipo, $listaBind, $editId);
            $stmt->execute();
            $stmt->close();
            $mensagem = 'Variável global atualizada com sucesso.';
        } else {
            $stmt = $mysqli->prepare("INSERT INTO documento_variaveis_globais (chave, rotulo, tipo, obrigatorio, lista_id) VALUES (?, ?, ?, 0, NULLIF(?,0))");
            $listaBind = ($listaId === null ? 0 : (int)$listaId);
            $stmt->bind_param('sssi', $chave, $rotulo, $tipo, $listaBind);
            $stmt->execute();
            $stmt->close();
            $mensagem = 'Variável global criada com sucesso.';
        }

        if (!headers_sent()) {
            header('Location: config_documentos.php?aba=campos&template_id=' . (int)$GLOBALS['tid']);
            exit;
        }
    }
}



/* =========================
 * EXCLUIR GLOBAL
 * ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'excluir_global') {
    $globalId   = (int)($_POST['global_id'] ?? 0);
    $templateId = (int)($_POST['template_id'] ?? 0);

    if ($globalId <= 0) {
        $erros[] = 'ID inválido para excluir a variável global.';
    }

    if (empty($erros)) {
        $mysqli->begin_transaction();
        try {
            // Remove vínculos primeiro (evita erro de FK, caso exista)
            $stmt = $mysqli->prepare("DELETE FROM documento_template_globais WHERE global_id = ?");
            $stmt->bind_param('i', $globalId);
            $stmt->execute();
            $stmt->close();

            // Remove a global
            $stmt = $mysqli->prepare("DELETE FROM documento_variaveis_globais WHERE id = ?");
            $stmt->bind_param('i', $globalId);
            $stmt->execute();
            $stmt->close();

            $mysqli->commit();
            $mensagem = 'Variável global excluída com sucesso.';
        } catch (\Throwable $e) {
            $mysqli->rollback();
            $erros[] = 'Falha ao excluir global: ' . $e->getMessage();
        }

        if (empty($erros) && !headers_sent()) {
            // mantém template_id na volta; se não vier no POST, tenta usar o tid global
            $tid = $templateId > 0 ? $templateId : (int)($GLOBALS['tid'] ?? 0);
            header('Location: config_documentos.php?aba=campos&template_id=' . $tid);
            exit;
        }
    }
}


/* =========================
 * SALVAR VÍNCULOS (template ↔ globais) + OBRIGATORIEDADE POR TEMPLATE
 * ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'salvar_vinculos_globais') {
    $templateId   = (int)($_POST['template_id'] ?? 0);
    $selecionados = $_POST['globais'] ?? [];
    $obrigPorTpl  = $_POST['globais_obrig'] ?? [];
    $ordemPorTpl  = $_POST['ordem_global'] ?? [];


    if ($templateId <= 0) {
        $erros[] = 'Template inválido para vincular globais.';
    }

    if (empty($erros)) {
        // Normaliza ids selecionados (sem duplicar)
        $ids = [];
        if (is_array($selecionados)) {
            foreach ($selecionados as $gid) {
                $gid = (int)$gid;
                if ($gid > 0) $ids[$gid] = true;
            }
        }
        $ids = array_keys($ids);

        $mysqli->begin_transaction();
        try {
            // 1) Remove vínculos que NÃO estão mais selecionados
            if (count($ids) > 0) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $sqlDel = "DELETE FROM documento_template_globais
                           WHERE template_id = ?
                             AND global_id NOT IN ($in)";
                $stmt = $mysqli->prepare($sqlDel);

                $types  = 'i' . str_repeat('i', count($ids));
                $params = array_merge([$templateId], $ids);

                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $mysqli->prepare("DELETE FROM documento_template_globais WHERE template_id = ?");
                $stmt->bind_param('i', $templateId);
                $stmt->execute();
                $stmt->close();
            }

            // 2) Upsert dos selecionados (override + obrigatorio = 0/1)
            $stmtUp = $mysqli->prepare("
            INSERT INTO documento_template_globais (template_id, global_id, ordem, obrigatorio, obrigatorio_override)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                obrigatorio = VALUES(obrigatorio),
                obrigatorio_override = VALUES(obrigatorio_override),
                ordem = VALUES(ordem)
        ");


            foreach ($ids as $gid) {
                $obrigTpl = !empty($obrigPorTpl[$gid]) ? 1 : 0;
                $ordemTpl = isset($ordemPorTpl[$gid]) ? (int)$ordemPorTpl[$gid] : 0;

                $stmtUp->bind_param('iiiii', $templateId, $gid, $ordemTpl, $obrigTpl, $obrigTpl);
                $stmtUp->execute();
            }


            $stmtUp->close();

            $mysqli->commit();
            $mensagem = 'Vínculos de variáveis globais atualizados com sucesso.';
        } catch (\Throwable $e) {
            $mysqli->rollback();
            $erros[] = 'Falha ao salvar vínculos: ' . $e->getMessage();
        }

        if (empty($erros) && !headers_sent()) {
            header('Location: config_documentos.php?aba=campos&template_id=' . $templateId);
            exit;
        }
    }
}