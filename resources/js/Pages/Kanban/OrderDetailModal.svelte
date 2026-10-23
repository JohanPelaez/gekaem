<script>
    import { router } from '@inertiajs/svelte';
    import CurrencyInput from '../../Components/CurrencyInput.svelte';

    let {
        isOpen = $bindable(false),
        order = null,
        highlightedTaskId = null,
        orderStatuses = [],
        workflows = [],
        hasFinancialAccess = true,
        isIsolatedView = false,
        onStatusChange = () => {},
        onTaskStageChange = () => {},
        onEditOrder = () => {},
        onOpenIsolatedBoard = (ordId) => {
            router.visit(`/kanban?order_id=${ordId}`);
        },
        onClose = () => {},
    } = $props();

    // Estado del formulario rápido de abonos colapsable
    let showNewPaymentForm = $state(false);
    let paymentAmount = $state('');
    let paymentDate = $state(new Date().toISOString().slice(0, 10));
    let paymentMethod = $state('transfer');
    let paymentNotes = $state('');
    let paymentVoucher = $state(null);
    let isSavingPayment = $state(false);
    let paymentError = $state('');

    // Resetear formulario de abono cuando cambia el pedido
    $effect(() => {
        if (order) {
            paymentAmount = order.pending_balance > 0 ? Number(order.pending_balance) : '';
            paymentDate = new Date().toISOString().slice(0, 10);
            paymentMethod = 'transfer';
            paymentNotes = '';
            paymentVoucher = null;
            showNewPaymentForm = false;
            paymentError = '';
        }
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

    function handleVoucherChange(e) {
        paymentVoucher = e.target.files?.[0] ?? null;
    }

    function handlePaymentSubmit(e) {
        e.preventDefault();
        if (!order || !paymentAmount) return;

        paymentError = '';
        isSavingPayment = true;

        const formData = new FormData();
        formData.append('amount', String(paymentAmount));
        formData.append('payment_date', paymentDate);
        formData.append('payment_method', paymentMethod);
        formData.append('notes', paymentNotes ?? '');
        if (paymentVoucher) {
            formData.append('voucher', paymentVoucher);
        }

        router.post(`/orders/${order.id}/payments`, formData, {
            preserveScroll: true,
            onFinish: () => { isSavingPayment = false; },
            onSuccess: () => {
                showNewPaymentForm = false;
                paymentAmount = '';
                paymentNotes = '';
                paymentVoucher = null;
            },
            onError: (errs) => {
                paymentError = Object.values(errs).flat().join(' ');
            },
        });
    }

    function handleDeletePayment(payment) {
        if (confirm(`¿Eliminar este abono por ${formatCurrency(payment.amount)}?`)) {
            router.delete(`/payments/${payment.id}`, {
                preserveScroll: true,
            });
        }
    }

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
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-4xl w-full p-6 shadow-2xl space-y-5 max-h-[92vh] overflow-y-auto">
            <!-- CABECERA DEL PEDIDO -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-4">
                <div class="flex items-center gap-3">
                    <span class="px-2.5 py-1 rounded-xl font-mono font-bold text-sm bg-slate-950 text-emerald-400 border border-slate-800">
                        #{order.order_number}
                    </span>
                    <div>
                        <h2 class="text-lg font-bold text-white flex items-center gap-2">
                            <span>Pedido #{order.order_number}</span>
                            {#if order.shipping_type === 'contraentrega'}
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                    🚚 Contraentrega
                                </span>
                            {:else}
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-sky-500/10 text-sky-400 border border-sky-500/20">
                                    📦 Envío Incluido
                                </span>
                            {/if}
                        </h2>
                        <div class="flex items-center gap-2 mt-0.5 text-xs text-slate-400 font-mono">
                            <span>📅 Entrega: {formatDateTime(order.committed_delivery_at || order.predicted_delivery_at)}</span>
                            {#if deliveryBadge}
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold border {deliveryBadge.color}">
                                    {deliveryBadge.text}
                                </span>
                            {/if}
                        </div>
                    </div>
                </div>

                <!-- SELECTOR DE ESTADO DEL PEDIDO Y BOTÓN CERRAR -->
                <div class="flex items-center gap-2 self-end sm:self-auto">
                    <!-- Selector interactivo de Estado de Pedido -->
                    <div class="flex items-center gap-1.5 bg-slate-950 px-2.5 py-1.5 rounded-xl border border-slate-800">
                        <span
                            class="w-3 h-3 rounded-full shrink-0 shadow-sm"
                            style="background-color: {activeStatus?.color || '#64748b'};"
                        ></span>
                        <label for="detail-order-status" class="sr-only">Estado del Pedido</label>
                        <select
                            id="detail-order-status"
                            value={order.order_status_id || activeStatus?.id || ''}
                            onchange={handleStatusSelect}
                            class="bg-transparent text-white font-bold text-xs focus:outline-none cursor-pointer"
                        >
                            {#each orderStatuses as st}
                                <option value={st.id} class="bg-slate-900 text-white">
                                    {st.name}
                                </option>
                            {/each}
                        </select>
                    </div>

                    <button
                        type="button"
                        onclick={closeModal}
                        class="text-slate-400 hover:text-white p-1 rounded-lg"
                    >
                        ✕
                    </button>
                </div>
            </div>

            <!-- BLOQUE DE CLIENTE Y DESTINO -->
            <div class="p-4 bg-slate-950 rounded-2xl border border-slate-800 space-y-2 text-xs">
                <div class="flex items-center justify-between border-b border-slate-800/80 pb-2">
                    <span class="font-bold text-slate-300 uppercase tracking-wider text-[11px]">Información del Cliente y Destino</span>
                    {#if order.customer?.tax_id}
                        <span class="text-slate-500 font-mono text-[11px]">NIT / C.C.: {order.customer.tax_id}</span>
                    {/if}
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 pt-1">
                    <div>
                        <span class="text-[10px] text-slate-500 uppercase font-bold block">Nombre Completo</span>
                        <span class="text-white font-semibold text-sm">{order.customer?.name || 'Cliente no asignado'}</span>
                    </div>

                    <div>
                        <span class="text-[10px] text-slate-500 uppercase font-bold block">Contacto</span>
                        <div class="text-slate-300 flex items-center gap-2">
                            {#if order.customer?.phone}
                                <a href="tel:{order.customer.phone}" class="text-emerald-400 hover:underline">
                                    📞 {order.customer.phone}
                                </a>
                            {:else}
                                <span class="text-slate-500">Sin teléfono</span>
                            {/if}
                            {#if order.customer?.email}
                                <span class="text-slate-600">•</span>
                                <a href="mailto:{order.customer.email}" class="text-sky-400 hover:underline truncate">
                                    ✉️ {order.customer.email}
                                </a>
                            {/if}
                        </div>
                    </div>

                    <div>
                        <span class="text-[10px] text-slate-500 uppercase font-bold block">Ubicación</span>
                        <span class="text-slate-200">
                            {[order.customer?.city, order.customer?.department].filter(Boolean).join(', ') || 'Ciudad no especificada'}
                            {#if order.customer?.neighborhood}
                                <span class="text-slate-400">({order.customer.neighborhood})</span>
                            {/if}
                        </span>
                    </div>

                    <div class="sm:col-span-2 lg:col-span-3">
                        <span class="text-[10px] text-slate-500 uppercase font-bold block">Dirección de Entrega</span>
                        <span class="text-slate-300 font-mono">{order.customer?.address || 'Sin dirección física registrada'}</span>
                    </div>
                </div>
            </div>

            <!-- DESGLOSE DE PRODUCTOS Y TAREAS DE PRODUCCIÓN -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-white uppercase tracking-wider">
                        Productos en Producción ({getOrderTasks(order).length})
                    </span>
                    <span class="text-[11px] text-slate-500">
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
                            class="p-3 bg-slate-950 rounded-2xl border transition-all flex flex-col md:flex-row md:items-center justify-between gap-3 {isHighlighted ? 'border-sky-500 ring-2 ring-sky-500/50 bg-sky-950/20 shadow-md' : 'border-slate-800 hover:border-slate-700'}"
                        >
                            <div class="space-y-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-white text-xs truncate">
                                        {task.item_name_snapshot || task.productVariant?.name_label || 'Producto'}
                                    </span>
                                    {#if task.productVariant?.product?.productType?.name}
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold bg-slate-800 text-slate-400">
                                            {task.productVariant.product.productType.name}
                                        </span>
                                    {/if}
                                </div>

                                <div class="flex items-center gap-3 text-[11px] text-slate-400 font-mono flex-wrap">
                                    <span>Cant: <strong class="text-slate-200">{Number(task.quantity)}</strong></span>
                                    <span>•</span>
                                    <span>Flujo: <strong class="text-slate-300">{wf?.name || 'Estándar'}</strong></span>
                                    <span>•</span>
                                    <span>Est.: <strong class="text-slate-300">{task.estimated_minutes} min</strong></span>
                                    {#if hasFinancialAccess && task.unit_price}
                                        <span>•</span>
                                        <span class="text-emerald-400">{formatCurrency(task.unit_price)} c/u</span>
                                    {/if}
                                </div>
                            </div>

                            <!-- Selector Coloreado de Etapa del Flujo -->
                            <div class="flex items-center gap-2 shrink-0 self-end md:self-auto">
                                <span class="text-[11px] text-slate-500 font-semibold">Etapa:</span>
                                <div
                                    class="flex items-center gap-1.5 px-2.5 py-1 rounded-xl border border-slate-700/80 bg-slate-900"
                                    style="border-left: 4px solid {currentStage?.color || '#64748b'};"
                                >
                                    <label for="detail-task-stage-{task.id}" class="sr-only">Etapa de la tarea</label>
                                    <select
                                        id="detail-task-stage-{task.id}"
                                        value={task.current_stage_id}
                                        onchange={(e) => handleStageSelect(task, e)}
                                        class="bg-transparent text-white font-medium text-xs focus:outline-none cursor-pointer"
                                    >
                                        {#each stages as stg}
                                            <option value={stg.id} class="bg-slate-900 text-white">
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

            <!-- BLOQUE FINANCIERO Y ABONOS (SOLO VISIBLE CON PERMISOS) -->
            {#if hasFinancialAccess}
                <div class="p-4 bg-slate-950 rounded-2xl border border-slate-800 space-y-3">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                        <span class="font-bold text-slate-300 uppercase tracking-wider text-[11px]">Estado Financiero del Pedido</span>
                        <button
                            type="button"
                            onclick={() => showNewPaymentForm = !showNewPaymentForm}
                            class="px-2.5 py-1 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-400 font-semibold text-xs border border-emerald-500/30 transition-colors flex items-center gap-1"
                        >
                            <span>{showNewPaymentForm ? '✕ Cancelar' : '+ Registrar Abono'}</span>
                        </button>
                    </div>

                    <!-- Resumen de Saldos -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="p-3 bg-slate-900 rounded-xl border border-slate-800">
                            <span class="text-[10px] text-slate-400 uppercase font-bold block mb-0.5">Total del Pedido</span>
                            <span class="font-mono font-bold text-white text-base">{formatCurrency(order.total_amount)}</span>
                        </div>

                        <div class="p-3 bg-slate-900 rounded-xl border border-slate-800">
                            <span class="text-[10px] text-slate-400 uppercase font-bold block mb-0.5">Total Abonado</span>
                            <span class="font-mono font-bold text-emerald-400 text-base">{formatCurrency(order.paid_amount)}</span>
                        </div>

                        <div class="p-3 bg-slate-900 rounded-xl border border-slate-800">
                            <span class="text-[10px] text-slate-400 uppercase font-bold block mb-0.5">Saldo Pendiente</span>
                            <span class="font-mono font-bold text-base {order.pending_balance > 0 ? 'text-amber-400' : 'text-emerald-400'}">
                                {order.pending_balance > 0 ? formatCurrency(order.pending_balance) : '✓ Pagado ($0)'}
                            </span>
                        </div>
                    </div>

                    <!-- Formulario Rápido Colapsable de Nuevo Abono -->
                    {#if showNewPaymentForm}
                        <form onsubmit={handlePaymentSubmit} class="p-3.5 bg-slate-900 rounded-xl border border-emerald-500/30 space-y-3 animate-in fade-in duration-150">
                            <span class="text-xs font-bold text-emerald-400 block">Nuevo Abono a Pedido #{order.order_number}</span>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label for="detail-pay-amount" class="block text-[11px] font-semibold text-slate-300 mb-1">Monto ($) *</label>
                                    <CurrencyInput bind:value={paymentAmount} id="detail-pay-amount" required placeholder="0" />
                                </div>

                                <div>
                                    <label for="detail-pay-date" class="block text-[11px] font-semibold text-slate-300 mb-1">Fecha de Pago *</label>
                                    <input
                                        id="detail-pay-date"
                                        type="date"
                                        bind:value={paymentDate}
                                        required
                                        class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-emerald-500 font-mono"
                                    />
                                </div>

                                <div>
                                    <label for="detail-pay-method" class="block text-[11px] font-semibold text-slate-300 mb-1">Método de Pago *</label>
                                    <select
                                        id="detail-pay-method"
                                        bind:value={paymentMethod}
                                        class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-emerald-500"
                                    >
                                        <option value="transfer">Transferencia / Nequi / Daviplata</option>
                                        <option value="cash">Efectivo</option>
                                        <option value="card">Tarjeta</option>
                                        <option value="other">Otro medio</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label for="detail-pay-notes" class="block text-[11px] font-semibold text-slate-300 mb-1">Notas / Referencia</label>
                                    <input
                                        id="detail-pay-notes"
                                        type="text"
                                        bind:value={paymentNotes}
                                        placeholder="Ej. Aprobación #1234..."
                                        class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-emerald-500"
                                    />
                                </div>

                                <div>
                                    <label for="detail-pay-voucher" class="block text-[11px] font-semibold text-slate-300 mb-1">Comprobante de Pago (Foto o PDF)</label>
                                    <input
                                        id="detail-pay-voucher"
                                        type="file"
                                        accept="image/*,.pdf"
                                        onchange={handleVoucherChange}
                                        class="w-full text-slate-400 text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-800 file:text-slate-200 hover:file:bg-slate-700"
                                    />
                                </div>
                            </div>

                            {#if paymentError}
                                <p class="text-[11px] text-rose-400">{paymentError}</p>
                            {/if}

                            <div class="flex justify-end gap-2 pt-1">
                                <button
                                    type="button"
                                    onclick={() => showNewPaymentForm = false}
                                    class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-semibold"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={isSavingPayment || !paymentAmount}
                                    class="px-4 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-lg text-xs shadow-md disabled:opacity-50"
                                >
                                    {isSavingPayment ? 'Guardando Abono...' : 'Guardar Abono'}
                                </button>
                            </div>
                        </form>
                    {/if}

                    <!-- Historial de Abonos -->
                    <div class="space-y-1.5 pt-1">
                        <span class="text-[11px] font-bold text-slate-400 block">Historial de Pagos</span>
                        {#if !order.payments || order.payments.length === 0}
                            <div class="p-3 text-center border border-dashed border-slate-800 rounded-xl text-slate-500 text-xs">
                                No se han registrado abonos previos en este pedido.
                            </div>
                        {:else}
                            <div class="space-y-1.5">
                                {#each order.payments as pay}
                                    <div class="p-2.5 bg-slate-900 rounded-xl border border-slate-800 flex items-center justify-between text-xs">
                                        <div class="flex items-center gap-3">
                                            <span class="font-mono font-bold text-emerald-400">
                                                {formatCurrency(pay.amount)}
                                            </span>
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold uppercase tracking-wider bg-slate-800 text-slate-300">
                                                {pay.payment_method}
                                            </span>
                                            <span class="text-slate-400 font-mono text-[11px]">
                                                📅 {pay.payment_date}
                                            </span>
                                            {#if pay.notes}
                                                <span class="text-slate-500 truncate max-w-xs">• "{pay.notes}"</span>
                                            {/if}
                                        </div>

                                        <div class="flex items-center gap-2">
                                            {#if pay.voucher_path}
                                                <a
                                                    href={`/storage/${pay.voucher_path}`}
                                                    target="_blank"
                                                    class="px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 text-sky-400 text-[11px] font-semibold border border-slate-700 flex items-center gap-1"
                                                >
                                                    <span>📎</span>
                                                    <span>Recibo</span>
                                                </a>
                                            {/if}
                                            <button
                                                type="button"
                                                onclick={() => handleDeletePayment(pay)}
                                                class="text-slate-500 hover:text-rose-400 p-1"
                                                title="Eliminar abono"
                                            >
                                                🗑
                                            </button>
                                        </div>
                                    </div>
                                {/each}
                            </div>
                        {/if}
                    </div>
                </div>
            {/if}

            <!-- NOTAS DEL PEDIDO -->
            {#if order.notes}
                <div class="p-3 bg-slate-950 rounded-2xl border border-slate-800 space-y-1">
                    <span class="text-[10px] text-slate-500 uppercase font-bold block">Notas / Instrucciones Especiales</span>
                    <p class="text-xs text-slate-200">{order.notes}</p>
                </div>
            {/if}

            <!-- PIE DEL MODAL (ACCIONES) -->
            <div class="pt-3 border-t border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        onclick={() => {
                            closeModal();
                            onEditOrder(order);
                        }}
                        class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white font-semibold text-xs border border-slate-700 transition-colors flex items-center gap-1.5"
                    >
                        <span>✏️</span>
                        <span>Editar Pedido</span>
                    </button>

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
                            class="px-3.5 py-2 rounded-xl bg-sky-950/70 hover:bg-sky-900/70 text-sky-400 font-semibold text-xs border border-sky-800/70 transition-colors flex items-center gap-1.5"
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
                    class="px-5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white font-semibold text-xs"
                >
                    Cerrar
                </button>
            </div>
        </div>
    </div>
{/if}
