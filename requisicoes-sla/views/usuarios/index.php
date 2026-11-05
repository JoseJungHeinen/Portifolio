<?php $title = 'Usuários'; ?>
<?php if ($acesso = flash('acesso_link')): ?>
<div class="card border-0 shadow-sm mb-4" style="border-left:4px solid var(--bs-success) !important">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-person-check-fill text-success fs-5"></i>
            <span class="fw-bold">Usuário <strong><?= e($acesso['nome']) ?></strong> criado com sucesso!</span>
        </div>
        <p class="small text-muted mb-2">Envie o link abaixo ao usuário. Ao clicar, ele definirá a própria senha de acesso. O link expira em 1 hora.</p>
        <div class="input-group input-group-sm mb-2">
            <input type="text" class="form-control font-monospace" id="inputLinkAcesso"
                   value="<?= e($acesso['url']) ?>" readonly>
            <button class="btn btn-outline-success" type="button" id="btnCopiarLink" onclick="copiarLinkAcesso()">
                <i class="bi bi-copy"></i> Copiar link
            </button>
        </div>
        <div class="small text-muted">
            <i class="bi bi-envelope me-1"></i><?= e($acesso['email']) ?>
            &nbsp;·&nbsp;
            <i class="bi bi-clock-history me-1 text-warning"></i>Link de uso único — expira em 1 hora.
        </div>
    </div>
</div>
<script>
function copiarLinkAcesso() {
    const input = document.getElementById('inputLinkAcesso');
    const btn   = document.getElementById('btnCopiarLink');
    navigator.clipboard.writeText(input.value).then(() => {
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Copiado!';
        btn.classList.replace('btn-outline-success', 'btn-success');
        setTimeout(() => {
            btn.innerHTML = '<i class="bi bi-copy"></i> Copiar link';
            btn.classList.replace('btn-success', 'btn-outline-success');
        }, 2500);
    });
}
</script>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0"><i class="bi bi-people me-2 text-primary"></i>Usuários</h4>
    <a href="<?= url('usuarios/novo') ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Novo Usuário</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end" method="GET">
            <div class="col-md-3">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" name="busca" class="form-control" placeholder="Nome ou e-mail..."
                           value="<?= e($_GET['busca'] ?? '') ?>">
                </div>
            </div>
            <div class="col-auto">
                <select name="filial_id" class="form-select form-select-sm">
                    <option value="">Todas as Filiais</option>
                    <?php foreach ($filiais as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= ($_GET['filial_id'] ?? '') === $f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="setor_id" class="form-select form-select-sm">
                    <option value="">Todos os Setores</option>
                    <?php foreach ($setores as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($_GET['setor_id'] ?? '') === $s['id'] ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="perfil" class="form-select form-select-sm">
                    <option value="">Todos os Perfis</option>
                    <?php foreach ($perfis as $p): ?>
                        <option value="<?= $p ?>" <?= ($_GET['perfil'] ?? '') === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="ativo" class="form-select form-select-sm">
                    <option value="">Todos os Status</option>
                    <option value="1" <?= isset($_GET['ativo']) && $_GET['ativo'] === '1' ? 'selected' : '' ?>>Ativos</option>
                    <option value="0" <?= isset($_GET['ativo']) && $_GET['ativo'] === '0' ? 'selected' : '' ?>>Inativos</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            </div>
            <?php if (!empty($_GET)): ?>
            <div class="col-auto">
                <a href="<?= url('usuarios') ?>" class="btn btn-link btn-sm text-muted p-0">
                    <i class="bi bi-x-circle me-1"></i>Limpar
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4">Usuário</th>
                        <th>Filial / Setor</th>
                        <th>Último Acesso</th>
                        <th>Status / Perfil</th>
                        <th class="text-end pe-3">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td class="px-4">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar-sm"><?= strtoupper(substr($u['nome'],0,1)) ?></div>
                                <div>
                                    <div class="fw-semibold small"><?= e($u['nome']) ?></div>
                                    <div class="text-muted" style="font-size:.78rem"><?= e($u['email']) ?></div>
                                    <?php if ($u['force_change_senha']): ?>
                                        <span class="badge bg-warning text-dark mt-1" style="font-size:.65rem">
                                            <i class="bi bi-key me-1"></i>Troca obrigatória
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="small"><?= e($u['filial_nome']) ?><br><span class="text-muted"><?= e($u['setor_nome']) ?></span></td>
                        <td class="small text-muted"><?= datetime_br($u['ultimo_acesso']) ?></td>
                        <td>
                            <?php if ($u['bloqueado_ate'] && strtotime($u['bloqueado_ate']) > time()): ?>
                                <span class="badge bg-danger"><i class="bi bi-lock me-1"></i>Bloqueado</span>
                            <?php elseif ($u['ativo']): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Ativo</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-dash-circle me-1"></i>Inativo</span>
                            <?php endif; ?>
                            <?php
                            $perfilCor = [
                                'admin'       => 'danger',
                                'diretor'     => 'dark',
                                'gestor'      => 'warning text-dark',
                                'comprador'   => 'primary',
                                'almoxarife'  => 'info text-dark',
                                'solicitante' => 'secondary',
                            ];
                            $cor = $perfilCor[$u['perfil']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $cor ?> mt-1 d-block" style="width:fit-content">
                                <?= ucfirst($u['perfil']) ?>
                            </span>
                        </td>
                        <td class="pe-3">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= url('usuarios/'.$u['id'].'/editar') ?>"
                                   class="btn btn-outline-primary btn-sm" title="Editar usuário">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($u['id'] !== auth()['id']): ?>
                                <form method="POST" action="<?= url('usuarios/'.$u['id'].'/delete') ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-outline-<?= $u['ativo'] ? 'warning' : 'secondary' ?> btn-sm"
                                            title="<?= $u['ativo'] ? 'Desativar usuário' : 'Usuário já inativo' ?>"
                                            onclick="return confirm('Desativar o usuário <?= e($u['nome']) ?>?')"
                                            <?= !$u['ativo'] ? 'disabled' : '' ?>>
                                        <i class="bi bi-person-dash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($usuarios)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-people fs-2 d-block mb-2"></i>
                            Nenhum usuário encontrado.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (!empty($usuarios)): ?>
    <div class="card-footer text-muted small py-2 px-3">
        <?= count($usuarios) ?> usuário<?= count($usuarios) !== 1 ? 's' : '' ?> encontrado<?= count($usuarios) !== 1 ? 's' : '' ?>
    </div>
    <?php endif; ?>
</div>