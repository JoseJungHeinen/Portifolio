#!/bin/bash
# Fluxos publicados cujo NOME promete faixa de valor e cujas colunas estao vazias.
# Espelha cli/verificar_faixas_valor.php. Somente leitura.
set -u
echo "===== FLUXOS COM FAIXA PROMETIDA NO NOME E COLUNAS VAZIAS ====="
sudo mysql Notas -e "
SELECT t.nome AS fluxo, tv.numero_versao AS ver,
       (SELECT COUNT(*) FROM nf_processos p
         WHERE p.template_versao_id = tv.id AND p.is_teste=0 AND p.deletado_em IS NULL) AS processos
  FROM nf_template_versoes tv
  JOIN nf_templates t ON t.id = tv.template_id
 WHERE tv.status='publicado' AND t.ativo=1
   AND tv.valor_min IS NULL AND tv.valor_max IS NULL
   AND (t.nome LIKE '%até%' OR t.nome LIKE '%acima%' OR t.nome LIKE '%abaixo%'
        OR t.nome LIKE '%R\$%' OR t.nome LIKE '%\$ %')
 ORDER BY processos DESC, t.nome;"

echo
echo "===== RESUMO ====="
sudo mysql Notas -e "
SELECT COUNT(*) AS versoes_publicadas,
       SUM(tv.valor_min IS NOT NULL OR tv.valor_max IS NOT NULL) AS declaram_faixa,
       SUM(tv.valor_min IS NULL AND tv.valor_max IS NULL)        AS sem_faixa
  FROM nf_template_versoes tv
  JOIN nf_templates t ON t.id = tv.template_id
 WHERE tv.status='publicado' AND t.ativo=1;"

echo
echo "===== OS QUE JA DECLARAM FAIXA (para conferir sobreposicao/lacuna) ====="
sudo mysql Notas -e "
SELECT t.nome AS fluxo, tv.numero_versao AS ver, tv.valor_min, tv.valor_max
  FROM nf_template_versoes tv
  JOIN nf_templates t ON t.id = tv.template_id
 WHERE tv.status='publicado' AND t.ativo=1
   AND (tv.valor_min IS NOT NULL OR tv.valor_max IS NOT NULL)
 ORDER BY t.nome;"
echo "===== FIM ====="
