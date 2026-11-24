<script>
    import { untrack } from 'svelte';
    import { router, page } from '@inertiajs/svelte';
    import OrderPaymentsManager from '../../Components/OrderPaymentsManager.svelte';
    import ShippingGuideModal from '../../Components/ShippingGuideModal.svelte';

    let {
        isOpen = $bindable(false),
        order = null,
        highlightedTaskId = null,
        orderStatuses = [],
        workflows = [],
        hasFinancialAccess = true,
        isIsolatedView = false,
        errors = {},
        onStatusChange = () => {},
        onTaskStageChange = () => {},
        onPaymentsUpdated = () => {},
        onEditOrder = () => {},
        onOpenIsolatedBoard = (ordId) => {
            router.visit(`/kanban?order_id=${ordId}`);
        },
        onClose = () => {},
    } = $props();

    let authUser = $derived(page.props.auth?.user);
    let isOwnerOrAdmin = $derived(Boolean(authUser?.is_super_admin || authUser?.role === 'owner' || authUser?.role === 'admin'));
    let canGenerateShippingGuide = $derived(Boolean(authUser && authUser.role !== 'worker' && order?.customer));

    // Estado del modal de Guía de Envío
    let showShippingGuideModal = $state(false);
    let localSenderProfile = $state(null);

    $effect(() => {
        const incoming = page.props.senderProfile;
        untrack(() => {
            if (incoming) {
                localSenderProfile = { ...incoming };
            }
        });
    });

    function getOrderTasks(ord) {
        return ord?.production_tasks ?? ord?.productionTasks ?? ord?.tasks ?? [];
    }

    function getTaskWorkflow(task) {
        const wfId = Number(task?.workflow_id ?? task?.workflow?.id ?? 0);
        return workflows.find((w) => Number(w.id) === wfId) ?? task?.workflow ?? null;
    }

    function formatCurrency(val) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
        }).format(val || 0);
    }

    function formatDateTime(dateStr) {
        if (!dateStr) return 'Sin fecha comprometida';
        const d = new Date(dateStr);
        return d.toLocaleDateString('es-CO', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function formatPaymentDate(dateStr) {
        if (!dateStr) return 'Sin fecha';
        const match = String(dateStr).trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (match) {
            const [, y, m, d] = match;
            const localDate = new Date(Number(y), Number(m) - 1, Number(d));
            return localDate.toLocaleDateString('es-CO', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
            });
        }
        const parsed = new Date(dateStr);
        if (Number.isNaN(parsed.getTime())) return String(dateStr);
        return parsed.toLocaleDateString('es-CO', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
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
            return { text: `Atrasado (${Math.abs(diffDays)}d)`, color: 'bg-rose-500/10 text-rose-400 border-rose-500/20' };
        }
        if (diffDays === 0) {
            return { text: '⚡ Entrega Hoy', color: 'bg-amber-500/10 text-amber-400 border-amber-500/20' };
        }
        if (diffDays === 1) {
            return { text: 'Mañana', color: 'bg-sky-500/10 text-sky-400 border-sky-500/20' };
        }
        return null;
    }

    let deliveryBadge = $derived(
        order ? getDeliveryBadge(order.committed_delivery_at, order.derived_status) : null
    );

    let activeStatus = $derived.by(() => {
        if (!order) return null;
        if (order.order_status_id) {
            return orderStatuses.find((s) => s.id === order.order_status_id) ?? null;
        }
        return orderStatuses.find((s) => s.system_key === order.derived_status) ?? null;
    });

    function handleStatusSelect(e) {
        const newStatusId = Number(e.target.value);
        if (order && newStatusId) {
            onStatusChange(order.id, newStatusId);
        }
    }

    function handleStageSelect(task, e) {
        const newStageId = Number(e.target.value);
        if (task && newStageId) {
            onTaskStageChange(task.id, newStageId);
        }
    }

    function closeModal() {
        isOpen = false;
        onClose();
    }
</script>

{#if isOpen && order}
    <div class="fixed inset-0 z-50 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl max-w-4xl w-full p-6 shadow-2xl space-y-5 max-h-[92vh] overflow-y-auto text-slate-900 dark:text-slate-100 animate-in fade-in zoom-in-95 duration-100">
            <!-- CABECERA DEL PEDIDO -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200 dark:border-[#26334D] pb-4">
                <div class="flex items-center gap-3">
                    <span class="px-2.5 py-1 rounded-xl font-mono font-bold text-sm bg-slate-100 dark:bg-slate-950 text-emerald-600 dark:text-emerald-400 border border-slate-200 dark:border-slate-800 shadow-sm">
                        #{order.order_number}
                    </span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>Pedido #{order.order_number}</span>
                            {#if order.shipping_type === 'contraentrega'}
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                                    🚚 Contraentrega
                                </span>
                            {:else}
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-[#4F46E5]/10 text-[#4F46E5] dark:text-indigo-400 border border-[#4F46E5]/20">
                                    📦 Envío Incluido
                                </span>
                            {/if}
                        </h2>
                        <div class="flex items-center gap-2 mt-0.5 text-xs text-[#64748B] dark:text-slate-400 font-mono">
                            <span>📅 Entrega: {formatDateTime(order.committed_delivery_at || order.predicted_delivery_at)}</span>
                            {#if deliveryBadge}
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border {deliveryBadge.color}">
                                    {deliveryBadge.text}
                                </span>
                            {/if}
                        </div>
                    </div>
                </div>

                <!-- SELECTOR DE ESTADO DEL PEDIDO Y BOTÓN CERRAR -->
                <div class="flex items-center gap-2 self-end sm:self-auto">
                    <!-- Selector interactivo de Estado de Pedido -->
                    <div class="flex items-center gap-1.5 bg-slate-50 dark:bg-[#0B0F17] px-2.5 py-1.5 rounded-xl border border-slate-200 dark:border-[#26334D]">
                        <span
                            class="w-3 h-3 rounded-full shrink-0 shadow-sm"
                            style="background-color: {activeStatus?.color || '#64748b'};"
                        ></span>
                        <label for="detail-order-status" class="sr-only">Estado del Pedido</label>
                        <select
                            id="detail-order-status"
                            value={order.order_status_id || activeStatus?.id || ''}
                            onchange={handleStatusSelect}
                            class="bg-transparent text-slate-900 dark:text-white font-bold text-xs focus:outline-none cursor-pointer"
                        >
                            {#each orderStatuses as st}
                                <option value={st.id} class="bg-white dark:bg-slate-900 text-slate-900 dark:text-white">
                                    {st.name}
                                </option>
                            {/each}
                        </select>
                    </div>

                    <button
                        type="button"
                        onclick={closeModal}
                        class="text-slate-400 hover:text-slate-700 dark:hover:text-white p-1 rounded-lg"
                    >
                        ✕
                    </button>
                </div>
            </div>

            <!-- BLOQUE DE CLIENTE Y DESTINO -->
            <div class="p-4 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-slate-200 dark:border-[#26334D] space-y-2 text-xs">
                <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-2">
                    <span class="font-bold text-slate-800 dark:text-slate-300 uppercase tracking-wider text-[11px]">Información del Cliente y Destino</span>
                    {#if order.customer?.document_number || order.customer?.tax_id}
                        <span class="text-[#64748B] dark:text-slate-500 font-mono text-[11px]">Doc: {order.customer.document_number || order.customer.tax_id}</span>
                    {/if}
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 pt-1">
                    <div>
                        <span class="text-[10px] text-[#64748B] dark:text-slate-500 uppercase font-bold block">Nombre Completo</span>
                        <span class="text-slate-900 dark:text-white font-semibold text-sm">{order.customer?.name || 'Cliente no asignado'}</span>
                    </div>

                    <div>
                        <span class="text-[10px] text-[#64748B] dark:text-slate-500 uppercase font-bold block">Contacto</span>
                        <div class="text-slate-700 dark:text-slate-300 flex items-center gap-2">
                            {#if order.customer?.phone}
                                <a href="tel:{order.customer.phone}" class="text-emerald-600 dark:text-emerald-400 hover:underline">
                                    📞 {order.customer.phone}
                                </a>
                            {:else}
                                <span class="text-slate-400">Sin teléfono</span>
                            {/if}
                            {#if order.customer?.email}
                                <span class="text-slate-400">•</span>
                                <a href="mailto:{order.customer.email}" class="text-[#4F46E5] dark:text-sky-400 hover:underline truncate">
                                    ✉️ {order.customer.email}
                                </a>
                            {/if}
                        </div>
                    </div>

                    <div>
                        <span class="text-[10px] text-[#64748B] dark:text-slate-500 uppercase font-bold block">Ubicación</span>
                        <span class="text-slate-800 dark:text-slate-200">
                            {[order.customer?.city, order.customer?.department].filter(Boolean).join(', ') || 'Ciudad no especificada'}
                            {#if order.customer?.neighborhood}
                                <span class="text-slate-500 dark:text-slate-400">({order.customer.neighborhood})</span>
                            {/if}
                        </span>
                    </div>

                    <div class="sm:col-span-2 lg:col-span-3">
                        <span class="text-[10px] text-[#64748B] dark:text-slate-500 uppercase font-bold block">Dirección de Entrega</span>
                        <span class="text-slate-700 dark:text-slate-300 font-mono">{order.customer?.address || 'Sin dirección física registrada'}</span>
                    </div>
                </div>
            </div>

            <!-- DESGLOSE DE PRODUCTOS Y TAREAS DE PRODUCCIÓN -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-900 dark:text-white uppercase tracking-wider">
                        Productos en Producción ({getOrderTasks(order).length})
                    </span>
                    <span class="text-[11px] text-[#64748B] dark:text-slate-500">
                        Puedes cambiar la etapa operativa de cada producto directamente
                    </span>
                </div>

                <div class="space-y-2">
                    {#each getOrderTasks(order) as task (task.id)}
                        {@const wf = getTaskWorkflow(task)}
                        {@const stages = wf?.stages ?? []}
                        {@const currentStage = stages.find((s) => s.id === task.current_stage_id) ?? task.current_stage ?? task.currentStage}
                        {@const isHighlighted = highlightedTaskId && Number(task.id) === Number(highlightedTaskId)}
                        <div
                            class="p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border transition-all flex flex-col md:flex-row md:items-center justify-between gap-3 {isHighlighted ? 'border-[#4F46E5] ring-2 ring-[#4F46E5]/40 bg-indigo-50/50 dark:bg-sky-950/20 shadow-md' : 'border-slate-200 dark:border-[#26334D] hover:border-slate-300 dark:hover:border-slate-600'}"
                        >
                            <div class="space-y-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-slate-900 dark:text-white text-xs truncate">
                                        {task.item_name_snapshot || task.productVariant?.name_label || 'Producto'}
                                    </span>
                                    {#if task.productVariant?.product?.productType?.name}
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-semibold bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-400">
                                            {task.productVariant.product.productType.name}
                                        </span>
                                    {/if}
                                </div>

                                <div class="flex items-center gap-3 text-[11px] text-[#64748B] dark:text-slate-400 font-mono flex-wrap">
                                    <span>Cant: <strong class="text-slate-800 dark:text-slate-200">{Math.round(Number(task.quantity))} unidades</strong></span>
                                    <span>•</span>
                                    <span>Flujo: <strong class="text-slate-700 dark:text-slate-300">{wf?.name || 'Estándar'}</strong></span>
                                    <span>•</span>
                                    <span>Est.: <strong class="text-slate-700 dark:text-slate-300">{task.estimated_minutes} min</strong></span>
                                    {#if hasFinancialAccess && task.unit_price}
                                        <span>•</span>
                                        <span class="text-emerald-600 dark:text-emerald-400 font-semibold">{formatCurrency(task.unit_price)} c/u</span>
                                        {#if Number(task.discount_percentage) > 0}
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-700 dark:text-amber-300 border border-amber-500/30">
                                                -{Number(task.discount_percentage)}% desc.
                                            </span>
                                        {/if}
                                    {/if}
                                </div>
                            </div>

                            <!-- Selector Coloreado de Etapa del Flujo -->
                            <div class="flex items-center gap-2 shrink-0 self-end md:self-auto">
                                <span class="text-[11px] text-[#64748B] dark:text-slate-500 font-semibold">Etapa:</span>
                                <div
                                    class="flex items-center gap-1.5 px-2.5 py-1 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-sm"
                                    style="border-left: 4px solid {currentStage?.color || '#64748b'};"
                                >
                                    <label for="detail-task-stage-{task.id}" class="sr-only">Etapa de la tarea</label>
                                    <select
                                        id="detail-task-stage-{task.id}"
                                        value={task.current_stage_id}
                                        onchange={(e) => handleStageSelect(task, e)}
                                        class="bg-transparent text-slate-900 dark:text-white font-medium text-xs focus:outline-none cursor-pointer"
                                    >
                                        {#each stages as stg}
                                            <option value={stg.id} class="bg-white dark:bg-slate-900 text-slate-900 dark:text-white">
                                                {stg.name}
                                            </option>
                                        {/each}
                                    </select>
                                </div>
                            </div>
                        </div>
                    {/each}
                </div>
            </div>

            <!-- BLOQUE FINANCIERO, ABONOS Y COBROS PROGRAMADOS (SOLO VISIBLE CON PERMISOS) -->
            {#if hasFinancialAccess}
                <div class="p-4 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-slate-200 dark:border-[#26334D] space-y-3">
                    <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-2">
                        <span class="font-bold text-slate-800 dark:text-slate-300 uppercase tracking-wider text-[11px]">
                            Estado Financiero, Abonos y Cobros Programados
                        </span>
                    </div>

                    <OrderPaymentsManager
                        {order}
                        allowVoucher={true}
                        onUpdated={(payload) => onPaymentsUpdated(payload)}
                    />
                </div>
            {/if}

            <!-- NOTAS DEL PEDIDO -->
            {#if order.notes}
                <div class="p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-slate-200 dark:border-[#26334D] space-y-1">
                    <span class="text-[10px] text-[#64748B] dark:text-slate-500 uppercase font-bold block">Notas / Instrucciones Especiales</span>
                    <p class="text-xs text-slate-700 dark:text-slate-200">{order.notes}</p>
                </div>
            {/if}

            <!-- PIE DEL MODAL (ACCIONES) -->
            <div class="pt-4 border-t border-slate-200 dark:border-[#26334D] flex items-end justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2 flex-1 min-w-0">
                    {#if isOwnerOrAdmin}
                        <button
                            type="button"
                            onclick={() => {
                                closeModal();
                                onEditOrder(order);
                            }}
                            class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 hover:text-slate-900 dark:hover:text-white font-semibold text-xs border border-slate-200 dark:border-[#26334D] transition-colors flex items-center gap-1.5"
                        >
                            <span>✏️</span>
                            <span>Editar Pedido</span>
                        </button>
                    {/if}

                    {#if canGenerateShippingGuide}
                        <button
                            type="button"
                            onclick={() => (showShippingGuideModal = true)}
                            class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl border border-slate-300 dark:border-[#26334D] bg-white dark:bg-[#1E293B] hover:border-[#4F46E5] text-slate-800 dark:text-slate-100 text-xs font-semibold transition"
                            title="Generar e imprimir Guía de Envío para el pedido #{order.order_number}"
                        >
                            <span>🏷️</span>
                            <span>Guía de Envío</span>
                        </button>
                    {/if}

                    {#if !isIsolatedView && order}
                        <button
                            type="button"
                            onclick={() => {
                                const targetId = order.id;
                                closeModal();
                                if (onOpenIsolatedBoard) {
                                    onOpenIsolatedBoard(targetId);
                                } else {
                                    router.visit(`/kanban?order_id=${targetId}`);
                                }
                            }}
                            class="px-3.5 py-2 rounded-xl bg-indigo-50 hover:bg-indigo-100 text-[#4F46E5] dark:bg-sky-950/70 dark:hover:bg-sky-900/70 dark:text-sky-400 font-semibold text-xs border border-indigo-200 dark:border-sky-800/70 transition-colors flex items-center gap-1.5"
                            title="Ir al tablero exclusivo del pedido #{order.order_number}"
                        >
                            <span>📋</span>
                            <span>Ir al Tablero Exclusivo del Pedido</span>
                        </button>
                    {/if}
                </div>

                <button
                    type="button"
                    onclick={closeModal}
                    class="px-5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold text-xs transition shrink-0 ml-auto whitespace-nowrap"
                >
                    Cerrar
                </button>
            </div>
        </div>
    </div>
{/if}

<ShippingGuideModal
    open={showShippingGuideModal}
    customer={order?.customer ?? null}
    initialOrder={order}
    senderProfile={localSenderProfile ?? page.props.senderProfile ?? null}
    onClose={() => {
        showShippingGuideModal = false;
    }}
    onSenderProfileSaved={(updated) => {
        localSenderProfile = { ...updated };
    }}
/>
