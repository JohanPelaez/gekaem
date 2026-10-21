<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import { csrfFetch } from '../../Utils/csrfFetch';

    let {
        workflows = [],
        orders = [],
        customers = [],
        products = [],
        productTypes = [],
        selectedOrderId = null,
    } = $props();

    // Copia local de pedidos para optimismo reactivo
    let localOrders = $state([]);

    $effect(() => {
        localOrders = JSON.parse(JSON.stringify(orders));
    });

    // ID del pedido actualmente abierto en vista aislada
    let activeOrderId = $state(null);

    $effect(() => {
        if (selectedOrderId) {
            activeOrderId = Number(selectedOrderId);
        }
    });

    // Vista general: 'kanban' (Macro Pedidos) | 'table' (Tabla de Tareas)
    let generalViewMode = $state('kanban');

    // Filtros progresivos reactivos
    let filters = $state({
        customerId: '',
        status: 'all', // 'all' | 'pending' | 'in_progress' | 'blocked' | 'ready'
        dateRange: 'all', // 'all' | 'late' | 'today' | 'week'
        workflowId: '',
        productId: '',
        productTypeId: '',
        search: '',
    });

    // Estado Drag & Drop para Pedidos Macro y Tareas de Flujo
    let draggedOrderId = $state(null);
    let dragOverMacroStatus = $state(null);
    let draggedTaskId = $state(null);
    let dragOverStageId = $state(null);
    let isMoving = $state(false);

    // --- FUNCIONES HELPER SEGURAS (SOPORTAN snake_case Y camelCase) ---
    function getOrderTasks(order) {
        return order?.production_tasks ?? order?.productionTasks ?? [];
    }

    function getTaskVariant(task) {
        return task?.product_variant ?? task?.productVariant ?? null;
    }

    function getTaskProduct(task) {
        return getTaskVariant(task)?.product ?? task?.product ?? null;
    }

    function getTaskStage(task) {
        return task?.current_stage ?? task?.currentStage ?? null;
    }

    function getTaskWorkflowId(task) {
        return Number(task?.workflow_id ?? task?.workflow?.id ?? getTaskProduct(task)?.workflow_id ?? 0);
    }

    // Pedido actualmente seleccionado en el Nivel 2
    let activeOrder = $derived(
        activeOrderId ? localOrders.find((o) => o.id === activeOrderId) ?? null : null,
    );

    // Flujos únicos presentes en las tareas del pedido activo
    let orderWorkflows = $derived.by(() => {
        if (!activeOrder) return [];
        const tasks = getOrderTasks(activeOrder);
        const map = new Map();

        for (const task of tasks) {
            const wfId = getTaskWorkflowId(task);
            const fullWf = workflows.find((w) => Number(w.id) === wfId) ?? task.workflow;
            if (fullWf && fullWf.id) {
                const id = Number(fullWf.id);
                if (!map.has(id)) {
                    map.set(id, {
                        ...fullWf,
                        stages: fullWf.stages ?? fullWf.workflow_stages ?? [],
                        tasksCount: 0,
                    });
                }
                map.get(id).tasksCount++;
            }
        }

        // Si ninguna tarea mapeó a un flujo pero hay flujos disponibles en el tenant
        if (map.size === 0 && workflows.length > 0) {
            const fallbackWf = workflows.find((w) => w.is_default) ?? workflows[0];
            map.set(Number(fallbackWf.id), {
                ...fallbackWf,
                stages: fallbackWf.stages ?? fallbackWf.workflow_stages ?? [],
                tasksCount: tasks.length,
            });
        }

        return Array.from(map.values());
    });

    // ID del flujo seleccionado dentro del pedido
    let selectedOrderWorkflowId = $state(null);

    // Flujo activo en el pedido con fallback automático al primero
    let activeOrderWorkflow = $derived.by(() => {
        if (orderWorkflows.length === 0) return null;
        if (selectedOrderWorkflowId) {
            const found = orderWorkflows.find((w) => Number(w.id) === Number(selectedOrderWorkflowId));
            if (found) return found;
        }
        return orderWorkflows[0];
    });

    let activeOrderStages = $derived(
        activeOrderWorkflow?.stages ?? [],
    );

    // Tareas del pedido activo en el flujo seleccionado
    let activeOrderTasks = $derived.by(() => {
        if (!activeOrder) return [];
        const tasks = getOrderTasks(activeOrder);
        if (!activeOrderWorkflow) return tasks;
        return tasks.filter((t) => {
            const wfId = getTaskWorkflowId(t);
            return wfId === Number(activeOrderWorkflow.id) || (wfId === 0 && orderWorkflows.length === 1);
        });
    });

    // Comprobador de si una tarea individual coincide con los filtros específicos de producto
    function taskMatchesSpecificFilters(task) {
        if (filters.workflowId) {
            const wfId = getTaskWorkflowId(task);
            if (String(wfId) !== String(filters.workflowId)) {
                return false;
            }
        }
        if (filters.productTypeId) {
            const prod = getTaskProduct(task);
            if (!prod || String(prod.product_type_id) !== String(filters.productTypeId)) {
                return false;
            }
        }
        if (filters.productId) {
            const prod = getTaskProduct(task);
            if (!prod || String(prod.id) !== String(filters.productId)) {
                return false;
            }
        }
        return true;
    }

    // Filtrado de pedidos
    let filteredOrders = $derived.by(() => {
        const now = new Date();
        now.setHours(0, 0, 0, 0);

        const endOfWeek = new Date(now);
        endOfWeek.setDate(now.getDate() + (7 - now.getDay()));

        return localOrders.filter((order) => {
            const tasks = getOrderTasks(order);

            // Buscador general (número o cliente)
            if (filters.search.trim()) {
                const q = filters.search.toLowerCase().trim();
                const numStr = String(order.order_number);
                const custName = (order.customer?.name ?? '').toLowerCase();
                if (!numStr.includes(q) && !custName.includes(q)) {
                    return false;
                }
            }

            // Filtro por cliente
            if (filters.customerId && String(order.customer_id) !== String(filters.customerId)) {
                return false;
            }

            // Filtro por estado macro del pedido
            if (filters.status !== 'all' && order.derived_status !== filters.status) {
                return false;
            }

            // Filtro por fecha de entrega comprometida
            if (filters.dateRange !== 'all' && order.committed_delivery_at) {
                const deliveryDate = new Date(order.committed_delivery_at);
                deliveryDate.setHours(0, 0, 0, 0);

                if (filters.dateRange === 'late') {
                    if (deliveryDate >= now || order.derived_status === 'ready') return false;
                } else if (filters.dateRange === 'today') {
                    if (deliveryDate.getTime() !== now.getTime()) return false;
                } else if (filters.dateRange === 'week') {
                    if (deliveryDate < now || deliveryDate > endOfWeek) return false;
                }
            }

            // En Vista Tabla de Producción:
            // Si hay filtros específicos (flujo, tipo o producto), el pedido se conserva si AL MENOS UN producto cumple.
            // NUNCA se oculta el pedido completo si solo un producto del pedido coincide.
            if (generalViewMode === 'table') {
                const hasSpecificFilters = Boolean(
                    filters.workflowId || filters.productTypeId || filters.productId
                );
                if (hasSpecificFilters) {
                    const hasMatchingTask = tasks.some(taskMatchesSpecificFilters);
                    if (!hasMatchingTask) return false;
                }
            }

            return true;
        });
    });

    // Columnas macro fijas para pedidos
    const MACRO_COLUMNS = [
        { key: 'pending', title: 'Pendientes', color: '#f59e0b', desc: 'Sin iniciar o en cola WIP' },
        { key: 'in_progress', title: 'En Fabricación', color: '#38bdf8', desc: 'Con tareas operativas en curso' },
        { key: 'blocked', title: 'Bloqueados', color: '#ef4444', desc: 'En pausa o con impedimentos' },
        { key: 'ready', title: 'Listos para Entrega', color: '#10b981', desc: 'Todas las tareas terminadas' },
    ];

    // Pedidos agrupados por columna macro
    let ordersByMacroColumn = $derived.by(() => {
        const groups = {
            pending: [],
            in_progress: [],
            blocked: [],
            ready: [],
        };
        for (const order of filteredOrders) {
            const status = order.derived_status;
            if (groups[status]) {
                groups[status].push(order);
            } else {
                groups.pending.push(order);
            }
        }
        return groups;
    });

    // Tareas individuales para la vista de tabla con filtrado directo sobre productos
    let filteredTableTasks = $derived.by(() => {
        const list = [];
        for (const order of filteredOrders) {
            const tasks = getOrderTasks(order);
            for (const task of tasks) {
                // Filtrado directo sobre cada producto/tarea individual
                if (!taskMatchesSpecificFilters(task)) {
                    continue;
                }

                const stage = getTaskStage(task);
                const wfId = getTaskWorkflowId(task);
                const wf = workflows.find((w) => Number(w.id) === wfId) ?? task.workflow;
                list.push({
                    ...task,
                    order_id: order.id,
                    order_number: order.order_number,
                    customer_name: order.customer?.name ?? 'Cliente',
                    order_status: order.derived_status,
                    committed_delivery_at: order.committed_delivery_at,
                    current_stage: stage,
                    workflow: wf,
                });
            }
        }
        return list;
    });

    function setGeneralViewMode(mode) {
        generalViewMode = mode;
        if (mode === 'kanban') {
            // Al volver al tablero visual, limpiamos los filtros específicos de producto
            filters.workflowId = '';
            filters.productTypeId = '';
            filters.productId = '';
        }
    }

    function resetFilters() {
        filters.search = '';
        filters.customerId = '';
        filters.status = 'all';
        filters.dateRange = 'all';
        filters.workflowId = '';
        filters.productId = '';
        filters.productTypeId = '';
    }

    let hasActiveFilters = $derived.by(() => {
        const generalActive = Boolean(
            filters.search.trim() ||
            filters.customerId ||
            filters.status !== 'all' ||
            filters.dateRange !== 'all'
        );
        if (generalViewMode === 'table') {
            return generalActive || Boolean(filters.workflowId || filters.productId || filters.productTypeId);
        }
        return generalActive;
    });

    function openOrderBoard(orderId) {
        activeOrderId = orderId;
        selectedOrderWorkflowId = null;
        const url = new URL(window.location.href);
        url.searchParams.set('order_id', orderId);
        window.history.pushState({}, '', url);
    }

    function closeOrderBoard() {
        activeOrderId = null;
        selectedOrderWorkflowId = null;
        const url = new URL(window.location.href);
        url.searchParams.delete('order_id');
        window.history.pushState({}, '', url);
    }

    // --- ACCIONES MACRO PARA PEDIDOS ---
    function handleMacroDragStart(e, orderId) {
        draggedOrderId = orderId;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(orderId));
    }

    function handleMacroDragOver(e, statusKey) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        dragOverMacroStatus = statusKey;
    }

    function handleMacroDrop(e, targetStatus) {
        e.preventDefault();
        dragOverMacroStatus = null;
        if (!draggedOrderId) return;

        updateOrderMacroStatus(draggedOrderId, targetStatus);
        draggedOrderId = null;
    }

    function updateOrderMacroStatus(orderId, newStatus) {
        const order = localOrders.find((o) => o.id === orderId);
        if (!order || order.derived_status === newStatus) return;

        // Actualización optimista
        order.derived_status = newStatus;

        isMoving = true;
        router.patch(
            `/kanban/orders/${orderId}/macro-status`,
            { status: newStatus },
            {
                preserveScroll: true,
                onFinish: () => { isMoving = false; },
            },
        );
    }

    // --- ACCIONES DE MOVIMIENTO DE TAREAS DENTRO DE UN FLUJO ---
    function handleTaskDragStart(e, taskId) {
        draggedTaskId = taskId;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(taskId));
    }

    function handleTaskDragOver(e, stageId) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        dragOverStageId = stageId;
    }

    function handleTaskDrop(e, targetStageId) {
        e.preventDefault();
        dragOverStageId = null;
        if (!draggedTaskId) return;

        moveTask(draggedTaskId, targetStageId);
        draggedTaskId = null;
    }

    function moveTask(taskId, targetStageId) {
        const targetStage = activeOrderStages.find((s) => Number(s.id) === Number(targetStageId));
        if (!targetStage) return;

        // Optimismo reactivo en el pedido activo
        if (activeOrder) {
            const tasks = getOrderTasks(activeOrder);
            for (const task of tasks) {
                if (task.id === taskId) {
                    task.current_stage_id = targetStageId;
                    task.current_stage = targetStage;
                    task.currentStage = targetStage;
                    if (targetStage.stage_category === 'in_progress') {
                        task.materials_consumed = true;
                    }
                    break;
                }
            }
        }

        isMoving = true;
        router.patch(
            `/kanban/tasks/${taskId}/move`,
            { target_stage_id: targetStageId, column_position: 0 },
            {
                preserveScroll: true,
                onFinish: () => { isMoving = false; },
            },
        );
    }

    // ID de la tarea que se encuentra actualizando su etapa en la tabla de producción
    let updatingTaskId = $state(null);

    // Obtener las etapas disponibles para el flujo de una tarea
    function getTaskWorkflowStages(task) {
        const wfId = getTaskWorkflowId(task);
        const wf = workflows.find((w) => Number(w.id) === wfId) ?? task.workflow;
        return wf?.stages ?? wf?.workflow_stages ?? [];
    }

    // Modificación directa e inmediata de etapa en la tabla de producción
    async function handleTableTaskStageChange(task, newStageId) {
        const stageId = Number(newStageId);
        if (!stageId || stageId === Number(task.current_stage_id ?? task.current_stage?.id)) {
            return;
        }

        const targetWorkflowStages = getTaskWorkflowStages(task);
        const targetStage = targetWorkflowStages.find((s) => Number(s.id) === stageId);
        if (!targetStage) return;

        const previousStage = task.current_stage;
        const previousStageId = task.current_stage_id;

        // Optimismo reactivo en el estado local
        for (const order of localOrders) {
            if (order.id === task.order_id) {
                const tasks = getOrderTasks(order);
                const foundTask = tasks.find((t) => t.id === task.id);
                if (foundTask) {
                    foundTask.current_stage_id = stageId;
                    foundTask.current_stage = targetStage;
                    foundTask.currentStage = targetStage;
                    if (['in_progress', 'done'].includes(targetStage.stage_category)) {
                        foundTask.materials_consumed = true;
                    }
                }
                break;
            }
        }

        updatingTaskId = task.id;

        try {
            const response = await csrfFetch(`/kanban/tasks/${task.id}/stage`, {
                method: 'PATCH',
                body: JSON.stringify({
                    stage_id: stageId,
                }),
            });

            if (!response.ok) {
                throw new Error('Error al actualizar la etapa de la tarea.');
            }

            const data = await response.json();
            if (data.order) {
                const localOrder = localOrders.find((o) => o.id === task.order_id);
                if (localOrder && data.order.derived_status) {
                    localOrder.derived_status = data.order.derived_status;
                }
            }
        } catch (err) {
            console.error('Error updating task stage:', err);
            // Rollback en caso de error
            for (const order of localOrders) {
                if (order.id === task.order_id) {
                    const tasks = getOrderTasks(order);
                    const foundTask = tasks.find((t) => t.id === task.id);
                    if (foundTask) {
                        foundTask.current_stage_id = previousStageId;
                        foundTask.current_stage = previousStage;
                        foundTask.currentStage = previousStage;
                    }
                    break;
                }
            }
        } finally {
            updatingTaskId = null;
        }
    }

    function formatCurrency(val) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
        }).format(val || 0);
    }

    function formatDate(dateStr) {
        if (!dateStr) return 'Sin fecha';
        const d = new Date(dateStr);
        return d.toLocaleDateString('es-CO', {
            day: '2-digit',
            month: 'short',
        });
    }

    function getDeliveryBadge(dateStr, status) {
        if (!dateStr) return null;
        if (status === 'ready' || status === 'delivered') return null;

        const now = new Date();
        now.setHours(0, 0, 0, 0);

        const d = new Date(dateStr);
        d.setHours(0, 0, 0, 0);

        const diffDays = Math.round((d.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));

        if (diffDays < 0) {
            return { text: `Atrasado (${Math.abs(diffDays)}d)`, color: '#ef4444' };
        }
        if (diffDays === 0) {
            return { text: 'Entrega Hoy', color: '#f59e0b' };
        }
        if (diffDays === 1) {
            return { text: 'Mañana', color: '#38bdf8' };
        }
        return null;
    }
</script>

<AppLayout title="Tablero Kanban de Producción">
    <div class="space-y-5">
        <!-- ========================================== -->
        <!-- MODO 1: TABLERO GENERAL CENTRADO EN PEDIDOS -->
        <!-- ========================================== -->
        {#if !activeOrder}
            <!-- ENCABEZADO Y CONTROLES GENERALES -->
            <div class="bg-slate-900 border border-slate-800 p-5 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 shadow-sm">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-sky-500/20 text-sky-400 border border-sky-500/30">
                            Módulo 5
                        </span>
                        <h1 class="text-2xl font-bold tracking-tight text-white">Tablero de Producción por Pedidos</h1>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">
                        Monitorea el avance integral de cada pedido ("US") y abre su tablero aislado para gestionar sus productos y flujos de trabajo.
                    </p>
                </div>

                <!-- SELECTOR DE VISTA: TABLERO / TABLA -->
                <div class="flex items-center gap-2 shrink-0 self-start md:self-auto">
                    <div class="bg-slate-950 p-1 rounded-xl border border-slate-800 flex items-center">
                        <button
                            type="button"
                            onclick={() => setGeneralViewMode('kanban')}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-colors {generalViewMode === 'kanban' ? 'bg-sky-500 text-white shadow' : 'text-slate-400 hover:text-white'}"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                            </svg>
                            <span>Tablero Macro</span>
                        </button>
                        <button
                            type="button"
                            onclick={() => setGeneralViewMode('table')}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-colors {generalViewMode === 'table' ? 'bg-sky-500 text-white shadow' : 'text-slate-400 hover:text-white'}"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                            </svg>
                            <span>Tabla de Producción</span>
                        </button>
                    </div>

                    <Link
                        href="/orders"
                        class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 transition-colors"
                    >
                        Gestionar Pedidos ➔
                    </Link>
                </div>
            </div>

            <!-- BARRA DE FILTROS PROGRESIVOS (DE LO GENERAL A LO ESPECÍFICO) -->
            <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl space-y-3 shadow-sm">
                <!-- Nivel 1: Filtros Generales -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <!-- Buscador rápido -->
                    <div>
                        <label for="filter-search" class="block text-[11px] font-semibold text-slate-400 mb-1">Buscar Pedido o Cliente</label>
                        <input
                            id="filter-search"
                            type="text"
                            bind:value={filters.search}
                            placeholder="Ej. #104 o 'Camila'..."
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs placeholder:text-slate-600 focus:outline-none focus:border-sky-500"
                        />
                    </div>

                    <!-- Selector de Cliente -->
                    <div>
                        <label for="filter-cust" class="block text-[11px] font-semibold text-slate-400 mb-1">Cliente</label>
                        <select
                            id="filter-cust"
                            bind:value={filters.customerId}
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-sky-500"
                        >
                            <option value="">Todos los Clientes</option>
                            {#each customers as cust (cust.id)}
                                <option value={cust.id}>{cust.name}</option>
                            {/each}
                        </select>
                    </div>

                    <!-- Estado del Pedido -->
                    <div>
                        <label for="filter-status" class="block text-[11px] font-semibold text-slate-400 mb-1">Estado del Pedido</label>
                        <select
                            id="filter-status"
                            bind:value={filters.status}
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-sky-500"
                        >
                            <option value="all">Todos los Estados</option>
                            <option value="pending">Pendientes</option>
                            <option value="in_progress">En Fabricación</option>
                            <option value="blocked">Bloqueados</option>
                            <option value="ready">Listos para Entrega</option>
                        </select>
                    </div>

                    <!-- Fecha de Entrega -->
                    <div>
                        <label for="filter-date" class="block text-[11px] font-semibold text-slate-400 mb-1">Fecha de Entrega</label>
                        <select
                            id="filter-date"
                            bind:value={filters.dateRange}
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-sky-500"
                        >
                            <option value="all">Todas las Fechas</option>
                            <option value="late">⚠️ Atrasados</option>
                            <option value="today">⚡ Entrega Hoy</option>
                            <option value="week">📅 Esta Semana</option>
                        </select>
                    </div>
                </div>

                <!-- Nivel 2: Filtros Específicos (Flujo, Tipo de Producto y Producto - Solo visibles en Tabla de Producción) -->
                {#if generalViewMode === 'table'}
                    <div class="pt-2 border-t border-slate-800/80 flex items-center gap-3 w-full flex-wrap text-xs">
                        <!-- Flujo de Trabajo -->
                        <div class="flex items-center gap-2">
                            <span class="text-slate-400 font-semibold text-[11px] shrink-0">Flujo:</span>
                            <select
                                bind:value={filters.workflowId}
                                class="bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1 text-white text-xs focus:outline-none focus:border-sky-500"
                            >
                                <option value="">Todos los Flujos</option>
                                {#each workflows as wf (wf.id)}
                                    <option value={wf.id}>{wf.name}</option>
                                {/each}
                            </select>
                        </div>

                        <!-- Tipo de Producto -->
                        <div class="flex items-center gap-2">
                            <span class="text-slate-400 font-semibold text-[11px] shrink-0">Tipo:</span>
                            <select
                                bind:value={filters.productTypeId}
                                class="bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1 text-white text-xs focus:outline-none focus:border-sky-500 max-w-[180px] truncate"
                            >
                                <option value="">Todos los Tipos</option>
                                {#each productTypes as pt (pt.id)}
                                    <option value={pt.id}>{pt.name}</option>
                                {/each}
                            </select>
                        </div>

                        <!-- Producto -->
                        <div class="flex items-center gap-2">
                            <span class="text-slate-400 font-semibold text-[11px] shrink-0">Producto:</span>
                            <select
                                bind:value={filters.productId}
                                class="bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1 text-white text-xs focus:outline-none focus:border-sky-500 max-w-[200px] truncate"
                            >
                                <option value="">Todos los Productos</option>
                                {#each products as prod (prod.id)}
                                    <option value={prod.id}>{prod.name}</option>
                                {/each}
                            </select>
                        </div>
                    </div>
                {/if}

                <!-- Barra Inferior de Conteo y Limpieza de Filtros -->
                <div class="pt-2 border-t border-slate-800/80 flex items-center justify-between gap-3 text-xs">
                    <span class="text-slate-500 text-[11px]">
                        {#if generalViewMode === 'table'}
                            {filteredTableTasks.length} producto(s) en {filteredOrders.length} pedido(s)
                        {:else}
                            {filteredOrders.length} pedido(s) encontrado(s)
                        {/if}
                    </span>
                    {#if hasActiveFilters}
                        <button
                            type="button"
                            onclick={resetFilters}
                            class="text-rose-400 hover:text-rose-300 text-[11px] font-semibold underline px-2 py-0.5"
                        >
                            Limpiar Filtros
                        </button>
                    {/if}
                </div>
            </div>

            <!-- VISTA A: TABLERO KANBAN MACRO (4 COLUMNAS DE PEDIDOS) -->
            {#if generalViewMode === 'kanban'}
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 items-start">
                    {#each MACRO_COLUMNS as col (col.key)}
                        {@const colOrders = ordersByMacroColumn[col.key] || []}
                        <div
                            class="bg-slate-900/90 border border-slate-800 rounded-2xl flex flex-col min-h-[580px] shadow-sm transition-colors {dragOverMacroStatus === col.key ? 'ring-2 ring-sky-500 bg-slate-900' : ''}"
                            style="border-top: 4px solid {col.color};"
                            ondragover={(e) => handleMacroDragOver(e, col.key)}
                            ondrop={(e) => handleMacroDrop(e, col.key)}
                            role="region"
                            aria-label={`Columna ${col.title}`}
                        >
                            <!-- Cabecera de la Columna Macro -->
                            <div class="p-4 border-b border-slate-800/80 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="w-2.5 h-2.5 rounded-full" style="background-color: {col.color};"></div>
                                    <h3 class="text-sm font-bold text-white tracking-wide">{col.title}</h3>
                                </div>
                                <span
                                    class="px-2 py-0.5 rounded-full text-xs font-mono font-bold"
                                    style="background-color: {col.color}22; color: {col.color}; border: 1px solid {col.color}44;"
                                >
                                    {colOrders.length}
                                </span>
                            </div>

                            <!-- Lista de Pedidos en esta Columna -->
                            <div class="p-3 space-y-3 flex-1 overflow-y-auto max-h-[780px]">
                                {#if colOrders.length === 0}
                                    <div class="py-12 text-center text-slate-600 text-xs italic">
                                        No hay pedidos en esta etapa.
                                    </div>
                                {:else}
                                    {#each colOrders as order (order.id)}
                                        {@const tasks = getOrderTasks(order)}
                                        {@const completedCount = tasks.filter(t => (getTaskStage(t)?.stage_category ?? t.stage_category) === 'done').length}
                                        {@const progressPercent = tasks.length > 0 ? Math.round((completedCount / tasks.length) * 100) : 0}
                                        {@const delivBadge = getDeliveryBadge(order.committed_delivery_at, order.derived_status)}

                                        <div
                                            class="bg-slate-950 border border-slate-800 hover:border-slate-700 rounded-xl p-3.5 space-y-3 shadow transition-all cursor-grab active:cursor-grabbing group"
                                            draggable="true"
                                            ondragstart={(e) => handleMacroDragStart(e, order.id)}
                                            role="article"
                                        >
                                            <!-- Encabezado de la Tarjeta del Pedido -->
                                            <div class="flex items-start justify-between gap-2">
                                                <div>
                                                    <span class="font-mono text-xs font-black text-sky-400">
                                                        #{order.order_number}
                                                    </span>
                                                    <h4 class="text-sm font-bold text-white group-hover:text-sky-300 transition-colors line-clamp-1">
                                                        {order.customer?.name ?? 'Cliente'}
                                                    </h4>
                                                </div>

                                                <span class="font-mono text-xs font-extrabold text-emerald-400 shrink-0">
                                                    {formatCurrency(order.total_amount)}
                                                </span>
                                            </div>

                                            <!-- Fechas y Alertas de Entrega -->
                                            <div class="flex items-center justify-between text-[11px] text-slate-400">
                                                <div class="flex items-center gap-1">
                                                    <span>📅 Entrega:</span>
                                                    <span class="font-medium text-slate-300">{formatDate(order.committed_delivery_at)}</span>
                                                </div>
                                                {#if delivBadge}
                                                    <span
                                                        class="px-1.5 py-0.5 rounded text-[10px] font-bold"
                                                        style="background-color: {delivBadge.color}22; color: {delivBadge.color}; border: 1px solid {delivBadge.color}44;"
                                                    >
                                                        {delivBadge.text}
                                                    </span>
                                                {/if}
                                            </div>

                                            <!-- Barra de Progreso de Tareas -->
                                            <div class="space-y-1">
                                                <div class="flex justify-between items-center text-[10px] text-slate-400 font-mono">
                                                    <span>Progreso: {completedCount} de {tasks.length} tareas</span>
                                                    <span class="font-bold text-slate-200">{progressPercent}%</span>
                                                </div>
                                                <div class="w-full h-1.5 bg-slate-900 rounded-full overflow-hidden border border-slate-800">
                                                    <div
                                                        class="h-full rounded-full transition-all duration-300"
                                                        style="width: {progressPercent}%; background-color: {col.color};"
                                                    ></div>
                                                </div>
                                            </div>

                                            <!-- Resumen de Productos con sus Etapas Coloreadas (Punto 6) -->
                                            <div class="space-y-1.5 pt-2 border-t border-slate-900">
                                                {#each tasks.slice(0, 3) as task}
                                                    {@const stage = getTaskStage(task)}
                                                    <div class="flex items-center justify-between text-[11px] gap-2">
                                                        <span class="text-slate-300 truncate font-medium flex-1" title={task.item_name_snapshot}>
                                                            {task.quantity > 1 ? `${task.quantity}x ` : ''}{task.item_name_snapshot}
                                                        </span>
                                                        {#if stage}
                                                            <span
                                                                class="px-1.5 py-0.5 rounded text-[9px] font-bold shrink-0 truncate max-w-[110px]"
                                                                style="background-color: {stage.color || '#64748b'}22; color: {stage.color || '#64748b'}; border: 1px solid {stage.color || '#64748b'}55;"
                                                                title={stage.name}
                                                            >
                                                                {stage.name}
                                                            </span>
                                                        {/if}
                                                    </div>
                                                {/each}
                                                {#if tasks.length > 3}
                                                    <span class="text-[10px] text-slate-500 font-medium block">
                                                        +{tasks.length - 3} producto(s) más...
                                                    </span>
                                                {/if}
                                            </div>

                                            <!-- Botón Destacado: Abrir Tablero del Pedido -->
                                            <div class="pt-2">
                                                <button
                                                    type="button"
                                                    onclick={() => openOrderBoard(order.id)}
                                                    class="w-full py-2 rounded-xl bg-slate-900 hover:bg-sky-950/60 border border-slate-800 hover:border-sky-500/50 text-sky-400 hover:text-sky-300 text-xs font-bold transition-all flex items-center justify-center gap-1.5 shadow-sm group-hover:border-sky-500/40"
                                                >
                                                    <span>Abrir Tablero del Pedido</span>
                                                    <span>➔</span>
                                                </button>
                                            </div>
                                        </div>
                                    {/each}
                                {/if}
                            </div>
                        </div>
                    {/each}
                </div>

            <!-- VISTA B: TABLA DE PRODUCCIÓN -->
            {:else}
                <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-950 border-b border-slate-800 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                <tr>
                                    <th class="p-3.5"># Pedido</th>
                                    <th class="p-3.5">Cliente</th>
                                    <th class="p-3.5">Producto Solicitado</th>
                                    <th class="p-3.5">Flujo Asignado</th>
                                    <th class="p-3.5">Etapa Actual (Modificar)</th>
                                    <th class="p-3.5">Tiempo Est.</th>
                                    <th class="p-3.5">Entrega</th>
                                    <th class="p-3.5 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/80">
                                {#if filteredTableTasks.length === 0}
                                    <tr>
                                        <td colspan="8" class="p-8 text-center text-slate-500 text-xs italic">
                                            No se encontraron tareas de producción con los filtros seleccionados.
                                        </td>
                                    </tr>
                                {:else}
                                    {#each filteredTableTasks as task (task.id)}
                                        {@const taskStages = getTaskWorkflowStages(task)}
                                        {@const curStage = task.current_stage ?? getTaskStage(task)}
                                        {@const curColor = curStage?.color || '#64748b'}
                                        <tr class="hover:bg-slate-800/30 transition-colors">
                                            <td class="p-3.5 font-mono font-bold text-sky-400">
                                                #{task.order_number}
                                            </td>
                                            <td class="p-3.5 font-semibold text-white">
                                                {task.customer_name}
                                            </td>
                                            <td class="p-3.5 text-slate-200">
                                                <div class="font-medium">{task.item_name_snapshot}</div>
                                                <div class="text-[10px] text-slate-500 font-mono">Cant: {task.quantity}</div>
                                            </td>
                                            <td class="p-3.5 text-slate-400">
                                                {task.workflow?.name ?? 'No asignado'}
                                            </td>
                                            <td class="p-3.5">
                                                {#if taskStages.length > 0}
                                                    <div class="relative inline-flex items-center">
                                                        <select
                                                            value={task.current_stage_id ?? curStage?.id}
                                                            onchange={(e) => handleTableTaskStageChange(task, e.target.value)}
                                                            disabled={updatingTaskId === task.id}
                                                            class="pl-2.5 pr-7 py-1.5 rounded-xl text-[11px] font-bold transition-all focus:outline-none cursor-pointer border shadow-sm appearance-none disabled:opacity-60 disabled:cursor-wait"
                                                            style="background-color: {curColor}1c; color: {curColor}; border-color: {curColor}55;"
                                                            title="Modificar etapa de producción"
                                                        >
                                                            {#each taskStages as st (st.id)}
                                                                <option
                                                                    value={st.id}
                                                                    class="bg-slate-900 text-white font-medium py-1"
                                                                    style="color: {st.color || '#fff'};"
                                                                >
                                                                    ● {st.name}
                                                                </option>
                                                            {/each}
                                                        </select>
                                                        <!-- Flecha o Spinner interactivo -->
                                                        <div class="pointer-events-none absolute right-2 flex items-center" style="color: {curColor};">
                                                            {#if updatingTaskId === task.id}
                                                                <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                                </svg>
                                                            {:else}
                                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                                                                </svg>
                                                            {/if}
                                                        </div>
                                                    </div>
                                                {:else}
                                                    <span class="text-slate-600 italic text-[11px]">Sin etapas</span>
                                                {/if}
                                            </td>
                                            <td class="p-3.5 font-mono text-amber-300">
                                                ⏱ {task.estimated_minutes ?? 0} min
                                            </td>
                                            <td class="p-3.5 text-slate-300 font-medium">
                                                {formatDate(task.committed_delivery_at)}
                                            </td>
                                            <td class="p-3.5 text-right">
                                                <button
                                                    type="button"
                                                    onclick={() => openOrderBoard(task.order_id)}
                                                    class="px-2.5 py-1 rounded-lg bg-sky-950/60 hover:bg-sky-900/60 border border-sky-800/60 text-sky-400 text-xs font-semibold transition-colors"
                                                >
                                                    Abrir Pedido ➔
                                                </button>
                                            </td>
                                        </tr>
                                    {/each}
                                {/if}
                            </tbody>
                        </table>
                    </div>
                </div>
            {/if}

        <!-- ============================================================== -->
        <!-- MODO 2: VISTA EXCLUSIVA Y AISLADA DE UN SOLO PEDIDO (MULTI-FLUJO) -->
        <!-- ============================================================== -->
        {:else}
            <!-- BARRA DE NAVEGACIÓN Y RETORNO -->
            <div class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    onclick={closeOrderBoard}
                    class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-slate-300 hover:text-white text-xs font-bold transition-colors flex items-center gap-2 shadow-sm"
                >
                    <span>←</span>
                    <span>Volver al Tablero General de Pedidos</span>
                </button>

                <div class="flex items-center gap-2">
                    <span class="text-slate-500 text-xs">Tablero Aislado del Pedido:</span>
                    <span class="font-mono text-sm font-black text-sky-400">#{activeOrder.order_number}</span>
                </div>
            </div>

            <!-- FICHA PRINCIPAL DEL PEDIDO AISLADO -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-xl font-bold text-white">
                                Pedido #{activeOrder.order_number} — {activeOrder.customer?.name ?? 'Cliente'}
                            </h2>
                            <span class="font-mono text-sm font-bold text-emerald-400 bg-emerald-950/50 px-2.5 py-0.5 rounded-lg border border-emerald-800/40">
                                {formatCurrency(activeOrder.total_amount)}
                            </span>
                        </div>
                        <div class="flex items-center gap-4 text-xs text-slate-400 mt-1 flex-wrap">
                            <span>📅 Entrega Comprometida: <strong class="text-slate-200">{formatDate(activeOrder.committed_delivery_at)}</strong></span>
                            <span>⏱ Tiempo Total: <strong class="text-amber-300 font-mono">{activeOrder.formatted_time}</strong></span>
                            {#if activeOrder.pending_balance > 0}
                                <span>Saldo Pendiente: <strong class="text-rose-400 font-mono">{formatCurrency(activeOrder.pending_balance)}</strong></span>
                            {/if}
                        </div>
                    </div>

                    <!-- SELECTOR RÁPIDO DE ESTADO MACRO DEL PEDIDO -->
                    <div class="flex items-center gap-2 self-start lg:self-auto">
                        <span class="text-xs text-slate-400 font-semibold">Estado del Pedido:</span>
                        <select
                            value={activeOrder.derived_status}
                            onchange={(e) => updateOrderMacroStatus(activeOrder.id, e.target.value)}
                            class="bg-slate-950 border border-slate-700 rounded-xl px-3 py-1.5 text-white text-xs font-semibold focus:outline-none focus:border-sky-500"
                        >
                            <option value="pending">Pendiente</option>
                            <option value="in_progress">En Fabricación</option>
                            <option value="blocked">Bloqueado</option>
                            <option value="ready">Listo para Entrega</option>
                        </select>
                    </div>
                </div>

                <!-- PESTAÑAS DE FLUJO AISLADAS: SOLO LOS FLUJOS PRESENTES EN ESTE PEDIDO -->
                {#if orderWorkflows.length > 1}
                    <div class="pt-3 border-t border-slate-800 flex items-center gap-2 overflow-x-auto">
                        <span class="text-xs text-slate-400 font-semibold shrink-0">Flujos en este Pedido:</span>
                        {#each orderWorkflows as wf (wf.id)}
                            {@const isActive = Number(activeOrderWorkflow?.id) === Number(wf.id)}
                            <button
                                type="button"
                                onclick={() => selectedOrderWorkflowId = wf.id}
                                class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all flex items-center gap-1.5 shrink-0 {isActive ? 'bg-sky-600 text-white shadow-md shadow-sky-600/20' : 'bg-slate-950 text-slate-400 hover:text-white border border-slate-800'}"
                            >
                                <span>{wf.name}</span>
                                <span class="px-1.5 py-0.2 rounded-full text-[10px] {isActive ? 'bg-sky-800 text-white' : 'bg-slate-800 text-slate-400'}">
                                    {wf.tasksCount}
                                </span>
                            </button>
                        {/each}
                    </div>
                {/if}
            </div>

            <!-- TABLERO KANBAN DE ETAPAS PARA EL FLUJO ACTIVO DEL PEDIDO -->
            {#if activeOrderStages.length === 0}
                <div class="p-12 text-center bg-slate-900 border border-slate-800 rounded-2xl text-slate-400 text-sm">
                    Este flujo no tiene etapas configuradas. Ve al Módulo de Flujos para agregar etapas.
                </div>
            {:else}
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 gap-4 items-start">
                    {#each activeOrderStages as stage (stage.id)}
                        {@const stageTasks = activeOrderTasks.filter(t => Number(t.current_stage_id ?? getTaskStage(t)?.id) === Number(stage.id))}
                        <div
                            class="bg-slate-900 border border-slate-800 rounded-2xl flex flex-col min-h-[500px] shadow-sm transition-colors {dragOverStageId === stage.id ? 'ring-2 ring-sky-500 bg-slate-900/90' : ''}"
                            style="border-top: 4px solid {stage.color || '#64748b'};"
                            ondragover={(e) => handleTaskDragOver(e, stage.id)}
                            ondrop={(e) => handleTaskDrop(e, stage.id)}
                            role="region"
                            aria-label={`Etapa ${stage.name}`}
                        >
                            <!-- Cabecera de la Etapa / Columna -->
                            <div class="p-3.5 border-b border-slate-800 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="w-2.5 h-2.5 rounded-full" style="background-color: {stage.color || '#64748b'};"></div>
                                    <h3 class="text-xs font-bold text-white tracking-wide truncate max-w-[130px]" title={stage.name}>
                                        {stage.name}
                                    </h3>
                                </div>
                                <span
                                    class="px-2 py-0.5 rounded-full text-[10px] font-mono font-bold"
                                    style="background-color: {stage.color || '#64748b'}22; color: {stage.color || '#64748b'}; border: 1px solid {stage.color || '#64748b'}44;"
                                >
                                    {stageTasks.length}
                                </span>
                            </div>

                            <!-- Lista de Tareas en esta Etapa -->
                            <div class="p-3 space-y-3 flex-1 overflow-y-auto max-h-[700px]">
                                {#if stageTasks.length === 0}
                                    <div class="py-12 text-center text-slate-600 text-xs italic">
                                        Sin productos en esta columna.
                                    </div>
                                {:else}
                                    {#each stageTasks as task (task.id)}
                                        <div
                                            class="bg-slate-950 border border-slate-800 hover:border-slate-700 rounded-xl p-3 space-y-2.5 shadow transition-all cursor-grab active:cursor-grabbing group"
                                            draggable="true"
                                            ondragstart={(e) => handleTaskDragStart(e, task.id)}
                                            role="article"
                                        >
                                            <div class="flex items-start justify-between gap-1.5">
                                                <h4 class="text-xs font-bold text-white group-hover:text-sky-300 transition-colors">
                                                    {task.item_name_snapshot}
                                                </h4>
                                                <span class="font-mono text-[10px] font-bold text-slate-400 bg-slate-900 px-1.5 py-0.5 rounded shrink-0">
                                                    {task.quantity}x
                                                </span>
                                            </div>

                                            <div class="flex items-center justify-between text-[10px] text-slate-400">
                                                <span class="font-mono text-amber-300">⏱ {task.estimated_minutes} min</span>
                                                {#if task.materials_consumed}
                                                    <span class="text-emerald-400 flex items-center gap-0.5 font-semibold" title="Materias primas descontadas del inventario">
                                                        <span>✓</span>
                                                        <span>BOM Ok</span>
                                                    </span>
                                                {/if}
                                            </div>

                                            <!-- Selector / Controles de Movimiento de Etapa -->
                                            <div class="pt-2 border-t border-slate-900 flex items-center justify-between gap-1 text-[11px]">
                                                <select
                                                    value={task.current_stage_id ?? getTaskStage(task)?.id}
                                                    onchange={(e) => moveTask(task.id, Number(e.target.value))}
                                                    class="w-full bg-slate-900 border border-slate-800 rounded-lg px-2 py-1 text-slate-300 text-[10px] font-medium focus:outline-none focus:border-sky-500"
                                                >
                                                    {#each activeOrderStages as st}
                                                        <option value={st.id}>{st.name}</option>
                                                    {/each}
                                                </select>
                                            </div>
                                        </div>
                                    {/each}
                                {/if}
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}
        {/if}
    </div>
</AppLayout>
