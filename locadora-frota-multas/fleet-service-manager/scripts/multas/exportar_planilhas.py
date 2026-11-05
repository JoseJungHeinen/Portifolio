"""
Exporta as planilhas de multas para JSON normalizado, que o importador
(scripts/multas/importar.ts) carrega no banco.

Uso:
    python -I scripts/multas/exportar_planilhas.py \
        --controle "C:/.../CONTROLE DE MULTAS - Nova.xlsx" \
        --captura  "C:/RPA/Multas_Geral.xlsx" \
        --saida    data/import

Requer apenas openpyxl. Lê os valores já calculados pelo Excel
(data_only), então salve as planilhas no Excel antes de exportar.
"""

import argparse
import datetime as dt
import json
import os
import warnings

import openpyxl

warnings.filterwarnings("ignore")


def txt(v):
    if v is None:
        return None
    if isinstance(v, (dt.datetime, dt.date)):
        return v.strftime("%Y-%m-%d")
    s = str(v).strip()
    return s or None


def num(v):
    if v is None or v == "":
        return None
    if isinstance(v, (int, float)):
        return round(float(v), 2)
    s = str(v).replace("R$", "").strip().replace(".", "").replace(",", ".")
    try:
        return round(float(s), 2)
    except ValueError:
        return None


def data(v):
    """Datas vêm como datetime, serial do Excel ou texto dd/mm/aaaa [às hh:mm]."""
    if v is None or v == "":
        return None
    if isinstance(v, dt.datetime):
        return v.isoformat(timespec="minutes")
    if isinstance(v, dt.date):
        return v.isoformat()
    if isinstance(v, (int, float)) and 20000 < v < 80000:
        return (dt.datetime(1899, 12, 30) + dt.timedelta(days=float(v))).date().isoformat()
    s = str(v).strip()
    for fmt in ("%d/%m/%Y às %H:%M", "%d/%m/%Y as %H:%M", "%d/%m/%Y %H:%M", "%d/%m/%Y"):
        try:
            d = dt.datetime.strptime(s, fmt)
            return d.isoformat(timespec="minutes") if "%H" in fmt else d.date().isoformat()
        except ValueError:
            pass
    return None


def linhas(ws, inicio=2):
    for r in ws.iter_rows(min_row=inicio, values_only=True):
        if r and any(c is not None and str(c).strip() != "" for c in r):
            yield r


def exportar_controle(wb):
    out = []
    for i, r in enumerate(linhas(wb["Controle Geral"]), start=2):
        r = list(r) + [None] * 24
        if not txt(r[4]):  # sem série não há multa
            continue
        out.append(
            {
                "linha": i,
                "placa": txt(r[0]),
                "loja": txt(r[1]),
                "orgaoCodigo": txt(r[2]),
                "autuador": txt(r[3]),
                "serie": txt(r[4]),
                "tipo": txt(r[5]),
                "codigoInfracao": txt(r[6]),
                "valor": num(r[7]),
                "dataInfracaoTexto": txt(r[8]),
                "dataInfracao": data(r[8]),
                "abertoPagamento": txt(r[9]),
                "dataCaptura": data(r[10]),
                "prazoIdentificacao": data(r[11]) or txt(r[11]),
                "email": txt(r[12]),
                "obs": txt(r[13]),
                "identificado": txt(r[14]),
                "faturado": txt(r[15]),
                "pagamento": txt(r[16]),
                "planilhaStatus": txt(r[17]),
                "planilhaEtapa": r[18] if isinstance(r[18], str) else txt(r[18]),
                "planilhaVencimentoDetran": txt(r[19]),
            }
        )
    return out


def exportar_detran(wb):
    out = []
    for i, r in enumerate(linhas(wb["Detran"]), start=2):
        r = list(r) + [None] * 23
        if not txt(r[4]):
            continue
        venc = txt(r[9])
        out.append(
            {
                "linha": i,
                "placa": txt(r[0]),
                "loja": txt(r[1]),
                "orgaoCodigo": txt(r[2]),
                "autuador": txt(r[3]),
                "serie": txt(r[4]),
                "tipo": txt(r[5]),
                "codigoInfracao": txt(r[6]),
                "valor": num(r[7]),
                "dataInfracaoTexto": txt(r[8]),
                "dataInfracao": data(r[8]),
                "vencimentoTexto": venc,
                "vencimento": data(r[9]),
                "dataCaptura": data(r[10]),
            }
        )
    return out


def exportar_veiculos(wb):
    out = []
    for r in linhas(wb["Veículos"]):
        r = list(r) + [None] * 4
        if txt(r[1]):
            out.append(
                {"modelo": txt(r[0]), "placa": txt(r[1]).upper(), "renavam": txt(r[2]),
                 "loja": txt(r[3]), "empresa": "EXEMPLO"}
            )
    if "Carros Eurovale" in wb.sheetnames:
        for r in linhas(wb["Carros Eurovale"]):
            r = list(r) + [None] * 3
            if txt(r[0]):
                out.append(
                    {"modelo": txt(r[1]), "placa": txt(r[0]).upper(), "renavam": txt(r[2]),
                     "loja": None, "empresa": "EUROVALE"}
                )
    return out


def exportar_painel(wb):
    ws = wb["Gráfico - Controle"]
    out = {"linhas": [], "lojas": []}
    secao = None
    for r in ws.iter_rows(values_only=True):
        rot, q, v = (r + (None,) * 6)[2:5]
        if rot == "Status":
            secao = "linhas"
            continue
        if rot == "Lojas":
            secao = "lojas"
            continue
        if not rot or secao is None:
            continue
        if secao == "linhas":
            out["linhas"].append({"rotulo": str(rot).strip(), "quantidade": q, "valor": num(v)})
            if str(rot).startswith("Total Geral"):
                secao = None
        elif secao == "lojas":
            if str(rot).startswith("Total"):
                secao = None
                continue
            out["lojas"].append({"loja": str(rot).strip(), "total": q, "emAberto": v})
    return out


def exportar_tabelas_detran(wb):
    orgaos = []
    for r in linhas(wb["Configuração"]):
        if r[1] is not None and r[2]:
            orgaos.append({"codigo": txt(r[1]), "nome": txt(r[2])})
    infracoes = []
    for r in linhas(wb["Planilha1"]):
        r = list(r) + [None] * 8
        if txt(r[0]) and txt(r[3]):
            infracoes.append(
                {"codigo": txt(r[0]), "descricao": txt(r[3]), "amparoLegal": txt(r[4]),
                 "infrator": txt(r[5]), "gravidade": txt(r[6]), "orgaoCompetente": txt(r[7])}
            )
    return orgaos, infracoes


def exportar_captura_robo(wb):
    """Aba DADOS do Multas_Geral.xlsx: colunas A-H = card bruto do robô,
    I-R = o que as fórmulas extraíram (guardado para conferência)."""
    out = []
    for i, r in enumerate(linhas(wb["DADOS"]), start=2):
        r = list(r) + [None] * 26
        if not txt(r[0]):
            continue
        out.append(
            {
                "linha": i,
                "bruto": {
                    "placaModelo": r[0] or "", "orgao": str(r[1] or ""), "serie": str(r[2] or ""),
                    "descricao": r[3] or "", "ocorrida": r[4] or "", "local": r[5] or "",
                    "situacao": r[6] or "", "loja": r[7] or "",
                },
                "planilha": {
                    "valida": txt(r[24]), "placa": txt(r[8]), "serie": txt(r[12]),
                    "orgao": txt(r[10]), "tipo": txt(r[13]), "valor": num(r[15]),
                    "data": txt(r[16]), "vencimento": txt(r[17]),
                },
            }
        )
    return out


def salvar(pasta, nome, dados):
    caminho = os.path.join(pasta, nome)
    with open(caminho, "w", encoding="utf-8") as f:
        json.dump(dados, f, ensure_ascii=False, indent=1)
    n = len(dados) if isinstance(dados, list) else "-"
    print(f"  {nome}: {n} registros")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--controle", required=True)
    ap.add_argument("--captura")
    ap.add_argument("--saida", default="data/import")
    a = ap.parse_args()
    os.makedirs(a.saida, exist_ok=True)

    wb = openpyxl.load_workbook(a.controle, read_only=True, data_only=True)
    print("Exportando", a.controle)
    salvar(a.saida, "controle_geral.json", exportar_controle(wb))
    salvar(a.saida, "detran.json", exportar_detran(wb))
    salvar(a.saida, "veiculos.json", exportar_veiculos(wb))
    salvar(a.saida, "painel_planilha.json", exportar_painel(wb))

    if a.captura:
        wb2 = openpyxl.load_workbook(a.captura, read_only=True, data_only=True)
        print("Exportando", a.captura)
        orgaos, infracoes = exportar_tabelas_detran(wb2)
        salvar(a.saida, "orgaos.json", orgaos)
        salvar(a.saida, "infracoes.json", infracoes)
        salvar(a.saida, "captura_robo.json", exportar_captura_robo(wb2))


if __name__ == "__main__":
    main()
