<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let { workflows = [], selectedWorkflowId = null, orders = [] } = $props();

    // Estado reactivo local en Svelte 5
    let viewMode = $state('swimlanes'); // 'swimlanes' | 'columns'
    let draggedTaskId = $state(null);
    let dragOverStageId = $state(null);
    let isMoving = $state(false);

    // Copia local de pedidos para optimismo reactivo
    let localOrders = $state([]);

    $effect(() => {
        localOrders = JSON.parse(JSON.stringify(orders));
    });

    let currentWorkflow = $derived(
        workflows.find((w) => w.id === selectedWorkflowId) ?? workflows[0],
    );
    let stages = $derived(currentWorkflow?.stages ?? []);

    // Estado derivado: todas las tareas agrupadas para la vista de columnas
    let allTasks = $derived(
        localOrders.flatMap((order) =>
            (order.tasks ?? []).map((task) => ({
                ...task,
                order_number: order.order_number,
                customer_name: order.customer?.name ?? 'Cliente',
                order_status: order.derived_status,
            })),
        ),
    );

    function selectWorkflow(wfId) {
        router.get(
            '/kanban',
            { workflow_id: wfId },
            { preserveState: true, preserveScroll: true },
        );
    }

    function handleDragStart(e, taskId) {
        draggedTaskId = taskId;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(taskId));
    }

    function handleDragOver(e, stageId) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        dragOverStageId = stageId;
    }

    function handleDragLeave(e) {
        dragOverStageId = null;
    }

    function handleDrop(e, targetStageId) {
        e.preventDefault();
        dragOverStageId = null;
        if (!draggedTaskId) return;

        moveTask(draggedTaskId, targetStageId);
        draggedTaskId = null;
    }

    function moveTask(taskId, targetStageId) {
        const targetStage = stages.find((s) => s.id === targetStageId);
        if (!targetStage) return;

        // Actualización optimista del estado local en Svelte 5
        let found = false;
        for (const order of localOrders) {
            for (const task of order.tasks ?? []) {
                if (task.id === taskId) {
                    task.current_stage_id = targetStageId;
                    task.current_stage = targetStage;
                    if (targetStage.stage_category === 'in_progress') {
                        task.materials_consumed = true;
                    }
                    found = true;
                    break;
                }
            }
            if (found) break;
        }

        isMoving = true;
        router.patch(
            `/kanban/tasks/${taskId}/move`,
            { target_stage_id: targetStageId, column_position: 0 },
            {
                preserveScroll: true,
                onFinish: () => {
                    isMoving = false;
                },
            },
        );
    }

    function getStatusBadge(status) {
        switch (status) {
            case 'pending':
                return {
                    label: 'En Espera',
                    classes:
                        'bg-amber-500/15 text-amber-300 border-amber-500/30',
                };
            case 'in_progress':
                return {
                    label: 'En Producción',
                    classes: 'bg-sky-500/15 text-sky-300 border-sky-500/30',
                };
            case 'blocked':
                return {
                    label: 'Bloqueado',
                    classes: 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                };
            case 'ready':
                return {
                    label: 'Listo para Entrega',
                    classes:
                        'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                };
            case 'delivered':
                return {
                    label: 'Entregado',
                    classes:
                        'bg-slate-500/15 text-slate-300 border-slate-500/30',
                };
            default:
                return {
                    label: status,
                    classes:
                        'bg-slate-500/15 text-slate-300 border-slate-500/30',
                };
        }
    }

    function getCategoryColor(category) {
        switch (category) {
            case 'pending':
                return 'bg-amber-400';
            case 'in_progress':
                return 'bg-sky-400';
            case 'blocked':
                return 'bg-rose-400';
            case 'done':
                return 'bg-emerald-400';
            default:
                return 'bg-slate-400';
        }
    }

    function getNextStage(currentStageId) {
        const idx = stages.findIndex((s) => s.id === currentStageId);
        if (idx !== -1 && idx < stages.length - 1) {
            return stages[idx + 1];
        }
        return null;
    }

    function getPrevStage(currentStageId) {
        const idx = stages.findIndex((s) => s.id === currentStageId);
        if (idx > 0) {
            return stages[idx - 1];
        }
        return null;
    }
</script>

<AppLayout title="Tablero Kanban">
    <div class="space-y-6">
        <!-- ENCABEZADO Y SELECTOR DE FLUJOS (WORKFLOWS) -->
        <div
            class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-slate-900/90 border border-slate-800 p-5 rounded-2xl backdrop-blur-sm"
        >
            <div>
                <div class="flex items-center gap-2">
                    <span
                        class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30"
                    >
                        Módulo 5
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">
                        Tablero de Producción Kanban
                    </h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Gestión visual Make-to-Order: Los pedidos actúan como
                    carriles agrupadores (<strong>US</strong>) y los productos
                    solicitados como tarjetas operativas (<strong
                        >Tareas</strong
                    >).
                </p>
            </div>

            <!-- Selector de Flujos / Pestañas de Workflows -->
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold text-slate-400 mr-1"
                    >Flujo:</span
                >
                {#each workflows as wf}
                    <button
                        type="button"
                        onclick={() => selectWorkflow(wf.id)}
                        class="px-3.5 py-1.5 rounded-xl text-xs font-semibold transition-all {wf.id ===
                        currentWorkflow?.id
                            ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/30'
                            : 'bg-slate-800 text-slate-300 hover:bg-slate-700 hover:text-white border border-slate-700/80'}"
                    >
                        {wf.name}
                        {#if wf.is_default}
                            <span
                                class="ml-1 text-[10px] px-1 py-0.2 bg-black/20 rounded font-normal"
                                >Defecto</span
                            >
                        {/if}
                    </button>
                {/each}
            </div>
        </div>

        <!-- BARRA DE CONTROL Y SELECTOR DE VISTA -->
        <div
            class="flex flex-wrap items-center justify-between gap-3 text-xs border-b border-slate-800/80 pb-3"
        >
            <div class="flex items-center gap-2">
                <span class="text-slate-400">Modo de Visualización:</span>
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-1 flex items-center gap-1">
                    <button
                        type="button"
                        onclick={() => (viewMode = 'swimlanes')}
                        class="px-3 py-1 rounded-lg font-medium transition-all {viewMode ===
                        'swimlanes'
                            ? 'bg-emerald-600 text-white shadow-sm'
                            : 'text-slate-400 hover:text-white'}"
                    >
                        Carriles por Pedido (US)
                    </button>
                    <button
                        type="button"
                        onclick={() => (viewMode = 'columns')}
                        class="px-3 py-1 rounded-lg font-medium transition-all {viewMode ===
                        'columns'
                            ? 'bg-emerald-600 text-white shadow-sm'
                            : 'text-slate-400 hover:text-white'}"
                    >
                        Vista Global de Columnas
                    </button>
                </div>
            </div>

            <!-- Leyenda de Estados Dinámicos -->
            <div class="hidden sm:flex items-center gap-4 text-[11px] text-slate-400">
                <span class="flex items-center gap-1.5"
                    ><span class="w-2 h-2 rounded-full bg-amber-400"></span> Pendiente</span
                >
                <span class="flex items-center gap-1.5"
                    ><span class="w-2 h-2 rounded-full bg-sky-400"></span> En Proceso</span
                >
                <span class="flex items-center gap-1.5"
                    ><span class="w-2 h-2 rounded-full bg-rose-400"></span> Bloqueado</span
                >
                <span class="flex items-center gap-1.5"
                    ><span class="w-2 h-2 rounded-full bg-emerald-400"></span> Terminado</span
                >
            </div>
        </div>

        <!-- MODO 1: CARRILES HORIZONTALES (SWIMLANES POR PEDIDO "US") -->
        {#if viewMode === 'swimlanes'}
            {#if localOrders.length === 0}
                <div
                    class="p-12 text-center bg-slate-900/50 border border-slate-800 rounded-2xl"
                >
                    <div
                        class="w-12 h-12 mx-auto rounded-full bg-slate-800 flex items-center justify-center text-slate-400 text-xl mb-3"
                    >
                        📦
                    </div>
                    <h3 class="text-base font-semibold text-white">
                        No hay pedidos activos para este flujo
                    </h3>
                    <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                        Crea un nuevo pedido desde el módulo de Pedidos o cambia
                        al flujo predeterminado.
                    </p>
                </div>
            {:else}
                <div class="space-y-6">
                    {#each localOrders as order (order.id)}
                        {@const badge = getStatusBadge(order.derived_status)}
                        <div
                            class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-lg shadow-black/20"
                        >
                            <!-- ENCABEZADO DEL CARRIL (US / PEDIDO) -->
                            <div
                                class="bg-slate-900 px-5 py-3.5 border-b border-slate-800 flex flex-wrap items-center justify-between gap-3"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center font-bold text-xs"
                                    >
                                        #{order.order_number}
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="font-bold text-sm text-white"
                                            >
                                                {order.customer?.name ??
                                                    'Cliente'}
                                            </span>
                                            <span
                                                class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider border {badge.classes}"
                                            >
                                                {badge.label}
                                            </span>
                                        </div>
                                        <div
                                            class="text-[11px] text-slate-400 flex items-center gap-3 mt-0.5"
                                        >
                                            <span
                                                >Total Pedido: <strong
                                                    class="text-slate-200"
                                                    >${order.total_amount}</strong
                                                ></span
                                            >
                                            <span>•</span>
                                            <span
                                                >Duración con holgura: <strong
                                                    class="text-amber-300"
                                                    >{order.formatted_time}</strong
                                                ></span
                                            >
                                        </div>
                                    </div>
                                </div>

                                <!-- Predicción de Entrega del Pedido -->
                                <div
                                    class="text-right bg-slate-950/60 px-3 py-1.5 rounded-xl border border-slate-800/80 text-xs"
                                >
                                    <span class="text-[10px] text-slate-400 uppercase font-semibold block"
                                        >Entrega Proyectada:</span
                                    >
                                    <span class="font-mono text-emerald-400 font-bold">
                                        {order.predicted_delivery_at
                                            ? new Date(
                                                  order.predicted_delivery_at,
                                              ).toLocaleDateString('es-CO', {
                                                  weekday: 'short',
                                                  day: '2-digit',
                                                  month: 'short',
                                                  hour: '2-digit',
                                                  minute: '2-digit',
                                              })
                                            : 'Calculando...'}
                                    </span>
                                </div>
                            </div>

                            <!-- COLUMNAS KANBAN DENTRO DEL CARRIL -->
                            <div
                                class="grid grid-flow-col auto-cols-[minmax(250px,1fr)] overflow-x-auto p-4 gap-3.5 bg-slate-950/40 min-h-[170px]"
                            >
                                {#each stages as stage (stage.id)}
                                    {@const stageTasks = (
                                        order.tasks ?? []
                                    ).filter(
                                        (t) => t.current_stage_id === stage.id,
                                    )}
                                    {@const isDropTarget =
                                        dragOverStageId === stage.id}
                                    <div
                                        class="bg-slate-900/60 rounded-xl border border-slate-800/80 p-2.5 flex flex-col transition-all {isDropTarget
                                            ? 'ring-2 ring-emerald-500 bg-emerald-950/20 border-emerald-500/50'
                                            : ''}"
                                        ondragover={(e) =>
                                            handleDragOver(e, stage.id)}
                                        ondragleave={handleDragLeave}
                                        ondrop={(e) => handleDrop(e, stage.id)}
                                        role="region"
                                        aria-label={stage.name}
                                    >
                                        <!-- Título de la etapa -->
                                        <div
                                            class="flex items-center justify-between pb-2 mb-2 border-b border-slate-800/60 text-xs"
                                        >
                                            <div
                                                class="flex items-center gap-1.5 min-w-0"
                                            >
                                                <span
                                                    class="w-2 h-2 rounded-full shrink-0 {getCategoryColor(
                                                        stage.stage_category,
                                                    )}"
                                                ></span>
                                                <span
                                                    class="font-semibold text-slate-300 truncate text-[11px]"
                                                >
                                                    {stage.name}
                                                </span>
                                            </div>
                                            <span
                                                class="text-[10px] px-1.5 py-0.2 rounded-md bg-slate-800 text-slate-400 font-mono"
                                            >
                                                {stageTasks.length}
                                            </span>
                                        </div>

                                        <!-- Tareas dentro de la columna -->
                                        <div class="space-y-2.5 flex-1 min-h-[90px]">
                                            {#each stageTasks as task (task.id)}
                                                {@render taskCard(task, stage)}
                                            {:else}
                                                <div
                                                    class="h-full flex items-center justify-center border border-dashed border-slate-800/60 rounded-lg p-3 text-[11px] text-slate-600 text-center"
                                                >
                                                    Soltar aquí
                                                </div>
                                            {/each}
                                        </div>
                                    </div>
                                {/each}
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}

            <!-- MODO 2: VISTA GLOBAL DE COLUMNAS -->
        {:else}
            <div
                class="grid grid-flow-col auto-cols-[minmax(280px,1fr)] overflow-x-auto gap-4 pb-4"
            >
                {#each stages as stage (stage.id)}
                    {@const stageTasks = allTasks.filter(
                        (t) => t.current_stage_id === stage.id,
                    )}
                    {@const isDropTarget = dragOverStageId === stage.id}
                    <div
                        class="bg-slate-900 border border-slate-800 rounded-2xl p-3 flex flex-col min-h-[550px] shadow-sm transition-all {isDropTarget
                            ? 'ring-2 ring-emerald-500 bg-emerald-950/20'
                            : ''}"
                        ondragover={(e) => handleDragOver(e, stage.id)}
                        ondragleave={handleDragLeave}
                        ondrop={(e) => handleDrop(e, stage.id)}
                        role="region"
                        aria-label={stage.name}
                    >
                        <!-- Cabecera de Columna -->
                        <div
                            class="flex items-center justify-between pb-3 mb-3 border-b border-slate-800"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                <span
                                    class="w-2.5 h-2.5 rounded-full {getCategoryColor(
                                        stage.stage_category,
                                    )}"
                                ></span>
                                <span
                                    class="font-bold text-sm text-slate-200 truncate"
                                >
                                    {stage.name}
                                </span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                {#if stage.wip_limit}
                                    <span
                                        class="text-[10px] text-slate-500 font-mono"
                                        >WIP: {stage.wip_limit}</span
                                    >
                                {/if}
                                <span
                                    class="px-2 py-0.5 rounded-full bg-slate-800 text-emerald-400 font-bold text-xs font-mono"
                                >
                                    {stageTasks.length}
                                </span>
                            </div>
                        </div>

                        <!-- Lista de tarjetas de tareas -->
                        <div class="space-y-3 flex-1 overflow-y-auto">
                            {#each stageTasks as task (task.id)}
                                {@render taskCard(task, stage, true)}
                            {:else}
                                <div
                                    class="h-32 flex items-center justify-center border-2 border-dashed border-slate-800/80 rounded-xl text-xs text-slate-600 font-medium"
                                >
                                    Sin tareas
                                </div>
                            {/each}
                        </div>
                    </div>
                {/each}
            </div>
        {/if}
    </div>
</AppLayout>

<!-- SNIPPET DE TARJETA DE TAREA KANBAN (SVELTE 5) -->
{#snippet taskCard(task, currentStage, showOrderHeader = false)}
    {@const prevStage = getPrevStage(currentStage.id)}
    {@const nextStage = getNextStage(currentStage.id)}

    <div
        role="group"
        aria-label={task.item_name_snapshot}
        draggable="true"
        ondragstart={(e) => handleDragStart(e, task.id)}
        class="bg-slate-800/90 hover:bg-slate-800 border border-slate-700/80 hover:border-slate-600 rounded-xl p-3 shadow-md transition-all cursor-grab active:cursor-grabbing group hover:shadow-emerald-500/5 select-none"
    >
        <!-- Badge opcional de Pedido si estamos en vista de columnas -->
        {#if showOrderHeader}
            <div
                class="flex items-center justify-between text-[11px] mb-2 pb-1.5 border-b border-slate-700/60"
            >
                <span class="font-bold text-emerald-400 font-mono"
                    >Pedido #{task.order_number}</span
                >
                <span class="text-slate-400 truncate max-w-[120px]"
                    >{task.customer_name}</span
                >
            </div>
        {/if}

        <!-- Nombre del Producto / Snapshot -->
        <h4
            class="text-xs font-bold text-white tracking-tight line-clamp-2 leading-snug group-hover:text-emerald-300 transition-colors"
        >
            {task.item_name_snapshot}
        </h4>

        <!-- Metadatos de la Tarea: Cantidad, Tiempo estimado y Precio -->
        <div
            class="mt-2 flex items-center justify-between text-[11px] text-slate-400"
        >
            <span class="bg-slate-900/80 px-2 py-0.5 rounded font-mono text-slate-300">
                Cant: <strong class="text-white">{Number(task.quantity)}</strong>
            </span>
            <span class="font-mono text-amber-300 font-medium">
                ⏱ {task.formatted_time}
            </span>
        </div>

        <!-- Indicador de Consumo de Materia Prima -->
        <div class="mt-2.5 pt-2 border-t border-slate-700/50 flex items-center justify-between text-[10px]">
            {#if task.materials_consumed}
                <span
                    class="flex items-center gap-1 text-emerald-400 font-medium"
                >
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                    Consumo registrado
                </span>
            {:else}
                <span class="flex items-center gap-1 text-amber-400 font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                    Material reservado
                </span>
            {/if}

            <!-- Botones Rápidos de Avance (Para Móviles y Clics Rápidos) -->
            <div class="flex items-center gap-1">
                {#if prevStage}
                    <button
                        type="button"
                        onclick={() => moveTask(task.id, prevStage.id)}
                        class="w-5 h-5 rounded bg-slate-700/80 hover:bg-slate-600 text-slate-300 hover:text-white flex items-center justify-center font-bold text-[11px] transition-colors"
                        title={`Mover a ${prevStage.name}`}
                    >
                        ←
                    </button>
                {/if}
                {#if nextStage}
                    <button
                        type="button"
                        onclick={() => moveTask(task.id, nextStage.id)}
                        class="w-5 h-5 rounded bg-emerald-600/80 hover:bg-emerald-500 text-white flex items-center justify-center font-bold text-[11px] transition-colors"
                        title={`Mover a ${nextStage.name}`}
                    >
                        →
                    </button>
                {/if}
            </div>
        </div>
    </div>
{/snippet}
