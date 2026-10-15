<script>
    import AppLayout from '../Layouts/AppLayout.svelte';

    let { moduleName = 'Módulo', moduleCode = 'MVP', description = 'En construcción' } = $props();
</script>

<AppLayout title={moduleName}>
    <div class="max-w-xl mx-auto my-12 bg-slate-900 border border-slate-800 rounded-2xl p-8 text-center shadow-xl space-y-4">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl font-bold">
            🔨
        </div>
        <div>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                {moduleCode}
            </span>
            <h2 class="text-2xl font-bold text-white mt-2">{moduleName}</h2>
            <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                {description}
            </p>
        </div>
        <div class="pt-4 border-t border-slate-800 text-xs text-slate-500">
            Este módulo se integrará en la siguiente fase de desarrollo de Gekaem.
        </div>
    </div>
</AppLayout>
