<script>
    import { untrack } from 'svelte';
    import { router, Link, page } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import { csrfFetch } from '../../Utils/csrfFetch';
    import { canViewPrices } from '../../Utils/permissions';
    import OrderFormModal from '../../Components/OrderFormModal.svelte';
    import OrderStatusModal from './OrderStatusModal.svelte';
    import OrderDetailModal from './OrderDetailModal.svelte';
    import ProductionTaskDetailModal from './ProductionTaskDetailModal.svelte';
    import ProductFormModal from '../../Components/ProductFormModal.svelte';

    let {
        workflows = [],
        orders = [],
        orderStatuses = [],
        allScheduledOrders = [],
        ordersCountByDate = {},
        customers = [],
        products = [],
        productTypes = [],
        rawMaterials = [],
        orthogonalAttributes = [],
        attributes = [],
        unitsOfMeasure = [],
        selectedOrderId = null,
        errors = {},
    } = $props();

    let authUser = $derived(page.props.auth?.user);
    let hasFinancialAccess = $derived(canViewPrices(authUser));
    let isOwnerOrAdmin = $derived(Boolean(authUser?.is_super_admin || authUser?.role === 'owner'));

    // Copia local de pedidos para optimismo reactivo
    let localOrders = $state([]);
    $effect(() => {
        const incoming = orders;
        untrack(() => {
            localOrders = JSON.parse(JSON.stringify(incoming || []));
        });
    });

    // Copia local de estados de pedido ordenados por posición
    let localStatuses = $state([]);
    $effect(() => {
        const incoming = orderStatuses;
        untrack(() => {
            localStatuses = JSON.parse(JSON.stringify(incoming || [])).toSorted((a, b) => (a.position || 0) - (b.position || 0));
        });
    });

    // ID del pedido actualmente abierto en vista aislada
    let activeOrderId = $state(null);
    $effect(() => {
        const incomingId = selectedOrderId;
        untrack(() => {
            if (incomingId) {
                activeOrderId = Number(incomingId);
            } else if (typeof window !== 'undefined') {
                const params = new URLSearchParams(window.location.search);
                const ordId = params.get('order_id');
                if (ordId) {
                    activeOrderId = Number(ordId);
                }
            }
        });
    });

    // Vista general: 'kanban' (Macro Pedidos) | 'table' (Tabla de Tareas)
    let generalViewMode = $state('kanban');

    // Filtros progresivos reactivos
    let filters = $state({
        customerId: '',
        status: 'all',
        dateRange: 'all', // 'all' | 'late' | 'today' | 'week'
        workflowId: '',
        productId: '',
        productTypeId: '',
        search: '',
    });

    // Drag & Drop para Pedidos Macro y Tareas de Flujo
    let draggedOrderId = $state(null);
    let dragOverMacroStatusId = $state(null);
    let draggedTaskId = $state(null);
    let dragOverStageId = $state(null);
    let isMoving = $state(false);

    // Modales
    let isStatusModalOpen = $state(false);
    let isOrderFormOpen = $state(false);
    let isEditingOrder = $state(false);
    let editingOrder = $state(null);

    // Modal de Detalle Completo de Pedido (Punto 18)
    let isDetailModalOpen = $state(false);
    let selectedOrderForDetail = $state(null);
    let highlightedTaskId = $state(null);

    // Modal de Ficha Técnica de Producción al tocar una card de Producto (Punto 2)
    let selectedTaskDetail = $state(null);
    let isTaskDetailModalOpen = $state(false);

    // Modal de Edición de Receta / Producto
    let editingProductForBOM = $state(null);
    let isProductFormOpen = $state(false);

    // --- FUNCIONES HELPER SEGURAS ---
    function getOrderTasks(order) {
        return order?.production_tasks ?? order?.productionTasks ?? order?.tasks ?? [];
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

    function getOrderDateKey(ord) {
        if (!ord) return '';
        if (ord.delivery_date_key && /^\d{4}-\d{2}-\d{2}$/.test(ord.delivery_date_key)) {
            return ord.delivery_date_key;
        }
        const raw = ord.committed_delivery_at || ord.predicted_delivery_at || ord.delivery_date || '';
        if (!raw) return '';
        const match = String(raw).trim().match(/^(\d{4}-\d{2}-\d{2})/);
        return match ? match[1] : '';
    }

    // Mapa reactivo de pedidos por fecha para el mini-calendario
    let allOrdersByDate = $derived.by(() => {
        const map = {};
        const combined = [
            ...(Array.isArray(allScheduledOrders) ? allScheduledOrders : []),
            ...(Array.isArray(localOrders) ? localOrders : []),
        ];

        const seenIds = new Set();
        for (const ord of combined) {
            if (!ord || seenIds.has(ord.id)) continue;
            seenIds.add(ord.id);
            const key = getOrderDateKey(ord);
            if (!key) continue;
            if (!map[key]) {
                map[key] = [];
            }
            map[key].push(ord);
        }
        return map;
    });

    // Pedido actualmente seleccionado en el Nivel 2
    let activeOrder = $derived(
        activeOrderId ? localOrders.find((o) => Number(o.id) === Number(activeOrderId)) ?? null : null,
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

    // Etapas ordenadas del flujo activo del pedido
    let activeOrderStages = $derived.by(() => {
        if (!activeOrderWorkflow) return [];
        const stages = activeOrderWorkflow.stages ?? [];
        return [...stages].sort((a, b) => (a.position || 0) - (b.position || 0));
    });

    // Tareas del pedido que corresponden al flujo seleccionado
    let activeOrderTasks = $derived.by(() => {
        if (!activeOrder || !activeOrderWorkflow) return [];
        const tasks = getOrderTasks(activeOrder);
        const activeWfId = Number(activeOrderWorkflow.id);

        return tasks.filter((t) => {
            const taskWfId = getTaskWorkflowId(t);
            return taskWfId === activeWfId || (!taskWfId && activeOrderWorkflowsCount === 1);
        });
    });

    let activeOrderWorkflowsCount = $derived(orderWorkflows.length);

    // Evalúa si una tarea individual coincide con los filtros específicos de producto
    function taskMatchesSpecificFilters(task) {
        if (filters.workflowId && getTaskWorkflowId(task) !== Number(filters.workflowId)) {
            return false;
        }

        const variant = getTaskVariant(task);
        const product = getTaskProduct(task);

        if (filters.productTypeId) {
            const pType = product?.product_type_id ?? product?.productType?.id ?? variant?.product?.product_type_id;
            if (Number(pType) !== Number(filters.productTypeId)) {
                return false;
            }
        }

        if (filters.productId) {
            const prodId = product?.id ?? variant?.product_id;
            if (Number(prodId) !== Number(filters.productId)) {
                return false;
            }
        }

        return true;
    }

    // Filtrado reactivo de pedidos para el Tablero General
    let filteredOrders = $derived.by(() => {
        const now = new Date();
        now.setHours(0, 0, 0, 0);

        const endOfWeek = new Date(now);
        endOfWeek.setDate(now.getDate() + (7 - (now.getDay() === 0 ? 7 : now.getDay())));
        endOfWeek.setHours(23, 59, 59, 999);

        return localOrders.filter((order) => {
            const tasks = getOrderTasks(order);

            // Búsqueda textual por número de pedido o nombre de cliente
            if (filters.search.trim()) {
                const term = filters.search.toLowerCase().trim();
                const numStr = String(order.order_number || '');
                const clientName = (order.customer?.name || '').toLowerCase();
                if (!numStr.includes(term) && !clientName.includes(term)) {
                    return false;
                }
            }

            // Filtro por Cliente
            if (filters.customerId && Number(order.customer_id) !== Number(filters.customerId)) {
                return false;
            }

            // Filtro por Estado (por ID o por derived_status legacy)
            if (filters.status !== 'all') {
                const matches = (Number(order.order_status_id) === Number(filters.status)) ||
                    (order.derived_status === filters.status);
                if (!matches) return false;
            }

            // Filtro por Fecha de Entrega
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

    // Pedidos agrupados dinámicamente por order_status_id (Punto 1)
    let ordersByStatusId = $derived.by(() => {
        const map = new Map();
        for (const st of localStatuses) {
            map.set(st.id, []);
        }

        const firstStatusId = localStatuses[0]?.id;

        for (const order of filteredOrders) {
            let targetId = order.order_status_id;
            if (!targetId || !map.has(targetId)) {
                const found = localStatuses.find((s) => s.system_key === order.derived_status);
                targetId = found ? found.id : firstStatusId;
            }

            if (targetId && map.has(targetId)) {
                map.get(targetId).push(order);
            } else if (firstStatusId && map.has(firstStatusId)) {
                map.get(firstStatusId).push(order);
            }
        }

        return map;
    });

    // Tareas individuales para la vista de tabla con filtrado directo sobre productos
    let filteredTableTasks = $derived.by(() => {
        const list = [];
        for (const order of filteredOrders) {
            const tasks = getOrderTasks(order);
            for (const task of tasks) {
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
                    rawOrder: order,
                });
            }
        }
        return list;
    });

    function setGeneralViewMode(mode) {
        generalViewMode = mode;
        if (mode === 'kanban') {
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
        if (!orderId) return;
        activeOrderId = Number(orderId);
        selectedOrderWorkflowId = null;
        router.visit(`/kanban?order_id=${orderId}`, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function closeOrderBoard() {
        activeOrderId = null;
        selectedOrderWorkflowId = null;
        router.visit('/kanban', {
            preserveState: true,
            preserveScroll: true,
        });
    }

    // --- MODALES ---
    function openNewOrderModal() {
        isEditingOrder = false;
        editingOrder = null;
        isOrderFormOpen = true;
    }

    function openEditOrderModal(order) {
        isEditingOrder = true;
        editingOrder = order;
        isOrderFormOpen = true;
    }

    function openDetailModal(order, taskId = null) {
        selectedOrderForDetail = order;
        highlightedTaskId = taskId;
        isDetailModalOpen = true;
    }

    function openTaskDetailModal(task) {
        selectedTaskDetail = task;
        isTaskDetailModalOpen = true;
    }

    function closeTaskDetailModal() {
        isTaskDetailModalOpen = false;
        selectedTaskDetail = null;
    }

    function handleEditProductRecipe(productToEdit) {
        editingProductForBOM = productToEdit;
        isProductFormOpen = true;
    }

    // --- ACCIONES MACRO PARA PEDIDOS (DRAG & DROP DINÁMICO) ---
    function handleMacroDragStart(e, orderId) {
        draggedOrderId = orderId;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(orderId));
    }

    function handleMacroDragOver(e, statusId) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        dragOverMacroStatusId = statusId;
    }

    function handleMacroDrop(e, targetStatusId) {
        e.preventDefault();
        dragOverMacroStatusId = null;
        if (!draggedOrderId) return;

        updateOrderStatus(draggedOrderId, targetStatusId);
        draggedOrderId = null;
    }

    async function updateOrderStatus(orderId, newStatusId) {
        const order = localOrders.find((o) => o.id === orderId);
        if (!order) return;

        const targetStatus = localStatuses.find((s) => s.id === Number(newStatusId));
        if (!targetStatus) return;

        // Actualización optimista
        order.order_status_id = targetStatus.id;
        if (targetStatus.system_key && targetStatus.system_key !== 'custom') {
            order.derived_status = targetStatus.system_key;
        }

        if (selectedOrderForDetail && selectedOrderForDetail.id === orderId) {
            selectedOrderForDetail.order_status_id = targetStatus.id;
            if (targetStatus.system_key && targetStatus.system_key !== 'custom') {
                selectedOrderForDetail.derived_status = targetStatus.system_key;
            }
        }

        isMoving = true;
        try {
            const res = await csrfFetch(`/kanban/orders/${orderId}/status`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_status_id: targetStatus.id }),
            });

            if (!res.ok) {
                throw new Error('Error al actualizar estado del pedido');
            }

            const data = await res.json().catch(() => null);
            if (data?.order) {
                const idx = localOrders.findIndex((o) => o.id === orderId);
                if (idx !== -1) {
                    localOrders[idx] = { ...localOrders[idx], ...data.order };
                }
                if (selectedOrderForDetail && selectedOrderForDetail.id === orderId) {
                    selectedOrderForDetail = { ...selectedOrderForDetail, ...data.order };
                }
            }
        } catch (err) {
            console.error('Error al actualizar estado de pedido:', err);
            router.reload({ preserveScroll: true });
        } finally {
            isMoving = false;
        }
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

        if (selectedOrderForDetail) {
            const tasks = getOrderTasks(selectedOrderForDetail);
            const task = tasks.find((t) => t.id === taskId);
            if (task) {
                task.current_stage_id = targetStageId;
                task.current_stage = targetStage;
                task.currentStage = targetStage;
            }
        }

        if (selectedTaskDetail && selectedTaskDetail.id === taskId) {
            selectedTaskDetail.current_stage_id = targetStageId;
            selectedTaskDetail.current_stage = targetStage;
            selectedTaskDetail.currentStage = targetStage;
            if (targetStage.stage_category === 'in_progress') {
                selectedTaskDetail.materials_consumed = true;
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

    // Modificación directa e inmediata de etapa en la tabla de producción o modal de detalle
    async function handleTableTaskStageChange(task, newStageId) {
        const stageId = Number(newStageId);
        if (!stageId) return;

        const wfId = getTaskWorkflowId(task);
        const wf = workflows.find((w) => Number(w.id) === wfId) ?? task.workflow;
        const stages = wf?.stages ?? wf?.workflow_stages ?? [];
        const targetStage = stages.find((s) => Number(s.id) === stageId);
        if (!targetStage) return;

        const previousStage = task.current_stage;
        const previousStageId = task.current_stage_id;

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
                        Monitorea el avance integral de cada pedido ("US") con columnas dinámicas personalizables y abre el detalle completo al tocar cualquier card.
                    </p>
                </div>

                <!-- ACCIONES DE CABECERA: BOTÓN NUEVO PEDIDO + CONFIGURACIÓN DE ESTADOS + SELECTOR DE VISTA -->
                <div class="flex items-center gap-2 shrink-0 self-start md:self-auto flex-wrap">
                    <!-- Botón Crear Nuevo Pedido directamente en Kanban (Punto 2) -->
                    <button
                        type="button"
                        onclick={openNewOrderModal}
                        class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold shadow-md shadow-emerald-600/20 transition-all flex items-center gap-1.5"
                    >
                        <span>➕</span>
                        <span>Nuevo Pedido</span>
                    </button>

                    <!-- Botón Configuración de Estados (Visible para Dueño y Admin - Punto 1) -->
                    {#if isOwnerOrAdmin}
                        <button
                            type="button"
                            onclick={() => isStatusModalOpen = true}
                            class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 transition-colors flex items-center gap-1.5"
                            title="Gestionar y reordenar estados de pedidos con Drag & Drop"
                        >
                            <span>⚙️</span>
                            <span>Estados de Pedidos</span>
                        </button>
                    {/if}

                    <!-- SELECTOR DE VISTA: TABLERO / TABLA -->
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
                        class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold border border-slate-700 transition-colors"
                    >
                        Gestionar Pedidos ➔
                    </Link>
                </div>
            </div>

            <!-- BARRA DE FILTROS PROGRESIVOS -->
            <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl space-y-3 shadow-sm">
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

                    <!-- Estado del Pedido (Dinámico con localStatuses) -->
                    <div>
                        <label for="filter-status" class="block text-[11px] font-semibold text-slate-400 mb-1">Estado del Pedido</label>
                        <select
                            id="filter-status"
                            bind:value={filters.status}
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-sky-500"
                        >
                            <option value="all">Todos los Estados</option>
                            {#each localStatuses as st (st.id)}
                                <option value={st.id}>{st.name}</option>
                            {/each}
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

                <!-- Nivel 2: Filtros Específicos para Tabla de Producción -->
                {#if generalViewMode === 'table'}
                    <div class="pt-2 border-t border-slate-800/80 flex items-center gap-3 w-full flex-wrap text-xs">
                        <div class="flex items-center gap-2">
                            <label for="filter-kb-workflow" class="text-slate-400 font-semibold text-[11px] shrink-0">Flujo:</label>
                            <select
                                id="filter-kb-workflow"
                                bind:value={filters.workflowId}
                                class="bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1 text-white text-xs focus:outline-none focus:border-sky-500"
                            >
                                <option value="">Todos los Flujos</option>
                                {#each workflows as wf (wf.id)}
                                    <option value={wf.id}>{wf.name}</option>
                                {/each}
                            </select>
                        </div>

                        <div class="flex items-center gap-2">
                            <label for="filter-kb-type" class="text-slate-400 font-semibold text-[11px] shrink-0">Tipo:</label>
                            <select
                                id="filter-kb-type"
                                bind:value={filters.productTypeId}
                                class="bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1 text-white text-xs focus:outline-none focus:border-sky-500 max-w-[180px] truncate"
                            >
                                <option value="">Todos los Tipos</option>
                                {#each productTypes as pt (pt.id)}
                                    <option value={pt.id}>{pt.name}</option>
                                {/each}
                            </select>
                        </div>

                        <div class="flex items-center gap-2">
                            <label for="filter-kb-product" class="text-slate-400 font-semibold text-[11px] shrink-0">Producto:</label>
                            <select
                                id="filter-kb-product"
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
                            {filteredOrders.length} pedido(s) encontrado(s) en {localStatuses.length} columnas
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

            <!-- VISTA A: TABLERO KANBAN MACRO (COLUMNAS DINÁMICAS POR ESTADOS DE PEDIDO — PUNTOS 1 Y 18) -->
            {#if generalViewMode === 'kanban'}
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-4 items-start">
                    {#each localStatuses as col (col.id)}
                        {@const colOrders = ordersByStatusId.get(col.id) || []}
                        <div
                            class="bg-slate-900/90 border border-slate-800 rounded-2xl flex flex-col min-h-[580px] shadow-sm transition-colors {dragOverMacroStatusId === col.id ? 'ring-2 ring-sky-500 bg-slate-900' : ''}"
                            style="border-top: 4px solid {col.color || '#64748b'};"
                            ondragover={(e) => handleMacroDragOver(e, col.id)}
                            ondrop={(e) => handleMacroDrop(e, col.id)}
                            role="region"
                            aria-label={`Columna ${col.name}`}
                        >
                            <!-- Cabecera de la Columna Dinámica -->
                            <div class="p-4 border-b border-slate-800/80 flex items-center justify-between">
                                <div class="flex items-center gap-2 min-w-0">
                                    <div class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {col.color || '#64748b'};"></div>
                                    <h3 class="text-sm font-bold text-white tracking-wide truncate" title={col.name}>
                                        {col.name}
                                    </h3>
                                </div>
                                <span
                                    class="px-2 py-0.5 rounded-full text-xs font-mono font-bold shrink-0"
                                    style="background-color: {col.color || '#64748b'}22; color: {col.color || '#64748b'}; border: 1px solid {col.color || '#64748b'}44;"
                                >
                                    {colOrders.length}
                                </span>
                            </div>

                            <!-- Lista de Tarjetas de Pedido en esta Columna -->
                            <div class="p-3 space-y-3 flex-1 overflow-y-auto max-h-[780px]">
                                {#if colOrders.length === 0}
                                    <div class="py-12 text-center text-slate-600 text-xs italic">
                                        No hay pedidos en este estado.
                                    </div>
                                {:else}
                                    {#each colOrders as order (order.id)}
                                        {@const tasks = getOrderTasks(order)}
                                        {@const completedCount = tasks.filter(t => (getTaskStage(t)?.stage_category ?? t.stage_category) === 'done').length}
                                        {@const progressPercent = tasks.length > 0 ? Math.round((completedCount / tasks.length) * 100) : 0}
                                        {@const delivBadge = getDeliveryBadge(order.committed_delivery_at, order.derived_status)}

                                        <div
                                            class="bg-slate-950 border border-slate-800 hover:border-sky-500/50 rounded-xl p-3.5 space-y-3 shadow transition-all cursor-pointer group"
                                            draggable="true"
                                            ondragstart={(e) => handleMacroDragStart(e, order.id)}
                                            onclick={() => openDetailModal(order)}
                                            onkeydown={(e) => { if (e.key === 'Enter' || e.key === ' ') openDetailModal(order); }}
                                            role="button"
                                            tabindex="0"
                                            title="Toca para ver el detalle completo del pedido"
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

                                                {#if hasFinancialAccess}
                                                    <span class="font-mono text-xs font-extrabold text-emerald-400 shrink-0">
                                                        {formatCurrency(order.total_amount)}
                                                    </span>
                                                {/if}
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
                                                        style="width: {progressPercent}%; background-color: {col.color || '#38bdf8'};"
                                                    ></div>
                                                </div>
                                            </div>

                                            <!-- Resumen de Productos con sus Etapas Coloreadas -->
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

                                            <!-- Pie de la Tarjeta: Botón Flujo Aislado -->
                                            <div class="flex items-center justify-end pt-2 border-t border-slate-900">
                                                <button
                                                    type="button"
                                                    onclick={(e) => {
                                                        e.stopPropagation();
                                                        openOrderBoard(order.id);
                                                    }}
                                                    class="py-1 px-2.5 rounded-lg bg-slate-900 hover:bg-sky-950/60 border border-slate-800 hover:border-sky-500/50 text-sky-400 text-[10px] font-bold transition-all flex items-center gap-1"
                                                    title="Ver tareas y flujos exclusivos de este pedido"
                                                >
                                                    <span>Flujo</span>
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
                                        {@const currentStage = task.current_stage}
                                        {@const targetWorkflowStages = task.workflow?.stages ?? []}
                                        {@const delivBadge = getDeliveryBadge(task.committed_delivery_at, task.order_status)}

                                        <tr
                                            class="hover:bg-slate-800/40 transition-colors cursor-pointer"
                                            onclick={() => openDetailModal(task.rawOrder, task.id)}
                                        >
                                            <td class="p-3.5 font-mono font-bold text-sky-400">
                                                #{task.order_number}
                                            </td>
                                            <td class="p-3.5 text-white font-medium">
                                                {task.customer_name}
                                            </td>
                                            <td class="p-3.5 text-slate-200">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="font-bold text-white">{task.quantity}x</span>
                                                    <span>{task.item_name_snapshot}</span>
                                                </div>
                                            </td>
                                            <td class="p-3.5 text-slate-400">
                                                {task.workflow?.name ?? 'Sin Flujo'}
                                            </td>
                                            <td class="p-3.5" onclick={(e) => e.stopPropagation()}>
                                                <label for="table-stage-sel-{task.id}" class="sr-only">Etapa de la tarea</label>
                                                <select
                                                    id="table-stage-sel-{task.id}"
                                                    value={task.current_stage_id}
                                                    onchange={(e) => handleTableTaskStageChange(task, e.target.value)}
                                                    class="bg-slate-950 border border-slate-700 rounded-lg px-2.5 py-1 text-white text-xs font-semibold focus:outline-none focus:border-sky-500"
                                                    style="border-left: 4px solid {currentStage?.color || '#64748b'};"
                                                >
                                                    {#each targetWorkflowStages as stage}
                                                        <option value={stage.id}>{stage.name}</option>
                                                    {/each}
                                                </select>
                                            </td>
                                            <td class="p-3.5 font-mono text-amber-300">
                                                ⏱ {task.estimated_minutes} min
                                            </td>
                                            <td class="p-3.5">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-slate-300">{formatDate(task.committed_delivery_at)}</span>
                                                    {#if delivBadge}
                                                        <span
                                                            class="px-1.5 py-0.5 rounded text-[9px] font-bold"
                                                            style="background-color: {delivBadge.color}22; color: {delivBadge.color}; border: 1px solid {delivBadge.color}44;"
                                                        >
                                                            {delivBadge.text}
                                                        </span>
                                                    {/if}
                                                </div>
                                            </td>
                                            <td class="p-3.5 text-right" onclick={(e) => e.stopPropagation()}>
                                                <button
                                                    type="button"
                                                    onclick={() => openOrderBoard(task.order_id)}
                                                    class="px-2.5 py-1 rounded-lg bg-sky-950/70 hover:bg-sky-900/70 text-sky-400 text-xs font-bold border border-sky-800/70 transition-colors"
                                                >
                                                    Abrir Pedido
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
                    <button
                        type="button"
                        onclick={() => openDetailModal(activeOrder)}
                        class="px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-700 text-slate-200 text-xs font-semibold transition-colors flex items-center gap-1.5"
                    >
                        <span>👁️</span>
                        <span>Ver Ficha Completa</span>
                    </button>
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
                            {#if hasFinancialAccess}
                                <span class="font-mono text-sm font-bold text-emerald-400 bg-emerald-950/50 px-2.5 py-0.5 rounded-lg border border-emerald-800/40">
                                    {formatCurrency(activeOrder.total_amount)}
                                </span>
                            {/if}
                        </div>
                        <div class="flex items-center gap-4 text-xs text-slate-400 mt-1 flex-wrap">
                            <span>📅 Entrega Comprometida: <strong class="text-slate-200">{formatDate(activeOrder.committed_delivery_at)}</strong></span>
                            <span>⏱ Tiempo Total: <strong class="text-amber-300 font-mono">{activeOrder.formatted_time}</strong></span>
                            {#if hasFinancialAccess && activeOrder.pending_balance > 0}
                                <span>Saldo Pendiente: <strong class="text-rose-400 font-mono">{formatCurrency(activeOrder.pending_balance)}</strong></span>
                            {/if}
                        </div>
                    </div>

                    <!-- SELECTOR RÁPIDO DE ESTADO DEL PEDIDO (CON ESTADOS DINÁMICOS) -->
                    <div class="flex items-center gap-2 self-start lg:self-auto">
                        <label for="isolated-order-st" class="text-xs text-slate-400 font-semibold">Estado del Pedido:</label>
                        <select
                            id="isolated-order-st"
                            value={activeOrder.order_status_id}
                            onchange={(e) => updateOrderStatus(activeOrder.id, Number(e.target.value))}
                            class="bg-slate-950 border border-slate-700 rounded-xl px-3 py-1.5 text-white text-xs font-semibold focus:outline-none focus:border-sky-500"
                        >
                            {#each localStatuses as st}
                                <option value={st.id}>{st.name}</option>
                            {/each}
                        </select>
                    </div>
                </div>

                <!-- PESTAÑAS DE FLUJO AISLADAS -->
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
                                            class="bg-slate-950 border border-slate-800 hover:border-sky-500/50 rounded-xl p-3 space-y-2.5 shadow transition-all cursor-pointer group"
                                            draggable="true"
                                            ondragstart={(e) => handleTaskDragStart(e, task.id)}
                                            onclick={() => openTaskDetailModal(task)}
                                            onkeydown={(e) => { if (e.key === 'Enter' || e.key === ' ') openTaskDetailModal(task); }}
                                            role="button"
                                            tabindex="0"
                                            title="Toca para ver la ficha técnica y receta del producto"
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

<!-- MODAL DE CREACIÓN / EDICIÓN DE PEDIDO DIRECTAMENTE DESDE KANBAN (PUNTO 2) -->
<OrderFormModal
    bind:isOpen={isOrderFormOpen}
    isEditing={isEditingOrder}
    editingOrder={editingOrder}
    {customers}
    {products}
    {workflows}
    {productTypes}
    {rawMaterials}
    {orthogonalAttributes}
    {attributes}
    {unitsOfMeasure}
    {allOrdersByDate}
    {ordersCountByDate}
    {allScheduledOrders}
    {hasFinancialAccess}
    {errors}
    onSuccess={() => {
        isOrderFormOpen = false;
        router.reload({ preserveScroll: true });
    }}
    onClose={() => {
        isOrderFormOpen = false;
        editingOrder = null;
        isEditingOrder = false;
    }}
/>

<!-- MODAL DE CONFIGURACIÓN DE ESTADOS DE PEDIDO (PUNTO 1) -->
<OrderStatusModal
    bind:isOpen={isStatusModalOpen}
    {orderStatuses}
    onStatusesChanged={() => {
        router.reload({ preserveScroll: true, only: ['orderStatuses', 'orders'] });
    }}
/>

<!-- MODAL DE DETALLE COMPLETO AL TOCAR CUALQUIER CARD EN EL TABLERO (PUNTO 18) -->
<OrderDetailModal
    bind:isOpen={isDetailModalOpen}
    order={selectedOrderForDetail}
    {highlightedTaskId}
    {orderStatuses}
    {workflows}
    {hasFinancialAccess}
    isIsolatedView={Boolean(activeOrder)}
    onStatusChange={(ordId, newStatusId) => updateOrderStatus(ordId, newStatusId)}
    onTaskStageChange={(taskId, newStageId) => {
        const t = filteredTableTasks.find((item) => item.id === taskId) || { id: taskId, order_id: selectedOrderForDetail?.id };
        handleTableTaskStageChange(t, newStageId);
    }}
    onEditOrder={(ord) => openEditOrderModal(ord)}
    onOpenIsolatedBoard={(ordId) => openOrderBoard(ordId)}
    onClose={() => {
        selectedOrderForDetail = null;
        highlightedTaskId = null;
    }}
/>

<!-- MODAL DE FICHA TÉCNICA DEL PRODUCTO / RECETA BOM (PUNTO 2) -->
<ProductionTaskDetailModal
    isOpen={isTaskDetailModalOpen}
    task={selectedTaskDetail}
    order={activeOrder ?? selectedTaskDetail?.order}
    stages={selectedTaskDetail?.workflow?.stages ?? activeOrderStages}
    {rawMaterials}
    canManageCatalog={isOwnerOrAdmin}
    onStageChange={(taskId, newStageId) => moveTask(taskId, newStageId)}
    onEditProduct={(prod) => handleEditProductRecipe(prod)}
    onClose={closeTaskDetailModal}
/>

<!-- MODAL DE EDICIÓN RÁPIDA DE RECETA / PRODUCTO -->
<ProductFormModal
    bind:isOpen={isProductFormOpen}
    product={editingProductForBOM}
    {productTypes}
    {workflows}
    {rawMaterials}
    {orthogonalAttributes}
    {attributes}
    {unitsOfMeasure}
    {errors}
    onSuccess={() => {
        isProductFormOpen = false;
        editingProductForBOM = null;
        router.reload({ preserveScroll: true });
    }}
    onClose={() => {
        isProductFormOpen = false;
        editingProductForBOM = null;
    }}
/>
