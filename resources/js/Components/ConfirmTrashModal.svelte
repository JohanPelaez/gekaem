<script>
    let {
        open = false,
        title = 'Enviar a la Papelera',
        itemLabel = '',
        processing = false,
        onCancel = () => {},
        onConfirm = () => {},
    } = $props();
</script>

{#if open}
    <div class="fixed inset-0 z-50 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-600 dark:text-rose-400 flex items-center justify-center text-lg shrink-0">
                    🗑️
                </div>
                <div class="min-w-0">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">
                        {title}
                    </h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 mt-1">
                        ¿Estás seguro de eliminar <strong class="text-slate-900 dark:text-white">"{itemLabel}"</strong>?
                    </p>
                </div>
            </div>

            <div class="p-3.5 rounded-xl bg-sky-500/10 dark:bg-sky-500/10 border border-sky-500/30 text-sky-800 dark:text-sky-300 text-xs leading-relaxed">
                ♻️ Este elemento se moverá a la <strong>Papelera de Reciclaje</strong> y podrás recuperarlo durante <strong>8 días calendario</strong> antes de que se elimine de forma permanente.
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-100 dark:border-[#26334D]">
                <button
                    type="button"
                    onclick={onCancel}
                    disabled={processing}
                    class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-[#0B0F17] dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-[#26334D] text-xs font-semibold transition-colors cursor-pointer"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    onclick={onConfirm}
                    disabled={processing}
                    class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shadow-xs transition-colors disabled:opacity-50 cursor-pointer"
                >
                    {processing ? 'Eliminando...' : 'Sí, mover a la Papelera'}
                </button>
            </div>
        </div>
    </div>
{/if}

