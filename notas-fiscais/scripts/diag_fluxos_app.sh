#!/bin/bash
# Diagnostico de uso real dos fluxos no Server-APP (banco Notas).
# Somente leitura. Nao altera nada.
set -u
Q() { sudo mysql -N -B Notas -e "$1"; }

echo "===== 1. VOLUME POR TEMPLATE (processos reais, nao teste) ====="
sudo mysql Notas -e "
SELECT t.nome AS template, v.numero_versao AS ver, v.status AS ver_status,
       JSON_LENGTH(v.campos) AS campos, JSON_LENGTH(v.etapas) AS etapas,
       v.tem_financeiro AS fin, v.permite_sem_nota AS sem_nota,
       COUNT(p.id) AS processos
  FROM nf_template_versoes v
  JOIN nf_templates t ON t.id = v.template_id
  LEFT JOIN nf_processos p ON p.template_versao_id = v.id
       AND p.is_teste = 0 AND p.deletado_em IS NULL
 GROUP BY v.id
 ORDER BY processos DESC, t.nome;"

echo
echo "===== 2. STATUS DOS PROCESSOS REAIS ====="
sudo mysql Notas -e "
SELECT status, COUNT(*) AS qtd
  FROM nf_processos WHERE is_teste = 0 AND deletado_em IS NULL
 GROUP BY status ORDER BY qtd DESC;"

echo
echo "===== 3. PROCESSOS SEM NOTA (fluxo de tarefa puro) ====="
sudo mysql Notas -e "
SELECT sem_nota, COUNT(*) AS qtd
  FROM nf_processos WHERE is_teste = 0 AND deletado_em IS NULL
 GROUP BY sem_nota;"

echo
echo "===== 4. TOP FORNECEDOR_NOME (denuncia o fornecedor fantasma) ====="
sudo mysql Notas -e "
SELECT fornecedor_nome, fornecedor_doc, COUNT(*) AS qtd
  FROM nf_processos WHERE is_teste = 0 AND deletado_em IS NULL
 GROUP BY fornecedor_nome, fornecedor_doc ORDER BY qtd DESC LIMIT 15;"

echo
echo "===== 5. ABERTURA POR MES ====="
sudo mysql Notas -e "
SELECT DATE_FORMAT(criado_em,'%Y-%m') AS mes, COUNT(*) AS abertos
  FROM nf_processos WHERE is_teste = 0 AND deletado_em IS NULL
 GROUP BY mes ORDER BY mes DESC LIMIT 8;"

echo
echo "===== 6. ETAPAS PENDENTES HA MAIS DE 72H (fluxo travado) ====="
sudo mysql Notas -e "
SELECT p.codigo, t.nome AS template, e.numero_etapa, e.nome_etapa,
       e.tipo_aprovador,
       TIMESTAMPDIFF(HOUR, COALESCE(h.criado_em, p.criado_em), NOW()) AS horas_parado
  FROM nf_processo_etapas e
  JOIN nf_processos p ON p.id = e.processo_id
  JOIN nf_template_versoes v ON v.id = p.template_versao_id
  JOIN nf_templates t ON t.id = v.template_id
  LEFT JOIN nf_processo_historico h ON h.processo_id = p.id
       AND h.numero_etapa = e.numero_etapa
 WHERE e.status = 'pendente' AND p.status IN ('aberto','devolvido')
   AND p.is_teste = 0 AND p.deletado_em IS NULL
 GROUP BY e.id
HAVING horas_parado > 72
 ORDER BY horas_parado DESC LIMIT 20;"

echo
echo "===== 7. CATALOGO DE ETAPAS EM USO (tipos especiais) ====="
sudo mysql Notas -e "
SELECT nome, somente_observacao AS obs, salto_incondicional AS ir_para,
       abre_fluxo, assinatura_externa AS assin,
       (decisao_lista_id IS NOT NULL) AS decisao, sla_horas, ativo
  FROM nf_etapas ORDER BY nome;"

echo
echo "===== 8. ANEXOS: VOLUME E TIPOS ====="
sudo mysql Notas -e "
SELECT COUNT(*) AS anexos, ROUND(SUM(tamanho_bytes)/1048576,1) AS mb,
       COUNT(DISTINCT processo_id) AS processos_com_anexo
  FROM nf_processo_anexos WHERE deletado_em IS NULL AND is_teste = 0;"

echo
echo "===== 9. MESMA PESSOA APROVANDO 2+ ETAPAS DO MESMO PROCESSO (SoD) ====="
sudo mysql Notas -e "
SELECT p.codigo, t.nome AS template, e.atuado_por,
       COUNT(*) AS etapas_aprovadas
  FROM nf_processo_etapas e
  JOIN nf_processos p ON p.id = e.processo_id
  JOIN nf_template_versoes v ON v.id = p.template_versao_id
  JOIN nf_templates t ON t.id = v.template_id
 WHERE e.status = 'aprovado' AND e.atuado_por IS NOT NULL
   AND p.is_teste = 0 AND p.deletado_em IS NULL
 GROUP BY p.id, e.atuado_por
HAVING etapas_aprovadas > 1
 ORDER BY etapas_aprovadas DESC LIMIT 20;"

echo
echo "===== 10. SEGREDOS EM CLARO NA CONFIG ====="
sudo mysql Notas -e "
SELECT chave, CASE WHEN valor = '' THEN '(vazio)'
                   ELSE CONCAT(LEFT(valor,4), '... (', CHAR_LENGTH(valor), ' chars)') END AS amostra
  FROM nf_configuracoes
 WHERE chave LIKE '%key%' OR chave LIKE '%senha%' OR chave LIKE '%token%' OR chave LIKE '%pass%'
 ORDER BY chave;"

echo
echo "===== 11. NOTIFICACOES DE SLA DISPARADAS ====="
sudo mysql Notas -e "SELECT COUNT(*) AS alertas_sla FROM nf_sla_notificacoes;" 2>/dev/null || echo "(tabela vazia/ausente)"

echo
echo "===== FIM ====="
