<script>
    /**
     * ViewToggle Component (Svelte 5 Runes)
     * Compact selector for toggling between 'table' and 'cards' view with localStorage persistence.
     * Default view is strictly 'table'.
     */
    let {
        mode = $bindable('table'),
        storageKey = 'gekaem_view_mode',
        onchange,
    } = $props();

    // Sincronizar con localStorage en el cliente
    $effect(() => {
        if (typeof window !== 'undefined' && storageKey) {
            const saved = localStorage.getItem(storageKey);
            if (saved === 'cards' || saved === 'table') {
                if (mode !== saved) {
                    mode = saved;
                }
            } else {
                localStorage.setItem(storageKey, 'table');
                mode = 'table';
            }
        }
    });

    function setView(newMode) {
        if (mode === newMode) return;
        mode = newMode;
        if (typeof window !== 'undefined' && storageKey) {
            localStorage.setItem(storageKey, newMode);
        }
        if (typeof onchange === 'function') {
            onchange(newMode);
        }
    }
</script>

<div class="inline-flex items-center p-1 bg-slate-950 border border-slate-800 rounded-xl shadow-inner text-xs" role="group" aria-label="Cambiar vista">
    <button
        type="button"
        onclick={() => setView('table')}
        class="px-3 py-1.5 rounded-lg font-semibold flex items-center gap-1.5 transition-all {mode === 'table' ? 'bg-slate-800 text-emerald-400 shadow-sm border border-slate-700 font-bold' : 'text-slate-400 hover:text-slate-200'}"
        title="Vista en Tabla Expandible"
        aria-pressed={mode === 'table'}
    >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-9 4h18a2 2 0 002-2V6a2 2 0 00-2-2H3a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <span>Tabla</span>
    </button>

    <button
        type="button"
        onclick={() => setView('cards')}
        class="px-3 py-1.5 rounded-lg font-semibold flex items-center gap-1.5 transition-all {mode === 'cards' ? 'bg-slate-800 text-emerald-400 shadow-sm border border-slate-700 font-bold' : 'text-slate-400 hover:text-slate-200'}"
        title="Vista en Tarjetas"
        aria-pressed={mode === 'cards'}
    >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
        </svg>
        <span>Tarjetas</span>
    </button>
</div>
