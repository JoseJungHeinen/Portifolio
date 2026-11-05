#!/bin/bash
set -u
echo "===== A. OS 14 COM FAIXA: o tipo de nota exige valor? ====="
echo "(exige_valor=0 => o step 1 IGNORA a faixa e o campo fica editavel no step 2:"
echo " so o guard novo, ainda nao publicado, fecharia esse caminho)"
sudo mysql Notas -e "
SELECT t.nome AS fluxo, tv.valor_min AS vmin, tv.valor_max AS vmax,
       IFNULL(li.valor,'(qualquer tipo)') AS tipo_nota,
       IFNULL(li.exige_valor,'-')         AS exige_valor
  FROM nf_template_versoes tv
  JOIN nf_templates t ON t.id=tv.template_id
  LEFT JOIN nf_lista_itens li ON li.id = tv.tipo_nota_id
 WHERE tv.status='publicado' AND t.ativo=1
   AND (tv.valor_min IS NOT NULL OR tv.valor_max IS NOT NULL)
 ORDER BY li.exige_valor, t.nome;"

echo
echo "===== B. AINDA SEM FAIXA E COM FAIXA PROMETIDA NO NOME ====="
sudo mysql Notas -e "
SELECT t.nome AS fluxo, tv.numero_versao AS ver,
       (SELECT COUNT(*) FROM nf_processos p WHERE p.template_versao_id=tv.id
         AND p.is_teste=0 AND p.deletado_em IS NULL) AS processos
  FROM nf_template_versoes tv JOIN nf_templates t ON t.id=tv.template_id
 WHERE tv.status='publicado' AND t.ativo=1
   AND tv.valor_min IS NULL AND tv.valor_max IS NULL
   AND (t.nome LIKE '%até%' OR t.nome LIKE '%acima%' OR t.nome LIKE '%abaixo%'
        OR t.nome LIKE '%R\$%' OR t.nome LIKE '%\$ %')
 ORDER BY processos DESC;"

echo
echo "===== C. RESUMO ====="
sudo mysql Notas -e "
SELECT COUNT(*) AS publicadas,
       SUM(tv.valor_min IS NOT NULL OR tv.valor_max IS NOT NULL) AS com_faixa,
       SUM(tv.valor_min IS NULL AND tv.valor_max IS NULL)        AS sem_faixa
  FROM nf_template_versoes tv JOIN nf_templates t ON t.id=tv.template_id
 WHERE tv.status='publicado' AND t.ativo=1;"
echo "===== FIM ====="
