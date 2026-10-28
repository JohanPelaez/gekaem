<script>
    import { untrack } from 'svelte';
    import { csrfFetch } from '../../Utils/csrfFetch.js';

    let {
        isOpen = $bindable(false),
        orderStatuses = [],
        errors = {},
        onStatusesChanged = () => {},
    } = $props();

    // Copia local para reordenar y editar
    let localStatuses = $state([]);
    $effect(() => {
        const incoming = orderStatuses;
        untrack(() => {
            localStatuses = JSON.parse(JSON.stringify(incoming || [])).toSorted((a, b) => (a.position || 0) - (b.position || 0));
        });
    });

    // Formulario de creación
    let newName = $state('');
    let newColor = $state('#6366f1');
    let isCreating = $state(false);
    let createError = $state('');

    // Estado para edición en línea de un estado
    let editingStatusId = $state(null);
    let editName = $state('');
    let editColor = $state('');
    let isSavingEdit = $state(false);

    // Estado de Drag & Drop para reordenar
    let draggedIndex = $state(null);
    let dragOverIndex = $state(null);
    let isReordering = $state(false);

    const PRESET_COLORS = [
        '#64748b', // Slate
        '#3b82f6', // Blue
        '#06b6d4', // Cyan
        '#10b981', // Emerald
        '#f59e0b', // Amber
        '#ef4444', // Rose/Red
        '#8b5cf6', // Violet
        '#ec4899', // Pink
        '#14b8a6', // Teal
        '#f97316', // Orange
    ];

    async function handleCreate(e) {
        e.preventDefault();
        if (!newName.trim()) return;

        isCreating = true;
        createError = '';

        try {
            const res = await csrfFetch('/order-statuses', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: newName.trim(),
                    color: newColor,
                }),
            });

            if (!res.ok) {
                const errData = await res.json().catch(() => ({}));
                throw new Error(errData.message || 'Error al crear el estado.');
            }

            const data = await res.json();
            if (data.status) {
                localStatuses = [...localStatuses, data.status];
            }
            newName = '';
            newColor = '#6366f1';
            onStatusesChanged();
        } catch (err) {
            createError = err.message || 'No se pudo crear el estado.';
        } finally {
            isCreating = false;
        }
    }

    function startEdit(status) {
        editingStatusId = status.id;
        editName = status.name;
        editColor = status.color || '#64748b';
    }

    function cancelEdit() {
        editingStatusId = null;
        editName = '';
        editColor = '';
    }

    async function saveEdit(statusId) {
        if (!editName.trim()) return;

        isSavingEdit = true;
        try {
            const res = await csrfFetch(`/order-statuses/${statusId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: editName.trim(),
                    color: editColor,
                }),
            });

            if (!res.ok) {
                throw new Error('Error al actualizar el estado.');
            }

            const found = localStatuses.find((s) => s.id === statusId);
            if (found) {
                found.name = editName.trim();
                found.color = editColor;
            }
            cancelEdit();
            onStatusesChanged();
        } catch (err) {
            alert(err.message || 'Error al guardar cambios.');
        } finally {
            isSavingEdit = false;
        }
    }

    async function handleDelete(status) {
        const isSystem = status.system_key && status.system_key !== 'custom';
        const msg = isSystem
            ? `¿Estás seguro de eliminar el estado del sistema "${status.name}"? Los pedidos asignados pasarán al primer estado disponible.`
            : `¿Eliminar el estado "${status.name}"? Los pedidos que estén en esta columna se reasignarán automáticamente.`;

        if (!confirm(msg)) return;

        try {
            const res = await csrfFetch(`/order-statuses/${status.id}`, {
                method: 'DELETE',
            });

            if (!res.ok) {
                throw new Error('No se pudo eliminar el estado.');
            }

            localStatuses = localStatuses.filter((s) => s.id !== status.id);
            onStatusesChanged();
        } catch (err) {
            alert(err.message || 'Error al eliminar el estado.');
        }
    }

    // --- Drag & Drop de Reordenamiento ---
    function handleDragStart(index) {
        draggedIndex = index;
    }

    function handleDragOver(e, index) {
        e.preventDefault();
        dragOverIndex = index;
    }

    async function handleDrop(e, targetIndex) {
        e.preventDefault();
        if (draggedIndex === null || draggedIndex === targetIndex) {
            draggedIndex = null;
            dragOverIndex = null;
            return;
        }

        const items = [...localStatuses];
        const [moved] = items.splice(draggedIndex, 1);
        items.splice(targetIndex, 0, moved);

        // Actualizar posiciones locales
        items.forEach((item, idx) => {
            item.position = idx + 1;
        });

        localStatuses = items;
        draggedIndex = null;
        dragOverIndex = null;

        // Persistir nuevo orden
        isReordering = true;
        try {
            await csrfFetch('/order-statuses/reorder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    status_ids: items.map((s) => s.id),
                }),
            });
            onStatusesChanged();
        } catch (err) {
            console.error('Error al guardar nuevo orden de estados:', err);
        } finally {
            isReordering = false;
        }
    }
</script>

{#if isOpen}
    <div class="fixed inset-0 z-50 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl max-w-xl w-full p-6 shadow-2xl space-y-5 max-h-[92vh] overflow-y-auto text-slate-900 dark:text-slate-100 animate-in fade-in zoom-in-95 duration-100">
            <!-- Cabecera -->
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <div class="flex items-center gap-2.5">
                    <span class="text-xl">⚙️</span>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">Estados de Pedidos</h3>
                        <p class="text-xs text-[#64748B] dark:text-slate-400">
                            Crea, edita y reordena con Drag & Drop las columnas de tu Tablero Macro.
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    onclick={() => isOpen = false}
                    class="text-slate-400 hover:text-slate-700 dark:hover:text-white p-1 rounded-lg"
                >
                    ✕
                </button>
            </div>

            <!-- Formulario para Crear Nuevo Estado -->
            <form onsubmit={handleCreate} class="p-4 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-slate-200 dark:border-[#26334D] space-y-3">
                <span class="text-xs font-bold text-slate-900 dark:text-white block">Crear Nuevo Estado de Pedido</span>

                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                    <div class="sm:col-span-6">
                        <label for="new-status-name" class="block text-[11px] font-semibold text-[#64748B] dark:text-slate-400 mb-1">Nombre del Estado *</label>
                        <input
                            id="new-status-name"
                            type="text"
                            bind:value={newName}
                            required
                            placeholder="Ej. Control de Calidad, Empaque..."
                            class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white text-xs placeholder:text-slate-400 focus:outline-none focus:border-[#4F46E5]"
                        />
                    </div>

                    <div class="sm:col-span-3">
                        <label for="new-status-color-input" class="block text-[11px] font-semibold text-[#64748B] dark:text-slate-400 mb-1">Color HEX *</label>
                        <div class="flex items-center gap-1.5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl px-2 py-1">
                            <label for="new-status-color-picker" class="sr-only">Selector de color</label>
                            <input
                                id="new-status-color-picker"
                                type="color"
                                bind:value={newColor}
                                class="w-6 h-6 rounded border-0 cursor-pointer bg-transparent"
                            />
                            <input
                                id="new-status-color-input"
                                type="text"
                                bind:value={newColor}
                                class="w-full bg-transparent text-slate-900 dark:text-white font-mono text-xs focus:outline-none"
                            />
                        </div>
                    </div>

                    <div class="sm:col-span-3">
                        <button
                            type="submit"
                            disabled={isCreating || !newName.trim()}
                            class="w-full px-3 py-2 bg-[#4F46E5] hover:bg-[#4338CA] text-white font-semibold rounded-xl text-xs transition shadow-sm disabled:opacity-50"
                        >
                            {isCreating ? 'Guardando...' : '+ Crear'}
                        </button>
                    </div>
                </div>

                <!-- Paleta de colores rápidos -->
                <div class="flex items-center gap-1.5 pt-1">
                    <span class="text-[10px] text-[#64748B] dark:text-slate-500 font-semibold mr-1">Colores:</span>
                    {#each PRESET_COLORS as preset}
                        <button
                            type="button"
                            onclick={() => newColor = preset}
                            class="w-4 h-4 rounded-full border border-slate-300 dark:border-white/20 transition-transform hover:scale-125 {newColor === preset ? 'ring-2 ring-[#4F46E5] dark:ring-white scale-110' : ''}"
                            style="background-color: {preset};"
                            title={preset}
                        ></button>
                    {/each}
                </div>

                {#if createError}
                    <p class="text-[11px] text-rose-600 dark:text-rose-400">{createError}</p>
                {/if}
            </form>

            <!-- Lista de Estados Reordenables -->
            <div class="space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-bold text-slate-900 dark:text-white">Estados Actuales ({localStatuses.length})</span>
                    <span class="text-[11px] text-[#64748B] dark:text-slate-500">Arrastra desde ⋮⋮ para cambiar el orden</span>
                </div>

                {#if localStatuses.length === 0}
                    <div class="p-6 text-center border-2 border-dashed border-slate-200 dark:border-[#26334D] rounded-2xl text-[#64748B] dark:text-slate-500 text-xs">
                        No hay estados registrados para este taller.
                    </div>
                {:else}
                    <div class="space-y-2" role="list">
                        {#each localStatuses as status, index (status.id)}
                            <div
                                role="listitem"
                                draggable="true"
                                ondragstart={() => handleDragStart(index)}
                                ondragover={(e) => handleDragOver(e, index)}
                                ondrop={(e) => handleDrop(e, index)}
                                class="p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border transition-all flex flex-col sm:flex-row sm:items-center justify-between gap-3 {dragOverIndex === index ? 'border-[#4F46E5] bg-indigo-50/50 dark:bg-sky-950/20 shadow-md' : 'border-slate-200 dark:border-[#26334D] hover:border-slate-300 dark:hover:border-slate-600'}"
                            >
                                {#if editingStatusId === status.id}
                                    <!-- Modo Edición Inline -->
                                    <div class="flex-1 grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
                                        <div class="sm:col-span-6">
                                            <label for="edit-status-name-{status.id}" class="sr-only">Nombre</label>
                                            <input
                                                id="edit-status-name-{status.id}"
                                                type="text"
                                                bind:value={editName}
                                                class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1 text-slate-900 dark:text-white text-xs focus:outline-none focus:border-[#4F46E5]"
                                            />
                                        </div>
                                        <div class="sm:col-span-6 flex items-center gap-1.5">
                                            <label for="edit-color-picker-{status.id}" class="sr-only">Color</label>
                                            <input
                                                id="edit-color-picker-{status.id}"
                                                type="color"
                                                bind:value={editColor}
                                                class="w-6 h-6 rounded border-0 cursor-pointer bg-transparent shrink-0"
                                            />
                                            <label for="edit-color-text-{status.id}" class="sr-only">Color Hex</label>
                                            <input
                                                id="edit-color-text-{status.id}"
                                                type="text"
                                                bind:value={editColor}
                                                class="w-20 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1 text-slate-900 dark:text-white font-mono text-xs"
                                            />
                                            <button
                                                type="button"
                                                onclick={() => saveEdit(status.id)}
                                                disabled={isSavingEdit || !editName.trim()}
                                                class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-lg text-xs"
                                            >
                                                ✓
                                            </button>
                                            <button
                                                type="button"
                                                onclick={cancelEdit}
                                                class="px-2 py-1 bg-slate-200 hover:bg-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-300 rounded-lg text-xs"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    </div>
                                {:else}
                                    <!-- Modo Visualización / Arrastre -->
                                    <div class="flex items-center gap-3 min-w-0">
                                        <!-- Handle de arrastre -->
                                        <span
                                            class="cursor-grab active:cursor-grabbing text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 text-base select-none px-1"
                                            title="Arrastrar para reordenar"
                                        >
                                            ⋮⋮
                                        </span>

                                        <!-- Posición -->
                                        <span class="w-5 text-center font-mono font-bold text-[11px] text-[#64748B] dark:text-slate-500">
                                            {index + 1}
                                        </span>

                                        <!-- Indicador de color -->
                                        <span
                                            class="w-3.5 h-3.5 rounded-full shrink-0 shadow-sm"
                                            style="background-color: {status.color || '#64748b'};"
                                        ></span>

                                        <!-- Nombre y badge -->
                                        <div class="flex items-center gap-2 truncate">
                                            <span class="font-bold text-slate-900 dark:text-white text-xs truncate">
                                                {status.name}
                                            </span>
                                            {#if status.system_key && status.system_key !== 'custom'}
                                                <span class="px-2 py-0.5 rounded-full text-[9px] font-bold tracking-wider uppercase bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-300 dark:border-slate-700 shrink-0">
                                                    Sistema
                                                </span>
                                            {/if}
                                        </div>
                                    </div>

                                    <!-- Acciones -->
                                    <div class="flex items-center gap-1.5 self-end sm:self-auto shrink-0">
                                        <button
                                            type="button"
                                            onclick={() => startEdit(status)}
                                            class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white text-xs font-semibold border border-slate-200 dark:border-[#26334D] transition-colors"
                                            title="Editar nombre y color"
                                        >
                                            ✏️ Editar
                                        </button>
                                        <button
                                            type="button"
                                            onclick={() => handleDelete(status)}
                                            class="p-1 rounded-lg text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors"
                                            title="Eliminar estado"
                                        >
                                            🗑
                                        </button>
                                    </div>
                                {/if}
                            </div>
                        {/each}
                    </div>
                {/if}
            </div>

            <!-- Pie del Modal -->
            <div class="pt-3 border-t border-slate-200 dark:border-[#26334D] flex items-center justify-between">
                <span class="text-[11px] text-[#64748B] dark:text-slate-500">
                    {isReordering ? 'Guardando orden...' : 'Los cambios en las columnas se aplican en tiempo real al tablero.'}
                </span>
                <button
                    type="button"
                    onclick={() => isOpen = false}
                    class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold text-xs transition"
                >
                    Cerrar
                </button>
            </div>
        </div>
    </div>
{/if}
