<?php
// @versao Ver.008.291026
?>
<?php $title = $usuario ? 'Editar Usuário' : 'Novo Usuário'; ?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('usuarios') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-person-plus me-2 text-primary"></i><?= $title ?></h4>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <form method="POST" action="<?= $usuario ? url('usuarios/'.$usuario['id']) : url('usuarios') ?>">
            <?= csrf_field() ?>

            <!-- DADOS PESSOAIS -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold text-primary border-bottom">
                    <i class="bi bi-person me-2"></i>Dados Pessoais
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Nome completo *</label>
                            <input type="text" name="nome" class="form-control" required
                                   value="<?= e($usuario['nome'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">E-mail</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= e($usuario['email'] ?? '') ?>"
                                   <?= $usuario ? 'readonly' : '' ?>>
                            <?php if ($usuario): ?>
                            <div class="form-text"><i class="bi bi-lock me-1"></i>O e-mail não pode ser alterado após o cadastro.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <?= $usuario ? 'Nova Senha' : 'Senha *' ?>
                                <?php if ($usuario): ?>
                                <small class="text-muted fw-normal">(deixe em branco para manter a atual)</small>
                                <?php endif; ?>
                            </label>
                            <div class="input-group">
                                <input type="<?= (!$usuario && $senhaTemp) ? 'text' : 'password' ?>"
                                       name="<?= $usuario ? 'nova_senha' : 'senha' ?>"
                                       id="campoSenha"
                                       class="form-control"
                                       <?= $usuario ? '' : 'required' ?>
                                       minlength="8"
                                       value="<?= !$usuario ? e($senhaTemp ?? '') : '' ?>"
                                       placeholder="Mín. 8 caracteres, 1 maiúscula e 1 número"
                                       autocomplete="new-password">
                                <?php if (!$usuario && $senhaTemp): ?>
                                <button type="button" class="btn btn-outline-secondary" id="btnCopiarSenha"
                                        onclick="copiarSenha()" title="Copiar senha">
                                    <i class="bi bi-copy"></i>
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="toggleSenha()" title="Mostrar/ocultar senha">
                                    <i class="bi bi-<?= (!$usuario && $senhaTemp) ? 'eye-slash' : 'eye' ?>" id="iconeSenha"></i>
                                </button>
                            </div>
                            <?php if (!$usuario && $senhaTemp): ?>
                            <div class="alert alert-warning d-flex align-items-center gap-2 py-2 small mt-2 mb-0">
                                <i class="bi bi-clipboard-check fs-5 flex-shrink-0"></i>
                                <span><strong>Copie esta senha agora</strong> para enviar ao usuário. Ele será obrigado a alterá-la no primeiro acesso.</span>
                            </div>
                            <?php else: ?>
                            <div class="mt-1" id="forcaSenhaWrap" style="display:none">
                                <div class="progress" style="height:4px">
                                    <div id="forcaBarra" class="progress-bar" style="width:0%"></div>
                                </div>
                                <small id="forcaTexto" class="text-muted"></small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ACESSO E PERMISSÕES -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold text-primary border-bottom">
                    <i class="bi bi-shield-check me-2"></i>Acesso e Permissões
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Perfil *</label>
                            <select name="perfil" id="perfilSelect" class="form-select" required>
                                <?php foreach ($perfis as $p): ?>
                                    <option value="<?= $p ?>" <?= ($usuario['perfil'] ?? '') === $p ? 'selected' : '' ?>>
                                        <?= ucfirst($p) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Define o que o usuário pode ver e fazer no sistema.</div>

                            <?php
                            // O escopo do gestor vem de gestores_setor, não da lotação:
                            // sem vínculo ele não vê requisição nenhuma. O aviso aparece
                            // via JS sempre que o perfil selecionado for "gestor".
                            $temVinculo = $vinculosGestor > 0;
                            ?>
                            <div id="avisoGestor"
                                 class="alert <?= $temVinculo ? 'alert-info' : 'alert-warning' ?> align-items-start gap-2 mt-2 mb-0 py-2 px-3 small d-none">
                                <i class="bi <?= $temVinculo ? 'bi-info-circle' : 'bi-exclamation-triangle' ?> mt-1"></i>
                                <div>
                                    <?php if ($temVinculo): ?>
                                        Este gestor responde por <strong><?= $vinculosGestor ?></strong>
                                        combinaç<?= $vinculosGestor === 1 ? 'ão' : 'ões' ?> de unidade e setor.
                                        Ele vê apenas as requisições dessas combinações.
                                    <?php else: ?>
                                        <strong>Gestor sem vínculo não vê requisições.</strong>
                                        O que um gestor enxerga em Aprovações, Requisições, Relatórios e Dashboard
                                        vem do cadastro de <em>Gestores</em>, não da unidade e do setor de lotação.
                                        Enquanto não houver vínculo, as telas dele ficam vazias.
                                    <?php endif; ?>
                                    <a href="<?= url('configuracoes/gestores') ?>" class="alert-link ms-1">
                                        Gerenciar vínculos <i class="bi bi-arrow-right-short"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Filial *</label>
                            <select name="filial_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($filiais as $f): ?>
                                    <option value="<?= $f['id'] ?>" <?= ($usuario['filial_id'] ?? '') === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="todas_filiais"
                                       id="todasFiliais" value="1" <?= !empty($usuario['todas_filiais']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="todasFiliais">Acesso a todas as filiais</label>
                                <div class="form-text">Vê requisições, ordens de compra, estoque, fiscal, relatórios e dashboard de todas as filiais. Abrir ou lançar em outra filial continua só com diretor e admin, que já veem tudo.</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Setor *</label>
                            <select name="setor_id" class="form-select" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($setores as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= ($usuario['setor_id'] ?? '') === $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CONTROLES (só na edição) -->
            <?php if ($usuario): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold text-primary border-bottom">
                    <i class="bi bi-toggles me-2"></i>Controles da Conta
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="ativo"
                                       id="ativo" value="1" <?= ($usuario['ativo'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="ativo">Conta ativa</label>
                                <div class="form-text">Desmarcar impede o login imediatamente.</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="force_change_senha"
                                       id="forceTroca" value="1" <?= ($usuario['force_change_senha'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="forceTroca">Forçar troca de senha</label>
                                <div class="form-text">O usuário será obrigado a definir nova senha no próximo login.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- PERMISSÕES (só na edição) -->
            <?php if ($usuario && $usuario['perfil'] !== 'admin'): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold text-primary border-bottom">
                    <i class="bi bi-sliders me-2"></i>Permissões
                    <small class="text-muted fw-normal ms-2">— herdadas do cargo <strong><?= e(ucfirst($usuario['perfil'])) ?></strong>; o que for diferente aparece como <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-normal">exceção</span></small>
                </div>
                <div class="card-body">
                    <input type="hidden" name="perm_enviado" value="1">
                    <?php
                    $marcadas = $permMarcadas;
                    $padrao   = $permPadrao;
                    include ROOT . '/views/partials/permissoes_cartoes.php';
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- BLOQUEIOS DO DASHBOARD (só na edição) -->
            <?php if ($usuario && $usuario['perfil'] !== 'admin'): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold text-primary border-bottom">
                    <i class="bi bi-eye-slash me-2"></i>Bloqueios do Dashboard
                    <small class="text-muted fw-normal ms-2">— o usuário não conseguirá reexibir estes blocos</small>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-column gap-2">
                        <?php
                        $dashboardWidgetLabels = [
                            'kpis'               => 'KPIs do topo (Aprovado, Req. Aprovadas, Pendentes, Anomalias)',
                            'grafico_historico'  => 'Gráfico Compras — 12 meses',
                            'resumo_requisicoes' => 'Resumo de Requisições',
                            'consumo_setor'      => 'Compras por Setor',
                            'comparativo_filial' => 'Comparativo por Filial',
                            'contratos'          => 'Contratos Recorrentes',
                            'eventuais'          => 'Despesas Eventuais',
                            'anomalias'          => 'Anomalias Ativas',
                        ];
                        foreach ($dashboardWidgetLabels as $key => $label):
                            $bloqueado = in_array($key, $dashboardBloqueios ?? [], true);
                        ?>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   name="dashboard_bloqueio[<?= $key ?>]" id="bloqueio_<?= $key ?>"
                                   value="1" <?= $bloqueado ? 'checked' : '' ?>>
                            <label class="form-check-label" for="bloqueio_<?= $key ?>">
                                <?= $label ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-lg me-1"></i>Salvar
                </button>
                <a href="<?= url('usuarios') ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
            <?php if (!$usuario): ?>
            <div class="alert alert-info py-2 small mt-3">
                <i class="bi bi-info-circle me-1"></i>
                O usuário receberá uma senha temporária e deverá alterá-la no primeiro acesso.
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
function copiarSenha() {
    const campo = document.getElementById('campoSenha');
    const btn   = document.getElementById('btnCopiarSenha');
    navigator.clipboard.writeText(campo.value).then(() => {
        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
        btn.classList.replace('btn-outline-secondary', 'btn-success');
        setTimeout(() => {
            btn.innerHTML = '<i class="bi bi-copy"></i>';
            btn.classList.replace('btn-success', 'btn-outline-secondary');
        }, 2000);
    });
}

function toggleSenha() {
    const campo = document.getElementById('campoSenha');
    const icone = document.getElementById('iconeSenha');
    if (campo.type === 'password') {
        campo.type = 'text';
        icone.className = 'bi bi-eye-slash';
    } else {
        campo.type = 'password';
        icone.className = 'bi bi-eye';
    }
}

document.getElementById('campoSenha')?.addEventListener('input', function () {
    const v = this.value;
    const wrap = document.getElementById('forcaSenhaWrap');
    const barra = document.getElementById('forcaBarra');
    const texto = document.getElementById('forcaTexto');
    if (!v) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';
    let score = 0;
    if (v.length >= 8)              score++;
    if (/[A-Z]/.test(v))            score++;
    if (/[0-9]/.test(v))            score++;
    if (/[^A-Za-z0-9]/.test(v))    score++;
    const niveis = [
        { pct: 25, cor: 'bg-danger',  txt: 'Fraca' },
        { pct: 50, cor: 'bg-warning', txt: 'Razoável' },
        { pct: 75, cor: 'bg-info',    txt: 'Boa' },
        { pct: 100, cor: 'bg-success', txt: 'Forte' },
    ];
    const n = niveis[score - 1] ?? niveis[0];
    barra.style.width = n.pct + '%';
    barra.className = 'progress-bar ' + n.cor;
    texto.textContent = n.txt;
});

// Aviso de vínculo: só faz sentido para o perfil "gestor", cujo escopo vem de
// gestores_setor e não da lotação. Roda no load para cobrir a edição.
(function () {
    const perfil = document.getElementById('perfilSelect');
    const aviso  = document.getElementById('avisoGestor');
    if (!perfil || !aviso) return;

    const atualizar = () => aviso.classList.toggle('d-none', perfil.value !== 'gestor');
    perfil.addEventListener('change', atualizar);
    atualizar();
})();
</script>