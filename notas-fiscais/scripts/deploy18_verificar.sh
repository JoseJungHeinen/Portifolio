#!/bin/bash
# deploy_18 — extrai o pacote em ~/deploy e roda as verificações ANTES de publicar.
# Não toca em /var/www — só prepara e confere.
set -u

rm -rf ~/deploy
mkdir -p ~/deploy
tar xzf ~/deploy18.tar.gz -C ~/deploy

echo "=== arquivos recebidos ==="
find ~/deploy -type f | sed "s|$HOME/deploy/||" | sort

echo
echo "=== php -l ==="
ERRO=0
while IFS= read -r f; do
  OUT=$(php -l "$f" 2>&1)
  if [ $? -ne 0 ]; then echo "FALHOU: $f"; echo "$OUT"; ERRO=1; fi
done < <(find ~/deploy -name '*.php')
[ $ERRO -eq 0 ] && echo "todos limpos"

echo
echo "=== verificar_versao --comparar ==="
php /var/www/exemplo/notas/cli/verificar_versao.php --comparar ~/deploy /var/www/exemplo/notas

echo
echo "=== diff do item de revisao manual (fora-horario.php) ==="
if [ -f /var/www/exemplo/notas/views/errors/fora-horario.php ]; then
  diff -u /var/www/exemplo/notas/views/errors/fora-horario.php ~/deploy/views/errors/fora-horario.php \
    && echo "(identico ao que ja esta publicado)"
else
  echo "NAO EXISTE no servidor — seria arquivo novo"
fi

echo
echo "=== os 6 que nunca foram deployados: existem la? ==="
for f in public/assets/css/modules/templates.css src/Models/TableList.php \
         src/Controllers/TableController.php views/tables/items.php \
         views/tables/_etapa_campos.php views/processos/preencher_etapa.php; do
  if [ -f "/var/www/exemplo/notas/$f" ]; then
    V=$(grep -ohE '@versao [A-Za-z]+_[0-9]+ V_[0-9]+_[0-9/]+' "/var/www/exemplo/notas/$f" | head -1)
    printf "  %-46s SERVIDOR: %s\n" "$f" "${V:-<sem marca>}"
  else
    printf "  %-46s NAO EXISTE (arquivo novo)\n" "$f"
  fi
done
