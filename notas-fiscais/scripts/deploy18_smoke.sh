#!/bin/bash
# deploy_18 — smoke test. Usa /Notas/ (a raiz do dominio devolve 200 de OUTRA
# pagina e nao testa nada do Notas — ver CLAUDE.md §Ambientes).
set -u
APP=/var/www/exemplo/notas

echo "=== HTTP ==="
for u in "https://app.exemplo.local/" \
         "https://app.exemplo.local/" \
         "https://app.exemplo.local/"; do
  printf '  %-64s %s\n' "${u#https://app.exemplo.local/" "$(curl -sL -o /dev/null -w '%{http_code}' "$u")"
done

echo
echo "=== versoes publicadas ==="
for f in public/assets/js/modules/templates.js public/assets/css/modules/templates.css \
         src/Models/Process.php src/Models/Task.php src/Models/Template.php \
         src/Models/TableList.php src/Models/Etapa.php \
         src/Controllers/ProcessController.php src/Controllers/TaskController.php \
         src/Controllers/TemplateController.php src/Controllers/TableController.php \
         views/templates/editor.php views/processos/detalhe.php \
         views/processos/novo/step2.php views/processos/preencher_etapa.php \
         views/tables/items.php views/tables/_etapa_campos.php; do
  printf '  %-46s %s\n' "$f" "$(grep -ohE '@versao [A-Za-z]+_[0-9]+ V_[0-9]+_[0-9/]+' "$APP/$f" | head -1 | sed 's/@versao //')"
done

echo
echo "=== erros recentes do PHP (ultimos 5 min) ==="
LOG=$(ls -t /var/log/apache2/*error*.log 2>/dev/null | head -1)
if [ -n "$LOG" ]; then
  sudo -n awk -v d="$(date -d '5 min ago' '+%s')" '{print}' "$LOG" | tail -15
else
  echo "  (log nao encontrado)"
fi

echo
echo "=== sanidade do banco: nada quebrou nas etapas existentes ==="
sudo -n mysql -N -B Notas -e "
  SELECT CONCAT('processos abertos: ', COUNT(*)) FROM nf_processos WHERE status='aberto' AND deletado_em IS NULL;
  SELECT CONCAT('etapas com decisao_saltos: ', COUNT(*)) FROM nf_processo_etapas WHERE decisao_saltos IS NOT NULL;
  SELECT CONCAT('etapas de catalogo: ', COUNT(*)) FROM nf_etapas WHERE ativo=1;
  SELECT CONCAT('etapas especiais (salto/abre): ', COUNT(*)) FROM nf_etapas WHERE salto_incondicional=1 OR abre_fluxo=1;"

echo
echo "=== limpeza ==="
rm -rf ~/deploy ~/deploy_sql ~/deploy18.tar.gz ~/sql18.tar.gz && echo "  temporarios removidos"
