"""Converte uma aba de .xlsx em CSV UTF-8 para cli/importar_planilha.php.

Uso: python -I cli/xlsx_para_csv.py <arquivo.xlsx> <aba> <saida.csv>
Requer openpyxl (já instalado no Python da máquina). Lê em modo read_only:
a planilha v4 tem caches de tabela dinâmica que estouram memória no modo normal.
"""
import csv
import datetime
import os
import sys

import openpyxl


def celula(valor):
    if valor is None:
        return ''
    if isinstance(valor, (datetime.datetime, datetime.date)):
        return valor.strftime('%Y-%m-%d')
    if isinstance(valor, float):
        if valor.is_integer():
            return str(int(valor))
        return format(valor, 'f').rstrip('0').rstrip('.')  # nunca notação científica
    return valor


def main(origem, aba, destino):
    if os.path.abspath(origem) == os.path.abspath(destino) or destino.lower().endswith('.xlsx'):
        sys.exit('O destino não pode ser a própria planilha nem um .xlsx.')
    wb = openpyxl.load_workbook(origem, read_only=True, data_only=True)
    if aba not in wb.sheetnames:
        sys.exit(f'Aba "{aba}" não encontrada. Abas: {", ".join(wb.sheetnames)}')
    with open(destino, 'w', newline='', encoding='utf-8') as f:
        escritor = csv.writer(f)
        total = 0
        for linha in wb[aba].iter_rows(values_only=True):
            escritor.writerow([celula(c) for c in linha])
            total += 1
    wb.close()
    print(f'{total} linhas gravadas em {destino}')


if __name__ == '__main__':
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    main(*sys.argv[1:])
