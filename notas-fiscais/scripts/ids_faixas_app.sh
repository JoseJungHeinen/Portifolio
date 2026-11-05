#!/bin/bash
set -u
echo "===== 6 LIMPOS (a preencher) ====="
sudo mysql Notas -N -B -e "
SELECT tv.id, tv.numero_versao, tv.valor_min, tv.valor_max, t.nome
  FROM nf_template_versoes tv JOIN nf_templates t ON t.id=tv.template_id
 WHERE tv.status='publicado' AND t.ativo=1
   AND tv.valor_min IS NULL AND tv.valor_max IS NULL
   AND t.nome IN ('Manutenção de Seminovos (até \$ 1000,00)',
                  'Locadora (acima de \$ 1000,00)',
                  'Contabilidade (acima de R\$500)',
                  'Peças (até R\$500)',
                  'Contabilidade (até R\$500)',
                  'Manutenção de Seminovos / Locadora (até \$ 1000,00)')
 ORDER BY t.nome;"

echo
echo "===== 4 COM min=501 (a ajustar para 500.01) ====="
sudo mysql Notas -N -B -e "
SELECT tv.id, tv.valor_min, tv.valor_max, t.nome
  FROM nf_template_versoes tv JOIN nf_templates t ON t.id=tv.template_id
 WHERE tv.status='publicado' AND t.ativo=1 AND tv.valor_min = 501.00
 ORDER BY t.nome;"

echo
echo "===== 3 CONFLITANTES (NAO tocar - confirmar que seguem sem faixa) ====="
sudo mysql Notas -N -B -e "
SELECT tv.id, tv.valor_min, tv.valor_max, t.nome
  FROM nf_template_versoes tv JOIN nf_templates t ON t.id=tv.template_id
 WHERE tv.status='publicado' AND t.ativo=1
   AND t.nome IN ('Locadora (até \$ 1000,00)',
                  'Manutenção de Seminovos (acima de \$ 1000,00)',
                  'Manutenção de Seminovos / Locadora (acima de \$ 1000,00)');"

echo
echo "===== USUARIO PARA A AUDITORIA ====="
sudo mysql Notas -N -B -e "SELECT id, nome, perfil FROM usuarios WHERE email='vendadireta@exemplo.com';"
echo "===== FIM ====="
