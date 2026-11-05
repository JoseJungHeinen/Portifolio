<?php
// @versao Ini_01 V_03_18/09/2026
/**
 * views/home/bemvindo.php
 * Tela inicial de boas-vindas (Notas) — standalone, exibida após o login.
 *
 * Variáveis esperadas:
 *   $primeiroNome  string  Primeiro nome do usuário (pode ser vazio)
 */

$primeiroNome = $primeiroNome ?? '';
$cssVer = filemtime(ROOT . '/public/assets/css/modules/home.css');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Bem-vindo — Notas</title>

  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/design-system.css?v=<?= filemtime(ROOT . '/public/assets/css/design-system.css') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/assets/css/modules/home.css?v=<?= $cssVer ?>">

  <script src="<?= BASE_URL ?>/public/assets/js/vendor/lucide.min.js" defer></script>
</head>
<body class="welcome-page">

  <div class="welcome-wrap">

    <div class="welcome-content">
      <h1 class="welcome-brand">Notas</h1>
      <p class="welcome-tagline">Sistema Inteligente de Gestão Orçamentária</p>

      <p class="welcome-text">
        <?php if ($primeiroNome !== ''): ?>
          <span class="welcome-greeting">Seja muito bem-vindo, <?= h($primeiroNome) ?>!</span>
        <?php else: ?>
          <span class="welcome-greeting">Seja muito bem-vindo!</span>
        <?php endif; ?>
        Esta é a sua plataforma inteligente para simplificar a gestão financeira e
        orçamentária para toda a equipe. Estamos aqui para ajudar a todos a alcançarem
        seus objetivos com clareza e eficiência. Vamos começar?
      </p>

      <div class="welcome-actions">
        <a class="welcome-btn" href="<?= APP_PUBLIC_URL ?>/index.php?module=tarefas&amp;action=index">
          <i data-lucide="arrow-right" width="20" height="20"></i>
          Começar
        </a>
      </div>
    </div>

    <div class="welcome-illustration" aria-hidden="true">
      <!-- Ilustração conceitual: painel de gestão com gráfico em ascensão -->
      <svg viewBox="0 0 520 440" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Gráfico de gestão orçamentária">
        <defs>
          <linearGradient id="sigoBar" x1="0" y1="1" x2="1" y2="0">
            <stop offset="0" stop-color="#3E7BE6"/>
            <stop offset="1" stop-color="#3DDC97"/>
          </linearGradient>
          <linearGradient id="sigoLine" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#4C9BFF"/>
            <stop offset="1" stop-color="#3DDC97"/>
          </linearGradient>
          <linearGradient id="sigoPanel" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="rgba(80,150,230,0.18)"/>
            <stop offset="1" stop-color="rgba(40,200,150,0.10)"/>
          </linearGradient>
        </defs>

        <!-- Painel translúcido -->
        <rect x="70" y="40" width="380" height="300" rx="20"
              fill="url(#sigoPanel)" stroke="rgba(120,180,230,0.35)" stroke-width="1.5"/>

        <!-- Cabeçalho do painel -->
        <circle cx="104" cy="76" r="9" fill="#4C9BFF"/>
        <rect x="122" y="70" width="120" height="8" rx="4" fill="rgba(200,220,245,0.55)"/>
        <rect x="122" y="86" width="72" height="7" rx="3.5" fill="rgba(200,220,245,0.30)"/>

        <!-- Barras -->
        <g>
          <rect x="120" y="238" width="42" height="70"  rx="7" fill="url(#sigoBar)" opacity="0.92"/>
          <rect x="182" y="200" width="42" height="108" rx="7" fill="url(#sigoBar)" opacity="0.92"/>
          <rect x="244" y="222" width="42" height="86"  rx="7" fill="url(#sigoBar)" opacity="0.92"/>
          <rect x="306" y="168" width="42" height="140" rx="7" fill="url(#sigoBar)" opacity="0.92"/>
          <rect x="368" y="128" width="42" height="180" rx="7" fill="url(#sigoBar)" opacity="0.96"/>
        </g>

        <!-- Linha de tendência + seta -->
        <polyline points="141,214 203,176 265,196 327,150 389,110"
                  fill="none" stroke="url(#sigoLine)" stroke-width="4"
                  stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="141" cy="214" r="5" fill="#4C9BFF"/>
        <circle cx="203" cy="176" r="5" fill="#43AEDB"/>
        <circle cx="265" cy="196" r="5" fill="#3FC7B0"/>
        <circle cx="327" cy="150" r="5" fill="#3DDC97"/>
        <path d="M389,110 l-16,4 M389,110 l-4,16 M389,110 l20,-16"
              fill="none" stroke="#3DDC97" stroke-width="4"
              stroke-linecap="round" stroke-linejoin="round"/>

        <!-- Nós/chips ao redor -->
        <g stroke="rgba(120,180,230,0.45)" stroke-width="1.5" fill="rgba(60,110,180,0.18)">
          <rect x="24"  y="150" width="46" height="46" rx="11"/>
          <rect x="452" y="96"  width="46" height="46" rx="11"/>
          <rect x="452" y="228" width="46" height="46" rx="11"/>
        </g>
        <line x1="70" y1="173" x2="47" y2="173" stroke="rgba(120,180,230,0.40)" stroke-width="1.5"/>
        <line x1="450" y1="119" x2="475" y2="119" stroke="rgba(120,180,230,0.40)" stroke-width="1.5"/>
        <line x1="450" y1="251" x2="475" y2="251" stroke="rgba(120,180,230,0.40)" stroke-width="1.5"/>
        <circle cx="47"  cy="173" r="6" fill="none" stroke="#4C9BFF" stroke-width="2"/>
        <circle cx="475" cy="119" r="6" fill="none" stroke="#3DA9DB" stroke-width="2"/>
        <circle cx="475" cy="251" r="6" fill="none" stroke="#3DDC97" stroke-width="2"/>

        <!-- Base/reflexo -->
        <ellipse cx="260" cy="360" rx="150" ry="18" fill="rgba(60,140,210,0.12)"/>
      </svg>
    </div>

  </div>

  <span class="welcome-sparkle"><i data-lucide="sparkles"></i></span>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof lucide !== 'undefined') lucide.createIcons();
    });
  </script>
</body>
</html>
