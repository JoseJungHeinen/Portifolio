<?php
// @versao Ver.003.301026
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
                        <?php $sitAtual = $fornecedor['situacao'] ?? 'pendente'; ?>
                        <span class="badge fs-6 <?= ['pendente' => 'bg-warning text-dark', 'homologado' => 'bg-success', 'bloqueado' => 'bg-danger'][$sitAtual] ?? 'bg-secondary' ?>">
                            <?= e(\App\Models\Fornecedor::SITUACOES[$sitAtual] ?? $sitAtual) ?>
                        </span>
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

                        <dt class="col-sm-4">Regime confirmado em</dt>
                        <dd class="col-sm-8">
                            <?= !empty($fornecedor['regime_confirmado_em']) ? date_br($fornecedor['regime_confirmado_em']) : '<span class="text-muted">Nunca</span>' ?>
                            <?php if (can(['comprador','admin'])): ?>
                            <form method="POST" action="<?= url('fornecedores/' . $fornecedor['id'] . '/confirmar-regime') ?>" class="d-inline ms-2">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-success btn-sm py-0">
                                    <i class="bi bi-check2-circle me-1"></i>Confirmar regime hoje
                                </button>
                            </form>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-4">CND Federal</dt>
                        <dd class="col-sm-8">
                            <?php $seloCnd = \App\Models\Fornecedor::seloCnd($fornecedor['cnd_federal_validade'] ?? null); ?>
                            <?php if (!empty($fornecedor['cnd_federal_validade']) || !empty($fornecedor['cnd_federal_situacao'])): ?>
                                <?= e(\App\Models\Fornecedor::CND_SITUACOES[$fornecedor['cnd_federal_situacao'] ?? ''] ?? 'Situação não informada') ?>
                                <?= !empty($fornecedor['cnd_federal_validade']) ? ' — válida até ' . date_br($fornecedor['cnd_federal_validade']) : '' ?>
                            <?php else: ?>
                                <span class="text-muted">Não informada</span>
                            <?php endif; ?>
                            <?php if ($seloCnd): ?>
                                <span class="badge <?= e($seloCnd['classe']) ?> ms-1" style="<?= e($seloCnd['estilo']) ?>"><?= e($seloCnd['texto']) ?></span>
                            <?php endif; ?>
                        </dd>

                        <dt class="col-sm-4">Contato fiscal</dt>
                        <dd class="col-sm-8"><?= e($fornecedor['contato_fiscal'] ?: '—') ?></dd>

                        <?php
                        $marcas = array_filter([
                            !empty($fornecedor['banco_proprio'])         ? 'Conta em nome do próprio CNPJ' : null,
                            !empty($fornecedor['mei'])                   ? 'MEI' : null,
                            !empty($fornecedor['mao_de_obra'])           ? 'Mão de obra no local' . (!empty($fornecedor['doc_trabalhista']) ? ' (documentação trabalhista entregue)' : '') : null,
                            !empty($fornecedor['tem_contrato'])          ? 'Contrato/OC anual' . (!empty($fornecedor['clausula_reequilibrio']) ? ' com cláusula de reequilíbrio' : '') : null,
                            !empty($fornecedor['curva_a'])               ? 'Curva A' : null,
                        ]);
                        ?>
                        <?php if ($marcas): ?>
                        <dt class="col-sm-4">Cadastro</dt>
                        <dd class="col-sm-8"><?php foreach ($marcas as $m): ?><span class="badge bg-light text-secondary border me-1"><?= e($m) ?></span><?php endforeach; ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($mesmaRaiz)): ?>
                        <dt class="col-sm-4"><?= \App\Models\Fornecedor::ehMatriz($fornecedor['cnpj']) ? 'Filiais' : 'Mesma empresa' ?></dt>
                        <dd class="col-sm-8">
                            <?php foreach ($mesmaRaiz as $x): ?>
                            <a href="<?= url('fornecedores/' . $x['id']) ?>" class="btn btn-sm btn-outline-secondary py-0 mb-1">
                                <?= \App\Models\Fornecedor::ehMatriz($x['cnpj']) ? 'Matriz · ' : '' ?><?= e($x['nome_fantasia'] ?: $x['razao_social']) ?> · <?= e(formatCnpj($x['cnpj'])) ?>
                            </a>
                            <?php endforeach; ?>
                        </dd>
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
            <!-- CLASSIFICAÇÃO (etapa 3) -->
            <?php
            $F        = \App\Models\Fornecedor::class;
            $ultima   = $avaliacoes[0] ?? null;
            $notaUlt  = $ultima ? (float)$ultima['nota'] : null;
            $podeEditarClassif = can(['comprador','admin']);
            $periodos = [];
            for ($k = 0, $d = new \DateTimeImmutable('first day of this month'); $k < 8; $k++, $d = $d->modify('-3 months')) {
                $periodos[] = $F::periodoDe($d->format('Y-m-d'));
            }
            $periodos = array_values(array_unique($periodos));
            ?>
            <div class="card border-0 shadow-sm mb-4" id="avaliacao">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="fw-semibold"><i class="bi bi-award me-2 text-primary"></i>Classificação</span>
                    <span>
                        <?php if (!empty($fornecedor['curva_a'])): ?><span class="badge bg-light text-secondary border">Curva A</span><?php endif; ?>
                        <span class="badge <?= ['pendente' => 'bg-warning text-dark', 'homologado' => 'bg-success', 'bloqueado' => 'bg-danger'][$sitAtual] ?? 'bg-secondary' ?>"><?= e($F::SITUACOES[$sitAtual] ?? $sitAtual) ?></span>
                    </span>
                </div>
                <div class="card-body">
                    <?php if ($sitAtual === 'bloqueado'): ?>
                    <div class="alert alert-danger py-2 small"><i class="bi bi-slash-circle me-1"></i>Fornecedor <b>bloqueado</b>: não recebe cotação nem OC.</div>
                    <?php elseif ($sugereBloqueio): ?>
                    <div class="alert alert-danger py-2 small d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span><i class="bi bi-exclamation-octagon me-1"></i>Nota abaixo de 2,0 em dois períodos seguidos — <b>a política prevê bloqueio</b>.</span>
                        <?php if ($podeEditarClassif): ?>
                        <form method="POST" action="<?= url('fornecedores/' . $fornecedor['id'] . '/bloquear') ?>" class="m-0"
                              onsubmit="return confirm('Bloquear este fornecedor? Ele deixa de receber cotação e OC.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="motivo" value="Nota abaixo de 2,0 em dois períodos seguidos">
                            <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-slash-circle me-1"></i>Bloquear fornecedor</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="small text-muted">Última avaliação</div>
                            <?php if ($notaUlt === null): ?>
                                <div class="text-muted">Nenhuma avaliação registrada.</div>
                            <?php else: ?>
                                <div><b class="fs-5 <?= $F::classeNota($notaUlt) ?>"><?= $F::formatarNota($notaUlt) ?></b>
                                    <span class="small text-muted"><?= e($F::periodoRotulo($ultima['periodo'])) ?></span>
                                    <?php if ($F::precisaPlanoAcao($notaUlt)): ?><span class="badge bg-warning text-dark ms-1">Plano de ação</span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted">OCs nos últimos 12 meses</div>
                            <div class="fw-semibold"><?= money($valorOc12m) ?></div>
                        </div>
                    </div>

                    <?php if ($pendencias && $sitAtual !== 'bloqueado'): ?>
                    <div class="alert alert-warning py-2 small mb-3">
                        <b>Pendências:</b>
                        <ul class="mb-0 ps-3">
                            <?php foreach ($pendencias as $txtPend): ?><li><?= e($txtPend) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <?php elseif (!$pendencias): ?>
                    <div class="small text-success mb-3"><i class="bi bi-check2-circle me-1"></i>Cadastro sem pendências.</div>
                    <?php endif; ?>

                    <?php if ($avaliacoes): ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Período</th><th class="text-end">Prazo 20%</th><th class="text-end">Qualid. 20%</th><th class="text-end">Preço 30%</th><th class="text-end">Conform. 30%</th><th class="text-end">Nota</th><?php if ($podeEditarClassif): ?><th></th><?php endif; ?></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($avaliacoes as $av): $n = (float)$av['nota']; ?>
                                <tr title="<?= e(trim(($av['usuario_nome'] ?? '') . ' · ' . datetime_br($av['atualizado_em'] ?? $av['criado_em']))) ?>">
                                    <td><?= e($av['periodo'] ? $F::periodoRotulo($av['periodo']) : date_br($av['criado_em'])) ?></td>
                                    <?php foreach (array_keys($F::CRITERIOS) as $c): ?>
                                    <td class="text-end"><?= $av[$c] !== null ? e(str_replace('.', ',', rtrim(rtrim((string)$av[$c], '0'), '.'))) : '—' ?></td>
                                    <?php endforeach; ?>
                                    <td class="text-end"><b class="<?= $F::classeNota($n) ?>"><?= $F::formatarNota($n) ?></b>
                                        <?php if ($F::precisaPlanoAcao($n)): ?><div><span class="badge bg-warning text-dark">Plano de ação</span></div><?php endif; ?>
                                    </td>
                                    <?php if ($podeEditarClassif): ?>
                                    <td class="text-end">
                                        <form method="POST" action="<?= url('fornecedores/' . $fornecedor['id'] . '/remover-avaliacao') ?>" class="m-0"
                                              onsubmit="return confirm('Remover a avaliação deste período?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="avaliacao_id" value="<?= e($av['id']) ?>">
                                            <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Remover"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php if (!empty($av['comentario'])): ?>
                                <tr><td colspan="<?= $podeEditarClassif ? 7 : 6 ?>" class="small text-muted border-top-0 pt-0"><?= nl2br(e($av['comentario'])) ?></td></tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if ($podeEditarClassif): ?>
                    <a class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" href="#novaAvaliacao" role="button" aria-expanded="false">
                        <i class="bi bi-plus-lg me-1"></i>Nova avaliação
                    </a>
                    <div class="collapse mt-3" id="novaAvaliacao">
                        <form method="POST" action="<?= url('fornecedores/' . $fornecedor['id'] . '/avaliar') ?>" id="frmAvaliacao" class="row g-2">
                            <?= csrf_field() ?>
                            <?php foreach ($F::CRITERIOS as $c => [$rotC, $pesoC]): ?>
                            <div class="col-6 col-md-3">
                                <label class="form-label small mb-0" for="av_<?= $c ?>"><?= e($rotC) ?> (<?= (int)round($pesoC * 100) ?>%)</label>
                                <input type="number" name="<?= $c ?>" id="av_<?= $c ?>" class="form-control form-control-sm js-crit"
                                       min="0" max="5" step="0.5" required data-peso="<?= $pesoC ?>">
                            </div>
                            <?php endforeach; ?>
                            <div class="col-md-4">
                                <label class="form-label small mb-0" for="av_periodo">Período</label>
                                <select name="periodo" id="av_periodo" class="form-select form-select-sm">
                                    <?php foreach ($periodos as $per): ?>
                                    <option value="<?= e($per) ?>"><?= e($F::periodoRotulo($per)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small mb-0" for="av_comentario">Evidências / plano de ação</label>
                                <textarea name="comentario" id="av_comentario" class="form-control form-control-sm" rows="2" maxlength="2000"></textarea>
                            </div>
                            <div class="col-12 small" id="avNota">Notas de 0 a 5. Refazer um período substitui a avaliação dele.</div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Salvar avaliação</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

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

        </div>
    </div>
</div>

<script>
(function () {
    var f = document.getElementById('frmAvaliacao');
    if (!f) return;
    var out = document.getElementById('avNota');
    f.addEventListener('input', function () {
        var cs = f.querySelectorAll('.js-crit'), n = 0, ok = true;
        cs.forEach(function (c) { if (c.value === '') ok = false; n += parseFloat(c.dataset.peso) * parseFloat(c.value || 0); });
        if (!ok) return;
        var txt = n.toFixed(2).replace('.', ',').replace(/0$/, '');
        out.innerHTML = 'Nota ponderada <b>' + txt + '</b> — ' + (n < 2 ? 'abaixo de 2,0: se repetir no próximo período, bloquear'
            : n < 3 ? 'abaixo de 3,0: gerar plano de ação com o fornecedor' : 'dentro do esperado') + '.';
    });
})();
</script>
