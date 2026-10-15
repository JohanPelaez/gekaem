<script>
    import { router } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let { workflows = [], errors = {} } = $props();

    let selectedWorkflowId = $state(null);
    let selectedWorkflow = $derived(
        workflows.find((w) => w.id === selectedWorkflowId) ?? workflows[0] ?? null,
    );
    let showCreateWorkflowModal = $state(false);
    let showCreateStageModal = $state(false);
    let editingStage = $state(null);

    let workflowProcessing = $state(false);
    let stageProcessing = $state(false);

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

    // Formulario para nueva etapa en Svelte 5 Rune
    let stageForm = $state({
        name: '',
        color: '#38bdf8',
        position: 1,
        stage_category: 'in_progress',
        wip_limit: '',
    });

    function resetStageForm() {
        stageForm.name = '';
        stageForm.color = '#38bdf8';
        stageForm.position = 1;
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

    function openCreateStage(wf) {
        selectedWorkflowId = wf.id;
        const nextPos = (wf.stages?.length ?? 0) + 1;
        stageForm.name = '';
        stageForm.color = '#38bdf8';
        stageForm.position = nextPos;
        stageForm.stage_category = 'in_progress';
        stageForm.wip_limit = '';
        editingStage = null;
        showCreateStageModal = true;
    }

    function openEditStage(stage, wf) {
        selectedWorkflowId = wf.id;
        editingStage = stage;
        stageForm.name = stage.name;
        stageForm.color = stage.color;
        stageForm.position = stage.position;
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

    function getCategoryBadge(category) {
        switch (category) {
            case 'pending':
                return { label: 'Pendiente', color: 'bg-amber-500/20 text-amber-300 border-amber-500/30' };
            case 'in_progress':
                return { label: 'En Proceso', color: 'bg-sky-500/20 text-sky-300 border-sky-500/30' };
            case 'blocked':
                return { label: 'Bloqueado', color: 'bg-rose-500/20 text-rose-300 border-rose-500/30' };
            case 'done':
                return { label: 'Terminado', color: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' };
            default:
                return { label: category, color: 'bg-slate-500/20 text-slate-300 border-slate-500/30' };
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
                    Configura los flujos de producción del taller y define las columnas de los tableros Kanban con sus categorías de estado obligatorias.
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
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-bold text-white">{wf.name}</h2>
                                {#if wf.is_default}
                                    <span class="px-2 py-0.5 rounded-full text-[10px] uppercase font-bold tracking-wider bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                        Flujo Predeterminado
                                    </span>
                                {/if}
                            </div>
                            <p class="text-xs text-slate-400 mt-0.5">{wf.description ?? 'Sin descripción'}</p>
                            <span class="text-[11px] text-slate-500 block mt-1">
                                {wf.products_count ?? 0} producto(s) asignados a este flujo
                            </span>
                        </div>

                        <button
                            type="button"
                            onclick={() => openCreateStage(wf)}
                            class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-semibold flex items-center gap-1.5 shrink-0 self-start sm:self-auto transition-colors"
                        >
                            <span>+</span>
                            <span>Agregar Etapa</span>
                        </button>
                    </div>

                    <!-- Columnas / Etapas Dinámicas del Flujo -->
                    <div class="mt-4">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-3">
                            Etapas en el Tablero Kanban ({wf.stages?.length ?? 0})
                        </span>

                        {#if !wf.stages || wf.stages.length === 0}
                            <div class="p-6 text-center border-2 border-dashed border-slate-800 rounded-xl text-xs text-slate-500">
                                No hay etapas definidas para este flujo. Agrega una etapa para empezar a operar.
                            </div>
                        {:else}
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
                                {#each wf.stages as stage (stage.id)}
                                    {@const badge = getCategoryBadge(stage.stage_category)}
                                    <div class="bg-slate-950/70 border border-slate-800 rounded-xl p-3.5 flex flex-col justify-between group hover:border-slate-700 transition-colors">
                                        <div>
                                            <div class="flex items-center justify-between text-xs mb-2">
                                                <span class="font-mono text-slate-500 text-[10px]">Posición #{stage.position}</span>
                                                <div class="w-3.5 h-3.5 rounded-full border border-black/30" style="background-color: {stage.color}"></div>
                                            </div>
                                            <h4 class="font-bold text-sm text-white truncate" title={stage.name}>{stage.name}</h4>
                                            
                                            <div class="mt-2 flex flex-col gap-1">
                                                <span class="text-[10px] px-2 py-0.5 rounded-md font-bold uppercase tracking-wider border text-center {badge.color}">
                                                    {badge.label}
                                                </span>
                                                {#if stage.wip_limit}
                                                    <span class="text-[10px] text-slate-400 font-mono text-center">
                                                        Límite WIP: {stage.wip_limit}
                                                    </span>
                                                {/if}
                                            </div>
                                        </div>

                                        <!-- Acciones de Etapa -->
                                        <div class="mt-3 pt-2 border-t border-slate-800/80 flex items-center justify-end gap-2 text-[11px]">
                                            <button
                                                type="button"
                                                onclick={() => openEditStage(stage, wf)}
                                                class="text-slate-400 hover:text-emerald-400 font-semibold"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => deleteStage(stage)}
                                                class="text-slate-500 hover:text-rose-400 font-semibold"
                                            >
                                                Eliminar
                                            </button>
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

<!-- MODAL CREAR / EDITAR ETAPA -->
{#if showCreateStageModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">
                    {editingStage ? `Editar Etapa: ${editingStage.name}` : `Nueva Etapa en: ${selectedWorkflow?.name}`}
                </h3>
                <button type="button" onclick={() => showCreateStageModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitStage} class="space-y-4 text-xs">
                <div>
                    <label for="stage-name" class="block font-semibold text-slate-300 mb-1">Nombre de la Columna / Etapa *</label>
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

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="stage-pos" class="block font-semibold text-slate-300 mb-1">Posición en Kanban *</label>
                        <input
                            id="stage-pos"
                            type="number"
                            min="1"
                            bind:value={stageForm.position}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                        />
                    </div>
                    <div>
                        <label for="stage-color" class="block font-semibold text-slate-300 mb-1">Color Identificador</label>
                        <div class="flex items-center gap-2">
                            <input
                                id="stage-color"
                                type="color"
                                bind:value={stageForm.color}
                                class="w-8 h-8 rounded-lg cursor-pointer bg-transparent border-0"
                            />
                            <input
                                type="text"
                                bind:value={stageForm.color}
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-2 py-1.5 text-white text-xs font-mono"
                            />
                        </div>
                    </div>
                </div>

                <div>
                    <label for="stage-cat" class="block font-semibold text-slate-300 mb-1">Categoría Interna de Estado *</label>
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
                        {editingStage ? 'Actualizar Etapa' : 'Guardar Etapa'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
