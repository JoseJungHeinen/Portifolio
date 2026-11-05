#!/bin/bash
set -u
echo "===== A. ETAPA ATUAL PENDENTE HA MAIS DE 7 DIAS (so a etapa corrente) ====="
sudo mysql Notas -e "
SELECT p.codigo, t.nome AS template, p.etapa_atual, e.nome_etapa, e.tipo_aprovador,
       DATEDIFF(NOW(), p.criado_em) AS dias_aberto
  FROM nf_processos p
  JOIN nf_processo_etapas e ON e.processo_id = p.id
       AND e.numero_etapa = p.etapa_atual AND e.status = 'pendente'
  JOIN nf_template_versoes v ON v.id = p.template_versao_id
  JOIN nf_templates t ON t.id = v.template_id
 WHERE p.status IN ('aberto','devolvido') AND p.is_teste = 0 AND p.deletado_em IS NULL
   AND DATEDIFF(NOW(), p.criado_em) > 7
 ORDER BY dias_aberto DESC LIMIT 15;"

echo
echo "--- total de processos parados por faixa ---"
sudo mysql Notas -e "
SELECT CASE WHEN DATEDIFF(NOW(),p.criado_em) > 30 THEN 'mais de 30 dias'
            WHEN DATEDIFF(NOW(),p.criado_em) > 14 THEN '15 a 30 dias'
            WHEN DATEDIFF(NOW(),p.criado_em) >  7 THEN '8 a 14 dias'
            ELSE 'ate 7 dias' END AS faixa, COUNT(*) AS qtd
  FROM nf_processos p
 WHERE p.status IN ('aberto','devolvido') AND p.is_teste = 0 AND p.deletado_em IS NULL
 GROUP BY faixa ORDER BY qtd DESC;"

echo
echo "===== B. CHURN DE TEMPLATE (quantas versoes por template) ====="
sudo mysql Notas -e "
SELECT COUNT(*) AS templates, SUM(v) AS versoes_totais, ROUND(AVG(v),1) AS media, MAX(v) AS maximo
  FROM (SELECT template_id, COUNT(*) v FROM nf_template_versoes GROUP BY template_id) x;"
sudo mysql Notas -e "
SELECT t.nome, COUNT(*) AS versoes
  FROM nf_template_versoes v JOIN nf_templates t ON t.id=v.template_id
 GROUP BY t.id HAVING versoes >= 5 ORDER BY versoes DESC LIMIT 15;"

echo
echo "===== C. TEMPLATES PUBLICADOS ATIVOS ====="
sudo mysql Notas -e "
SELECT COUNT(DISTINCT t.id) AS templates_com_versao_publicada
  FROM nf_templates t JOIN nf_template_versoes v ON v.template_id=t.id
 WHERE v.status='publicado' AND t.ativo=1;"

echo
echo "===== D. PARES QUE SO DIFEREM POR FAIXA DE VALOR ====="
sudo mysql Notas -e "
SELECT t.nome, v.valor_min, v.valor_max, v.filial_id IS NOT NULL AS tem_filial
  FROM nf_templates t JOIN nf_template_versoes v ON v.template_id=t.id
 WHERE v.status='publicado' AND t.ativo=1
   AND (t.nome LIKE '%R\$500%' OR t.nome LIKE '%1000,00%')
 ORDER BY t.nome;"

echo
echo "===== E. SoD: TOTAL DE PROCESSOS COM APROVADOR REPETIDO ====="
sudo mysql Notas -e "
SELECT COUNT(*) AS processos_com_aprovador_repetido FROM (
  SELECT p.id
    FROM nf_processo_etapas e JOIN nf_processos p ON p.id=e.processo_id
   WHERE e.status='aprovado' AND e.atuado_por IS NOT NULL
     AND p.is_teste=0 AND p.deletado_em IS NULL
   GROUP BY p.id, e.atuado_por HAVING COUNT(*)>1) x;"

echo
echo "===== F. ETAPAS COM SLA CONFIGURADO ====="
sudo mysql Notas -e "SELECT COUNT(*) AS total_etapas, SUM(sla_horas IS NOT NULL) AS com_sla FROM nf_etapas;"

echo "===== FIM ====="
