<script>
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let { workflows = [], errors = {} } = $props();

    let selectedWorkflowId = $state(null);
    let localWorkflows = $state([]);
    $effect(() => {
        const incoming = workflows;
        untrack(() => {
            localWorkflows = (incoming || []).map((wf) => ({
                ...wf,
                stages: [...(wf.stages || [])],
            }));
        });
    });

    let selectedWorkflow = $derived(
        localWorkflows.find((w) => w.id === selectedWorkflowId) ?? localWorkflows[0] ?? null,
    );

    let showCreateWorkflowModal = $state(false);
    let showEditWorkflowModal = $state(false);
    let editingWorkflow = $state(null);

    let showCreateStageModal = $state(false);
    let editingStage = $state(null);

    let workflowProcessing = $state(false);
    let stageProcessing = $state(false);
    let reorderProcessing = $state(false);

    // Paleta de colores predefinida de alta calidad para etapas
    const COLOR_PALETTE = [
        '#38bdf8', // Sky
        '#3b82f6', // Blue
        '#10b981', // Emerald
        '#14b8a6', // Teal
        '#f59e0b', // Amber
        '#f97316', // Orange
        '#ef4444', // Red
        '#ec4899', // Pink
        '#8b5cf6', // Purple
        '#64748b', // Slate
    ];

    // Formulario para nuevo flujo en Svelte 5 Rune
    let workflowForm = $state({
        name: '',
        description: '',
        is_default: false,
    });

    function resetWorkflowForm() {
        workflowForm.name = '';
        workflowForm.description = '';
        workflowForm.is_default = false;
    }

    // Formulario para editar flujo en Svelte 5 Rune
    let editWorkflowForm = $state({
        name: '',
        description: '',
        is_default: false,
    });

    function openEditWorkflow(wf) {
        editingWorkflow = wf;
        editWorkflowForm.name = wf.name ?? '';
        editWorkflowForm.description = wf.description ?? '';
        editWorkflowForm.is_default = Boolean(wf.is_default);
        showEditWorkflowModal = true;
    }

    // Formulario para nueva/editar etapa en Svelte 5 Rune (Posición automática)
    let stageForm = $state({
        name: '',
        color: '#38bdf8',
        stage_category: 'in_progress',
        wip_limit: '',
    });

    function resetStageForm() {
        stageForm.name = '';
        stageForm.color = '#38bdf8';
        stageForm.stage_category = 'in_progress';
        stageForm.wip_limit = '';
    }

    const categoryDescriptions = {
        pending: 'En Espera (Sin iniciar)',
        in_progress: 'En Proceso (Descuenta materias primas si no se consumieron)',
        blocked: 'Bloqueado (Detiene y marca en alerta el pedido)',
        done: 'Terminado (Completa y avanza el pedido a listo)',
    };

    function submitWorkflow(e) {
        e.preventDefault();
        router.post('/workflows', workflowForm, {
            preserveScroll: true,
            onStart: () => { workflowProcessing = true; },
            onFinish: () => { workflowProcessing = false; },
            onSuccess: () => {
                showCreateWorkflowModal = false;
                resetWorkflowForm();
            },
        });
    }

    function submitEditWorkflow(e) {
        e.preventDefault();
        if (!editingWorkflow) return;
        router.patch(`/workflows/${editingWorkflow.id}`, editWorkflowForm, {
            preserveScroll: true,
            onStart: () => { workflowProcessing = true; },
            onFinish: () => { workflowProcessing = false; },
            onSuccess: () => {
                showEditWorkflowModal = false;
                editingWorkflow = null;
            },
        });
    }

    function openCreateStage(wf) {
        selectedWorkflowId = wf.id;
        stageForm.name = '';
        stageForm.color = '#38bdf8';
        stageForm.stage_category = 'in_progress';
        stageForm.wip_limit = '';
        editingStage = null;
        showCreateStageModal = true;
    }

    function openEditStage(stage, wf) {
        selectedWorkflowId = wf.id;
        editingStage = stage;
        stageForm.name = stage.name;
        stageForm.color = stage.color || '#38bdf8';
        stageForm.stage_category = stage.stage_category;
        stageForm.wip_limit = stage.wip_limit ?? '';
        showCreateStageModal = true;
    }

    function submitStage(e) {
        e.preventDefault();
        if (editingStage) {
            router.patch(`/workflows/stages/${editingStage.id}`, stageForm, {
                preserveScroll: true,
                onStart: () => { stageProcessing = true; },
                onFinish: () => { stageProcessing = false; },
                onSuccess: () => {
                    showCreateStageModal = false;
                    editingStage = null;
                    resetStageForm();
                },
            });
        } else {
            router.post(`/workflows/${selectedWorkflow.id}/stages`, stageForm, {
                preserveScroll: true,
                onStart: () => { stageProcessing = true; },
                onFinish: () => { stageProcessing = false; },
                onSuccess: () => {
                    showCreateStageModal = false;
                    resetStageForm();
                },
            });
        }
    }

    function deleteStage(stage) {
        if (confirm(`¿Eliminar la etapa "${stage.name}"? Esta acción no se puede deshacer.`)) {
            router.delete(`/workflows/stages/${stage.id}`, {
                preserveScroll: true,
            });
        }
    }

    // Estado y handlers para Drag & Drop de Columnas (Punto 16)
    let draggedStageId = $state(null);
    let draggedWorkflowId = $state(null);
    let dragOverStageId = $state(null);

    function handleDragStart(e, stage, wf) {
        draggedStageId = stage.id;
        draggedWorkflowId = wf.id;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(stage.id));
    }

    function handleDragOver(e, stage, wf) {
        if (draggedWorkflowId !== wf.id || draggedStageId === stage.id) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    }

    function handleDragEnter(stage, wf) {
        if (draggedWorkflowId === wf.id && draggedStageId !== stage.id) {
            dragOverStageId = stage.id;
        }
    }

    function handleDragLeave(stage) {
        if (dragOverStageId === stage.id) {
            dragOverStageId = null;
        }
    }

    function handleDragEnd() {
        draggedStageId = null;
        draggedWorkflowId = null;
        dragOverStageId = null;
    }

    function handleDrop(e, targetStage, wf) {
        e.preventDefault();
        if (draggedWorkflowId !== wf.id || !draggedStageId || draggedStageId === targetStage.id) {
            handleDragEnd();
            return;
        }

        const stagesList = [...(wf.stages || [])];
        const sourceIndex = stagesList.findIndex((s) => s.id === draggedStageId);
        const targetIndex = stagesList.findIndex((s) => s.id === targetStage.id);

        if (sourceIndex === -1 || targetIndex === -1) {
            handleDragEnd();
            return;
        }

        // Reordenamiento visual instantáneo en el cliente
        const [moved] = stagesList.splice(sourceIndex, 1);
        stagesList.splice(targetIndex, 0, moved);

        // Actualizar posiciones visuales
        stagesList.forEach((s, i) => {
            s.position = i + 1;
        });
        wf.stages = stagesList;

        const stageIds = stagesList.map((s) => s.id);
        handleDragEnd();

        // Persistir inmediatamente el nuevo orden en el servidor
        reorderProcessing = true;
        router.post(
            `/workflows/${wf.id}/stages/reorder`,
            { stage_ids: stageIds },
            {
                preserveScroll: true,
                onFinish: () => { reorderProcessing = false; },
            },
        );
    }

    function moveStage(wf, stageIndex, direction) {
        const stagesList = [...(wf.stages || [])];
        const targetIndex = stageIndex + direction;
        if (targetIndex < 0 || targetIndex >= stagesList.length) return;

        const currentStageItem = stagesList[stageIndex];
        stagesList[stageIndex] = stagesList[targetIndex];
        stagesList[targetIndex] = currentStageItem;

        stagesList.forEach((s, i) => {
            s.position = i + 1;
        });
        wf.stages = stagesList;

        const stageIds = stagesList.map((s) => s.id);

        reorderProcessing = true;
        router.post(
            `/workflows/${wf.id}/stages/reorder`,
            { stage_ids: stageIds },
            {
                preserveScroll: true,
                onFinish: () => { reorderProcessing = false; },
            },
        );
    }

    function getCategoryLabel(category) {
        switch (category) {
            case 'pending': return 'En Espera';
            case 'in_progress': return 'En Proceso';
            case 'blocked': return 'Bloqueado';
            case 'done': return 'Terminado';
            default: return category;
        }
    }
</script>

<AppLayout title="Tipos de Flujos">
    <div class="space-y-6">
        <!-- ENCABEZADO -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Módulo 7
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Tipos de Flujos y Estados Dinámicos</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Configura los flujos de producción del taller, edita títulos, personaliza colores en tiempo real y reordena las columnas de los tableros Kanban.
                </p>
            </div>
            <button
                type="button"
                onclick={() => showCreateWorkflowModal = true}
                class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-colors shadow-lg shadow-emerald-600/20 flex items-center gap-2 shrink-0 self-start sm:self-auto"
            >
                <span>+</span>
                <span>Nuevo Flujo</span>
            </button>
        </div>

        <!-- LISTA DE FLUJOS -->
        <div class="space-y-6">
            {#each workflows as wf (wf.id)}
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-md">
                    <!-- Cabecera del Flujo -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-800">
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h2 class="text-lg font-bold text-white">{wf.name}</h2>
                                {#if wf.is_default}
                                    <span class="px-2 py-0.5 rounded-full text-[10px] uppercase font-bold tracking-wider bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                        Predeterminado
                                    </span>
                                {/if}
                                <button
                                    type="button"
                                    onclick={() => openEditWorkflow(wf)}
                                    class="text-[11px] text-sky-400 hover:text-sky-300 font-semibold px-2 py-0.5 rounded bg-sky-950/40 border border-sky-800/40 hover:bg-sky-900/40 transition-colors flex items-center gap-1"
                                >
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    <span>Editar</span>
                                </button>
                            </div>
                            <p class="text-xs text-slate-400 mt-1">{wf.description ?? 'Sin descripción'}</p>
                            <span class="text-[11px] text-slate-500 block mt-1">
                                {wf.products_count ?? 0} producto(s) asignados a este flujo
                            </span>
                        </div>

                        <button
                            type="button"
                            onclick={() => openCreateStage(wf)}
                            class="px-3.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-semibold flex items-center gap-1.5 shrink-0 self-start sm:self-auto transition-colors"
                        >
                            <span>+</span>
                            <span>Agregar Columna</span>
                        </button>
                    </div>

                    <!-- Columnas / Etapas Dinámicas del Flujo -->
                    <div class="mt-4">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">
                                Columnas en el Tablero Kanban ({wf.stages?.length ?? 0})
                            </span>
                            <span class="text-[11px] text-slate-500">
                                Arrastra las columnas o usa los botones ◀ ▶ para reordenar
                            </span>
                        </div>

                        {#if !wf.stages || wf.stages.length === 0}
                            <div class="p-6 text-center border-2 border-dashed border-slate-800 rounded-xl text-xs text-slate-500">
                                No hay etapas definidas para este flujo. Agrega una columna para empezar a operar en el Kanban.
                            </div>
                        {:else}
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 gap-3">
                                {#each wf.stages as stage, idx (stage.id)}
                                    <div
                                        role="group"
                                        aria-label="Columna: {stage.name}"
                                        draggable="true"
                                        ondragstart={(e) => handleDragStart(e, stage, wf)}
                                        ondragover={(e) => handleDragOver(e, stage, wf)}
                                        ondragenter={() => handleDragEnter(stage, wf)}
                                        ondragleave={() => handleDragLeave(stage)}
                                        ondragend={handleDragEnd}
                                        ondrop={(e) => handleDrop(e, stage, wf)}
                                        class="rounded-xl p-3.5 flex flex-col justify-between group transition-all shadow-sm cursor-grab active:cursor-grabbing select-none {draggedStageId === stage.id ? 'opacity-30 scale-95 border-2 border-dashed border-emerald-400 bg-slate-900/50' : dragOverStageId === stage.id ? 'border-2 border-emerald-400 bg-emerald-950/50 scale-[1.03] shadow-lg shadow-emerald-950/60 ring-2 ring-emerald-500/30' : 'bg-slate-950/70 border border-slate-800 hover:border-slate-700'}"
                                        style="border-top: 3px solid {stage.color || '#64748b'};"
                                    >
                                        <div>
                                            <!-- Barra de posición y controles de ordenamiento -->
                                            <div class="flex items-center justify-between text-xs mb-2">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-slate-600 group-hover:text-slate-400 text-xs font-mono select-none" title="Arrastra para mover">⋮⋮</span>
                                                    <div
                                                        class="w-3 h-3 rounded-full shadow-sm"
                                                        style="background-color: {stage.color || '#64748b'};"
                                                    ></div>
                                                    <span class="font-mono text-slate-400 text-[10px] font-bold">Columna #{stage.position}</span>
                                                </div>

                                                <!-- Botones Reordenar ◀ ▶ -->
                                                <div class="flex items-center gap-1">
                                                    <button
                                                        type="button"
                                                        disabled={idx === 0 || reorderProcessing}
                                                        onclick={(e) => { e.stopPropagation(); moveStage(wf, idx, -1); }}
                                                        class="p-1 rounded bg-slate-900 hover:bg-slate-800 text-slate-400 hover:text-white disabled:opacity-20 text-[10px] font-bold transition-opacity"
                                                        title="Mover columna a la izquierda"
                                                    >
                                                        ◀
                                                    </button>
                                                    <button
                                                        type="button"
                                                        disabled={idx === wf.stages.length - 1 || reorderProcessing}
                                                        onclick={(e) => { e.stopPropagation(); moveStage(wf, idx, 1); }}
                                                        class="p-1 rounded bg-slate-900 hover:bg-slate-800 text-slate-400 hover:text-white disabled:opacity-20 text-[10px] font-bold transition-opacity"
                                                        title="Mover columna a la derecha"
                                                    >
                                                        ▶
                                                    </button>
                                                </div>
                                            </div>

                                            <h4 class="font-bold text-sm text-white truncate" title={stage.name}>{stage.name}</h4>
                                            
                                            <div class="mt-2.5 flex flex-col gap-1.5">
                                                <!-- Badge con respeto estricto de color HEX -->
                                                <span
                                                    class="text-[10px] px-2 py-0.5 rounded-md font-bold uppercase tracking-wider text-center"
                                                    style="background-color: {stage.color || '#64748b'}22; color: {stage.color || '#64748b'}; border: 1px solid {stage.color || '#64748b'}55;"
                                                >
                                                    {getCategoryLabel(stage.stage_category)}
                                                </span>
                                                {#if stage.wip_limit}
                                                    <span class="text-[10px] text-slate-400 font-mono text-center">
                                                        Límite WIP: {stage.wip_limit}
                                                    </span>
                                                {/if}
                                            </div>
                                        </div>

                                        <!-- Acciones de Etapa -->
                                        <div class="mt-3 pt-2.5 border-t border-slate-800/80 flex items-center justify-between text-[11px]">
                                            <span class="text-[10px] font-mono text-slate-500">{stage.color}</span>
                                            <div class="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onclick={() => openEditStage(stage, wf)}
                                                    class="text-slate-400 hover:text-emerald-400 font-semibold transition-colors"
                                                >
                                                    Editar
                                                </button>
                                                <button
                                                    type="button"
                                                    onclick={() => deleteStage(stage)}
                                                    class="text-slate-500 hover:text-rose-400 font-semibold transition-colors"
                                                >
                                                    Eliminar
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                {/each}
                            </div>
                        {/if}
                    </div>
                </div>
            {/each}
        </div>
    </div>
</AppLayout>

<!-- MODAL NUEVO FLUJO -->
{#if showCreateWorkflowModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">Crear Nuevo Flujo de Producción</h3>
                <button type="button" onclick={() => showCreateWorkflowModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitWorkflow} class="space-y-4 text-xs">
                <div>
                    <label for="wf-name" class="block font-semibold text-slate-300 mb-1">Nombre del Flujo *</label>
                    <input
                        id="wf-name"
                        type="text"
                        bind:value={workflowForm.name}
                        placeholder="Ej. Confección de Vestidos"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div>
                    <label for="wf-desc" class="block font-semibold text-slate-300 mb-1">Descripción</label>
                    <textarea
                        id="wf-desc"
                        bind:value={workflowForm.description}
                        placeholder="Detalles del proceso para el taller..."
                        rows="2"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    ></textarea>
                </div>

                <div class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        id="is_default"
                        bind:checked={workflowForm.is_default}
                        class="rounded bg-slate-950 border-slate-800 text-emerald-500 focus:ring-emerald-500"
                    />
                    <label for="is_default" class="text-slate-300 font-medium">Establecer como flujo predeterminado del taller</label>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={() => showCreateWorkflowModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={workflowProcessing}
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                    >
                        Guardar Flujo
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL EDITAR FLUJO -->
{#if showEditWorkflowModal && editingWorkflow}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">Editar Flujo: {editingWorkflow.name}</h3>
                <button type="button" onclick={() => showEditWorkflowModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitEditWorkflow} class="space-y-4 text-xs">
                <div>
                    <label for="edit-wf-name" class="block font-semibold text-slate-300 mb-1">Nombre del Flujo *</label>
                    <input
                        id="edit-wf-name"
                        type="text"
                        bind:value={editWorkflowForm.name}
                        placeholder="Ej. Confección de Vestidos"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                </div>

                <div>
                    <label for="edit-wf-desc" class="block font-semibold text-slate-300 mb-1">Descripción</label>
                    <textarea
                        id="edit-wf-desc"
                        bind:value={editWorkflowForm.description}
                        placeholder="Detalles del proceso para el taller..."
                        rows="2"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    ></textarea>
                </div>

                <div class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        id="edit_is_default"
                        bind:checked={editWorkflowForm.is_default}
                        class="rounded bg-slate-950 border-slate-800 text-emerald-500 focus:ring-emerald-500"
                    />
                    <label for="edit_is_default" class="text-slate-300 font-medium">Establecer como flujo predeterminado del taller</label>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={() => showEditWorkflowModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={workflowProcessing}
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                    >
                        Actualizar Flujo
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL CREAR / EDITAR ETAPA -->
{#if showCreateStageModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">
                    {editingStage ? `Editar Columna: ${editingStage.name}` : `Nueva Columna en: ${selectedWorkflow?.name}`}
                </h3>
                <button type="button" onclick={() => showCreateStageModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitStage} class="space-y-4 text-xs">
                <div>
                    <label for="stage-name" class="block font-semibold text-slate-300 mb-1">Nombre de la Etapa *</label>
                    <input
                        id="stage-name"
                        type="text"
                        bind:value={stageForm.name}
                        placeholder="Ej. Sublimación Térmica"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div>
                    <label for="stage-color" class="block font-semibold text-slate-300 mb-1">Color Identificador</label>
                    <div class="flex items-center gap-2">
                        <input
                            id="stage-color"
                            type="color"
                            bind:value={stageForm.color}
                            class="w-8 h-8 rounded-lg cursor-pointer bg-transparent border-0"
                            title="Seleccionar color"
                        />
                        <label for="stage-color-hex" class="sr-only">Código Hexadecimal</label>
                        <input
                            id="stage-color-hex"
                            type="text"
                            bind:value={stageForm.color}
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-2 py-1.5 text-white text-xs font-mono"
                        />
                    </div>
                </div>

                <!-- Paleta de colores predefinidos para selección rápida -->
                <div>
                    <span class="block font-semibold text-slate-400 text-[11px] mb-1.5">Paleta Rápida de Colores</span>
                    <div class="flex items-center gap-1.5 flex-wrap">
                        {#each COLOR_PALETTE as hex}
                            <button
                                type="button"
                                onclick={() => stageForm.color = hex}
                                class="w-6 h-6 rounded-full border transition-transform hover:scale-110 {stageForm.color === hex ? 'ring-2 ring-white ring-offset-2 ring-offset-slate-900 border-white' : 'border-black/30'}"
                                style="background-color: {hex};"
                                title={hex}
                            ></button>
                        {/each}
                    </div>
                </div>

                <div>
                    <label for="stage-cat" class="block font-semibold text-slate-300 mb-1">Comportamiento / Estado en Sistema *</label>
                    <select
                        id="stage-cat"
                        bind:value={stageForm.stage_category}
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    >
                        <option value="pending">pending — En Espera</option>
                        <option value="in_progress">in_progress — En Fabricación / Operación</option>
                        <option value="blocked">blocked — Bloqueado / Detenido</option>
                        <option value="done">done — Terminado / Listo</option>
                    </select>
                    <p class="text-[10px] text-slate-500 mt-1">
                        {categoryDescriptions[stageForm.stage_category] ?? ''}
                    </p>
                </div>

                <div>
                    <label for="stage-wip" class="block font-semibold text-slate-300 mb-1">Límite WIP (Opcional)</label>
                    <input
                        id="stage-wip"
                        type="number"
                        min="1"
                        bind:value={stageForm.wip_limit}
                        placeholder="Ej. 4 tareas máximas simultáneas"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={() => showCreateStageModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={stageProcessing}
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                    >
                        {editingStage ? 'Actualizar Columna' : 'Guardar Columna'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
