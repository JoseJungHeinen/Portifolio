<?php
// config/topnav.php - include nas paginas internas
$search_placeholder = $search_placeholder ?? 'Pesquisar...';
?>

<header class="glass-nav sticky top-0 z-50 flex h-16 w-full items-center justify-between px-4 sm:px-6">
    <div class="flex min-w-0 items-center gap-3 sm:gap-5">
        <button id="sidebar-toggle"
                type="button"
                class="hidden h-10 w-10 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-600 transition-colors hover:border-primary/30 hover:text-primary md:inline-flex"
                aria-label="Recolher menu lateral"
                aria-expanded="true">
            <span class="material-symbols-outlined text-[20px]">menu_open</span>
        </button>

        <div class="min-w-0">
            <span class="block truncate text-sm font-bold uppercase tracking-widest text-zinc-900 font-headline sm:text-xl">
                Exemplo Gestao de Projetos
            </span>
        </div>

        <div class="hidden items-center gap-2 rounded-lg border border-outline-variant/10 bg-surface-container px-3 py-1.5 xl:flex">
            <span class="material-symbols-outlined text-sm text-zinc-400">search</span>
            <input
                class="w-56 border-none bg-transparent text-sm placeholder:text-zinc-400 focus:outline-none"
                placeholder="<?= htmlspecialchars($search_placeholder) ?>"
                type="text"
            >
        </div>
    </div>

    <div class="relative group">
        <button class="flex h-8 w-8 items-center justify-center rounded-lg border border-zinc-200 bg-surface-container-high transition-colors hover:border-primary/30">
            <span class="material-symbols-outlined text-xl text-zinc-500" style="font-variation-settings:'FILL' 1,'wght' 400">
                person
            </span>
        </button>

        <div class="absolute right-0 z-50 mt-2 hidden w-48 rounded-xl border border-zinc-100 bg-white py-2 shadow-lg group-hover:block">
            <div class="border-b border-zinc-100 px-4 py-2">
                <p class="truncate text-sm font-bold text-on-surface"><?= htmlspecialchars(usuario()['nome'] ?? '') ?></p>
                <p class="truncate text-xs text-secondary"><?= htmlspecialchars(usuario()['email'] ?? '') ?></p>
            </div>

            <a href="configuracoes.php" class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-600 transition-colors hover:bg-surface-container-low hover:text-primary">
                <span class="material-symbols-outlined text-sm">settings</span>
                Configuracoes
            </a>

            <a href="logout.php" class="flex items-center gap-2 px-4 py-2 text-sm text-zinc-600 transition-colors hover:bg-surface-container-low hover:text-primary">
                <span class="material-symbols-outlined text-sm">logout</span>
                Sair
            </a>
        </div>
    </div>
</header>
