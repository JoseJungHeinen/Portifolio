#!/bin/bash
# Item 0 do PLANO-EVOLUCAO-FLUXOS: preenche a faixa de valor dos 6 fluxos sem
# conflito e uniformiza o limite inferior dos 4 que usavam 501 -> 500.01.
#
# NAO toca nos 3 fluxos em que a faixa do nome contraria o uso real
# (Locadora ate $1000, Manut. Seminovos acima $1000, Manut. Sem./Locadora acima $1000).
#
# Rede de seguranca: aborta ANTES de escrever se algum processo ja existente
# violaria a faixa nova.
set -u

echo "===== 1. ESTADO ATUAL ====="
sudo mysql Notas -e "
SELECT t.nome AS fluxo, tv.numero_versao AS ver, tv.valor_min, tv.valor_max
  FROM nf_template_versoes tv JOIN nf_templates t ON t.id=tv.template_id
 WHERE tv.id IN (
   'a66c0151-a080-4dd3-9745-1a665df7e178','8b1a1007-a2ca-4351-b9a4-b1795b0438c5',
   'dd0540dc-239d-4940-8762-a8e4d2eb8cb5','cf06f2d3-bfcb-4a09-9675-f648d75fafdb',
   '29fa1cc3-bc03-4933-b80e-7470616b3d42','7029b2a2-6829-48c4-80c6-f03dbb30edad',
   '67984ac3-f13b-4b16-9bd1-7c1bd9d474c1','10e4a7cf-86c9-4e33-9163-04233bd8118c',
   '2a797fc0-fbee-4427-a287-35b3c7500613','2c02a150-015a-4872-b54d-4275ccbef09a')
 ORDER BY t.nome;"

echo
echo "===== 2. REDE DE SEGURANCA: algum processo existente violaria a faixa nova? ====="
VIOL=$(sudo mysql Notas -N -B -e "
SELECT COUNT(*) FROM nf_processos p
  JOIN (
    SELECT 'a66c0151-a080-4dd3-9745-1a665df7e178' AS vid, CAST(500.01  AS DECIMAL(15,2)) AS vmin, CAST(NULL AS DECIMAL(15,2)) AS vmax
    UNION ALL SELECT '8b1a1007-a2ca-4351-b9a4-b1795b0438c5', NULL,    500.00
    UNION ALL SELECT 'dd0540dc-239d-4940-8762-a8e4d2eb8cb5', 1000.01, NULL
    UNION ALL SELECT 'cf06f2d3-bfcb-4a09-9675-f648d75fafdb', NULL,    1000.00
    UNION ALL SELECT '29fa1cc3-bc03-4933-b80e-7470616b3d42', NULL,    1000.00
    UNION ALL SELECT '7029b2a2-6829-48c4-80c6-f03dbb30edad', NULL,    500.00
    UNION ALL SELECT '67984ac3-f13b-4b16-9bd1-7c1bd9d474c1', 500.01,  NULL
    UNION ALL SELECT '10e4a7cf-86c9-4e33-9163-04233bd8118c', 500.01,  100000.00
    UNION ALL SELECT '2a797fc0-fbee-4427-a287-35b3c7500613', 500.01,  NULL
    UNION ALL SELECT '2c02a150-015a-4872-b54d-4275ccbef09a', 500.01,  100000.00
  ) f ON f.vid = p.template_versao_id
 WHERE p.is_teste = 0 AND p.deletado_em IS NULL AND p.valor_nf IS NOT NULL
   AND ((f.vmin IS NOT NULL AND p.valor_nf < f.vmin)
     OR (f.vmax IS NOT NULL AND p.valor_nf > f.vmax));")

echo "Processos existentes que violariam a faixa nova: $VIOL"
if [ "$VIOL" != "0" ]; then
  echo
  echo "ABORTADO — nada foi alterado. Ha processo aberto fora da faixa que seria gravada."
  exit 1
fi
echo "Nenhum. Seguro prosseguir."

echo
echo "===== 3. APLICANDO ====="
sudo mysql Notas <<'SQL'
CREATE TEMPORARY TABLE tmp_faixa_antes AS
  SELECT id, valor_min AS antes_min, valor_max AS antes_max
    FROM nf_template_versoes
   WHERE id IN (
     'a66c0151-a080-4dd3-9745-1a665df7e178','8b1a1007-a2ca-4351-b9a4-b1795b0438c5',
     'dd0540dc-239d-4940-8762-a8e4d2eb8cb5','cf06f2d3-bfcb-4a09-9675-f648d75fafdb',
     '29fa1cc3-bc03-4933-b80e-7470616b3d42','7029b2a2-6829-48c4-80c6-f03dbb30edad',
     '67984ac3-f13b-4b16-9bd1-7c1bd9d474c1','10e4a7cf-86c9-4e33-9163-04233bd8118c',
     '2a797fc0-fbee-4427-a287-35b3c7500613','2c02a150-015a-4872-b54d-4275ccbef09a');

START TRANSACTION;

-- 6 fluxos que estavam sem faixa nenhuma
UPDATE nf_template_versoes SET valor_min = 500.01,  valor_max = NULL    WHERE id = 'a66c0151-a080-4dd3-9745-1a665df7e178';
UPDATE nf_template_versoes SET valor_min = NULL,    valor_max = 500.00  WHERE id = '8b1a1007-a2ca-4351-b9a4-b1795b0438c5';
UPDATE nf_template_versoes SET valor_min = 1000.01, valor_max = NULL    WHERE id = 'dd0540dc-239d-4940-8762-a8e4d2eb8cb5';
UPDATE nf_template_versoes SET valor_min = NULL,    valor_max = 1000.00 WHERE id = 'cf06f2d3-bfcb-4a09-9675-f648d75fafdb';
UPDATE nf_template_versoes SET valor_min = NULL,    valor_max = 1000.00 WHERE id = '29fa1cc3-bc03-4933-b80e-7470616b3d42';
UPDATE nf_template_versoes SET valor_min = NULL,    valor_max = 500.00  WHERE id = '7029b2a2-6829-48c4-80c6-f03dbb30edad';

-- 4 que ja tinham faixa: 501 -> 500.01, fechando a lacuna medida
UPDATE nf_template_versoes SET valor_min = 500.01 WHERE id = '67984ac3-f13b-4b16-9bd1-7c1bd9d474c1';
UPDATE nf_template_versoes SET valor_min = 500.01 WHERE id = '10e4a7cf-86c9-4e33-9163-04233bd8118c';
UPDATE nf_template_versoes SET valor_min = 500.01 WHERE id = '2a797fc0-fbee-4427-a287-35b3c7500613';
UPDATE nf_template_versoes SET valor_min = 500.01 WHERE id = '2c02a150-015a-4872-b54d-4275ccbef09a';

-- Trilha de auditoria. usuario_id NULL de proposito: a alteracao foi aplicada
-- por script de manutencao, nao por uma conta de tela. Atribui-la a um usuario
-- que nao clicou nada seria pior que nao atribuir.
INSERT INTO logs_auditoria
      (id, usuario_id, entidade_tipo, entidade_id, acao, payload_antes, payload_depois, ip_address, user_agent, criado_em)
SELECT UUID(), NULL, 'nf_template_versao', tv.id, 'nf_template_versao.faixa_valor_corrigida',
       JSON_OBJECT('valor_min', a.antes_min, 'valor_max', a.antes_max),
       JSON_OBJECT('valor_min', tv.valor_min, 'valor_max', tv.valor_max,
                   'origem', 'scripts/preencher_faixas_app.sh - item 0 do docs/PLANO-EVOLUCAO-FLUXOS.md',
                   'motivo', 'faixa prometida no nome do fluxo nao estava declarada; limite inferior uniformizado para X+0,01'),
       NULL, 'srv.ps1/manutencao', NOW()
  FROM tmp_faixa_antes a
  JOIN nf_template_versoes tv ON tv.id = a.id
 WHERE a.antes_min <=> tv.valor_min = 0 OR a.antes_max <=> tv.valor_max = 0;

COMMIT;
DROP TEMPORARY TABLE tmp_faixa_antes;
SQL

echo
echo "===== 4. ESTADO FINAL ====="
sudo mysql Notas -e "
SELECT t.nome AS fluxo, tv.numero_versao AS ver, tv.valor_min, tv.valor_max
  FROM nf_template_versoes tv JOIN nf_templates t ON t.id=tv.template_id
 WHERE tv.status='publicado' AND t.ativo=1
   AND (tv.valor_min IS NOT NULL OR tv.valor_max IS NOT NULL)
 ORDER BY t.nome;"

echo
echo "===== 5. AUDITORIA GRAVADA ====="
sudo mysql Notas -e "
SELECT entidade_id, payload_antes, payload_depois
  FROM logs_auditoria WHERE acao='nf_template_versao.faixa_valor_corrigida'
 ORDER BY criado_em DESC LIMIT 12;"

echo
echo "===== 6. CONFERENCIA: nenhum processo existente fora da propria faixa ====="
sudo mysql Notas -e "
SELECT t.nome AS fluxo, tv.valor_min AS vmin, tv.valor_max AS vmax, COUNT(p.id) AS procs,
       SUM(tv.valor_min IS NOT NULL AND p.valor_nf < tv.valor_min) AS abaixo,
       SUM(tv.valor_max IS NOT NULL AND p.valor_nf > tv.valor_max) AS acima
  FROM nf_template_versoes tv
  JOIN nf_templates t ON t.id=tv.template_id
  LEFT JOIN nf_processos p ON p.template_versao_id=tv.id AND p.is_teste=0 AND p.deletado_em IS NULL
 WHERE tv.status='publicado' AND t.ativo=1 AND (tv.valor_min IS NOT NULL OR tv.valor_max IS NOT NULL)
 GROUP BY tv.id ORDER BY t.nome;"
echo "===== FIM ====="
