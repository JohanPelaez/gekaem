<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let {
        orders,
        customers = [],
        products = [],
        wipQueueMinutes = 0,
        dailyProductiveMinutes = 480,
        defaultBufferPercent = 20,
        filters = { search: '', status: '' },
        errors = {},
    } = $props();

    let search = $state('');
    let statusFilter = $state('');
    $effect(() => {
        search = filters.search ?? '';
        statusFilter = filters.status ?? '';
    });

    let showCreateModal = $state(false);
    let processing = $state(false);

    // Modal de Abonos
    let showPaymentModal = $state(false);
    let selectedOrderForPayment = $state(null);
    let paymentProcessing = $state(false);

    // Formulario de Abono
    let paymentForm = $state({
        amount: '',
        payment_date: '',
        payment_method: 'transfer',
        notes: '',
        voucher: null,
    });

    // Formulario de Creación de Pedido (Simplificado)
    let orderForm = $state({
        customer_id: '',
        committed_delivery_at: '',
        notes: '',
        items: [],
        has_initial_deposit: false,
        initial_deposit: '',
        payment_method: 'transfer',
        voucher: null,
    });

    let searchTimeout;
    function handleSearchInput() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            applyFilters();
        }, 300);
    }

    function applyFilters() {
        router.get(
            '/orders',
            { search, status: statusFilter },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function setStatus(st) {
        statusFilter = st;
        applyFilters();
    }

    function getTomorrowDateTimeLocal() {
        const d = new Date();
        d.setDate(d.getDate() + 2);
        d.setHours(17, 0, 0, 0);
        return d.toISOString().slice(0, 16);
    }

    function resetOrderForm() {
        orderForm.customer_id = customers[0]?.id ?? '';
        orderForm.committed_delivery_at = getTomorrowDateTimeLocal();
        orderForm.notes = '';
        orderForm.items = [];
        orderForm.has_initial_deposit = false;
        orderForm.initial_deposit = '';
        orderForm.payment_method = 'transfer';
        orderForm.voucher = null;
    }

    function openCreate() {
        resetOrderForm();
        if (products.length > 0) {
            addItem();
        }
        showCreateModal = true;
    }

    function addItem() {
        if (products.length === 0) return;
        const firstProd = products[0];
        const firstVariant = firstProd.variants?.[0] ?? null;

        orderForm.items = [
            ...orderForm.items,
            {
                product_id: firstProd.id,
                product_variant_id: firstVariant?.id ?? '',
                quantity: 1,
            },
        ];
    }

    function removeItem(index) {
        orderForm.items = orderForm.items.filter((_, i) => i !== index);
    }

    function onProductChange(item, productId) {
        item.product_id = Number(productId);
        const prod = products.find((p) => p.id === Number(productId));
        item.product_variant_id = prod?.variants?.[0]?.id ?? '';
    }

    function onInitialVoucherChange(e) {
        const file = e.target.files?.[0] ?? null;
        orderForm.voucher = file;
    }

    function onPaymentVoucherChange(e) {
        const file = e.target.files?.[0] ?? null;
        paymentForm.voucher = file;
    }

    // Cálculos reactivos con Runes de Svelte 5
    let totalOrderMinutes = $derived.by(() => {
        let total = 0;
        for (const item of orderForm.items) {
            if (!item.product_variant_id || !item.quantity) continue;
            const prod = products.find((p) => p.id == item.product_id);
            const variant = prod?.variants?.find((v) => v.id == item.product_variant_id);
            if (prod && variant) {
                const unitMins = Number(prod.base_production_minutes ?? 0) + Number(variant.production_minutes_delta ?? 0);
                total += Math.ceil(unitMins * Number(item.quantity));
            }
        }
        return total;
    });

    let totalAmount = $derived.by(() => {
        let sum = 0;
        for (const item of orderForm.items) {
            if (!item.product_variant_id || !item.quantity) continue;
            const prod = products.find((p) => p.id == item.product_id);
            const variant = prod?.variants?.find((v) => v.id == item.product_variant_id);
            if (prod && variant) {
                const price = variant.price_override !== null && variant.price_override !== ''
                    ? Number(variant.price_override)
                    : Number(prod.base_price ?? 0);
                sum += price * Number(item.quantity);
            }
        }
        return sum;
    });

    let initialDepositNumeric = $derived(
        orderForm.has_initial_deposit ? Number(orderForm.initial_deposit || 0) : 0
    );

    let initialPendingBalance = $derived(
        Math.max(0, totalAmount - initialDepositNumeric)
    );

    function formatMinutes(mins) {
        if (!mins || mins <= 0) return '0 min';
        const days = Math.floor(mins / dailyProductiveMinutes);
        const rem = mins % dailyProductiveMinutes;
        const hours = Math.floor(rem / 60);
        const m = rem % 60;
        const parts = [];
        if (days > 0) parts.push(`${days}d`);
        if (hours > 0) parts.push(`${hours}h`);
        if (m > 0 || parts.length === 0) parts.push(`${m}m`);
        return parts.join(' ');
    }

    function formatDate(dateStr) {
        if (!dateStr) return 'Sin fecha';
        const d = new Date(dateStr);
        return d.toLocaleDateString('es-CO', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function formatDateShort(dateStr) {
        if (!dateStr) return 'Sin fecha';
        const d = new Date(dateStr);
        return d.toLocaleDateString('es-CO', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    }

    function getDerivedStatusBadge(status) {
        switch (status) {
            case 'pending':
                return { label: 'En Espera', color: 'bg-amber-500/20 text-amber-300 border-amber-500/30' };
            case 'in_progress':
                return { label: 'En Producción', color: 'bg-sky-500/20 text-sky-300 border-sky-500/30' };
            case 'blocked':
                return { label: 'Bloqueado', color: 'bg-rose-500/20 text-rose-300 border-rose-500/30' };
            case 'ready':
                return { label: 'Listo para Entrega', color: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' };
            case 'delivered':
                return { label: 'Entregado', color: 'bg-purple-500/20 text-purple-300 border-purple-500/30' };
            default:
                return { label: status, color: 'bg-slate-500/20 text-slate-300 border-slate-500/30' };
        }
    }

    function getPaymentMethodLabel(method) {
        switch (method) {
            case 'cash': return 'Efectivo';
            case 'transfer': return 'Transferencia / Nequi / Daviplata';
            case 'card': return 'Tarjeta';
            case 'other': return 'Otro medio';
            default: return method;
        }
    }

    function submitOrder(e) {
        e.preventDefault();
        const validItems = orderForm.items.filter((i) => i.product_variant_id && Number(i.quantity) > 0);
        if (validItems.length === 0) {
            alert('Debes agregar al menos un producto con variante y cantidad válida al pedido.');
            return;
        }

        processing = true;
        router.post('/orders', orderForm, {
            preserveScroll: true,
            forceFormData: true,
            onStart: () => { processing = true; },
            onFinish: () => { processing = false; },
            onSuccess: () => {
                showCreateModal = false;
                resetOrderForm();
            },
        });
    }

    function openPaymentModal(order) {
        selectedOrderForPayment = order;
        paymentForm = {
            amount: Number(order.pending_balance) > 0 ? Number(order.pending_balance) : '',
            payment_date: new Date().toISOString().slice(0, 10),
            payment_method: 'transfer',
            notes: '',
            voucher: null,
        };
        showPaymentModal = true;
    }

    function submitPayment(e) {
        e.preventDefault();
        if (!selectedOrderForPayment) return;

        paymentProcessing = true;
        router.post(`/orders/${selectedOrderForPayment.id}/payments`, paymentForm, {
            preserveScroll: true,
            forceFormData: true,
            onStart: () => { paymentProcessing = true; },
            onFinish: () => { paymentProcessing = false; },
            onSuccess: () => {
                showPaymentModal = false;
            },
        });
    }

    function deletePayment(payment) {
        if (confirm(`¿Anular el abono de $${Number(payment.amount).toFixed(2)}?`)) {
            router.delete(`/payments/${payment.id}`, {
                preserveScroll: true,
                onSuccess: () => {
                    showPaymentModal = false;
                },
            });
        }
    }

    function markDelivered(order) {
        if (confirm(`¿Marcar el pedido #${order.order_number} como entregado al cliente?`)) {
            router.patch(`/orders/${order.id}/deliver`, {}, {
                preserveScroll: true,
            });
        }
    }
</script>

<AppLayout title="Gestión de Pedidos y Finanzas">
    <div class="space-y-6">
        <!-- ENCABEZADO Y ACCIONES -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-3xl shadow-xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Finanzas & Producción
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Pedidos, Abonos y Entregas</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Controla pedidos con fecha de entrega, registro de abonos y saldo pendiente en tiempo real.
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto">
                <Link
                    href="/kanban"
                    class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold text-xs transition-colors border border-slate-700 flex items-center gap-1.5"
                >
                    <span>Tablero Kanban</span>
                    <span>→</span>
                </Link>
                <button
                    type="button"
                    onclick={openCreate}
                    class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-all shadow-lg shadow-emerald-600/25 flex items-center gap-2"
                >
                    <span>+</span>
                    <span>Nuevo Pedido</span>
                </button>
            </div>
        </div>

        <!-- KPI SUMMARY CARDS -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl">
                <span class="text-[11px] font-medium text-slate-400 uppercase tracking-wider block">Cola en Producción</span>
                <div class="text-xl font-mono font-bold text-amber-300 mt-1">
                    ⏱ {formatMinutes(wipQueueMinutes)}
                </div>
                <span class="text-[10px] text-slate-500 mt-0.5 block">Taller: {dailyProductiveMinutes} min/día</span>
            </div>

            <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl">
                <span class="text-[11px] font-medium text-slate-400 uppercase tracking-wider block">Pedidos Totales</span>
                <div class="text-xl font-mono font-bold text-white mt-1">
                    {orders.total ?? 0}
                </div>
                <span class="text-[10px] text-slate-500 mt-0.5 block">Registrados en historial</span>
            </div>

            <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl">
                <span class="text-[11px] font-medium text-slate-400 uppercase tracking-wider block">Control de Holgura</span>
                <div class="text-xl font-mono font-bold text-emerald-400 mt-1">
                    +{defaultBufferPercent}%
                </div>
                <span class="text-[10px] text-slate-500 mt-0.5 block">Margen del taller</span>
            </div>

            <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl">
                <span class="text-[11px] font-medium text-slate-400 uppercase tracking-wider block">Clientes Activos</span>
                <div class="text-xl font-mono font-bold text-sky-400 mt-1">
                    {customers.length}
                </div>
                <span class="text-[10px] text-slate-500 mt-0.5 block">Directorio registrado</span>
            </div>
        </div>

        <!-- BARRA DE FILTROS Y BUSCADOR -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 bg-slate-900 border border-slate-800 p-3.5 rounded-2xl">
            <!-- Pestañas de estado -->
            <div class="flex flex-wrap items-center gap-1 text-xs">
                <button
                    type="button"
                    onclick={() => setStatus('')}
                    class="px-3 py-1.5 rounded-xl font-medium transition-colors {!statusFilter ? 'bg-emerald-600 text-white font-bold' : 'text-slate-400 hover:text-white'}"
                >
                    Todos
                </button>
                <button
                    type="button"
                    onclick={() => setStatus('pending')}
                    class="px-3 py-1.5 rounded-xl font-medium transition-colors {statusFilter === 'pending' ? 'bg-amber-600 text-white font-bold' : 'text-slate-400 hover:text-white'}"
                >
                    En Espera
                </button>
                <button
                    type="button"
                    onclick={() => setStatus('in_progress')}
                    class="px-3 py-1.5 rounded-xl font-medium transition-colors {statusFilter === 'in_progress' ? 'bg-sky-600 text-white font-bold' : 'text-slate-400 hover:text-white'}"
                >
                    En Producción
                </button>
                <button
                    type="button"
                    onclick={() => setStatus('blocked')}
                    class="px-3 py-1.5 rounded-xl font-medium transition-colors {statusFilter === 'blocked' ? 'bg-rose-600 text-white font-bold' : 'text-slate-400 hover:text-white'}"
                >
                    Bloqueados
                </button>
                <button
                    type="button"
                    onclick={() => setStatus('ready')}
                    class="px-3 py-1.5 rounded-xl font-medium transition-colors {statusFilter === 'ready' ? 'bg-emerald-600 text-white font-bold' : 'text-slate-400 hover:text-white'}"
                >
                    Listos
                </button>
                <button
                    type="button"
                    onclick={() => setStatus('delivered')}
                    class="px-3 py-1.5 rounded-xl font-medium transition-colors {statusFilter === 'delivered' ? 'bg-purple-600 text-white font-bold' : 'text-slate-400 hover:text-white'}"
                >
                    Entregados
                </button>
            </div>

            <!-- Buscador rápido -->
            <div class="flex items-center gap-2 bg-slate-950 border border-slate-800 px-3 py-1.5 rounded-xl w-full md:w-72">
                <span class="text-slate-500 text-xs">🔍</span>
                <input
                    type="text"
                    bind:value={search}
                    oninput={handleSearchInput}
                    placeholder="Buscar por # o cliente..."
                    class="w-full bg-transparent text-white placeholder-slate-500 text-xs focus:outline-none"
                />
                {#if search}
                    <button type="button" onclick={() => { search = ''; applyFilters(); }} class="text-slate-500 text-xs hover:text-white">✕</button>
                {/if}
            </div>
        </div>

        <!-- LISTA DE PEDIDOS -->
        <div class="space-y-4">
            {#each orders.data as order (order.id)}
                {@const badge = getDerivedStatusBadge(order.derived_status)}
                {@const tasksTotal = order.tasks_count ?? 0}
                {@const tasksCompleted = order.completed_tasks_count ?? 0}
                {@const progressPercent = tasksTotal > 0 ? Math.round((tasksCompleted / tasksTotal) * 100) : 0}

                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-5 shadow-md hover:border-slate-700 transition-colors space-y-4">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <!-- Identificación del Pedido -->
                        <div class="space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="px-2.5 py-1 rounded-xl bg-slate-950 font-mono font-bold text-sm text-emerald-400 border border-slate-800">
                                    #{order.order_number}
                                </span>
                                <h3 class="font-bold text-white text-base">
                                    {order.customer?.name ?? 'Cliente'}
                                </h3>
                                <span class="text-[10px] px-2.5 py-0.5 rounded-full font-bold uppercase tracking-wider border {badge.color}">
                                    {badge.label}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-xs text-slate-400">
                                {#if order.customer?.phone}
                                    <span>📞 {order.customer.phone}</span>
                                {/if}
                                {#if order.notes}
                                    <span class="italic max-w-sm truncate text-slate-500">"{order.notes}"</span>
                                {/if}
                                <span class="font-mono text-white font-bold">
                                    Total: ${Number(order.total_amount).toFixed(2)}
                                </span>
                            </div>
                        </div>

                        <!-- BADGES DE ESTADO FINANCIERO (PUNTOS 12 Y 13) -->
                        <div class="flex flex-wrap items-center gap-2">
                            {#if order.payment_status === 'paid'}
                                <span class="px-3 py-1.5 rounded-xl font-bold text-xs bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1.5">
                                    <span>✓</span>
                                    <span>Pagado Completo (${Number(order.total_amount).toFixed(2)})</span>
                                </span>
                            {:else if order.payment_status === 'partial'}
                                <span class="px-3 py-1.5 rounded-xl font-bold text-xs bg-amber-500/20 text-amber-300 border border-amber-500/40 flex items-center gap-1.5">
                                    <span>⏳</span>
                                    <span>Abonado: ${Number(order.paid_amount).toFixed(2)} / Saldo: ${Number(order.pending_balance).toFixed(2)}</span>
                                </span>
                            {:else}
                                <span class="px-3 py-1.5 rounded-xl font-bold text-xs bg-rose-500/20 text-rose-300 border border-rose-500/40 flex items-center gap-1.5">
                                    <span>⚠️</span>
                                    <span>Sin Abono (Saldo: ${Number(order.total_amount).toFixed(2)})</span>
                                </span>
                            {/if}

                            <button
                                type="button"
                                onclick={() => openPaymentModal(order)}
                                class="px-3 py-1.5 rounded-xl bg-slate-950 hover:bg-slate-800 text-emerald-400 font-semibold text-xs border border-emerald-500/30 flex items-center gap-1.5 transition-colors"
                            >
                                <span>💳</span>
                                <span>{order.pending_balance > 0 ? 'Registrar Abono' : 'Ver Abonos'}</span>
                                {#if order.payments && order.payments.length > 0}
                                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-slate-800 text-slate-300 font-mono">
                                        {order.payments.length}
                                    </span>
                                {/if}
                            </button>
                        </div>

                        <!-- Fecha de Entrega y Progreso -->
                        <div class="flex flex-col sm:flex-row lg:flex-col gap-2 w-full lg:w-60">
                            <div class="p-2.5 bg-slate-950 rounded-xl border border-slate-800 text-xs">
                                <span class="text-[10px] text-slate-400 uppercase font-bold block mb-0.5">Entrega Comprometida</span>
                                <span class="font-mono font-bold text-white text-xs block">
                                    📅 {formatDate(order.committed_delivery_at || order.predicted_delivery_at)}
                                </span>
                            </div>

                            <div class="space-y-1">
                                <div class="flex justify-between items-center text-[10px] text-slate-400 font-mono">
                                    <span>Taller: {tasksCompleted}/{tasksTotal} tareas</span>
                                    <span>{progressPercent}%</span>
                                </div>
                                <div class="w-full h-1.5 rounded-full bg-slate-950 border border-slate-800 overflow-hidden">
                                    <div
                                        class="h-full rounded-full transition-all duration-500 {progressPercent === 100 ? 'bg-emerald-500' : 'bg-sky-500'}"
                                        style="width: {progressPercent}%"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="flex items-center gap-2 self-end lg:self-center shrink-0">
                            <Link
                                href="/kanban"
                                class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 transition-colors"
                            >
                                Kanban ➔
                            </Link>

                            {#if order.derived_status === 'ready'}
                                <button
                                    type="button"
                                    onclick={() => markDelivered(order)}
                                    class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold shadow-lg shadow-emerald-600/20 transition-all flex items-center gap-1"
                                >
                                    <span>✓</span>
                                    <span>Entregar</span>
                                </button>
                            {/if}
                        </div>
                    </div>

                    <!-- Desglose de Productos / Tareas del Pedido -->
                    {#if order.production_tasks && order.production_tasks.length > 0}
                        <div class="pt-3 border-t border-slate-800 flex flex-wrap gap-2 items-center">
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Productos:</span>
                            {#each order.production_tasks as task}
                                <span class="inline-flex items-center gap-1 text-[11px] px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-800 text-slate-300 font-mono">
                                    <span class="font-bold text-white">{task.quantity}x</span>
                                    <span>{task.item_name_snapshot}</span>
                                    {#if task.current_stage}
                                        <span class="px-1.5 py-0.2 rounded text-[9px] font-bold" style="color: {task.current_stage.color}">
                                            [{task.current_stage.name}]
                                        </span>
                                    {/if}
                                </span>
                            {/each}
                        </div>
                    {/if}
                </div>
            {:else}
                <div class="p-12 text-center bg-slate-900 border border-slate-800 rounded-3xl text-slate-500 text-xs">
                    No se encontraron pedidos registrados con los filtros seleccionados.
                </div>
            {/each}
        </div>

        <!-- PAGINACIÓN -->
        {#if orders.links && orders.links.length > 3}
            <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl flex items-center justify-between text-xs text-slate-400">
                <div>Mostrando {orders.from ?? 0} a {orders.to ?? 0} de {orders.total} pedidos</div>
                <div class="flex items-center gap-1">
                    {#each orders.links as link}
                        {#if link.url}
                            <Link
                                href={link.url}
                                class="px-3 py-1.5 rounded-lg border text-xs font-medium transition-colors {link.active ? 'bg-emerald-600 text-white border-emerald-600 font-bold' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'}"
                            >
                                {@html link.label}
                            </Link>
                        {/if}
                    {/each}
                </div>
            </div>
        {/if}
    </div>
</AppLayout>

<!-- MODAL CREAR PEDIDO (SIMPLIFICADO - PUNTO 14) -->
{#if showCreateModal}
    <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-base font-bold text-white">Registrar Nuevo Pedido</h3>
                    <p class="text-[11px] text-slate-400">Genera las tarjetas de producción en el Tablero Kanban.</p>
                </div>
                <button type="button" onclick={() => showCreateModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitOrder} class="space-y-4 text-xs">
                <!-- CLIENTE Y FECHA DE ENTREGA COMPROMETIDA DIRECTA -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="ord-customer" class="block font-semibold text-slate-300 mb-1">Cliente *</label>
                        <select
                            id="ord-customer"
                            bind:value={orderForm.customer_id}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        >
                            <option value="">-- Selecciona un Cliente --</option>
                            {#each customers as c}
                                <option value={c.id}>{c.name} {c.phone ? `(${c.phone})` : ''}</option>
                            {/each}
                        </select>
                        {#if errors.customer_id}
                            <p class="text-[10px] text-rose-400 mt-1">{errors.customer_id}</p>
                        {/if}
                    </div>

                    <div>
                        <label for="ord-committed" class="block font-semibold text-slate-300 mb-1">
                            Fecha y Hora de Entrega Comprometida *
                        </label>
                        <input
                            id="ord-committed"
                            type="datetime-local"
                            bind:value={orderForm.committed_delivery_at}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono font-bold"
                        />
                    </div>
                </div>

                <!-- RENGLONES DE PRODUCTOS -->
                <div class="space-y-3 pt-2 border-t border-slate-800">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-xs">Productos del Pedido</span>
                            <p class="text-[11px] text-slate-500">Agrega las variantes y cantidades solicitadas.</p>
                        </div>
                        <button
                            type="button"
                            onclick={addItem}
                            disabled={products.length === 0}
                            class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs border border-slate-700 flex items-center gap-1.5 disabled:opacity-50"
                        >
                            <span>+</span>
                            <span>Agregar Producto</span>
                        </button>
                    </div>

                    {#if orderForm.items.length === 0}
                        <div class="p-6 text-center border-2 border-dashed border-slate-800 rounded-xl text-slate-500 text-xs">
                            Agrega al menos un producto al pedido.
                        </div>
                    {:else}
                        <div class="space-y-2">
                            {#each orderForm.items as item, idx}
                                {@const selectedProd = products.find((p) => p.id == item.product_id)}
                                {@const selectedVariant = selectedProd?.variants?.find((v) => v.id == item.product_variant_id)}
                                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 flex flex-col sm:flex-row sm:items-center gap-2.5">
                                    <!-- Selector de Producto -->
                                    <div class="flex-1">
                                        <label for="prod-sel-{idx}" class="text-[10px] text-slate-400 uppercase font-bold block mb-1">Producto</label>
                                        <select
                                            id="prod-sel-{idx}"
                                            bind:value={item.product_id}
                                            onchange={(e) => onProductChange(item, e.target.value)}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs"
                                        >
                                            {#each products as p}
                                                <option value={p.id}>{p.name}</option>
                                            {/each}
                                        </select>
                                    </div>

                                    <!-- Selector de Variante -->
                                    <div class="flex-1">
                                        <label for="var-sel-{idx}" class="text-[10px] text-slate-400 uppercase font-bold block mb-1">Variante</label>
                                        <select
                                            id="var-sel-{idx}"
                                            bind:value={item.product_variant_id}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs font-mono"
                                        >
                                            {#each selectedProd?.variants ?? [] as v}
                                                <option value={v.id}>
                                                    {v.name_label} {v.price_override ? `($${Number(v.price_override)})` : ''}
                                                </option>
                                            {/each}
                                        </select>
                                    </div>

                                    <!-- Cantidad -->
                                    <div class="w-24">
                                        <label for="qty-sel-{idx}" class="text-[10px] text-slate-400 uppercase font-bold block mb-1">Cantidad</label>
                                        <input
                                            id="qty-sel-{idx}"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            bind:value={item.quantity}
                                            required
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs font-mono font-bold"
                                        />
                                    </div>

                                    <!-- Botón Eliminar Renglón -->
                                    <button
                                        type="button"
                                        onclick={() => removeItem(idx)}
                                        class="p-2 text-slate-500 hover:text-rose-400 self-end sm:self-center"
                                        title="Quitar producto"
                                    >
                                        ✕
                                    </button>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>

                <!-- NOTAS -->
                <div>
                    <label for="ord-notes" class="block font-semibold text-slate-300 mb-1">Notas / Instrucciones Especiales</label>
                    <input
                        id="ord-notes"
                        type="text"
                        bind:value={orderForm.notes}
                        placeholder="Ej. Entregar en portería, medidas especiales..."
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                </div>

                <!-- SECCIÓN DE ABONO INICIAL (CHECKBOX COLAPSABLE - PUNTO 14) -->
                <div class="pt-3 border-t border-slate-800 space-y-3">
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input
                            type="checkbox"
                            bind:checked={orderForm.has_initial_deposit}
                            class="rounded bg-slate-950 border-slate-800 text-emerald-500 focus:ring-0"
                        />
                        <span class="font-bold text-white text-xs">Registrar Abono Inicial en este Pedido</span>
                    </label>

                    {#if orderForm.has_initial_deposit}
                        <div class="p-4 bg-slate-950 rounded-2xl border border-emerald-500/30 space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label for="ord-deposit" class="block font-semibold text-slate-300 mb-1">Monto del Abono ($) *</label>
                                    <input
                                        id="ord-deposit"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        max={totalAmount}
                                        bind:value={orderForm.initial_deposit}
                                        required
                                        placeholder="0.00"
                                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-emerald-400 font-mono font-bold text-sm focus:outline-none focus:border-emerald-500"
                                    />
                                </div>

                                <div>
                                    <label for="ord-method" class="block font-semibold text-slate-300 mb-1">Método de Pago *</label>
                                    <select
                                        id="ord-method"
                                        bind:value={orderForm.payment_method}
                                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-emerald-500"
                                    >
                                        <option value="transfer">Transferencia / Nequi / Daviplata</option>
                                        <option value="cash">Efectivo</option>
                                        <option value="card">Tarjeta</option>
                                        <option value="other">Otro medio</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label for="ord-voucher" class="block font-semibold text-slate-300 mb-1">
                                    Comprobante o Foto del Pago (Opcional, máx 5MB)
                                </label>
                                <input
                                    id="ord-voucher"
                                    type="file"
                                    accept="image/*,.pdf"
                                    onchange={onInitialVoucherChange}
                                    class="w-full text-slate-400 text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-800 file:text-slate-200 hover:file:bg-slate-700"
                                />
                            </div>

                            <!-- Saldo calculado -->
                            <div class="flex items-center justify-between text-xs pt-1 text-slate-400">
                                <span>Saldo pendiente estimado:</span>
                                <span class="font-mono font-bold text-amber-300">${initialPendingBalance.toFixed(2)}</span>
                            </div>
                        </div>
                    {/if}
                </div>

                <!-- TOTAL Y BOTONES -->
                <div class="flex items-center justify-between pt-3 border-t border-slate-800">
                    <div class="text-xs">
                        <span class="text-slate-400">Total del Pedido: </span>
                        <span class="font-mono font-bold text-emerald-400 text-base">${totalAmount.toFixed(2)}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            onclick={() => showCreateModal = false}
                            class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                        >
                            {processing ? 'Guardando...' : 'Crear Pedido'}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL REGISTRAR ABONO Y VER HISTORIAL DE PAGOS (PUNTOS 12 Y 13) -->
{#if showPaymentModal && selectedOrderForPayment}
    <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-lg w-full p-6 shadow-2xl space-y-5 max-h-[92vh] overflow-y-auto">
            <!-- Encabezado con métricas financieras del pedido -->
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded font-mono font-bold text-xs bg-slate-950 text-emerald-400 border border-slate-800">
                            #{selectedOrderForPayment.order_number}
                        </span>
                        <h3 class="text-base font-bold text-white">
                            Gestión de Abonos
                        </h3>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Cliente: {selectedOrderForPayment.customer?.name}
                    </p>
                </div>
                <button type="button" onclick={() => showPaymentModal = false} class="text-slate-400 hover:text-white text-base">✕</button>
            </div>

            <!-- Resumen Financiero -->
            <div class="grid grid-cols-3 gap-2.5 p-3.5 bg-slate-950 rounded-2xl border border-slate-800 text-center">
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-bold block">Total Pedido</span>
                    <span class="font-mono font-bold text-white text-sm">
                        ${Number(selectedOrderForPayment.total_amount).toFixed(2)}
                    </span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-bold block">Abonado</span>
                    <span class="font-mono font-bold text-emerald-400 text-sm">
                        ${Number(selectedOrderForPayment.paid_amount).toFixed(2)}
                    </span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-bold block">Saldo Pendiente</span>
                    <span class="font-mono font-bold text-amber-300 text-sm">
                        ${Number(selectedOrderForPayment.pending_balance).toFixed(2)}
                    </span>
                </div>
            </div>

            <!-- FORMULARIO DE NUEVO ABONO (SI HAY SALDO PENDIENTE) -->
            {#if Number(selectedOrderForPayment.pending_balance) > 0}
                <form onsubmit={submitPayment} class="space-y-3.5 text-xs p-4 bg-slate-950/60 rounded-2xl border border-emerald-500/30">
                    <span class="font-bold text-white text-xs block">Registrar Nuevo Abono</span>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="pay-amount" class="block font-semibold text-slate-300 mb-1">Monto del Abono ($) *</label>
                            <input
                                id="pay-amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                max={Number(selectedOrderForPayment.pending_balance)}
                                bind:value={paymentForm.amount}
                                required
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-emerald-400 font-mono font-bold text-sm focus:outline-none focus:border-emerald-500"
                            />
                        </div>

                        <div>
                            <label for="pay-date" class="block font-semibold text-slate-300 mb-1">Fecha de Pago *</label>
                            <input
                                id="pay-date"
                                type="date"
                                bind:value={paymentForm.payment_date}
                                required
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-mono text-xs focus:outline-none focus:border-emerald-500"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="pay-method" class="block font-semibold text-slate-300 mb-1">Método de Pago *</label>
                            <select
                                id="pay-method"
                                bind:value={paymentForm.payment_method}
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-emerald-500"
                            >
                                <option value="transfer">Transferencia / Nequi / Daviplata</option>
                                <option value="cash">Efectivo</option>
                                <option value="card">Tarjeta</option>
                                <option value="other">Otro medio</option>
                            </select>
                        </div>

                        <div>
                            <label for="pay-notes" class="block font-semibold text-slate-300 mb-1">Notas / Referencia</label>
                            <input
                                id="pay-notes"
                                type="text"
                                bind:value={paymentForm.notes}
                                placeholder="Ej. Aprobación #1234..."
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white text-xs focus:outline-none focus:border-emerald-500"
                            />
                        </div>
                    </div>

                    <div>
                        <label for="pay-voucher" class="block font-semibold text-slate-300 mb-1">Comprobante de Pago (Foto o PDF, máx 5MB)</label>
                        <input
                            id="pay-voucher"
                            type="file"
                            accept="image/*,.pdf"
                            onchange={onPaymentVoucherChange}
                            class="w-full text-slate-400 text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-800 file:text-slate-200 hover:file:bg-slate-700"
                        />
                    </div>

                    <div class="flex justify-end pt-1">
                        <button
                            type="submit"
                            disabled={paymentProcessing}
                            class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs shadow-lg shadow-emerald-600/25 transition-all disabled:opacity-50"
                        >
                            {paymentProcessing ? 'Guardando...' : 'Guardar Abono'}
                        </button>
                    </div>
                </form>
            {:else}
                <div class="p-3 bg-emerald-950/20 border border-emerald-500/30 rounded-xl text-center text-xs text-emerald-300 font-bold">
                    ✓ Este pedido ya se encuentra pagado en su totalidad.
                </div>
            {/if}

            <!-- HISTORIAL DE ABONOS REGISTRADOS -->
            <div class="space-y-2 pt-2 border-t border-slate-800">
                <span class="font-bold text-white text-xs block">Historial de Abonos Recibidos</span>

                {#if selectedOrderForPayment.payments && selectedOrderForPayment.payments.length > 0}
                    <div class="space-y-2 max-h-56 overflow-y-auto">
                        {#each selectedOrderForPayment.payments as pay (pay.id)}
                            <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 flex items-center justify-between text-xs">
                                <div class="space-y-0.5">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono font-bold text-emerald-400 text-sm">
                                            ${Number(pay.amount).toFixed(2)}
                                        </span>
                                        <span class="text-[10px] text-slate-400 font-mono">
                                            {formatDateShort(pay.payment_date)}
                                        </span>
                                        <span class="text-[9px] px-1.5 py-0.2 rounded font-bold uppercase tracking-wider bg-slate-800 text-slate-300">
                                            {getPaymentMethodLabel(pay.payment_method)}
                                        </span>
                                    </div>
                                    {#if pay.notes}
                                        <p class="text-[11px] text-slate-400 italic">"{pay.notes}"</p>
                                    {/if}
                                    {#if pay.creator}
                                        <span class="text-[10px] text-slate-500 block">Registrado por: {pay.creator.name}</span>
                                    {/if}
                                </div>

                                <div class="flex items-center gap-2">
                                    {#if pay.voucher_url}
                                        <a
                                            href={pay.voucher_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-emerald-400 text-xs font-semibold flex items-center gap-1 border border-slate-700"
                                            title="Ver Comprobante adjunto"
                                        >
                                            <span>📎</span>
                                            <span>Ver</span>
                                        </a>
                                    {/if}

                                    <button
                                        type="button"
                                        onclick={() => deletePayment(pay)}
                                        class="p-1.5 text-slate-500 hover:text-rose-400 rounded-lg hover:bg-rose-950/20"
                                        title="Anular abono"
                                    >
                                        ✕
                                    </button>
                                </div>
                            </div>
                        {/each}
                    </div>
                {:else}
                    <div class="p-6 text-center bg-slate-950 rounded-xl border border-slate-800 text-slate-500 text-xs">
                        No se han registrado abonos para este pedido.
                    </div>
                {/if}
            </div>
        </div>
    </div>
{/if}
