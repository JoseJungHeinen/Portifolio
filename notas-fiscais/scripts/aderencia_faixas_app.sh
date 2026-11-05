#!/bin/bash
# Os processos JA ABERTOS nesses fluxos respeitam a faixa que o nome promete?
# Se nao respeitam, preencher a faixa bloqueia pratica corrente. Somente leitura.
set -u
echo "===== ADERENCIA DOS PROCESSOS A FAIXA PROMETIDA NO NOME ====="
sudo mysql Notas -e "
SELECT t.nome AS fluxo, tv.numero_versao AS ver,
       COUNT(p.id) AS procs,
       SUM(p.valor_nf IS NULL)            AS sem_valor,
       MIN(p.valor_nf)                    AS menor,
       MAX(p.valor_nf)                    AS maior,
       ROUND(AVG(p.valor_nf),2)           AS media,
       SUM(p.valor_nf > 1000)             AS acima_1000,
       SUM(p.valor_nf <= 1000)            AS ate_1000,
       SUM(p.valor_nf > 500)              AS acima_500,
       SUM(p.valor_nf <= 500)             AS ate_500
  FROM nf_template_versoes tv
  JOIN nf_templates t ON t.id = tv.template_id
  LEFT JOIN nf_processos p ON p.template_versao_id = tv.id
       AND p.is_teste = 0 AND p.deletado_em IS NULL
 WHERE tv.status='publicado' AND t.ativo=1
   AND tv.valor_min IS NULL AND tv.valor_max IS NULL
   AND (t.nome LIKE '%até%' OR t.nome LIKE '%acima%' OR t.nome LIKE '%abaixo%'
        OR t.nome LIKE '%R\$%' OR t.nome LIKE '%\$ %')
 GROUP BY tv.id
 ORDER BY procs DESC;"

echo
echo "===== MESMO TESTE NOS 8 QUE JA DECLARAM FAIXA (violam a propria faixa?) ====="
sudo mysql Notas -e "
SELECT t.nome AS fluxo, tv.valor_min AS vmin, tv.valor_max AS vmax,
       COUNT(p.id) AS procs,
       SUM(tv.valor_min IS NOT NULL AND p.valor_nf < tv.valor_min) AS abaixo_do_min,
       SUM(tv.valor_max IS NOT NULL AND p.valor_nf > tv.valor_max) AS acima_do_max
  FROM nf_template_versoes tv
  JOIN nf_templates t ON t.id = tv.template_id
  LEFT JOIN nf_processos p ON p.template_versao_id = tv.id
       AND p.is_teste = 0 AND p.deletado_em IS NULL
 WHERE tv.status='publicado' AND t.ativo=1
   AND (tv.valor_min IS NOT NULL OR tv.valor_max IS NOT NULL)
 GROUP BY tv.id ORDER BY procs DESC;"

echo
echo "===== VALORES COM CENTAVOS NA ZONA DE LACUNA (500,01-500,99 / 1000,01-1000,99) ====="
sudo mysql Notas -e "
SELECT COUNT(*) AS na_lacuna_500  FROM nf_processos
 WHERE is_teste=0 AND deletado_em IS NULL AND valor_nf > 500  AND valor_nf < 501;
SELECT COUNT(*) AS na_lacuna_1000 FROM nf_processos
 WHERE is_teste=0 AND deletado_em IS NULL AND valor_nf > 1000 AND valor_nf < 1001;"
echo "===== FIM ====="
