<script>
    import AppLayout from '../Layouts/AppLayout.svelte';

    let { moduleName = 'Módulo', moduleCode = 'MVP', description = 'En construcción' } = $props();
</script>

<AppLayout title={moduleName}>
    <div class="max-w-xl mx-auto my-12 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-8 text-center shadow-sm space-y-4">
        <div class="w-16 h-16 mx-auto rounded-2xl bg-[#4F46E5]/10 border border-[#4F46E5]/20 text-[#4F46E5] flex items-center justify-center text-2xl font-bold">
            🔨
        </div>
        <div>
            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-50 dark:bg-[#4F46E5]/15 text-[#4F46E5] dark:text-indigo-300 border border-indigo-200 dark:border-[#4F46E5]/30">
                {moduleCode}
            </span>
            <h2 class="text-2xl font-bold text-slate-900 dark:text-white mt-2">{moduleName}</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
                {description}
            </p>
        </div>
        <div class="pt-4 border-t border-slate-100 dark:border-[#26334D] text-xs text-slate-400 dark:text-slate-500">
            Este módulo se integrará en la siguiente fase de desarrollo de gipPyme.
        </div>
    </div>
</AppLayout>
