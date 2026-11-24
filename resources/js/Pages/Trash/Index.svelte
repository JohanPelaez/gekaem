<script>
    import { untrack } from 'svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import { csrfFetch } from '../../Utils/csrfFetch.js';

    let {
        items = [],
        retentionDays = 8,
    } = $props();

    let localItems = $state([]);
    $effect(() => {
        const incoming = items;
        untrack(() => {
            localItems = JSON.parse(JSON.stringify(incoming || []));
        });
    });

    // Filtros reactivos
    let activeTab = $state('all');
    let searchQuery = $state('');

    // Estados de acciones y modales estilizados (sin window.confirm)
    let restoringKey = $state(null);
    let forceDeleteModalOpen = $state(false);
    let itemToForceDelete = $state(null);
    let isForceDeleting = $state(false);
    let forceDeleteError = $state('');

    let emptyModalOpen = $state(false);
    let isEmptying = $state(false);
    let emptyError = $state('');

    // Toast de confirmación / error
    let toast = $state({ visible: false, type: 'success', message: '' });
    let toastTimer = null;

    function showToast(message, type = 'success') {
        if (toastTimer) clearTimeout(toastTimer);
        toast = { visible: true, type, message };
        toastTimer = setTimeout(() => {
            toast = { visible: false, type: 'success', message: '' };
        }, 5000);
    }

    // Contadores reactivos por categoría
    let categoryCounts = $derived.by(() => {
        const res = {
            all: localItems.length,
            order: 0,
            customer: 0,
            workflow: 0,
            raw_material: 0,
            product: 0,
        };
        for (const item of localItems) {
            if (res[item.type] !== undefined) {
                res[item.type]++;
            }
        }
        return res;
    });

    let tabs = $derived([
        { key: 'all', label: 'Todos', icon: '', count: categoryCounts.all },
        { key: 'order', label: 'Pedidos', icon: '📦', count: categoryCounts.order },
        { key: 'customer', label: 'Clientes', icon: '👥', count: categoryCounts.customer },
        { key: 'workflow', label: 'Flujos de Trabajo', icon: '🔀', count: categoryCounts.workflow },
        { key: 'raw_material', label: 'Materias Primas', icon: '🧱', count: categoryCounts.raw_material },
        { key: 'product', label: 'Productos', icon: '🏷️', count: categoryCounts.product },
    ]);

    // Filtrado reactivo por pestaña + búsqueda
    let filteredItems = $derived.by(() => {
        const term = searchQuery.trim().toLowerCase();
        return localItems.filter((item) => {
            if (activeTab !== 'all' && item.type !== activeTab) {
                return false;
            }
            if (term) {
                const title = (item.title || '').toLowerCase();
                const subtitle = (item.subtitle || '').toLowerCase();
                const typeLabel = (item.type_label || '').toLowerCase();
                if (!title.includes(term) && !subtitle.includes(term) && !typeLabel.includes(term)) {
                    return false;
                }
            }
            return true;
        });
    });

    function getItemKey(item) {
        return `${item.type}:${item.id}`;
    }

    function formatDeletedDate(isoStr) {
        if (!isoStr) return 'Sin fecha';
        const d = new Date(isoStr);
        if (Number.isNaN(d.getTime())) return 'Sin fecha';
        return d.toLocaleDateString('es-CO', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function getTypeBadgeClass(type) {
        switch (type) {
            case 'order':
                return 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 border-indigo-500/25';
            case 'customer':
                return 'bg-cyan-500/10 text-cyan-700 dark:text-cyan-300 border-cyan-500/25';
            case 'workflow':
                return 'bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-500/25';
            case 'raw_material':
                return 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/25';
            case 'product':
                return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/25';
            default:
                return 'bg-slate-500/10 text-slate-700 dark:text-slate-300 border-slate-500/25';
        }
    }

    function getDaysBadgeClass(days) {
        if (days >= 4) {
            return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30';
        }
        if (days >= 2) {
            return 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30';
        }
        return 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/30';
    }

    function getDaysBadgeLabel(days) {
        if (days <= 0) return 'Se borra hoy';
        if (days === 1) return 'Se borra mañana';
        return `Quedan ${days} días`;
    }

    async function handleRestore(item) {
        const key = getItemKey(item);
        if (restoringKey) return;
        restoringKey = key;

        try {
            const res = await csrfFetch(`/trash/${item.type}/${item.id}/restore`, {
                method: 'POST',
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok || !data.success) {
                throw new Error(data.message || 'No se pudo recuperar el elemento.');
            }

            localItems = localItems.filter((i) => getItemKey(i) !== key);
            showToast(`↩️ "${item.title}" recuperado correctamente.`, 'success');
        } catch (err) {
            showToast(err.message || 'Error al recuperar el elemento.', 'error');
        } finally {
            restoringKey = null;
        }
    }

    function openForceDeleteModal(item) {
        itemToForceDelete = item;
        forceDeleteError = '';
        forceDeleteModalOpen = true;
    }

    function closeForceDeleteModal() {
        if (isForceDeleting) return;
        forceDeleteModalOpen = false;
        itemToForceDelete = null;
        forceDeleteError = '';
    }

    async function confirmForceDelete() {
        if (!itemToForceDelete) return;
        isForceDeleting = true;
        forceDeleteError = '';

        try {
            const res = await csrfFetch(`/trash/${itemToForceDelete.type}/${itemToForceDelete.id}/force`, {
                method: 'DELETE',
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok || !data.success) {
                throw new Error(data.message || 'No se pudo eliminar definitivamente el elemento.');
            }

            const key = getItemKey(itemToForceDelete);
            const title = itemToForceDelete.title;
            localItems = localItems.filter((i) => getItemKey(i) !== key);
            forceDeleteModalOpen = false;
            itemToForceDelete = null;
            showToast(`🗑️ "${title}" fue eliminado de forma permanente.`, 'success');
        } catch (err) {
            forceDeleteError = err.message || 'Error al eliminar definitivamente.';
        } finally {
            isForceDeleting = false;
        }
    }

    function openEmptyModal() {
        if (localItems.length === 0) return;
        emptyError = '';
        emptyModalOpen = true;
    }

    function closeEmptyModal() {
        if (isEmptying) return;
        emptyModalOpen = false;
        emptyError = '';
    }

    async function confirmEmptyTrash() {
        isEmptying = true;
        emptyError = '';

        try {
            const res = await csrfFetch('/trash/empty', {
                method: 'DELETE',
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok || !data.success) {
                throw new Error(data.message || 'No se pudo vaciar la papelera.');
            }

            if ((data.failed_count ?? 0) === 0) {
                localItems = [];
            } else {
                // Recargar si algún registro tuvo restricción FK
                window.location.reload();
                return;
            }

            emptyModalOpen = false;
            showToast(data.message || 'Papelera vaciada correctamente.', 'success');
        } catch (err) {
            emptyError = err.message || 'Error al vaciar la papelera.';
        } finally {
            isEmptying = false;
        }
    }
</script>

<AppLayout title="Papelera de Reciclaje">
    <div class="space-y-5 w-full max-w-none min-w-0">
        <!-- TOAST FLOTANTE -->
        {#if toast.visible}
            <div class="fixed bottom-5 right-5 z-50 max-w-md rounded-2xl px-4 py-3 shadow-xl border text-xs font-semibold flex items-center gap-3 {toast.type === 'error'
                ? 'bg-rose-50 dark:bg-rose-950/95 border-rose-300 dark:border-rose-700 text-rose-800 dark:text-rose-200'
                : 'bg-emerald-50 dark:bg-emerald-950/95 border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200'}">
                <span class="flex-1">{toast.message}</span>
                <button
                    type="button"
                    onclick={() => toast = { visible: false, type: 'success', message: '' }}
                    class="opacity-70 hover:opacity-100 font-bold"
                    aria-label="Cerrar notificación"
                >✕</button>
            </div>
        {/if}

        <!-- CABECERA Y ACCIONES GLOBALES -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        Papelera de Reciclaje
                    </h1>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-extrabold font-mono bg-slate-200/80 dark:bg-[#26334D] text-slate-700 dark:text-slate-200">
                        {localItems.length} {localItems.length === 1 ? 'elemento' : 'elementos'}
                    </span>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-50 dark:bg-[#4F46E5]/15 text-[#4F46E5] dark:text-indigo-300 border border-indigo-200 dark:border-[#4F46E5]/30">
                        Exclusivo CEO
                    </span>
                </div>
                <p class="text-xs text-[#64748B] dark:text-slate-400 mt-1">
                    Recupera elementos eliminados recientemente o purga registros de forma definitiva.
                </p>
            </div>

            <button
                type="button"
                onclick={openEmptyModal}
                disabled={localItems.length === 0}
                class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold border transition-all shrink-0 {localItems.length === 0
                    ? 'bg-slate-100 dark:bg-[#151D2C] border-slate-200 dark:border-[#26334D] text-slate-400 dark:text-slate-600 cursor-not-allowed'
                    : 'bg-rose-500/10 hover:bg-rose-600 text-rose-600 dark:text-rose-400 hover:text-white border-rose-500/30 hover:border-rose-600 shadow-xs cursor-pointer'}"
            >
                <span>🗑️ Vaciar Papelera</span>
            </button>
        </div>

        <!-- BANNER INFORMATIVO PERMANENTE DE POLÍTICA DE RETENCIÓN -->
        <div class="bg-amber-500/10 border border-amber-500/30 text-amber-800 dark:text-amber-300 rounded-2xl p-4 text-xs sm:text-sm leading-relaxed shadow-xs">
            <p class="font-medium">
                ⏳ <strong>Política de Retención:</strong> Los elementos eliminados (Pedidos, Clientes, Flujos de Trabajo, Materias Primas y Productos) permanecen en la Papelera de Reciclaje durante <strong>{retentionDays} días calendario</strong>. Pasado este tiempo, se eliminarán permanentemente de forma automática.
            </p>
        </div>

        <!-- PESTAÑAS DE FILTRO + BUSCADOR -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-4 shadow-xs space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                {#each tabs as tab}
                    <button
                        type="button"
                        onclick={() => activeTab = tab.key}
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold border transition-all cursor-pointer {activeTab === tab.key
                            ? 'bg-[#4F46E5] text-white border-[#4F46E5] shadow-xs'
                            : 'bg-slate-50 dark:bg-[#0B0F17] text-slate-600 dark:text-slate-300 border-slate-200 dark:border-[#26334D] hover:border-[#4F46E5]/40'}"
                    >
                        {#if tab.icon}
                            <span>{tab.icon}</span>
                        {/if}
                        <span>{tab.label}</span>
                        <span class="px-1.5 py-0.2 rounded-full text-[10px] font-bold font-mono {activeTab === tab.key
                            ? 'bg-white/20 text-white'
                            : 'bg-slate-200/80 dark:bg-[#26334D] text-slate-700 dark:text-slate-300'}">
                            {tab.count}
                        </span>
                    </button>
                {/each}
            </div>

            <div class="max-w-md">
                <label for="trash-search-input" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                    Buscar en la papelera
                </label>
                <input
                    id="trash-search-input"
                    type="text"
                    bind:value={searchQuery}
                    placeholder="Buscar por nombre, pedido, documento, SKU..."
                    class="w-full px-3.5 py-2 rounded-xl text-xs bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/15"
                />
            </div>
        </div>

        <!-- LISTADO DE ELEMENTOS ELIMINADOS O ESTADO VACÍO -->
        {#if filteredItems.length === 0}
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-10 text-center shadow-xs">
                <div class="w-14 h-14 mx-auto rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-3">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h2 class="text-base font-bold text-slate-900 dark:text-white">
                    {localItems.length === 0 ? 'La Papelera de Reciclaje está limpia' : 'No se encontraron elementos con este filtro'}
                </h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-md mx-auto">
                    {localItems.length === 0
                        ? 'No tienes registros eliminados pendientes de recuperación. Todo está al día en tu taller.'
                        : 'Prueba cambiando de categoría o limpiando el término de búsqueda.'}
                </p>
                {#if searchQuery.trim() || activeTab !== 'all'}
                    <button
                        type="button"
                        onclick={() => { activeTab = 'all'; searchQuery = ''; }}
                        class="mt-4 px-4 py-2 rounded-xl text-xs font-bold bg-slate-100 dark:bg-[#0B0F17] text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] hover:border-[#4F46E5]/40 transition-colors cursor-pointer"
                    >
                        Mostrar todos los elementos
                    </button>
                {/if}
            </div>
        {:else}
            <div class="space-y-2.5">
                {#each filteredItems as item (getItemKey(item))}
                    {@const itemKey = getItemKey(item)}
                    <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-4 shadow-xs hover:border-slate-300 dark:hover:border-slate-600 transition-all flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="min-w-0 space-y-1.5">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border {getTypeBadgeClass(item.type)}">
                                    {item.type_label}
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold border {getDaysBadgeClass(item.days_remaining)}">
                                    {getDaysBadgeLabel(item.days_remaining)}
                                </span>
                                <span class="text-[11px] text-slate-400 dark:text-slate-500">
                                    Eliminado el {formatDeletedDate(item.deleted_at)}
                                </span>
                            </div>

                            <h3 class="text-sm font-bold text-slate-900 dark:text-white truncate" title={item.title}>
                                {item.title}
                            </h3>

                            {#if item.subtitle}
                                <p class="text-xs text-slate-500 dark:text-slate-400 truncate" title={item.subtitle}>
                                    {item.subtitle}
                                </p>
                            {/if}
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <button
                                type="button"
                                onclick={() => handleRestore(item)}
                                disabled={restoringKey === itemKey}
                                class="px-3.5 py-2 rounded-xl text-xs font-bold bg-[#4F46E5] hover:bg-indigo-700 text-white shadow-xs transition-colors disabled:opacity-50 cursor-pointer"
                            >
                                {restoringKey === itemKey ? 'Recuperando...' : '↩️ Recuperar'}
                            </button>

                            <button
                                type="button"
                                onclick={() => openForceDeleteModal(item)}
                                disabled={restoringKey === itemKey}
                                class="px-3 py-2 rounded-xl text-xs font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 border border-transparent hover:border-rose-500/30 transition-colors cursor-pointer"
                            >
                                ✕ Eliminar ya
                            </button>
                        </div>
                    </div>
                {/each}
            </div>
        {/if}
    </div>

    <!-- MODAL ESTILIZADO: ELIMINAR DEFINITIVAMENTE UN ELEMENTO -->
    {#if forceDeleteModalOpen && itemToForceDelete}
        <div class="fixed inset-0 z-50 bg-slate-950/75 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl max-w-md w-full p-5 shadow-2xl space-y-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0 text-lg">
                        ⚠️
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">
                            Eliminar definitivamente
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            ¿Estás seguro de eliminar permanentemente <strong class="text-slate-900 dark:text-white">"{itemToForceDelete.title}"</strong>? Esta acción no se puede deshacer y el elemento no podrá recuperarse.
                        </p>
                    </div>
                </div>

                {#if forceDeleteError}
                    <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 text-xs font-medium">
                        {forceDeleteError}
                    </div>
                {/if}

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        onclick={closeForceDeleteModal}
                        disabled={isForceDeleting}
                        class="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-[#0B0F17] text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-[#26334D] hover:bg-slate-200 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        onclick={confirmForceDelete}
                        disabled={isForceDeleting}
                        class="px-4 py-2 rounded-xl text-xs font-bold bg-rose-600 hover:bg-rose-700 text-white shadow-xs transition-colors disabled:opacity-50 cursor-pointer"
                    >
                        {isForceDeleting ? 'Eliminando...' : 'Sí, eliminar permanentemente'}
                    </button>
                </div>
            </div>
        </div>
    {/if}

    <!-- MODAL ESTILIZADO: VACIAR TODA LA PAPELERA -->
    {#if emptyModalOpen}
        <div class="fixed inset-0 z-50 bg-slate-950/75 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl max-w-md w-full p-5 shadow-2xl space-y-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0 text-lg">
                        🗑️
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">
                            Vaciar Papelera de Reciclaje
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                            Se eliminarán permanentemente <strong class="text-slate-900 dark:text-white">{localItems.length} {localItems.length === 1 ? 'elemento' : 'elementos'}</strong> sin posibilidad de recuperación. ¿Deseas continuar?
                        </p>
                    </div>
                </div>

                {#if emptyError}
                    <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 text-xs font-medium">
                        {emptyError}
                    </div>
                {/if}

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        onclick={closeEmptyModal}
                        disabled={isEmptying}
                        class="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-[#0B0F17] text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-[#26334D] hover:bg-slate-200 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        onclick={confirmEmptyTrash}
                        disabled={isEmptying}
                        class="px-4 py-2 rounded-xl text-xs font-bold bg-rose-600 hover:bg-rose-700 text-white shadow-xs transition-colors disabled:opacity-50 cursor-pointer"
                    >
                        {isEmptying ? 'Vaciando...' : 'Sí, vaciar papelera'}
                    </button>
                </div>
            </div>
        </div>
    {/if}
</AppLayout>

