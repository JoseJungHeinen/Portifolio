<?php
// @versao Ver.001.140926
?>
<?php $title = $fornecedor ? 'Editar Fornecedor' : 'Novo Fornecedor'; ?>
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= url('fornecedores') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="fw-bold mb-0"><i class="bi bi-building me-2 text-primary"></i><?= $title ?></h4>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="<?= $fornecedor ? url('fornecedores/' . $fornecedor['id']) : url('fornecedores') ?>">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Razão Social *</label>
                            <input type="text" name="razao_social" class="form-control" required value="<?= e($fornecedor['razao_social'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">CNPJ *</label>
                            <div class="input-group">
                                <input type="text" name="cnpj" id="campoCnpj" class="form-control" placeholder="11.222.333/0001-81"
                                       value="<?= e(preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $fornecedor['cnpj'] ?? '')) ?>"
                                       <?= $fornecedor ? 'readonly' : 'required' ?>>
                                <?php if (!empty($cnpjAtivo)): ?>
                                <button type="button" class="btn btn-outline-primary" id="btnBuscarCnpj"
                                        title="Preenche os campos vazios com os dados da Receita">
                                    <i class="bi bi-search"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="form-text" id="msgCnpj"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Nome Fantasia</label>
                            <input type="text" name="nome_fantasia" class="form-control" value="<?= e($fornecedor['nome_fantasia'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">E-mail</label>
                            <input type="email" name="email" class="form-control" value="<?= e($fornecedor['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Telefone</label>
                            <input type="text" name="telefone" class="form-control" value="<?= e($fornecedor['telefone'] ?? '') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Endereço</label>
                            <input type="text" name="endereco" class="form-control" value="<?= e($fornecedor['endereco'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">CEP</label>
                            <input type="text" name="cep" class="form-control" maxlength="9" placeholder="00000-000"
                                   value="<?= e(preg_replace('/(\d{5})(\d{3})/', '$1-$2', $fornecedor['cep'] ?? '')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Cidade</label>
                            <input type="text" name="cidade" class="form-control" value="<?= e($fornecedor['cidade'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">UF</label>
                            <input type="text" name="estado" class="form-control text-uppercase" maxlength="2" value="<?= e($fornecedor['estado'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Regime Tributário</label>
                            <select name="regime_tributario" class="form-select">
                                <option value="">Não informado</option>
                                <option value="simples_nacional"  <?= ($fornecedor['regime_tributario'] ?? '') === 'simples_nacional'  ? 'selected' : '' ?>>Simples Nacional</option>
                                <option value="lucro_presumido"   <?= ($fornecedor['regime_tributario'] ?? '') === 'lucro_presumido'   ? 'selected' : '' ?>>Lucro Presumido</option>
                                <option value="lucro_real"        <?= ($fornecedor['regime_tributario'] ?? '') === 'lucro_real'        ? 'selected' : '' ?>>Lucro Real</option>
                                <option value="mei"               <?= ($fornecedor['regime_tributario'] ?? '') === 'mei'               ? 'selected' : '' ?>>MEI</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Limite de Crédito</label>
                            <?php
                            $creditoFmt = '';
                            if (!empty($fornecedor['credito_limite'])) {
                                $creditoFmt = number_format((float)$fornecedor['credito_limite'], 2, ',', '.');
                            }
                            ?>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" name="credito_limite" class="form-control"
                                       placeholder="0,00 — deixe em branco para sem limite"
                                       value="<?= $creditoFmt ?>">
                            </div>
                            <div class="form-text">Deixe em branco para não definir limite.</div>
                        </div>
                        <?php if ($fornecedor): ?>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="ativo" class="form-select">
                                <option value="1" <?= ($fornecedor['ativo'] ?? 1) ? 'selected' : '' ?>>Ativo</option>
                                <option value="0" <?= !($fornecedor['ativo'] ?? 1) ? 'selected' : '' ?>>Inativo</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold d-block">Utilização</label>
                            <div class="d-flex gap-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="para_compras" value="1" id="chkCompras"
                                        <?= ($fornecedor['para_compras'] ?? 1) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="chkCompras">
                                        <i class="bi bi-cart me-1 text-primary"></i>Compras / Cotações
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="para_contratos" value="1" id="chkContratos"
                                        <?= ($fornecedor['para_contratos'] ?? 0) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="chkContratos">
                                        <i class="bi bi-file-earmark-check me-1 text-success"></i>Contratos
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Descrição do Serviço</label>
                            <textarea name="descricao_servico" class="form-control" rows="2"
                                      placeholder="Descreva o que este fornecedor produz ou fornece para a empresa..."><?= e($fornecedor['descricao_servico'] ?? '') ?></textarea>
                            <div class="form-text">Ex: Fornecimento de pneus e acessórios, Serviços de pintura automotiva, Locação de equipamentos...</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Observa&ccedil;&otilde;es</label>
                            <textarea name="observacoes" class="form-control" rows="4"
                                      placeholder="Anote condi&ccedil;&otilde;es comerciais, contatos, restri&ccedil;&otilde;es ou qualquer informa&ccedil;&atilde;o relevante."><?= e($fornecedor['observacoes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salvar</button>
                        <a href="<?= url('fornecedores') ?>" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($cnpjAtivo)): ?>
<script>
(function () {
    var btn = document.getElementById('btnBuscarCnpj');
    if (!btn) return;
    var msg = document.getElementById('msgCnpj');

    // Preenche só o que está vazio: num cadastro novo isso preenche tudo, e num
    // fornecedor existente não apaga o que alguém ajustou à mão. O que veio
    // diferente vira aviso, para a pessoa decidir.
    function aplicar(dados) {
        var avisos = [];
        Object.keys(dados).forEach(function (campo) {
            var el = document.querySelector('[name="' + campo + '"]');
            var valor = (dados[campo] || '').toString();
            if (!el || valor === '') return;

            var atual = (el.value || '').trim();
            if (atual === '') {
                el.value = valor;
                el.classList.add('border-success');
            } else if (atual.toLowerCase() !== valor.toLowerCase()) {
                var rotulo = el.closest('div') && el.closest('div').querySelector('label')
                    ? el.closest('div').querySelector('label').textContent.replace(' *', '').trim()
                    : campo;
                avisos.push(rotulo + ': Receita informa "' + valor + '"');
            }
        });
        return avisos;
    }

    function alerta(classe, html) {
        var div = document.createElement('div');
        div.className = 'alert ' + classe + ' mt-3 mb-0 small';
        div.innerHTML = html;
        var form = document.querySelector('form');
        form.parentNode.insertBefore(div, form);
    }

    btn.addEventListener('click', function () {
        // Só dígitos na URL: a rota aceita [a-zA-Z0-9-], então ponto e barra dariam 404.
        var digitos = (document.getElementById('campoCnpj').value || '').replace(/\D/g, '');
        if (digitos.length !== 14) {
            msg.className = 'form-text text-danger';
            msg.textContent = 'Digite os 14 dígitos do CNPJ.';
            return;
        }

        btn.disabled = true;
        msg.className = 'form-text text-muted';
        msg.textContent = 'Consultando a Receita…';

        fetch('<?= rtrim($_ENV['APP_URL'] ?? '', '/') ?>/api/cnpj/' + digitos)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) {
                    msg.className = 'form-text text-danger';
                    msg.textContent = d.erro || 'Não foi possível consultar.';
                    return;
                }
                var avisos = aplicar(d.dados || {});
                msg.className = 'form-text text-success';
                msg.textContent = 'Dados encontrados e campos vazios preenchidos.';

                if (d.aviso) {
                    alerta('alert-danger', '<i class="bi bi-exclamation-triangle me-2"></i>' + d.aviso);
                }
                if (avisos.length) {
                    alerta('alert-warning', '<strong>Campos já preenchidos foram mantidos.</strong><br>' + avisos.join('<br>'));
                }
            })
            .catch(function () {
                msg.className = 'form-text text-danger';
                msg.textContent = 'Falha de comunicação com o servidor.';
            })
            .finally(function () { btn.disabled = false; });
    });
})();
</script>
<?php endif; ?>
