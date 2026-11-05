#!/bin/bash
# deploy_18 — publica os 17 arquivos das features em /var/www/exemplo/notas.
# fora-horario.php fica de fora por decisão do usuário (assume /public/, padrão
# do Server_novo; aqui o caminho canônico é sem /public/).
set -u
APP=/var/www/exemplo/notas

# Tira o item excluído do pacote antes de publicar — assim o laço abaixo não
# tem como levá-lo por engano.
rm -f ~/deploy/views/errors/fora-horario.php
rmdir ~/deploy/views/errors 2>/dev/null

echo "=== publicando ==="
N=0
while IFS= read -r src; do
  rel="${src#$HOME/deploy/}"
  dst="$APP/$rel"
  sudo -n mkdir -p "$(dirname "$dst")"
  sudo -n cp "$src" "$dst"
  sudo -n chown www-data:www-data "$dst"
  sudo -n chmod 644 "$dst"
  N=$((N+1))
  printf "  %-46s ok\n" "$rel"
done < <(find ~/deploy -type f | sort)
echo "publicados: $N"

echo
echo "=== MD5 local (pacote) x servidor ==="
DIF=0
while IFS= read -r src; do
  rel="${src#$HOME/deploy/}"
  a=$(md5sum "$src" | cut -d' ' -f1)
  b=$(md5sum "$APP/$rel" 2>/dev/null | cut -d' ' -f1)
  if [ "$a" != "$b" ]; then echo "  DIVERGE: $rel"; DIF=1; fi
done < <(find ~/deploy -type f)
[ $DIF -eq 0 ] && echo "  todos conferem"

echo
echo "=== permissoes do arquivo novo ==="
ls -l "$APP/views/processos/preencher_etapa.php"
