<?php
// @versao Ver.001.140926
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="<?= url('fornecedores') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Voltar
            </a>
        </div>
        <div>
            <?php if (can(['comprador','admin'])): ?>
                <a href="<?= url('fornecedores/' . $fornecedor['id'] . '/editar') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Editar
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- COLUNA ESQUERDA: Dados Cadastrais -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <h5 class="mb-0">
                            <i class="bi bi-building me-2"></i>
                            <?= e($fornecedor['razao_social']) ?>
                            <?php if ($fornecedor['ativo']): ?>
                                <span class="badge bg-success ms-1">Ativo</span>
                            <?php else: ?>
                                <span class="badge bg-danger ms-1">Inativo</span>
                            <?php endif; ?>
                        </h5>
                        <form id="frmRating" method="POST" action="<?= url('fornecedores/' . $fornecedor['id'] . '/avaliar') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="nota"       id="ratingNota" value="<?= $minhaAvaliacao['nota'] ?? '' ?>">
                            <input type="hidden" name="comentario"                value="<?= e($minhaAvaliacao['comentario'] ?? '') ?>">
                            <div class="d-flex align-items-center gap-1">
                                <?php for($s = 1; $s <= 5; $s++): ?>
                                <i class="bi bi-star-fill star-rate <?= $s <= ($minhaAvaliacao['nota'] ?? 0) ? 'text-warning' : 'text-white opacity-50' ?>"
                                   data-v="<?= $s ?>" style="cursor:pointer;font-size:1.15rem"
                                   title="<?= $s ?> estrela<?= $s > 1 ? 's' : '' ?>"></i>
                                <?php endfor; ?>
                                <?php if ($totalAvaliacoes > 0): ?>
                                <small class="ms-2 text-white opacity-75"><?= number_format($mediaAvaliacoes, 1, ',', '.') ?> (<?= $totalAvaliacoes ?>)</small>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:10px;opacity:0.65" class="mt-1 text-end">
                                <?= $minhaAvaliacao ? 'Sua nota: ' . $minhaAvaliacao['nota'] . ' ★  —  clique para alterar' : 'Clique nas estrelas para avaliar' ?>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">CNPJ</dt>
                        <dd class="col-sm-8"><code><?= e(preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $fornecedor['cnpj'] ?? '')) ?></code></dd>

                        <dt class="col-sm-4">Nome Fantasia</dt>
                        <dd class="col-sm-8"><?= e($fornecedor['nome_fantasia'] ?: '—') ?></dd>

                        <dt class="col-sm-4">E-mail</dt>
                        <dd class="col-sm-8">
                            <a href="mailto:<?= $fornecedor['email'] ?>"><?= e($fornecedor['email']) ?></a>
                        </dd>

                        <dt class="col-sm-4">Telefone</dt>
                        <dd class="col-sm-8"><?= e($fornecedor['telefone'] ?: '—') ?></dd>

                        <dt class="col-sm-4">Endereço</dt>
                        <dd class="col-sm-8">
                            <?= e($fornecedor['endereco'] ?: '—') ?>
                            <?php if (!empty($fornecedor['cep'])): ?>
                                <span class="text-muted">— CEP <?= e(preg_replace('/(\d{5})(\d{3})/', '$1-$2', $fornecedor['cep'])) ?></span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-4">Cidade/UF</dt>
                        <dd class="col-sm-8">
                            <?= e($fornecedor['cidade'] ?: '—') ?><?= $fornecedor['estado'] ? ' / ' . e($fornecedor['estado']) : '' ?>
                        </dd>

                        <?php if (!empty($fornecedor['regime_tributario'])): ?>
                        <?php
                        $regimes = [
                            'simples_nacional' => 'Simples Nacional',
                            'lucro_presumido'  => 'Lucro Presumido',
                            'lucro_real'       => 'Lucro Real',
                            'mei'              => 'MEI',
                        ];
                        ?>
                        <dt class="col-sm-4">Regime Tributário</dt>
                        <dd class="col-sm-8"><span class="fw-semibold"><?= $regimes[$fornecedor['regime_tributario']] ?? e($fornecedor['regime_tributario']) ?></span></dd>
                        <?php endif; ?>

                        <?php if (!empty($fornecedor['observacoes'])): ?>
                        <dt class="col-sm-4">Observa&ccedil;&otilde;es</dt>
                        <dd class="col-sm-8"><?= nl2br(e($fornecedor['observacoes'])) ?></dd>
                        <?php endif; ?>

                    </dl>
                </div>
            </div>

            <!-- CRÉDITO -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-credit-card me-2 text-success"></i>Crédito</span>
                    <?php if (can(['comprador','admin'])): ?>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse"
                            data-bs-target="#collapseCredito">
                        <i class="bi bi-pencil me-1"></i>Editar limite
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php
                    $limite     = (float)($fornecedor['credito_limite'] ?? 0);
                    $utilizado  = $creditoUtilizado;
                    $disponivel = $limite > 0 ? max(0, $limite - $utilizado) : null;
                    $pctUsado   = ($limite > 0) ? min(100, round($utilizado / $limite * 100, 1)) : 0;
                    $corBarra   = $pctUsado >= 90 ? 'bg-danger' : ($pctUsado >= 70 ? 'bg-warning' : 'bg-success');
                    ?>

                    <?php if ($limite > 0): ?>
                    <div class="row g-3 mb-3">
                        <div class="col-4 text-center">
                            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Limite</div>
                            <div class="fw-bold fs-6">R$ <?= number_format($limite, 2, ',', '.') ?></div>
                        </div>
                        <div class="col-4 text-center">
                            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Utilizado</div>
                            <div class="fw-bold fs-6 <?= $pctUsado >= 90 ? 'text-danger' : '' ?>">R$ <?= number_format($utilizado, 2, ',', '.') ?></div>
                        </div>
                        <div class="col-4 text-center">
                            <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Disponível</div>
                            <div class="fw-bold fs-6 text-success">R$ <?= number_format($disponivel, 2, ',', '.') ?></div>
                        </div>
                    </div>
                    <div class="progress mb-1" style="height:10px">
                        <div class="progress-bar <?= $corBarra ?>" style="width:<?= $pctUsado ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between">
                        <small class="text-muted"><?= $pctUsado ?>% utilizado</small>
                        <?php if ($pctUsado >= 90): ?>
                        <small class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Limite crítico</small>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="d-flex align-items-center gap-3">
                        <div>
                            <div class="text-muted" style="font-size:12px">Sem limite definido</div>
                            <?php if ($utilizado > 0): ?>
                            <div class="mt-1">Saldo em aberto: <strong>R$ <?= number_format($utilizado, 2, ',', '.') ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (can(['comprador','admin'])): ?>
                    <div class="collapse mt-3" id="collapseCredito">
                        <hr class="my-2">
                        <form method="POST" action="<?= url('fornecedores/' . $fornecedor['id']) ?>" class="row g-2 align-items-end">
                            <?= csrf_field() ?>
                            <div class="col">
                                <label class="form-label fw-semibold mb-1" style="font-size:12px">Novo Limite (R$)</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">R$</span>
                                    <input type="text" name="credito_limite" class="form-control"
                                           placeholder="0,00 — em branco para remover"
                                           value="<?= $limite > 0 ? number_format($limite, 2, ',', '.') : '' ?>">
                                </div>
                            </div>
                            <!-- Campos obrigatórios do update (enviados como hidden para não sobrescrever) -->
                            <input type="hidden" name="razao_social"       value="<?= e($fornecedor['razao_social']) ?>">
                            <input type="hidden" name="nome_fantasia"      value="<?= e($fornecedor['nome_fantasia'] ?? '') ?>">
                            <input type="hidden" name="email"              value="<?= e($fornecedor['email'] ?? '') ?>">
                            <input type="hidden" name="telefone"           value="<?= e($fornecedor['telefone'] ?? '') ?>">
                            <input type="hidden" name="endereco"           value="<?= e($fornecedor['endereco'] ?? '') ?>">
                            <input type="hidden" name="cep"               value="<?= e($fornecedor['cep'] ?? '') ?>">
                            <input type="hidden" name="descricao_servico" value="<?= e($fornecedor['descricao_servico'] ?? '') ?>">
                            <input type="hidden" name="para_compras"      value="<?= (int)($fornecedor['para_compras'] ?? 0) ?>">
                            <input type="hidden" name="para_contratos"    value="<?= (int)($fornecedor['para_contratos'] ?? 0) ?>">
                            <input type="hidden" name="cidade"             value="<?= e($fornecedor['cidade'] ?? '') ?>">
                            <input type="hidden" name="estado"             value="<?= e($fornecedor['estado'] ?? '') ?>">
                            <input type="hidden" name="regime_tributario"  value="<?= e($fornecedor['regime_tributario'] ?? '') ?>">
                            <input type="hidden" name="observacoes"        value="<?= e($fornecedor['observacoes'] ?? '') ?>">
                            <input type="hidden" name="ativo"              value="<?= $fornecedor['ativo'] ?>">
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-success">
                                    <i class="bi bi-check-lg me-1"></i>Salvar
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- COLUNA DIREITA: Histórico e Avaliações -->
        <div class="col-lg-6">
            <!-- HISTÓRICO DE COMPRAS -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2"></i>Histórico de Preços Recente</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($historico)): ?>
                        <p class="text-muted mb-0">Nenhuma compra registrada para este fornecedor.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Data</th>
                                        <th>Item</th>
                                        <th class="text-end">Valor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historico as $h): ?>
                                        <tr>
                                            <td><?= date('d/m/Y', strtotime($h['registrado_em'])) ?></td>
                                            <td><?= e($h['item_nome']) ?></td>
                                            <td class="text-end"><?= money($h['valor_unitario']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- AVALIAÇÕES DE OUTROS USUÁRIOS -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0 fw-semibold">
                        <i class="bi bi-chat-left-text me-2"></i>
                        Avaliações (<?= count($avaliacoes) ?>)
                    </h6>
                </div>
                <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                    <?php if (empty($avaliacoes)): ?>
                        <p class="text-muted mb-0">Nenhuma avaliação ainda. Seja o primeiro a avaliar!</p>
                    <?php else: ?>
                        <?php foreach ($avaliacoes as $i => $av): ?>
                            <div class="<?= $i < count($avaliacoes) - 1 ? 'border-bottom pb-3 mb-3' : '' ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= e($av['usuario_nome']) ?></strong>
                                        <span class="badge bg-secondary ms-2"><?= ucfirst($av['perfil']) ?></span>
                                        <div class="mt-1">
                                            <?php for($s = 1; $s <= 5; $s++): ?>
                                                <i class="bi bi-star-fill <?= $s <= $av['nota'] ? 'text-warning' : 'text-muted' ?>" style="font-size: 0.9rem;"></i>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <small class="text-muted">
                                        <?= date('d/m/Y H:i', strtotime($av['criado_em'])) ?>
                                    </small>
                                </div>
                                
                                <?php if ($av['comentario']): ?>
                                    <p class="mt-2 mb-0"><?= nl2br(e($av['comentario'])) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CRÉDITOS DO FORNECEDOR -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">
                        <i class="bi bi-credit-card me-1 text-success"></i>Créditos com o Fornecedor
                        <?php if ($totalCreditosDisp > 0): ?>
                        <span class="badge bg-success ms-1">R$ <?= number_format($totalCreditosDisp, 2, ',', '.') ?> disponível</span>
                        <?php endif; ?>
                    </span>
                    <?php if (can(['comprador','gestor','admin'])): ?>
                    <button class="btn btn-sm btn-outline-success"
                            data-bs-toggle="collapse" data-bs-target="#formNovoCredito">
                        <i class="bi bi-plus-lg me-1"></i>Adicionar
                    </button>
                    <?php endif; ?>
                </div>

                <div class="collapse" id="formNovoCredito">
                    <div class="card-body border-bottom bg-light">
                        <form method="POST" action="<?= url('fornecedores/' . $fornecedor['id'] . '/creditos') ?>"
                              class="row g-2 align-items-end">
                                  <?= csrf_field() ?>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold mb-1">Valor (R$) *</label>
                                <input type="text" name="valor" class="form-control form-control-sm"
                                       placeholder="0,00" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold mb-1">Descrição *</label>
                                <input type="text" name="descricao" class="form-control form-control-sm"
                                       placeholder="Ex: Devolução NF 1234, Nota de crédito..." required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold mb-1">Validade</label>
                                <input type="date" name="validade" class="form-control form-control-sm">
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-success btn-sm">
                                    <i class="bi bi-check-lg me-1"></i>Salvar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($creditosFornecedor)): ?>
                        <p class="text-muted small p-3 mb-0"><i class="bi bi-inbox me-1"></i>Nenhum crédito registrado.</p>
                    <?php else: ?>
                    <table class="table table-sm align-middle mb-0" style="font-size:.83rem">
                        <thead class="table-light">
                            <tr>
                                <th class="px-3">Descrição</th>
                                <th>Valor</th>
                                <th>Validade</th>
                                <th>Status</th>
                                <th>Adicionado por</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($creditosFornecedor as $cred): ?>
                        <tr class="<?= $cred['usado'] ? 'text-muted' : '' ?>">
                            <td class="px-3"><?= e($cred['descricao']) ?></td>
                            <td class="fw-semibold">R$ <?= number_format((float)$cred['valor'], 2, ',', '.') ?></td>
                            <td><?= $cred['validade'] ? date('d/m/Y', strtotime($cred['validade'])) : '—' ?></td>
                            <td>
                                <?php if ($cred['usado']): ?>
                                    <span class="badge bg-secondary">Utilizado</span>
                                <?php elseif ($cred['validade'] && $cred['validade'] < date('Y-m-d')): ?>
                                    <span class="badge bg-danger">Vencido</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Disponível</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= e($cred['criado_por_nome'] ?? '—') ?></td>
                            <td class="text-end pe-3">
                                <?php if (!$cred['usado'] && can(['gestor','admin'])): ?>
                                <form method="POST"
                                      action="<?= url('fornecedores/creditos/' . $cred['id'] . '/excluir') ?>"
                                      class="d-inline">
                                          <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-outline-danger btn-sm"
                                            onclick="return confirm('Remover este crédito?')"
                                            title="Remover">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-paperclip me-1 text-primary"></i>Documentos</span>
                    <?php if (can(['comprador','gestor','admin'])): ?>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#formUpload">
                        <i class="bi bi-upload me-1"></i>Enviar arquivo
                    </button>
                    <?php endif; ?>
                </div>
            
                <?php if (can(['comprador','gestor','admin'])): ?>
                <div class="collapse" id="formUpload">
                    <div class="card-body border-bottom bg-light">
                        <form method="POST" action="<?= url('fornecedores/' . $fornecedor['id'] . '/documentos') ?>"
                              enctype="multipart/form-data" class="row g-2 align-items-end">
                                  <?= csrf_field() ?>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Tipo</label>
                                <select name="tipo" class="form-select form-select-sm">
                                    <option value="contrato">Contrato</option>
                                    <option value="cartao_cnpj">Cartão CNPJ</option>
                                    <option value="certidao">Certidão</option>
                                    <option value="nota_fiscal">Nota Fiscal</option>
                                    <option value="outros">Outros</option>
                                </select>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label small fw-semibold">Arquivo <span class="text-muted">(PDF, imagem, Word, Excel — máx. 10MB)</span></label>
                                <input type="file" name="arquivo" class="form-control form-control-sm" required
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary btn-sm w-100">
                                    <i class="bi bi-upload me-1"></i>Enviar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            
                <div class="card-body p-0">
                    <?php if (empty($documentos)): ?>
                        <p class="text-muted small p-3 mb-0"><i class="bi bi-inbox me-1"></i>Nenhum documento anexado.</p>
                    <?php else: ?>
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Arquivo</th>
                                <th>Tipo</th>
                                <th>Tamanho</th>
                                <th>Enviado por</th>
                                <th>Data</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $tiposLabel = ['contrato'=>'Contrato','cartao_cnpj'=>'Cartão CNPJ','certidao'=>'Certidão','nota_fiscal'=>'Nota Fiscal','outros'=>'Outros'];
                        foreach ($documentos as $doc): ?>
                            <tr>
                                <td class="ps-3">
                                    <a href="<?= url('fornecedores/documentos/' . $doc['id'] . '/download') ?>"
                                       target="_blank" class="text-decoration-none small">
                                        <i class="bi bi-file-earmark me-1 text-primary"></i><?= e($doc['nome_original']) ?>
                                    </a>
                                </td>
                                <td><span class="badge bg-secondary"><?= $tiposLabel[$doc['tipo']] ?? e($doc['tipo']) ?></span></td>
                                <td class="text-muted small"><?= number_format($doc['tamanho_bytes'] / 1024, 1) ?> KB</td>
                                <td class="small"><?= e($doc['enviado_por_nome']) ?></td>
                                <td class="small text-muted"><?= date_br($doc['criado_em']) ?></td>
                                <td class="pe-2 text-end">
                                    <?php if (can(['gestor','admin'])): ?>
                                    <form method="POST" action="<?= url('fornecedores/documentos/' . $doc['id'] . '/excluir') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                                onclick="return confirm('Remover este documento?')" title="Remover">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- COMENTÁRIO -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white d-flex align-items-center">
                    <span class="fw-semibold"><i class="bi bi-chat-left-text me-2 text-primary"></i>Seu Comentário</span>
                </div>
                <div class="card-body">
                    <?php if ($minhaAvaliacao): ?>
                    <form method="POST" action="<?= url('fornecedores/' . $fornecedor['id'] . '/avaliar') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="nota" value="<?= $minhaAvaliacao['nota'] ?>" >
                        <textarea name="comentario" class="form-control mb-3" rows="3"
                                  placeholder="Compartilhe sua experiência com este fornecedor..."><?= e($minhaAvaliacao['comentario'] ?? '') ?></textarea>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-save me-1"></i>Salvar comentário
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    onclick="if(confirm('Remover sua avaliação?')) { const f = document.createElement('form'); f.method='POST'; f.action='<?= url('fornecedores/' . $fornecedor['id'] . '/remover-avaliacao') ?>'; document.body.appendChild(f); f.submit(); }">
                                <i class="bi bi-trash me-1"></i>Remover avaliação
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1 text-primary"></i>Clique nas estrelas ao lado do nome para avaliar e liberar o comentário.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.star-rate { transition: color 0.15s, opacity 0.15s; }
.star-rate:hover { color: #ffc107 !important; opacity: 1 !important; }
</style>

<script>
(function(){
    const stars   = document.querySelectorAll('.star-rate');
    const hidden  = document.getElementById('ratingNota');
    const current = () => parseInt(hidden ? hidden.value : 0) || 0;

    stars.forEach(star => {
        star.addEventListener('mouseover', function(){
            const n = parseInt(this.dataset.v);
            stars.forEach((s,i) => {
                s.classList.toggle('text-warning',  i < n);
                s.classList.remove('text-white');
                s.classList.toggle('opacity-50', i >= n);
            });
        });
        star.addEventListener('mouseout', function(){
            const n = current();
            stars.forEach((s,i) => {
                s.classList.toggle('text-warning',  i < n);
                s.classList.toggle('text-white',    i >= n);
                s.classList.toggle('opacity-50',    i >= n);
            });
        });
        star.addEventListener('click', function(){
            if(hidden) hidden.value = this.dataset.v;
            document.getElementById('frmRating').submit();
        });
    });
})();
</script>
