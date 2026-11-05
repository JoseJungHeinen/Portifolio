#!/bin/bash
# deploy_18 — dump preventivo + migrations 045 e 046 no banco Notas.
set -u

rm -rf ~/deploy_sql && mkdir -p ~/deploy_sql
tar xzf ~/sql18.tar.gz -C ~/deploy_sql

DUMP=~/Notas_$(date +%Y%m%d_%H%M%S).sql
echo "=== dump preventivo ==="
sudo -n mysqldump Notas > "$DUMP" && ls -lh "$DUMP"

echo
echo "=== estado ANTES ==="
sudo -n mysql -N -B Notas -e "
  SELECT 'nf_processo_etapas', COLUMN_NAME FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='Notas' AND TABLE_NAME='nf_processo_etapas'
     AND COLUMN_NAME IN ('decisao_campo','decisao_saltos','abre_template_id')
  UNION ALL
  SELECT 'nf_etapas', COLUMN_NAME FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='Notas' AND TABLE_NAME='nf_etapas'
     AND COLUMN_NAME IN ('salto_incondicional','abre_fluxo')
  UNION ALL
  SELECT 'nf_processos', COLUMN_NAME FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='Notas' AND TABLE_NAME='nf_processos'
     AND COLUMN_NAME='processo_pai_id';"
echo "(vazio acima = nenhuma coluna existe ainda, esperado)"

echo
echo "=== aplicando 045 ==="
sudo -n mysql Notas < ~/deploy_sql/045_etapa_decisao_saltos.sql && echo "045 ok"

echo
echo "=== aplicando 046 ==="
sudo -n mysql Notas < ~/deploy_sql/046_etapa_salto_e_abrir_fluxo.sql && echo "046 ok"

echo
echo "=== estado DEPOIS ==="
sudo -n mysql -N -B Notas -e "
  SELECT 'nf_processo_etapas', COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='Notas' AND TABLE_NAME='nf_processo_etapas'
     AND COLUMN_NAME IN ('decisao_campo','decisao_saltos','abre_template_id')
  UNION ALL
  SELECT 'nf_etapas', COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='Notas' AND TABLE_NAME='nf_etapas'
     AND COLUMN_NAME IN ('salto_incondicional','abre_fluxo')
  UNION ALL
  SELECT 'nf_processos', COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA='Notas' AND TABLE_NAME='nf_processos'
     AND COLUMN_NAME='processo_pai_id';"

echo
echo "=== dump guardado em: $DUMP ==="
