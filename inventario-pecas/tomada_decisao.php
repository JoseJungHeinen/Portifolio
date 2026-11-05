<?php
declare(strict_types=1);

session_start();
require 'conexao_inventario.php';

if (empty($_SESSION['inventario_login'])) {
    header('Location: index.php');
    exit;
}

$usuarioLogado = $_SESSION['inventario_login'];
$nomeUsuario   = (string)($usuarioLogado['usuario'] ?? 'Usuário');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Tomada de Decisão</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --red: #2347C8;
      --red-light: #FFEBEE;
      --red-soft: #FFE5E7;
      --amber: #F59E0B;
      --amber-light: #FFFBEB;
      --green: #2E7D32;
      --green-light: #E8F5E9;
      --blue: #1565C0;
      --blue-light: #E3F2FD;
      --gray-25: #FCFCFD;
      --gray-50: #F5F5F7;
      --gray-100: #ECEFF1;
      --gray-200: #E0E0E0;
      --gray-400: #B0BEC5;
      --gray-600: #546E7A;
      --gray-800: #263238;
      --white: #FFFFFF;
      --orange: #FF9800;
      --radius-card: 18px;
      --radius-input: 14px;
      --shadow-soft: 0 10px 20px rgba(15,23,42,0.18);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      background: radial-gradient(circle at top, #FFFFFF 0, #E3F2FD 55%, #CFD8DC 100%);
      color: var(--gray-800);
      -webkit-font-smoothing: antialiased;
    }

    .app { min-height: 100vh; display: flex; flex-direction: column; }

    /* ── HEADER ── */
    .header {
      padding: 10px 16px;
      background: linear-gradient(135deg, #B71C1C, var(--red));
      color: var(--white);
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      box-shadow: 0 4px 10px rgba(183,28,28,0.45);
      position: sticky; top: 0; z-index: 100;
    }
    .header-left { display: flex; align-items: center; gap: 8px; min-width: 0; flex-wrap: wrap; }
    .header-badge {
      padding: 2px 8px; border-radius: 999px;
      background: rgba(255,255,255,0.16);
      font-size: 11px; text-transform: uppercase; letter-spacing: .08em; white-space: nowrap;
    }
    .logout-btn {
      margin-left: auto; padding: 6px 12px; border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.75); background: rgba(244,67,54,0.15);
      color: #fff; font-size: 13px; text-decoration: none;
      display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
      backdrop-filter: blur(4px); white-space: nowrap;
      transition: background 0.15s, box-shadow 0.15s;
    }
    .logout-btn:hover { background: rgba(255,255,255,0.18); }
    .logout-btn svg { width: 14px; height: 14px; }

    /* ── LAYOUT ── */
    .page-content {
      flex: 1; padding: 16px;
      display: flex; flex-direction: column; gap: 12px;
    }

    /* ── CARD ── */
    .card {
      background: rgba(255,255,255,0.98); border-radius: var(--radius-card);
      padding: 14px 16px; display: flex; flex-direction: column; gap: 10px;
      box-shadow: var(--shadow-soft); border: 1px solid rgba(148,163,184,0.25);
    }
    .card-header {
      display: flex; justify-content: space-between; align-items: center;
      font-size: 12px; color: var(--gray-600);
    }
    .card-title { font-size: 14px; font-weight: 700; color: var(--gray-800); }

    /* ── SELECTS DE LOJA / INVENTÁRIO ── */
    .sel-row { display: flex; gap: 10px; flex-wrap: wrap; }
    .sel-group { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 160px; }
    .sel-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-600); }
    .sel-input {
      height: 42px; border-radius: 12px; border: 1.5px solid var(--gray-200);
      padding: 0 32px 0 12px; font-size: 13px; font-weight: 600; color: var(--gray-800);
      background: var(--white); cursor: pointer; outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
      appearance: none; -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23546E7A' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 12px center;
    }
    .sel-input:focus { border-color: var(--red); box-shadow: 0 0 0 2px rgba(35,71,200,0.14); }
    .sel-input:disabled { opacity: 0.45; cursor: default; }

    /* ── FILTRO TIPO ── */
    .filter-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
    .filter-tab {
      padding: 5px 12px; border-radius: 999px; border: 1.5px solid var(--gray-200);
      background: var(--white); font-size: 12px; font-weight: 600;
      color: var(--gray-600); cursor: pointer; transition: all 0.15s;
    }
    .filter-tab:hover { border-color: var(--gray-400); }
    .filter-tab.active { color: var(--white); border-color: transparent; }
    .filter-tab.active.all      { background: var(--gray-800); }
    .filter-tab.active.saldo    { background: var(--amber); }
    .filter-tab.active.nao-cont { background: var(--blue); }

    /* ── STATS ROW ── */
    .stats-row { display: flex; gap: 10px; flex-wrap: wrap; }
    .stat-box {
      flex: 1; min-width: 90px;
      background: rgba(255,255,255,0.9); border-radius: 14px; padding: 10px 12px;
      text-align: center; border: 1px solid rgba(148,163,184,0.2);
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .stat-num { font-size: 22px; font-weight: 700; color: var(--red); }
    .stat-num.amber { color: #B45309; }
    .stat-num.blue  { color: var(--blue); }
    .stat-lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-600); margin-top: 2px; }

    /* ── MAIN GRID (tabela + painel) ── */
    .main-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 12px;
    }
    @media (min-width: 900px) {
      .main-grid { grid-template-columns: 1fr 340px; }
    }

    /* ── ITEM TABLE ── */
    .item-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--gray-200); max-height: 55vh; overflow-y: auto; }
    .item-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .item-table thead { position: sticky; top: 0; z-index: 1; }
    .item-table th {
      background: var(--gray-50); padding: 8px 10px;
      text-align: left; font-size: 11px; text-transform: uppercase;
      letter-spacing: .06em; color: var(--gray-600);
      border-bottom: 1px solid var(--gray-200);
      white-space: nowrap;
    }
    .item-table td {
      padding: 8px 10px; border-bottom: 1px solid var(--gray-100);
      color: var(--gray-800); vertical-align: middle;
    }
    .item-table tr:last-child td { border-bottom: none; }
    .item-table tbody tr { cursor: pointer; transition: background 0.1s; }
    .item-table tbody tr:hover td { background: var(--red-light); }
    .item-table tbody tr.selected td { background: #FFEBEE; }
    .item-table tbody tr.com-decisao td { opacity: 0.55; }

    .tipo-badge {
      padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 600;
      white-space: nowrap;
    }
    .tipo-saldo   { background: var(--amber-light); color: #B45309; }
    .tipo-nao-cnt { background: var(--blue-light);  color: var(--blue); }
    .dec-badge {
      padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 600;
    }
    .dec-aj-pos  { background: var(--green-light);  color: var(--green); }
    .dec-aj-neg  { background: #FFF3E0; color: #E65100; }
    .dec-desc    { background: #FCE4EC; color: #880E4F; }
    .saldo-ajuste-wrap {
      display: inline-flex; align-items: center; gap: 5px;
      flex-wrap: wrap;
    }
    .saldo-ajuste-total { font-weight: 700; }
    .saldo-ajuste-delta {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 26px; padding: 1px 6px; border-radius: 999px;
      font-size: 10px; line-height: 1.5; font-weight: 800;
      white-space: nowrap;
    }
    .saldo-ajuste-delta.pos { background: var(--green-light); color: var(--green); }
    .saldo-ajuste-delta.neg { background: #FFF3E0; color: #E65100; }
    .oracle-field-val .saldo-ajuste-wrap,
    .br-ip-val .saldo-ajuste-wrap { justify-content: flex-start; }

    /* ── PAINEL ORACLE ── */
    .painel {
      display: flex; flex-direction: column; gap: 10px;
    }

    .oracle-empty {
      text-align: center; padding: 40px 16px;
      color: var(--gray-400); font-size: 13px;
    }
    .oracle-icon { font-size: 36px; margin-bottom: 10px; }

    .oracle-field { background: var(--gray-50); border-radius: 12px; padding: 10px 12px; border: 1px solid var(--gray-200); }
    .oracle-field-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: var(--gray-600); }
    .oracle-field-val { font-size: 16px; font-weight: 700; color: var(--gray-800); margin-top: 3px; word-break: break-all; }
    .oracle-field-val.descricao { font-size: 13px; font-weight: 500; line-height: 1.4; }
    .oracle-field.destaque { background: var(--red-light); border-color: #FFCDD2; }
    .oracle-field.destaque .oracle-field-val { color: var(--red); }

    .oracle-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }

    /* ── BOTÕES DE AÇÃO ── */
    .acoes-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .btn-acao {
      padding: 10px 8px; border-radius: 12px; border: none;
      font-size: 12px; font-weight: 700; cursor: pointer;
      display: flex; flex-direction: column; align-items: center; gap: 4px;
      transition: filter 0.15s, transform 0.05s;
    }
    .btn-acao:hover { filter: brightness(0.93); transform: translateY(-1px); }
    .btn-acao:active { transform: translateY(0); filter: brightness(0.88); }
    .btn-acao:disabled { opacity: 0.4; cursor: default; transform: none; filter: none; }
    .btn-acao .acao-icon { font-size: 20px; }
    .btn-ajuste-pos { background: var(--green-light); color: var(--green); }
    .btn-ajuste-neg { background: #FFF3E0; color: #E65100; }
    .btn-desc       { background: #FCE4EC; color: #880E4F; }

    /* ── LOADING / EMPTY ── */
    .loading { text-align: center; padding: 32px; color: var(--gray-400); font-size: 14px; }
    .spinner {
      width: 28px; height: 28px; border: 3px solid var(--gray-200);
      border-top-color: var(--red); border-radius: 50%;
      animation: spin 0.7s linear infinite; margin: 0 auto 10px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .empty-state { text-align: center; padding: 40px 16px; color: var(--gray-400); font-size: 13px; }
    .empty-icon { font-size: 36px; margin-bottom: 10px; }

    /* ── TOASTS ── */
    #msg-erro, #msg-ok {
      position: fixed; left: 16px; right: 16px; bottom: 20px;
      padding: 12px 14px; border-radius: 14px; font-size: 13px; font-weight: 600;
      box-shadow: 0 6px 14px rgba(0,0,0,0.25); display: none; z-index: 300;
    }
    #msg-erro { background: #FFEBEE; color: #C62828; border: 1px solid #FFCDD2; }
    #msg-ok   { background: var(--green-light); color: var(--green); border: 1px solid #A5D6A7; }

    /* ── MODAL ── */
    .dlg-backdrop {
      position: fixed; inset: 0; background: rgba(0,0,0,0.45);
      backdrop-filter: blur(3px); display: flex;
      align-items: center; justify-content: center;
      z-index: 200; padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity 0.18s;
    }
    .dlg-backdrop.show { opacity: 1; pointer-events: all; }
    .dlg-box {
      background: #fff; border-radius: 20px; padding: 24px 22px 20px;
      width: 100%; max-width: 340px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.25);
      transform: scale(0.92) translateY(10px);
      transition: transform 0.2s cubic-bezier(0.34,1.56,0.64,1);
    }
    .dlg-backdrop.show .dlg-box { transform: scale(1) translateY(0); }
    .dlg-icon { font-size: 2rem; text-align: center; margin-bottom: 10px; }
    .dlg-title { font-size: 16px; font-weight: 700; text-align: center; margin-bottom: 6px; }
    .dlg-sub { font-size: 13px; color: var(--gray-600); text-align: center; margin-bottom: 16px; line-height: 1.5; }
    .dlg-field { margin-bottom: 14px; }
    .dlg-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-600); margin-bottom: 4px; }
    .dlg-input {
      width: 100%; height: 48px; border-radius: 12px;
      border: 1.5px solid var(--gray-200); padding: 0 12px;
      font-size: 18px; font-weight: 700; text-align: center;
      color: var(--gray-800); background: #fafafa;
      outline: none; transition: border-color 0.15s;
    }
    .dlg-input:focus { border-color: var(--red); box-shadow: 0 0 0 2px rgba(35,71,200,0.16); }
    .dlg-btns { display: flex; gap: 10px; }
    .dlg-btn {
      flex: 1; height: 46px; border-radius: 12px; border: none;
      font-size: 14px; font-weight: 700; cursor: pointer; transition: filter 0.12s;
    }
    .dlg-btn:hover { filter: brightness(0.93); }
    .dlg-btn-cancel { background: var(--gray-100); color: var(--gray-600); }
    .dlg-btn-ok { background: linear-gradient(135deg, var(--red), #FF1744); color: #fff; }

    @media (max-width: 600px) {
      .oracle-grid { grid-template-columns: 1fr; }
      .acoes-grid  { grid-template-columns: 1fr 1fr; }
    }

    /* ── Barra de Pesquisa de Item ── */
    .search-input-wrap { position: relative; }
    .search-input-item {
      width: 100%; height: 38px; border-radius: 10px;
      border: 1.5px solid var(--gray-200); padding: 0 34px 0 34px;
      font-size: 13px; outline: none; background: var(--gray-50);
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .search-input-item:focus {
      border-color: var(--blue); box-shadow: 0 0 0 2px rgba(21,101,192,0.14);
      background: var(--white);
    }
    .search-icon-left {
      position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
      color: var(--gray-400); font-size: 14px; pointer-events: none; line-height: 1;
    }
    .btn-clear-search {
      position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: var(--gray-400); font-size: 18px; line-height: 1; padding: 2px 4px;
    }
    .btn-clear-search:hover { color: var(--gray-800); }
    .search-status { font-size: 11px; color: var(--gray-400); margin-top: 4px; min-height: 16px; }
    .item-table tbody tr.busca-com-decisao td { background: rgba(255,248,220,0.6); }
    .item-table tbody tr.busca-com-decisao:hover td { background: var(--amber-light); }
    .item-table tbody tr.busca-oracle-only td { background: rgba(227,242,253,0.5); }
    .item-table tbody tr.busca-oracle-only:hover td { background: var(--blue-light); }

    /* ── Busca Rápida (barra central de filtros) ── */
    .busca-rapida-wrap { flex: 1; min-width: 200px; max-width: 380px; position: relative; }
    .busca-rapida-input { height: 34px; font-size: 12px; }
    .busca-rapida-dropdown {
      position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 80;
      background: var(--white); border-radius: 14px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.2);
      border: 1px solid var(--gray-200);
      max-height: 440px; overflow-y: auto;
    }
    .br-result {
      padding: 10px 12px; border-bottom: 1px solid var(--gray-100);
      cursor: pointer; transition: background 0.1s;
    }
    .br-result:last-child { border-bottom: none; }
    .br-result:hover { background: var(--gray-50); }
    .br-result-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 2px; }
    .br-result-code { font-size: 13px; font-weight: 700; color: var(--gray-800); }
    .br-result-desc { font-size: 12px; color: var(--gray-600); margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .br-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px; }
    .br-chip { font-size: 10px; padding: 2px 6px; border-radius: 999px; background: var(--gray-100); color: var(--gray-600); white-space: nowrap; }
    .br-chip.c-red   { background: var(--red-light);   color: var(--red);   font-weight: 700; }
    .br-chip.c-green { background: var(--green-light); color: var(--green); font-weight: 700; }
    .br-chip.c-blue  { background: var(--blue-light);  color: var(--blue);  font-weight: 700; }
    .br-chip.c-amber { background: var(--amber-light); color: #B45309;      font-weight: 700; }
    .br-acoes { display: flex; gap: 5px; }
    .br-btn {
      flex: 1; padding: 5px 4px; border-radius: 8px; border: none;
      font-size: 11px; font-weight: 700; cursor: pointer; transition: filter 0.12s;
    }
    .br-btn:hover { filter: brightness(0.9); }
    .br-btn-pos  { background: var(--green-light); color: var(--green); }
    .br-btn-neg  { background: #FFF3E0;            color: #E65100; }
    .br-btn-desc { background: #FCE4EC;            color: #880E4F; }
    .br-empty { padding: 20px 12px; text-align: center; color: var(--gray-400); font-size: 12px; }

    /* Estoque por loja dentro do dropdown */
    .br-lojas { border-top: 1px solid var(--gray-100); padding-top: 8px; margin-top: 6px; }
    .br-lojas-title { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--gray-400); margin-bottom: 6px; font-weight: 600; }
    .br-loja-row {
      display: flex; align-items: center; gap: 8px;
      padding: 3px 0; font-size: 12px;
    }
    .br-loja-row.atual { background: rgba(21,101,192,0.06); border-radius: 6px; padding: 3px 6px; margin: 0 -6px; }
    .br-loja-nome { flex: 1; color: var(--gray-600); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .br-loja-row.atual .br-loja-nome { color: var(--blue); font-weight: 600; }
    .br-loja-saldo { font-weight: 700; min-width: 36px; text-align: right; font-size: 12px; }
    .br-loja-saldo.s-zero { color: var(--red); }
    .br-loja-saldo.s-pos  { color: var(--green); }
    .br-loja-bar { width: 50px; height: 4px; border-radius: 2px; background: var(--gray-200); flex-shrink: 0; overflow: hidden; }
    .br-loja-bar-fill { height: 100%; border-radius: 2px; background: var(--green); transition: width 0.3s; }
    .br-loja-bar-fill.zero { background: var(--red); }

    /* ── Painel de item selecionado via busca rápida ── */
    #br-item-painel {
      border-top: 1px solid var(--gray-100);
      margin-top: 12px;
      padding-top: 12px;
      animation: fadeIn .15s ease;
    }
    @keyframes fadeIn { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:none; } }
    .br-ip-header {
      display: flex; align-items: flex-start; justify-content: space-between;
      gap: 8px; margin-bottom: 10px;
    }
    .br-ip-identity { flex: 1; min-width: 0; }
    .br-ip-code { font-size: 15px; font-weight: 700; color: var(--gray-800); }
    .br-ip-desc { font-size: 12px; color: var(--gray-500); margin-top: 2px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .br-ip-close {
      background: none; border: none; cursor: pointer;
      font-size: 16px; color: var(--gray-400); padding: 0 4px; line-height: 1;
      flex-shrink: 0;
    }
    .br-ip-close:hover { color: var(--red); }
    .br-ip-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
      gap: 6px;
      margin-bottom: 10px;
    }
    .br-ip-field {
      background: var(--gray-50); border-radius: 8px;
      padding: 7px 10px; border: 1px solid var(--gray-200);
    }
    .br-ip-field.hl { background: var(--red-light); border-color: #FFCDD2; }
    .br-ip-lbl { font-size: 9px; text-transform: uppercase; letter-spacing: .08em;
      color: var(--gray-500); }
    .br-ip-val { font-size: 14px; font-weight: 700; color: var(--gray-800);
      margin-top: 2px; }
    .br-ip-field.hl .br-ip-val { color: var(--red); }
    .br-ip-acoes {
      display: flex; gap: 8px; flex-wrap: wrap;
    }
    .br-ip-btn {
      flex: 1; min-width: 90px; padding: 8px 10px;
      border: none; border-radius: 10px; cursor: pointer;
      font-size: 13px; font-weight: 600;
      display: flex; align-items: center; justify-content: center; gap: 5px;
      transition: opacity .15s;
    }
    .br-ip-btn:hover { opacity: .85; }
    .br-ip-btn-pos  { background: #E8F5E9; color: #2E7D32; }
    .br-ip-btn-neg  { background: #FFF3E0; color: #E65100; }
    .br-ip-btn-desc { background: #FFEBEE; color: var(--red); }
    .br-ip-lojas-titulo {
      font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
      color: var(--gray-400); font-weight: 600;
      margin: 10px 0 6px;
    }
    .br-ip-lojas-tabela {
      display: grid;
      grid-template-columns: 2fr minmax(130px,1fr) minmax(120px,1fr) repeat(3, auto);
      gap: 0;
      border: 1px solid var(--gray-200);
      border-radius: 8px;
      overflow: hidden;
      font-size: 12px;
      margin-bottom: 10px;
    }
    .br-ip-lj-cell {
      padding: 5px 10px;
      border-bottom: 1px solid var(--gray-100);
      display: flex; align-items: center;
    }
    .br-ip-lj-cell:nth-last-child(-n+6) { border-bottom: none; }
    .br-ip-lj-cod-diff { color: var(--red); font-weight: 600; font-family: monospace; font-size: 11px; }
    .br-ip-lj-cod-same { color: var(--gray-400); font-size: 11px; }
    .br-ip-lj-head {
      background: var(--gray-50);
      font-size: 10px; text-transform: uppercase; letter-spacing: .06em;
      color: var(--gray-500); font-weight: 600;
      padding: 5px 10px;
      border-bottom: 1px solid var(--gray-200);
    }
    .br-ip-lj-nome { color: var(--gray-600); }
    .br-ip-lj-num  { text-align: right; font-weight: 700; color: var(--gray-700); }
    .br-ip-lj-num.pos  { color: var(--green); }
    .br-ip-lj-num.zero { color: var(--red); }
    .br-ip-lj-row-atual .br-ip-lj-cell { background: rgba(21,101,192,0.05); }
    .br-ip-lj-row-atual .br-ip-lj-nome { color: var(--blue); font-weight: 600; }
  </style>
</head>
<body>
<div class="app">

  <header class="header">
    <div class="header-left">
      <span class="header-badge">Tomada de Decisão</span>
      <span class="header-badge"><?php echo htmlspecialchars($nomeUsuario); ?></span>
    </div>
    <a href="dashboard_inventario.php" class="logout-btn">
      <svg viewBox="0 0 20 20" fill="currentColor">
        <path d="M7 4a1 1 0 0 1 1-1h6a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8a1 1 0 1 1 0-2h6V5H8a1 1 0 0 1-1-1z"/>
        <path d="M4.293 9.293a1 1 0 0 1 1.414 0L7 10.586 5.707 11.88a1 1 0 1 1-1.414-1.414L4.586 10 4.293 9.707a1 1 0 0 1 0-1.414z"/>
      </svg>
      Sair
    </a>
  </header>

  <div class="page-content">

    <!-- Seleção de loja + inventário -->
    <div class="card">
      <div class="card-header">
        <span class="card-title">Selecionar Loja e Inventário</span>
        <span id="badge-inv" style="font-size:11px;color:var(--gray-400);"></span>
      </div>
      <div class="sel-row">
        <div class="sel-group">
          <label class="sel-label" for="sel-loja">Loja</label>
          <select class="sel-input" id="sel-loja" onchange="onLojaChange()" disabled>
            <option value="">Carregando…</option>
          </select>
        </div>
        <div class="sel-group">
          <label class="sel-label" for="sel-inventario">Inventário</label>
          <select class="sel-input" id="sel-inventario" onchange="onInventarioChange()" disabled>
            <option value="">— Selecione o inventário —</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Filtros de tipo + stats -->
    <div class="card" id="card-filtros" style="display:none;">
      <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div class="filter-tabs">
          <button class="filter-tab active all"      data-tipo="todos"       onclick="setFiltroTipo(this)">Todos</button>
          <button class="filter-tab saldo"           data-tipo="saldo_zero"  onclick="setFiltroTipo(this)">Saldo 0</button>
          <button class="filter-tab nao-cont"        data-tipo="nao_contado" onclick="setFiltroTipo(this)">Não Contado</button>
        </div>

        <!-- Campo de busca rápida por código -->
        <div class="busca-rapida-wrap">
          <div class="search-input-wrap">
            <span class="search-icon-left" style="font-size:13px;">🔍</span>
            <input type="text" id="inp-busca-rapida"
                   class="search-input-item busca-rapida-input"
                   placeholder="Buscar item por código…"
                   oninput="onBuscaRapidaInput()"
                   onkeydown="if(event.key==='Escape'){limparBuscaRapida();this.blur()}">
            <button class="btn-clear-search" id="btn-clear-busca-rapida"
                    onclick="limparBuscaRapida()" style="display:none;">×</button>
          </div>
          <div id="busca-rapida-dropdown" class="busca-rapida-dropdown" style="display:none;"></div>
        </div>

        <div class="stats-row">
          <div class="stat-box">
            <div class="stat-num" id="stat-total">—</div>
            <div class="stat-lbl">Total</div>
          </div>
          <div class="stat-box">
            <div class="stat-num amber" id="stat-saldo-zero">—</div>
            <div class="stat-lbl">Saldo 0</div>
          </div>
          <div class="stat-box">
            <div class="stat-num blue" id="stat-nao-contado">—</div>
            <div class="stat-lbl">Não Contado</div>
          </div>
        </div>
      </div>

      <!-- Painel do item selecionado via busca rápida -->
      <div id="br-item-painel" style="display:none;">
        <div class="br-ip-header">
          <div class="br-ip-identity">
            <div class="br-ip-code" id="br-ip-code">—</div>
            <div class="br-ip-desc" id="br-ip-desc">—</div>
          </div>
          <button class="br-ip-close" onclick="limparBuscaRapida()" title="Fechar">✕</button>
        </div>
        <div class="br-ip-grid" id="br-ip-grid"></div>
        <div id="br-ip-lojas" style="display:none;">
          <div class="br-ip-lojas-titulo">Estoque por loja</div>
          <div class="br-ip-lojas-tabela" id="br-ip-lojas-body"></div>
        </div>
        
      </div>
    </div>

    <!-- Grid: tabela + painel Oracle -->
    <div class="main-grid" id="main-grid" style="display:none;">

      <!-- Tabela de itens -->
      <div class="card">
        <div class="card-header">
          <span class="card-title">Itens para Decisão</span>
          <div style="display:flex;gap:8px;align-items:center;">
            <button onclick="recarregarItens()"
              style="background:none;border:none;cursor:pointer;color:var(--red);font-size:12px;font-weight:600;">
              ↻ Atualizar
            </button>
          </div>
        </div>

        <!-- Barra de pesquisa por código -->
        <div id="search-bar-item" style="padding-bottom:4px;">
          <div class="search-input-wrap">
            <span class="search-icon-left">🔍</span>
            <input type="text" id="inp-busca-item"
                   class="search-input-item"
                   placeholder="Código ou descrição do item..."
                   oninput="onBuscaItemInput()"
                   onkeydown="if(event.key==='Escape')limparBuscaItem()">
            <button class="btn-clear-search" id="btn-clear-busca-item"
                    onclick="limparBuscaItem()" style="display:none;">×</button>
          </div>
          <div id="busca-item-status" class="search-status"></div>
        </div>
        <div id="tabela-loading" class="loading" style="padding:20px;">
          <div class="spinner"></div>Carregando itens…
        </div>
        <div class="item-table-wrap" id="tabela-wrap" style="display:none;">
          <table class="item-table">
            <thead>
              <tr>
                <th>Código</th>
                <th>Descrição</th>
                <th>Saldo</th>
                <th>ABC</th>
                <th>Locação</th>
                <th>Cód. Alternativo</th>
                <th>Tipo</th>
                <th>Decisão</th>
              </tr>
            </thead>
            <tbody id="itens-tbody"></tbody>
          </table>
        </div>
        <div id="tabela-empty" class="empty-state" style="display:none;">
          <div class="empty-icon">✅</div>
          Nenhum item pendente de decisão para esta loja.
        </div>
      </div>

      <!-- Painel Oracle + Ações -->
      <div class="painel">
        <!-- Oracle data -->
        <div class="card" id="card-oracle">
          <div class="card-header">
            <span class="card-title">Dados do Item</span>
            <span id="oracle-fonte" style="font-size:10px;color:var(--gray-400);"></span>
          </div>
          <div id="oracle-content">
            <div class="oracle-empty">
              <div class="oracle-icon">🔍</div>
              Selecione um item da tabela para ver os dados.
            </div>
          </div>
        </div>

        <!-- Ações -->
        <div class="card" id="card-acoes">
          <div class="card-header">
            <span class="card-title">Ação</span>
            <span id="item-selecionado-badge" style="font-size:10px;background:var(--red-light);color:var(--red);padding:2px 8px;border-radius:999px;display:none;"></span>
          </div>
          <div class="acoes-grid" style="grid-template-columns:1fr 1fr 1fr;">
            <button class="btn-acao btn-ajuste-pos" id="btn-ajuste-pos" onclick="abrirModalAjuste('ajuste_positivo')" disabled>
              <span class="acao-icon">➕</span>
              Ajuste +
            </button>
            <button class="btn-acao btn-ajuste-neg" id="btn-ajuste-neg" onclick="abrirModalAjuste('ajuste_negativo')" disabled>
              <span class="acao-icon">➖</span>
              Ajuste −
            </button>
            <button class="btn-acao btn-desc" id="btn-desc" onclick="registrarDecisao('desconsiderar', 0)" disabled>
              <span class="acao-icon">🗑️</span>
              Descarte
            </button>
          </div>
          <a href="relatorio_descarte.php" target="_blank"
             style="display:block;text-align:center;font-size:12px;font-weight:600;color:var(--red);text-decoration:none;padding:6px 0 2px;opacity:0.8;">
            📋 Ver Relatório de Descarte
          </a>
        </div>
      </div>

    </div><!-- /.main-grid -->

    <div id="page-loading" class="loading" style="display:none;">
      <div class="spinner"></div>Carregando…
    </div>

  </div><!-- /.page-content -->
</div><!-- /.app -->

<div id="msg-erro"></div>
<div id="msg-ok"></div>

<!-- Modal Ajuste de Quantidade -->
<div class="dlg-backdrop" id="dlg-ajuste">
  <div class="dlg-box">
    <div class="dlg-icon">🔢</div>
    <div class="dlg-title" id="dlg-ajuste-title">Ajuste de Quantidade</div>
    <div class="dlg-sub" id="dlg-ajuste-sub"></div>
    <div class="dlg-field">
      <div class="dlg-label">Quantidade</div>
      <input type="number" class="dlg-input" id="dlg-qty-input" min="1" value="1">
    </div>
    <div class="dlg-field">
      <div class="dlg-label">Observação (opcional)</div>
      <input type="text" class="dlg-input" id="dlg-obs-input"
             style="font-size:14px;font-weight:400;text-align:left;"
             placeholder="Ex: item encontrado no depósito">
    </div>
    <div class="dlg-field">
      <div class="dlg-label">Locação</div>
      <input type="text" class="dlg-input" id="dlg-loc-input"
             style="font-size:14px;font-weight:400;text-align:left;"
             placeholder="00.000.000.00.000">
    </div>
    <div class="dlg-btns">
      <button class="dlg-btn dlg-btn-cancel" onclick="fecharModal()">Cancelar</button>
      <button class="dlg-btn dlg-btn-ok" id="dlg-confirmar-ajuste" onclick="confirmarAjuste()">Confirmar</button>
    </div>
  </div>
</div>

<script>
/* ── Estado ── */
const st = {
  lojaAtual:    null,  // {emp_num, rev_num, id_inventario, nome_empresa, nome_revenda}
  tipoFiltro:   'todos',
  itemSelecionado: null, // objeto item
  acaoAtual:    null,  // 'ajuste_positivo' | 'ajuste_negativo'
};

/* ── Helpers ── */
function esc(s) {
  return String(s || '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmt(n) { return parseFloat(n || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 }); }

function numOrNull(v) {
  if (v === null || v === undefined || v === '') return null;
  const n = Number(String(v).replace(',', '.'));
  return Number.isFinite(n) ? n : null;
}

function fmtQtd(v) {
  const n = numOrNull(v);
  if (n === null) return esc(v === null || v === undefined || v === '' ? '-' : v);
  return n.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function fmtSigned(v) {
  const n = numOrNull(v) || 0;
  return (n > 0 ? '+' : '') + fmtQtd(n);
}

function ajusteAssinado(item) {
  if (!item) return 0;
  const informado = numOrNull(item.ajuste_saldo);
  if (informado !== null && informado !== 0) return informado;
  const qtd = Math.abs(numOrNull(item.qtd_ajuste_existente) || 0);
  if (!qtd) return 0;
  if (item.decisao_registrada === 'ajuste_positivo') return qtd;
  if (item.decisao_registrada === 'ajuste_negativo') return -qtd;
  return 0;
}

function saldoAjustado(item, base, usarSaldoDoItem = true) {
  if (usarSaldoDoItem && item) {
    const informado = numOrNull(item.saldo_ajustado);
    if (informado !== null) return informado;
  }
  const baseNum = numOrNull(base);
  const delta = ajusteAssinado(item);
  if (baseNum === null) return delta || null;
  return baseNum + delta;
}

function renderAjusteDelta(delta) {
  if (!delta) return '';
  const cls = delta > 0 ? 'pos' : 'neg';
  return `<span class="saldo-ajuste-delta ${cls}">${fmtSigned(delta)}</span>`;
}

function renderSaldoAjustado(base, item, aplicarAjuste = true, usarSaldoDoItem = true) {
  const delta = aplicarAjuste ? ajusteAssinado(item) : 0;
  if (!delta) return fmtQtd(base);
  const final = saldoAjustado(item, base, usarSaldoDoItem);
  return `<span class="saldo-ajuste-wrap"><span class="saldo-ajuste-total">${fmtQtd(final)}</span>${renderAjusteDelta(delta)}</span>`;
}

function textoSaldoAjustado(base, item, aplicarAjuste = true, usarSaldoDoItem = true) {
  const delta = aplicarAjuste ? ajusteAssinado(item) : 0;
  if (!delta) return fmtQtd(base);
  return `${fmtQtd(saldoAjustado(item, base, usarSaldoDoItem))} (${fmtSigned(delta)})`;
}

function renderSaldoItem(item) {
  return renderSaldoAjustado(item ? item.saldo_estoque : null, item, true, true);
}

let toastTimer;
function mostrarErro(txt) {
  document.getElementById('msg-ok').style.display = 'none';
  const el = document.getElementById('msg-erro');
  el.textContent = txt; el.style.display = 'block';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.style.display = 'none', 4500);
}
function mostrarOk(txt) {
  document.getElementById('msg-erro').style.display = 'none';
  const el = document.getElementById('msg-ok');
  el.textContent = txt; el.style.display = 'block';
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.style.display = 'none', 3000);
}

/* ── Carregar lojas ── */
function carregarLojas() {
  fetch('tomada_decisao_ajax.php?modo=lojas')
    .then(r => r.json())
    .then(data => {
      const sel = document.getElementById('sel-loja');
      if (!data.ok || !data.lojas.length) {
        sel.innerHTML = '<option value="">Nenhuma loja cadastrada</option>';
        return;
      }
      sel.innerHTML = '<option value="">— Selecione a loja —</option>' +
        data.lojas.map(l =>
          `<option value="${l.emp_num}|${l.rev_num}"
                   data-nome="${esc(l.nome_empresa)}"
                   data-revnome="${esc(l.nome_revenda)}">
             ${esc(l.nome_empresa)} — ${esc(l.nome_revenda)}
           </option>`
        ).join('');
      sel.disabled = false;
    })
    .catch(() => mostrarErro('Erro ao carregar lojas'));
}

/* ── Seleciona loja → carrega inventários ── */
function onLojaChange() {
  const sel    = document.getElementById('sel-loja');
  const selInv = document.getElementById('sel-inventario');

  // Reseta inventário e oculta seções
  selInv.innerHTML = '<option value="">— Selecione o inventário —</option>';
  selInv.disabled  = true;
  document.getElementById('card-filtros').style.display = 'none';
  document.getElementById('main-grid').style.display    = 'none';
  document.getElementById('badge-inv').textContent      = '';
  st.lojaAtual       = null;
  st.itemSelecionado = null;
  limparSelecao();

  const val = sel.value;
  if (!val) return;

  const [emp, rev] = val.split('|');
  const opt = sel.options[sel.selectedIndex];
  st._pendente = {
    emp_num:      parseInt(emp),
    rev_num:      parseInt(rev),
    nome_empresa: opt.dataset.nome,
    nome_revenda: opt.dataset.revnome,
  };

  selInv.innerHTML = '<option value="">Carregando…</option>';

  fetch(`tomada_decisao_ajax.php?modo=inventarios&empresa=${emp}&revenda=${rev}`)
    .then(r => r.json())
    .then(data => {
      if (!data.ok || !data.inventarios.length) {
        selInv.innerHTML = '<option value="">Nenhum inventário encontrado</option>';
        return;
      }
      selInv.innerHTML = '<option value="">— Selecione o inventário —</option>' +
        data.inventarios.map(i => {
          const dt     = i.data_inicio ? i.data_inicio.substring(0, 10) : '';
          const icone  = i.status === 'aberto' ? '🟢' : '🔴';
          const status = i.status === 'aberto' ? 'Aberto' : 'Fechado';
          return `<option value="${i.id}">${icone} Inv. #${i.id} — ${status} (${dt})</option>`;
        }).join('');
      selInv.disabled = false;

      // Auto-seleciona se houver exatamente um inventário aberto
      const abertos = data.inventarios.filter(i => i.status === 'aberto');
      if (abertos.length === 1) {
        selInv.value = abertos[0].id;
        onInventarioChange();
      }
    })
    .catch(() => mostrarErro('Erro ao carregar inventários'));
}

/* ── Seleciona inventário → carrega itens ── */
function onInventarioChange() {
  const selInv = document.getElementById('sel-inventario');
  const idInv  = parseInt(selInv.value);

  if (!idInv || !st._pendente) {
    document.getElementById('card-filtros').style.display = 'none';
    document.getElementById('main-grid').style.display    = 'none';
    return;
  }

  st.lojaAtual = { ...st._pendente, id_inventario: idInv };
  st.itemSelecionado = null;

  document.getElementById('badge-inv').textContent      = 'Inv. #' + idInv;
  document.getElementById('card-filtros').style.display = '';
  document.getElementById('main-grid').style.display    = '';

  limparSelecao();
  carregarItens();
}

/* ── Filtro tipo ── */
function setFiltroTipo(btn) {
  document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  st.tipoFiltro = btn.dataset.tipo;
  carregarItens();
}

/* ── Carregar itens ── */
function carregarItens() {
  if (!st.lojaAtual) return;
  document.getElementById('tabela-loading').style.display = '';
  document.getElementById('tabela-wrap').style.display    = 'none';
  document.getElementById('tabela-empty').style.display   = 'none';

  const { emp_num, rev_num, id_inventario } = st.lojaAtual;
  const url = `tomada_decisao_ajax.php?modo=itens&empresa=${emp_num}&revenda=${rev_num}&id_inventario=${id_inventario}&tipo=${st.tipoFiltro}`;

  fetch(url)
    .then(r => r.json())
    .then(data => {
      document.getElementById('tabela-loading').style.display = 'none';
      if (!data.ok) { mostrarErro(data.mensagem); return; }

      document.getElementById('stat-total').textContent      = data.total;
      document.getElementById('stat-saldo-zero').textContent  = data.tot_saldo_zero;
      document.getElementById('stat-nao-contado').textContent = data.tot_nao_contado;

      const itens = data.itens || [];
      if (!itens.length) {
        document.getElementById('tabela-empty').style.display = '';
        return;
      }

      const tbody = document.getElementById('itens-tbody');
      tbody.innerHTML = itens.map(item => {
        const tipoCls = item.tipo_item === 'saldo_zero' ? 'tipo-saldo' :
                        item.tipo_item === 'nao_contado' ? 'tipo-nao-cnt' : '';
        const tipoLbl = item.tipo_item === 'saldo_zero' ? 'Saldo 0' :
                        item.tipo_item === 'nao_contado' ? 'Não Contado' :
                        item.decisao_registrada ? 'Ajustado' : 'Normal';
        const decBadge = renderDecBadge(item.decisao_registrada, item);
        const rowCls = item.decisao_registrada ? 'com-decisao' : '';
        const descTrunc = (item.descricao_item_estoque || '—').substring(0, 40);
        return `<tr class="${rowCls}" onclick="selecionarItem(this)" data-item='${JSON.stringify(item).replace(/'/g,"&#39;")}'>
          <td>
            <strong>${esc(item.item_estoque_pub)}</strong>
          </td>
          <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${esc(item.descricao_item_estoque)}">${esc(descTrunc)}</td>
          <td>${renderSaldoItem(item)}</td>
          <td>${item.class_abc || '—'}</td>
          <td>${esc(item.locacao_geral || '—')}</td>
          <td style="font-size:11px;color:var(--gray-600);font-family:monospace;">${esc(item.item_pub_revenda || '—')}</td>
          <td>${tipoCls
            ? `<span class="tipo-badge ${tipoCls}">${tipoLbl}</span>`
            : `<span style="color:var(--gray-400);font-size:11px;">${tipoLbl}</span>`}</td>
          <td>${decBadge}</td>
        </tr>`;
      }).join('');

      document.getElementById('tabela-wrap').style.display = '';
    })
    .catch(() => {
      document.getElementById('tabela-loading').style.display = 'none';
      mostrarErro('Erro de conexão ao carregar itens');
    });
}

function renderDecBadge(decisao, item = null) {
  if (!decisao) return '<span style="color:var(--gray-400);font-size:11px;">—</span>';
  const map = {
    ajuste_positivo: ['dec-aj-pos', 'Ajuste +'],
    ajuste_negativo: ['dec-aj-neg', 'Ajuste −'],
    desconsiderar:   ['dec-desc',   'Descarte'],
    // legados (podem existir em registros antigos)
    autorizar:       ['dec-aj-pos', 'Autorizado'],
    ajuste_final:    ['dec-aj-neg', 'Aj. Final'],
  };
  let [cls, lbl] = map[decisao] || ['', decisao];
  const delta = ajusteAssinado(item);
  if ((decisao === 'ajuste_positivo' || decisao === 'ajuste_negativo') && delta) {
    lbl = 'Ajuste ' + fmtSigned(delta);
  }
  return `<span class="dec-badge ${cls}">${esc(lbl)}</span>`;
}

function recarregarItens() { carregarItens(); }

/* ── Selecionar item ── */
function selecionarItem(tr) {
  document.querySelectorAll('#itens-tbody tr').forEach(r => r.classList.remove('selected'));
  tr.classList.add('selected');

  try { st.itemSelecionado = JSON.parse(tr.dataset.item); } catch { return; }
  const item = st.itemSelecionado;

  // Badge do item selecionado
  const badge = document.getElementById('item-selecionado-badge');
  badge.textContent = item.item_estoque_pub;
  badge.style.display = '';

  // Habilitar botões
  ['btn-ajuste-pos','btn-ajuste-neg','btn-desc'].forEach(id =>
    document.getElementById(id).disabled = false
  );

  // Mostrar dados do MySQL enquanto carrega Oracle
  mostrarDadosMySQL(item);

  // Carregar dados Oracle
  carregarOracle(item);
}

function mostrarDadosMySQL(item) {
  document.getElementById('oracle-fonte').textContent = 'MySQL (carregando Oracle…)';
  document.getElementById('oracle-content').innerHTML = `
    <div class="oracle-field" style="grid-column:1/-1;">
      <div class="oracle-field-lbl">Código</div>
      <div class="oracle-field-val">${esc(item.item_estoque_pub)}</div>
    </div>
    <div class="oracle-field" style="grid-column:1/-1;">
      <div class="oracle-field-lbl">Descrição</div>
      <div class="oracle-field-val descricao">${esc(item.descricao_item_estoque || '—')}</div>
    </div>
    <div class="oracle-grid">
      <div class="oracle-field destaque">
        <div class="oracle-field-lbl">Saldo Estoque</div>
        <div class="oracle-field-val">${renderSaldoItem(item)}</div>
      </div>
      <div class="oracle-field">
        <div class="oracle-field-lbl">Class. ABC</div>
        <div class="oracle-field-val">${esc(item.class_abc || '—')}</div>
      </div>
      <div class="oracle-field">
        <div class="oracle-field-lbl">Custo Entrada</div>
        <div class="oracle-field-val">R$ ${fmt(item.custo_entrada)}</div>
      </div>
      <div class="oracle-field">
        <div class="oracle-field-lbl">Locação</div>
        <div class="oracle-field-val" style="font-size:13px;">${esc(item.locacao_geral || '—')}</div>
      </div>
    </div>
    <div class="loading" style="padding:8px;"><div class="spinner" style="width:18px;height:18px;border-width:2px;"></div></div>
  `;
}

function carregarOracle(item) {
  const { emp_num, rev_num } = st.lojaAtual;
  fetch(`tomada_decisao_ajax.php?modo=oracle&empresa=${emp_num}&revenda=${rev_num}&item=${encodeURIComponent(item.item_estoque_pub)}`)
    .then(r => r.json())
    .then(data => {
      if (!data.ok) {
        document.getElementById('oracle-fonte').textContent = '';
        // Remove spinner, mantém dados MySQL
        const el = document.getElementById('oracle-content');
        const spinner = el.querySelector('.loading');
        if (spinner) spinner.remove();
        return;
      }
      document.getElementById('oracle-fonte').textContent = 'Apollo ✓';
      // Se o Oracle trouxe item_pub_revenda e o item atual não tinha, atualiza o painel de lojas
      if (data.item_pub_revenda && st.itemSelecionado && !st.itemSelecionado.item_pub_revenda) {
        st.itemSelecionado.item_pub_revenda = data.item_pub_revenda;
        preencherPainelItem(st.itemSelecionado);
      }
      document.getElementById('oracle-content').innerHTML = `
        <div class="oracle-field" style="margin-bottom:4px;">
          <div class="oracle-field-lbl">Item Público</div>
          <div class="oracle-field-val"><strong>${esc(data.item_estoque_pub || '—')}</strong></div>
        </div>
        <div class="oracle-field" style="margin-bottom:8px;">
          <div class="oracle-field-lbl">Descrição</div>
          <div class="oracle-field-val descricao">${esc(data.descricao || '—')}</div>
        </div>
        <div class="oracle-grid">
          <div class="oracle-field destaque">
            <div class="oracle-field-lbl">Qtd. Apollo</div>
            <div class="oracle-field-val">${data.quantidade}</div>
          </div>
          <div class="oracle-field">
            <div class="oracle-field-lbl">Class. ABC</div>
            <div class="oracle-field-val">${esc(data.class_abc || '—')}</div>
          </div>
          <div class="oracle-field">
            <div class="oracle-field-lbl">Custo Entrada</div>
            <div class="oracle-field-val">R$ ${fmt(data.custo_entrada)}</div>
          </div>
          <div class="oracle-field">
            <div class="oracle-field-lbl">Preço Alt.</div>
            <div class="oracle-field-val">R$ ${fmt(data.preco_alternativo)}</div>
          </div>
          <div class="oracle-field">
            <div class="oracle-field-lbl">Qtd. Contábil</div>
            <div class="oracle-field-val">${data.qtd_contabil}</div>
          </div>
          <div class="oracle-field">
            <div class="oracle-field-lbl">Qtd. Alocada</div>
            <div class="oracle-field-val">${data.qtd_alocada}</div>
          </div>
        </div>
      `;
    })
    .catch(() => {
      document.getElementById('oracle-fonte').textContent = 'Oracle indisponível';
      const el = document.getElementById('oracle-content');
      const spinner = el.querySelector('.loading');
      if (spinner) spinner.remove();
    });
}

/* ── Modal Ajuste ── */
function abrirModalAjuste(acao) {
  if (!st.itemSelecionado) return;
  st.acaoAtual = acao;
  const isNeg      = acao === 'ajuste_negativo';
  const item       = st.itemSelecionado;
  const temDecisao = item.decisao_registrada === acao;
  const prefixo    = temDecisao ? 'Atualizar ' : '';
  document.getElementById('dlg-ajuste-title').textContent =
    prefixo + (isNeg ? 'Ajuste − (Redução)' : 'Ajuste + (Acréscimo)');
  document.getElementById('dlg-ajuste-sub').textContent =
    `Item: ${item.item_estoque_pub} | Saldo: ${textoSaldoAjustado(item.saldo_estoque, item)}`;
  document.getElementById('dlg-qty-input').value =
    (temDecisao && item.qtd_ajuste_existente) ? String(Math.abs(parseInt(item.qtd_ajuste_existente) || 1)) : '1';
  document.getElementById('dlg-obs-input').value =
    (temDecisao && item.obs_existente) ? item.obs_existente : '';
  document.getElementById('dlg-loc-input').value =
    item.locacao_geral || '00.000.000.00.000';
  document.getElementById('dlg-ajuste').classList.add('show');
  setTimeout(() => document.getElementById('dlg-qty-input').focus(), 60);
}

function fecharModal() { document.getElementById('dlg-ajuste').classList.remove('show'); }

function confirmarAjuste() {
  const qty = parseInt(document.getElementById('dlg-qty-input').value) || 0;
  const obs = document.getElementById('dlg-obs-input').value.trim();
  const loc = document.getElementById('dlg-loc-input').value.trim() || '00.000.000.00.000';
  if (qty <= 0) { document.getElementById('dlg-qty-input').focus(); return; }
  fecharModal();
  registrarDecisao(st.acaoAtual, qty, obs, loc);
}

document.getElementById('dlg-ajuste').addEventListener('click', e => {
  if (e.target === document.getElementById('dlg-ajuste')) fecharModal();
});

/* ── Registrar Decisão ── */
async function registrarDecisao(decisao, qtd, obs = '', locacao = '') {
  if (!st.itemSelecionado || !st.lojaAtual) return;

  const item = st.itemSelecionado;
  const { emp_num, rev_num, id_inventario } = st.lojaAtual;

  const payload = {
    id_inventario,
    empresa:            emp_num,
    revenda:            rev_num,
    item:               item.item_estoque_pub,
    decisao,
    qtd_ajuste:         qtd,
    observacao:         obs,
    // Dados do item para inserção automática caso não esteja no inventário
    descricao:          item.descricao_item_estoque || '',
    locacao:            locacao || item.locacao_geral || '00.000.000.00.000',
    quantidade_oracle:  item.saldo_oracle           ?? 0,
    custo_entrada:      item.custo_entrada          ?? 0,
    class_abc:          item.class_abc              || '',
  };

  try {
    const resp = await fetch('tomada_decisao_ajax.php?modo=decisao', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await resp.json();
    if (!data.ok) { mostrarErro(data.mensagem || 'Erro ao registrar decisão'); return; }

    const labels = {
      ajuste_positivo: 'Ajuste + registrado',
      ajuste_negativo: 'Ajuste - registrado',
      ajuste_final:    'Ajuste Final registrado',
      autorizar:       'Item autorizado',
      desconsiderar:   'Item desconsiderado',
    };
    mostrarOk('✓ ' + (labels[decisao] || 'Decisão registrada'));
    limparSelecao();
    carregarItens();
  } catch {
    mostrarErro('Erro de conexão ao registrar decisão');
  }
}

/* ── Limpar seleção ── */
function limparSelecao() {
  st.itemSelecionado = null;
  document.querySelectorAll('#itens-tbody tr').forEach(r => r.classList.remove('selected'));
  document.getElementById('item-selecionado-badge').style.display = 'none';
  ['btn-ajuste-pos','btn-ajuste-neg','btn-desc'].forEach(id =>
    document.getElementById(id).disabled = true
  );
  document.getElementById('oracle-content').innerHTML = `
    <div class="oracle-empty">
      <div class="oracle-icon">🔍</div>
      Selecione um item da tabela para ver os dados do Oracle.
    </div>`;
  document.getElementById('oracle-fonte').textContent = '';
}

/* ── Busca Rápida (barra central de filtros) ── */
let _brTimer = null;
let _brItens  = [];
let _brFonte  = '';

function onBuscaRapidaInput() {
  const termo    = document.getElementById('inp-busca-rapida').value.trim();
  const btnClear = document.getElementById('btn-clear-busca-rapida');
  btnClear.style.display = termo ? '' : 'none';
  clearTimeout(_brTimer);
  if (!termo) {
    document.getElementById('busca-rapida-dropdown').style.display = 'none';
    return;
  }
  _brTimer = setTimeout(() => executarBuscaRapida(termo), 380);
}

function executarBuscaRapida(termo) {
  if (!st.lojaAtual) return;
  const { emp_num, rev_num, id_inventario } = st.lojaAtual;
  const urlOracle = `tomada_decisao_ajax.php?modo=buscar_item_oracle&codigo=${encodeURIComponent(termo)}&empresa=${emp_num}&revenda=${rev_num}&id_inventario=${id_inventario}`;
  fetch(urlOracle)
    .then(r => r.json())
    .then(data => {
      if (!data.ok && data.fallback) {
        _executarBuscaRapidaMySQL(termo);
        return;
      }
      _brItens = data.ok ? (data.itens || []) : [];
      _brFonte = 'oracle';
      selecionarResultadoBuscaRapida(termo);
    })
    .catch(() => _executarBuscaRapidaMySQL(termo));
}

function _executarBuscaRapidaMySQL(termo) {
  const { emp_num, rev_num, id_inventario } = st.lojaAtual;
  const url = `tomada_decisao_ajax.php?modo=buscar_item&codigo=${encodeURIComponent(termo)}&empresa=${emp_num}&revenda=${rev_num}&id_inventario=${id_inventario}`;
  fetch(url)
    .then(r => r.json())
    .then(data => {
      _brItens = data.ok ? (data.itens || []) : [];
      _brFonte = 'mysql';
      selecionarResultadoBuscaRapida(termo);
    })
    .catch(() => { _brItens = []; _brFonte = ''; selecionarResultadoBuscaRapida(termo); });
}

function selecionarResultadoBuscaRapida(termo) {
  const dd = document.getElementById('busca-rapida-dropdown');
  if (dd) dd.style.display = 'none';

  if (!_brItens.length) {
    document.getElementById('br-item-painel').style.display = 'none';
    mostrarErro('Nenhum item encontrado');
    return;
  }

  const termoNorm = String(termo || '').trim().toUpperCase();
  const idx = _brItens.findIndex(item =>
    String(item.item_estoque_pub  || '').toUpperCase() === termoNorm ||
    String(item.item_pub_revenda  || '').toUpperCase() === termoNorm ||
    String(item.cod_item_estoque  || '').toUpperCase() === termoNorm
  );
  selecionarBR(idx >= 0 ? idx : 0);
}

function renderBRDropdown() {
  const dd = document.getElementById('busca-rapida-dropdown');
  if (!_brItens.length) {
    dd.innerHTML = '<div class="br-empty">Nenhum item encontrado</div>';
    dd.style.display = '';
    return;
  }

  const fonteTag = _brFonte === 'oracle'
    ? '<span style="font-size:10px;color:var(--blue);font-weight:600;margin-left:4px;">Oracle ✓</span>'
    : '<span style="font-size:10px;color:var(--gray-400);">MySQL</span>';

  dd.innerHTML = _brItens.map((item, idx) => {
    const abcCls   = item.class_abc === 'A' ? 'c-green' : item.class_abc === 'B' ? 'c-blue' : item.class_abc === 'C' ? 'c-amber' : '';
    const decBadge = item.decisao_registrada ? renderDecBadge(item.decisao_registrada, item) : '';
    const valor    = item.custo_entrada ? `R$&nbsp;${fmt(item.custo_entrada)}` : '';

    // Saldo: Oracle tem saldo_oracle; MySQL tem saldo_estoque
    const saldoOracle  = item.saldo_oracle  !== undefined ? item.saldo_oracle  : null;
    const saldoEstoque = item.saldo_estoque !== undefined && item.saldo_estoque !== null ? item.saldo_estoque : null;
    const saldoExib    = saldoEstoque !== null ? saldoEstoque : saldoOracle;
    const saldoFinal   = saldoAjustado(item, saldoExib);
    const saldoCls     = (saldoFinal ?? numOrNull(saldoExib) ?? 0) <= 0 ? 'c-red' : '';

    const tipoBadge = item.tipo_item === 'saldo_zero'    ? `<span class="br-chip c-amber">Saldo 0</span>` :
                      item.tipo_item === 'nao_contado'   ? `<span class="br-chip c-blue">Não Contado</span>` :
                      item.tipo_item === 'oracle_only'   ? `<span class="br-chip" style="background:#E3F2FD;color:var(--blue);">Fora do inventário</span>` : '';

    // Linhas extras Oracle
    const oracleExtra = (_brFonte === 'oracle' && saldoOracle !== null) ? `
      <span class="br-chip">Contábil: <b>${parseFloat(item.qtd_contabil||0).toLocaleString('pt-BR',{minimumFractionDigits:0})}</b></span>
      <span class="br-chip">Alocado: <b>${parseFloat(item.qtd_alocada||0).toLocaleString('pt-BR',{minimumFractionDigits:0})}</b></span>
      ${item.preco_alternativo ? `<span class="br-chip">Preço alt: R$&nbsp;${fmt(item.preco_alternativo)}</span>` : ''}
    ` : '';

    // Seção de estoque por loja
    const lojas = item.lojas || [];
    const revAtual = st.lojaAtual ? st.lojaAtual.rev_num : -1;
    const maxSaldo = lojas.reduce((m, l) => {
      const base = numOrNull(l.saldo) || 0;
      const ajustado = l.rev_num === revAtual ? base + ajusteAssinado(item) : base;
      return Math.max(m, ajustado);
    }, 0);
    const lojasHtml = lojas.length ? `
      <div class="br-lojas">
        <div class="br-lojas-title">Estoque por loja (${lojas.length})</div>
        ${lojas.map(l => {
          const s     = numOrNull(l.saldo) || 0;
          const isAtual = l.rev_num === revAtual;
          const sFinal = isAtual ? s + ajusteAssinado(item) : s;
          const sCls  = sFinal <= 0 ? 's-zero' : 's-pos';
          const pct   = maxSaldo > 0 ? Math.round((Math.max(sFinal, 0) / maxSaldo) * 100) : 0;
          const rowCls  = isAtual ? 'br-loja-row atual' : 'br-loja-row';
          const nomeExib = [l.nome_revenda, l.nome_empresa].filter(Boolean).join(' · ');
          return `<div class="${rowCls}">
            <span class="br-loja-nome" title="${esc(nomeExib)}">${esc(nomeExib || 'Rev. ' + l.rev_num)}</span>
            <div class="br-loja-bar"><div class="br-loja-bar-fill${sFinal<=0?' zero':''}" style="width:${pct}%"></div></div>
            <span class="br-loja-saldo ${sCls}">${renderSaldoAjustado(l.saldo, item, isAtual, false)}</span>
          </div>`;
        }).join('')}
      </div>` : '';

    return `<div class="br-result" onclick="selecionarBR(${idx})">
      <div class="br-result-header">
        <span class="br-result-code">
          ${esc(item.item_estoque_pub)}
        </span>
        <span style="display:flex;align-items:center;gap:6px;">${decBadge}${fonteTag}</span>
      </div>
      <div class="br-result-desc" title="${esc(item.descricao_item_estoque||'—')}">${esc((item.descricao_item_estoque||'—').substring(0,55))}</div>
      <div class="br-chips">
        ${saldoExib !== null ? `<span class="br-chip ${saldoCls}">Saldo: <b>${renderSaldoAjustado(saldoExib, item)}</b></span>` : ''}
        ${item.class_abc    ? `<span class="br-chip ${abcCls}">ABC: ${esc(item.class_abc)}</span>` : ''}
        ${item.locacao_geral ? `<span class="br-chip">📍 ${esc(item.locacao_geral)}</span>` : ''}
        ${item.item_pub_revenda ? `<span class="br-chip" style="font-family:monospace;color:var(--gray-700);">OEM: ${esc(item.item_pub_revenda)}</span>` : ''}
        ${valor              ? `<span class="br-chip">${valor}</span>` : ''}
        ${oracleExtra}
        ${tipoBadge}
      </div>
      ${lojasHtml}
      <div class="br-acoes" onclick="event.stopPropagation()" style="margin-top:8px;">
        <button class="br-btn br-btn-pos"  onclick="ajusteRapidoItem(${idx},'ajuste_positivo')">➕ Ajuste +</button>
        <button class="br-btn br-btn-neg"  onclick="ajusteRapidoItem(${idx},'ajuste_negativo')">➖ Ajuste −</button>
        <button class="br-btn br-btn-desc" onclick="ajusteRapidoItem(${idx},'desconsiderar')">🗒 Descarte</button>
      </div>
    </div>`;
  }).join('');
  dd.style.display = '';
}

function preencherPainelItem(item) {
    document.getElementById('br-ip-code').innerHTML =
    esc(item.item_estoque_pub || '—');

  const f = (lbl, val, hl = false) =>
    `<div class="br-ip-field${hl ? ' hl' : ''}">
       <div class="br-ip-lbl">${lbl}</div>
       <div class="br-ip-val">${val}</div>
     </div>`;

  const saldoOra = item.saldo_oracle  !== undefined && item.saldo_oracle  !== null ? item.saldo_oracle  : '—';
  const saldoInv = item.saldo_estoque !== undefined && item.saldo_estoque !== null ? renderSaldoItem(item) : '—';
  const contabil = item.qtd_contabil  !== undefined && item.qtd_contabil  !== null ? parseFloat(item.qtd_contabil).toLocaleString('pt-BR') : '—';
  const alocado  = item.qtd_alocada   !== undefined && item.qtd_alocada   !== null ? parseFloat(item.qtd_alocada).toLocaleString('pt-BR')  : '—';
  const custo    = item.custo_entrada      ? `R$&nbsp;${fmt(item.custo_entrada)}`      : '—';
  const precoAlt = item.preco_alternativo  ? `R$&nbsp;${fmt(item.preco_alternativo)}`  : '—';
  const abc      = item.class_abc || '—';
  const loc      = item.locacao_geral || '—';
  const decisao  = item.decisao_registrada
    ? renderDecBadge(item.decisao_registrada, item)
    : '<span style="color:var(--gray-400);font-size:11px;">nenhuma</span>';

  const oemCode = item.item_pub_revenda ? esc(item.item_pub_revenda) : '—';
  document.getElementById('br-ip-grid').innerHTML =
    f('Saldo Apollo',    saldoOra, true) +
    f('Saldo Inventário',saldoInv) +
    f('Qtd Contábil',    contabil) +
    f('Qtd Alocada',     alocado) +
    f('Custo Entrada',   custo) +
    f('Preço Alt.',      precoAlt) +
    f('Class. ABC',      abc) +
    f('Locação',         loc) +
    f('Cód. Alternativo',        `<span style="font-family:monospace;">${oemCode}</span>`) +
    f('Decisão atual',   decisao);

  // Tabela de estoque por loja
  const lojas = Array.isArray(item.lojas) ? item.lojas : [];
  const lojasDiv = document.getElementById('br-ip-lojas');
  const lojasBody = document.getElementById('br-ip-lojas-body');
  if (lojas.length > 0) {
    const revAtual = st.lojaAtual ? st.lojaAtual.rev_num : -1;
    // Ordenar: loja atual primeiro, depois por nome
    const sorted = [...lojas].sort((a, b) => {
      if (a.rev_num === revAtual) return -1;
      if (b.rev_num === revAtual) return 1;
      return (a.nome_revenda || '').localeCompare(b.nome_revenda || '');
    });
    const header =
      `<div class="br-ip-lj-head">Loja</div>` +
      `<div class="br-ip-lj-head">Código</div>` +
      `<div class="br-ip-lj-head">Locação</div>` +
      `<div class="br-ip-lj-head" style="text-align:right;">Contábil</div>` +
      `<div class="br-ip-lj-head" style="text-align:right;">Alocado</div>` +
      `<div class="br-ip-lj-head" style="text-align:right;">Saldo</div>`;
    const rows = sorted.map(l => {
      const isAtual = l.rev_num === revAtual;
      const cls = isAtual ? 'br-ip-lj-row-atual' : '';
      const sNum = numOrNull(l.saldo) || 0;
      const sFinal = isAtual ? sNum + ajusteAssinado(item) : sNum;
      const saldoCls = sFinal > 0 ? 'pos' : sFinal < 0 ? 'zero' : '';
      const nomeExibir = [l.nome_empresa, l.nome_revenda].filter(Boolean).join(' – ') || ('Loja ' + l.rev_num);
      const nome = esc(nomeExibir) + (isAtual ? ' <span style="font-size:9px;color:var(--blue);">●</span>' : '');
      const loc  = l.locacao ? `<span style="font-family:monospace;font-size:11px;">${esc(l.locacao)}</span>` : '<span style="color:var(--gray-300);">—</span>';
      const oemCode = item.item_pub_revenda || '';
      const codCell = oemCode
        ? `<span style="font-family:monospace;font-size:11px;color:var(--gray-700);">${esc(oemCode)}</span>`
        : `<span style="color:var(--gray-300);">—</span>`;
      return `<div class="br-ip-lj-cell br-ip-lj-nome ${cls}">${nome}</div>` +
             `<div class="br-ip-lj-cell ${cls}">${codCell}</div>` +
             `<div class="br-ip-lj-cell ${cls}" style="font-size:11px;color:var(--gray-500);">${loc}</div>` +
             `<div class="br-ip-lj-cell br-ip-lj-num ${cls}">${parseFloat(l.qtd_cont ?? 0).toLocaleString('pt-BR')}</div>` +
             `<div class="br-ip-lj-cell br-ip-lj-num ${cls}">${parseFloat(l.qtd_aloc ?? 0).toLocaleString('pt-BR')}</div>` +
             `<div class="br-ip-lj-cell br-ip-lj-num ${saldoCls} ${cls}">${renderSaldoAjustado(l.saldo, item, isAtual, false)}</div>`;
    }).join('');
    lojasBody.innerHTML = header + rows;
    lojasDiv.style.display = '';
  } else {
    lojasDiv.style.display = 'none';
  }

  document.getElementById('br-item-painel').style.display = '';
}

function selecionarBR(idx) {
  const raw  = _brItens[idx];
  if (!raw) return;
  // Normaliza: saldo_estoque usa valor Oracle como fallback para mostrarDadosMySQL
  const item = Object.assign({}, raw, {
    saldo_estoque: raw.saldo_estoque !== undefined && raw.saldo_estoque !== null ? raw.saldo_estoque : (raw.saldo_oracle ?? '—'),
  });
  st.itemSelecionado = item;
  const badge = document.getElementById('item-selecionado-badge');
  badge.textContent = item.item_estoque_pub;
  badge.style.display = '';
  ['btn-ajuste-pos','btn-ajuste-neg','btn-desc'].forEach(id =>
    document.getElementById(id).disabled = false
  );
  preencherPainelItem(item);
  mostrarDadosMySQL(item);
  carregarOracle(item);
   document.querySelectorAll('#itens-tbody tr').forEach(tr => {
     tr.classList.remove('selected');
     try {
       const trItem = JSON.parse(tr.dataset.item);
       const match =
         trItem.item_estoque_pub === item.item_estoque_pub ||
         (item.item_pub_revenda  && trItem.item_estoque_pub === item.item_pub_revenda) ||
         (item.item_pub_revenda  && trItem.item_pub_revenda === item.item_pub_revenda) ||
         (trItem.item_pub_revenda && trItem.item_pub_revenda === item.item_estoque_pub);
       if (match) {
         tr.classList.add('selected');
         tr.scrollIntoView({ block: 'nearest' });
       }
     } catch {}
   });
   document.getElementById('busca-rapida-dropdown').style.display = 'none';
 }
 
 function ajusteRapidoItem(idx, acao) {
  const raw = _brItens[idx];
  if (!raw) return;
  const item = Object.assign({}, raw, {
    saldo_estoque: raw.saldo_estoque !== undefined && raw.saldo_estoque !== null ? raw.saldo_estoque : (raw.saldo_oracle ?? '—'),
  });
  st.itemSelecionado = item;
  document.getElementById('busca-rapida-dropdown').style.display = 'none';
  if (acao === 'desconsiderar') {
    registrarDecisao('desconsiderar', 0);
  } else {
    abrirModalAjuste(acao);
  }
}

function limparBuscaRapida() {
  clearTimeout(_brTimer);
  _brItens = [];
  const inp = document.getElementById('inp-busca-rapida');
  if (inp) inp.value = '';
  const btnClear = document.getElementById('btn-clear-busca-rapida');
  if (btnClear) btnClear.style.display = 'none';
  document.getElementById('busca-rapida-dropdown').style.display = 'none';
  document.getElementById('br-item-painel').style.display = 'none';
}

document.addEventListener('click', e => {
  const wrap = document.getElementById('busca-rapida-wrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('busca-rapida-dropdown').style.display = 'none';
  }
});

/* ── Pesquisa de Item ── */
let _buscaTimer  = null;
let _modoBusca   = true;

function toggleBuscaItem() {
  const bar = document.getElementById('search-bar-item');
  const btn = document.getElementById('btn-toggle-busca');
  if (_modoBusca) {
    _modoBusca = false;
    bar.style.display = 'none';
    btn.style.color   = 'var(--blue)';
    limparBuscaItem();
  } else {
    if (!st.lojaAtual) return;
    _modoBusca = true;
    bar.style.display = '';
    btn.style.color   = 'var(--red)';
    setTimeout(() => document.getElementById('inp-busca-item').focus(), 60);
  }
}

function onBuscaItemInput() {
  const termo  = document.getElementById('inp-busca-item').value.trim();
  const btnClear = document.getElementById('btn-clear-busca-item');
  btnClear.style.display = termo ? '' : 'none';
  clearTimeout(_buscaTimer);
  if (!termo) {
    document.getElementById('busca-item-status').textContent = '';
    carregarItens();
    return;
  }
  document.getElementById('busca-item-status').textContent = 'Buscando…';
  _buscaTimer = setTimeout(() => executarBuscaItem(termo), 380);
}

function executarBuscaItem(termo) {
  if (!st.lojaAtual) return;
  const { emp_num, rev_num, id_inventario } = st.lojaAtual;
  const url = `tomada_decisao_ajax.php?modo=buscar_item&codigo=${encodeURIComponent(termo)}&empresa=${emp_num}&revenda=${rev_num}&id_inventario=${id_inventario}`;

  fetch(url)
    .then(r => r.json())
    .then(data => {
      if (!data.ok) {
        document.getElementById('busca-item-status').textContent = 'Erro na busca';
        return;
      }
      if (data.itens.length > 0) {
        const n = data.itens.length;
        document.getElementById('busca-item-status').textContent =
          `${n} item${n > 1 ? 's' : ''} encontrado${n > 1 ? 's' : ''}`;
        renderTabelaBusca(data.itens);
      } else {
        document.getElementById('busca-item-status').textContent = 'Buscando no Oracle...';
        _executarBuscaItemOracle(termo);
      }
    })
    .catch(() => {
      document.getElementById('busca-item-status').textContent = 'Erro de conexao';
    });
}

function _executarBuscaItemOracle(termo) {
  const { emp_num, rev_num, id_inventario } = st.lojaAtual;
  const url = `tomada_decisao_ajax.php?modo=buscar_item_oracle&codigo=${encodeURIComponent(termo)}&empresa=${emp_num}&revenda=${rev_num}&id_inventario=${id_inventario}`;
  fetch(url)
    .then(r => r.json())
    .then(data => {
      const itens = (data.ok ? (data.itens || []) : []).map(it => ({
        ...it,
        saldo_estoque: (it.saldo_estoque !== undefined && it.saldo_estoque !== null)
          ? it.saldo_estoque : (it.saldo_oracle ?? null),
        descricao_item_estoque: it.descricao_item_estoque || it.descricao || '',
      }));
      const n = itens.length;
      document.getElementById('busca-item-status').textContent =
        n === 0 ? 'Nenhum item encontrado' :
        `${n} item${n > 1 ? 's' : ''} encontrado${n > 1 ? 's' : ''} (Oracle)`;
      renderTabelaBusca(itens);
    })
    .catch(() => {
      document.getElementById('busca-item-status').textContent = 'Nenhum item encontrado';
      renderTabelaBusca([]);
    });
}

function renderTabelaBusca(itens) {
  document.getElementById('tabela-loading').style.display = 'none';

  if (!itens.length) {
    document.getElementById('tabela-wrap').style.display  = 'none';
    document.getElementById('tabela-empty').style.display = '';
    document.getElementById('tabela-empty').innerHTML =
      '<div class="empty-icon">🔍</div>Nenhum item encontrado para este código.';
    limparSelecao();
    return;
  }

  const tbody = document.getElementById('itens-tbody');
  tbody.innerHTML = itens.map(item => {
    const tipoCls = item.tipo_item === 'saldo_zero'  ? 'tipo-saldo' :
                    item.tipo_item === 'nao_contado'  ? 'tipo-nao-cnt' : '';
    const tipoLbl = item.tipo_item === 'saldo_zero'   ? 'Saldo 0' :
                    item.tipo_item === 'nao_contado'   ? 'Não Contado' :
                    item.tipo_item === 'oracle_only'   ? 'Fora do Inv.' : 'Normal';
    const decBadge = renderDecBadge(item.decisao_registrada, item);
    const rowCls   = item.tipo_item === 'oracle_only' ? 'busca-oracle-only' :
                     (item.decisao_registrada ? 'busca-com-decisao' : '');
    const descTrunc = (item.descricao_item_estoque || '—').substring(0, 40);
    return `<tr class="${rowCls}" onclick="selecionarItem(this)" data-item='${JSON.stringify(item).replace(/'/g,"&#39;")}'>
      <td>
        <strong>${esc(item.item_estoque_pub)}</strong>
      </td>
      <td style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
          title="${esc(item.descricao_item_estoque)}">${esc(descTrunc)}</td>
      <td>${renderSaldoItem(item)}</td>
      <td>${item.class_abc || '—'}</td>
      <td>${esc(item.locacao_geral || '—')}</td>
      <td style="font-size:11px;color:var(--gray-600);font-family:monospace;">${esc(item.item_pub_revenda || '—')}</td>
      <td>${tipoCls
        ? `<span class="tipo-badge ${tipoCls}">${tipoLbl}</span>`
        : `<span style="color:var(--gray-400);font-size:11px;">${tipoLbl}</span>`}</td>
      <td>${decBadge}</td>
    </tr>`;
  }).join('');

  document.getElementById('tabela-empty').style.display = 'none';
  document.getElementById('tabela-wrap').style.display  = '';
}

function limparBuscaItem() {
  clearTimeout(_buscaTimer);
  const inp = document.getElementById('inp-busca-item');
  if (inp) inp.value = '';
  const btnClear = document.getElementById('btn-clear-busca-item');
  if (btnClear) btnClear.style.display = 'none';
  const status = document.getElementById('busca-item-status');
  if (status) status.textContent = '';
  carregarItens();
}

/* ── Init ── */
carregarLojas();
</script>
</body>
</html>



