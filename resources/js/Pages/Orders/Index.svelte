<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';
    import CurrencyInput from '../../Components/CurrencyInput.svelte';
    import QuickCreateModal from '../../Components/QuickCreateModal.svelte';

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

    let viewMode = $state('table'); // 'table' | 'cards'
    let expandedRows = $state(new Set());

    // Clientes locales sincronizados para permitir quick create
    let localCustomers = $state([]);
    $effect(() => {
        localCustomers = [...customers];
    });

    let showCreateModal = $state(false);
    let quickCustomerModalOpen = $state(false);
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

    function toggleRow(id) {
        const next = new Set(expandedRows);
        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }
        expandedRows = next;
    }

    function getTomorrowDateTimeLocal() {
        const d = new Date();
        d.setDate(d.getDate() + 2);
        d.setHours(17, 0, 0, 0);
        return d.toISOString().slice(0, 16);
    }

    function resetOrderForm() {
        orderForm.customer_id = localCustomers[0]?.id ?? '';
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

    function handleQuickCustomerSuccess(created) {
        localCustomers = [...localCustomers, created];
        orderForm.customer_id = created.id;
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

    let initialPendingBalance = $derived.by(() => {
        if (!orderForm.has_initial_deposit || !orderForm.initial_deposit) {
            return totalAmount;
        }
        const deposit = Number(orderForm.initial_deposit) || 0;
        return Math.max(0, totalAmount - deposit);
    });

    function submitOrder(e) {
        e.preventDefault();

        const formData = new FormData();
        formData.append('customer_id', String(orderForm.customer_id));
        formData.append('committed_delivery_at', orderForm.committed_delivery_at);
        formData.append('notes', orderForm.notes ?? '');
        formData.append('has_initial_deposit', orderForm.has_initial_deposit ? '1' : '0');

        if (orderForm.has_initial_deposit && orderForm.initial_deposit) {
            formData.append('initial_deposit', String(orderForm.initial_deposit));
            formData.append('payment_method', orderForm.payment_method);
            if (orderForm.voucher) {
                formData.append('voucher', orderForm.voucher);
            }
        }

        orderForm.items.forEach((item, index) => {
            formData.append(`items[${index}][product_id]`, String(item.product_id));
            formData.append(`items[${index}][product_variant_id]`, String(item.product_variant_id));
            formData.append(`items[${index}][quantity]`, String(item.quantity));
        });

        router.post('/orders', formData, {
            preserveScroll: true,
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
        paymentForm.amount = order.pending_balance > 0 ? Number(order.pending_balance) : '';
        paymentForm.payment_date = new Date().toISOString().slice(0, 10);
        paymentForm.payment_method = 'transfer';
        paymentForm.notes = '';
        paymentForm.voucher = null;
        showPaymentModal = true;
    }

    function submitPayment(e) {
        e.preventDefault();
        if (!selectedOrderForPayment) return;

        const formData = new FormData();
        formData.append('amount', String(paymentForm.amount));
        formData.append('payment_date', paymentForm.payment_date);
        formData.append('payment_method', paymentForm.payment_method);
        formData.append('notes', paymentForm.notes ?? '');
        if (paymentForm.voucher) {
            formData.append('voucher', paymentForm.voucher);
        }

        router.post(`/orders/${selectedOrderForPayment.id}/payments`, formData, {
            preserveScroll: true,
            onStart: () => { paymentProcessing = true; },
            onFinish: () => { paymentProcessing = false; },
            onSuccess: () => {
                showPaymentModal = false;
            },
        });
    }

    function deletePayment(payment) {
        if (confirm(`¿Eliminar este abono por ${formatCurrency(payment.amount)}?`)) {
            router.delete(`/payments/${payment.id}`, {
                preserveScroll: true,
            });
        }
    }

    function markDelivered(order) {
        if (confirm(`¿Confirmar entrega final del pedido #${order.order_number}?`)) {
            router.patch(`/orders/${order.id}/deliver`, {}, {
                preserveScroll: true,
            });
        }
    }

    function formatDate(dateStr) {
        if (!dateStr) return 'N/A';
        const d = new Date(dateStr);
        return d.toLocaleDateString('es-CO', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function formatMinutes(mins) {
        if (!mins) return '0 min';
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        if (h === 0) return `${m}m`;
        return m > 0 ? `${h}h ${m}m` : `${h}h`;
    }

    function formatCurrency(val) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
        }).format(Number(val || 0));
    }

    function getDerivedStatusBadge(status) {
        switch (status) {
            case 'pending':
                return { label: 'En Espera', color: 'bg-amber-500/15 text-amber-300 border-amber-500/30' };
            case 'in_progress':
                return { label: 'En Producción', color: 'bg-sky-500/15 text-sky-300 border-sky-500/30' };
            case 'blocked':
                return { label: 'Bloqueado', color: 'bg-rose-500/15 text-rose-300 border-rose-500/30' };
            case 'ready':
                return { label: 'Listo', color: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' };
            case 'delivered':
                return { label: 'Entregado', color: 'bg-purple-500/15 text-purple-300 border-purple-500/30' };
            default:
                return { label: status, color: 'bg-slate-800 text-slate-400 border-slate-700' };
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
            <div class="flex flex-wrap items-center gap-2 shrink-0 self-start sm:self-auto">
                <ViewToggle bind:mode={viewMode} storageKey="gekaem_orders_view" />
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
                    {localCustomers.length}
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

        <!-- VISTA TABLA (DEFAULT) -->
        {#if viewMode === 'table'}
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-lg">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950/90 text-slate-400 uppercase font-bold text-[10px] tracking-wider border-b border-slate-800">
                            <tr>
                                <th class="p-3.5 w-10 text-center"></th>
                                <th class="p-3.5">Pedido #</th>
                                <th class="p-3.5">Cliente</th>
                                <th class="p-3.5">Estado</th>
                                <th class="p-3.5">Entrega Comprometida</th>
                                <th class="p-3.5 text-right">Total</th>
                                <th class="p-3.5 text-right">Saldo</th>
                                <th class="p-3.5 text-center">Progreso</th>
                                <th class="p-3.5 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-slate-300">
                            {#each orders.data as order (order.id)}
                                {@const badge = getDerivedStatusBadge(order.derived_status)}
                                {@const tasksTotal = order.tasks_count ?? 0}
                                {@const tasksCompleted = order.completed_tasks_count ?? 0}
                                {@const progressPercent = tasksTotal > 0 ? Math.round((tasksCompleted / tasksTotal) * 100) : 0}
                                {@const isExpanded = expandedRows.has(order.id)}
                                <tr class="hover:bg-slate-800/40 transition-colors {isExpanded ? 'bg-slate-800/25' : ''}">
                                    <td class="p-3.5 text-center">
                                        <button
                                            type="button"
                                            onclick={() => toggleRow(order.id)}
                                            class="w-6 h-6 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center transition-transform {isExpanded ? 'rotate-90 text-emerald-400' : ''}"
                                            title={isExpanded ? 'Colapsar detalles' : 'Expandir productos y abonos'}
                                        >
                                            ▶
                                        </button>
                                    </td>
                                    <td class="p-3.5 font-mono font-bold text-emerald-400 text-sm">
                                        #{order.order_number}
                                    </td>
                                    <td class="p-3.5">
                                        <button
                                            type="button"
                                            class="font-bold text-white text-sm hover:text-emerald-400 text-left block transition-colors"
                                            onclick={() => toggleRow(order.id)}
                                        >
                                            {order.customer?.name ?? 'Cliente'}
                                        </button>
                                        {#if order.customer?.phone}
                                            <span class="text-[10px] text-slate-500 font-mono">📞 {order.customer.phone}</span>
                                        {/if}
                                    </td>
                                    <td class="p-3.5">
                                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider border {badge.color}">
                                            {badge.label}
                                        </span>
                                    </td>
                                    <td class="p-3.5 font-mono text-slate-300">
                                        📅 {formatDate(order.committed_delivery_at || order.predicted_delivery_at)}
                                    </td>
                                    <td class="p-3.5 text-right font-mono font-bold text-white">
                                        {formatCurrency(order.total_amount)}
                                    </td>
                                    <td class="p-3.5 text-right font-mono font-bold {order.pending_balance > 0 ? 'text-amber-400' : 'text-emerald-400'}">
                                        {order.pending_balance > 0 ? formatCurrency(order.pending_balance) : '✓ Pagado'}
                                    </td>
                                    <td class="p-3.5 text-center">
                                        <div class="inline-flex items-center gap-1.5">
                                            <div class="w-16 h-1.5 bg-slate-950 rounded-full overflow-hidden border border-slate-800">
                                                <div class="h-full bg-emerald-500" style="width: {progressPercent}%"></div>
                                            </div>
                                            <span class="font-mono text-[10px] text-slate-400">{progressPercent}%</span>
                                        </div>
                                    </td>
                                    <td class="p-3.5 text-right">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button
                                                type="button"
                                                onclick={() => openPaymentModal(order)}
                                                class="px-2.5 py-1 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 border border-emerald-500/30 text-xs font-semibold transition-colors"
                                                title="Registrar Abono o Ver Pagos"
                                            >
                                                💳 Abonos
                                            </button>
                                            {#if order.derived_status === 'ready'}
                                                <button
                                                    type="button"
                                                    onclick={() => markDelivered(order)}
                                                    class="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold transition-colors"
                                                >
                                                    ✓ Entregar
                                                </button>
                                            {/if}
                                        </div>
                                    </td>
                                </tr>

                                <!-- RENGLÓN EXPANDIDO (ACCORDION) -->
                                {#if isExpanded}
                                    <tr class="bg-slate-950/70 border-b border-slate-800">
                                        <td colspan="9" class="p-4 sm:p-6 space-y-4">
                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                                <!-- Productos y Tareas de Producción -->
                                                <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl space-y-2.5">
                                                    <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block border-b border-slate-800 pb-1.5">
                                                        Productos Solicitados ({order.production_tasks?.length ?? 0} ítems)
                                                    </span>
                                                    <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                                        {#each order.production_tasks ?? [] as task}
                                                            <div class="flex items-center justify-between p-2 rounded-xl bg-slate-950/80 border border-slate-800 text-xs">
                                                                <div>
                                                                    <div class="font-bold text-white flex items-center gap-1.5">
                                                                        <span class="font-mono text-emerald-400">{task.quantity}x</span>
                                                                        <span>{task.item_name_snapshot}</span>
                                                                    </div>
                                                                    <span class="text-[10px] text-slate-500 font-mono">
                                                                        Estimado: {task.estimated_minutes} min • Unitario: {formatCurrency(task.unit_price)}
                                                                    </span>
                                                                </div>
                                                                <div>
                                                                    {#if task.current_stage}
                                                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold border" style="color: {task.current_stage.color}; border-color: {task.current_stage.color}40; background: {task.current_stage.color}15">
                                                                            {task.current_stage.name}
                                                                        </span>
                                                                    {/if}
                                                                </div>
                                                            </div>
                                                        {:else}
                                                            <span class="text-slate-500 text-xs italic py-2 block">Sin tareas de producción asociadas.</span>
                                                        {/each}
                                                    </div>
                                                </div>

                                                <!-- Historial de Abonos y Comprobantes -->
                                                <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl space-y-2.5">
                                                    <div class="flex items-center justify-between border-b border-slate-800 pb-1.5">
                                                        <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                                            Historial de Abonos ({order.payments?.length ?? 0})
                                                        </span>
                                                        <span class="font-mono text-xs font-bold text-emerald-400">
                                                            Total abonado: {formatCurrency(order.paid_amount)}
                                                        </span>
                                                    </div>

                                                    <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                                        {#each order.payments ?? [] as pay}
                                                            <div class="flex items-center justify-between p-2 rounded-xl bg-slate-950/80 border border-slate-800 text-xs">
                                                                <div>
                                                                    <div class="font-bold text-white flex items-center gap-1.5 font-mono">
                                                                        <span class="text-emerald-400">{formatCurrency(pay.amount)}</span>
                                                                        <span class="text-slate-400 font-sans text-[11px] capitalize">• {pay.payment_method}</span>
                                                                    </div>
                                                                    <span class="text-[10px] text-slate-500 font-mono">Fecha: {pay.payment_date}</span>
                                                                </div>
                                                                <div class="flex items-center gap-1.5">
                                                                    {#if pay.voucher_path}
                                                                        <a
                                                                            href={`/storage/${pay.voucher_path}`}
                                                                            target="_blank"
                                                                            class="px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-sky-400 font-semibold text-[10px] border border-slate-700"
                                                                        >
                                                                            Comprobante ↗
                                                                        </a>
                                                                    {/if}
                                                                    <button
                                                                        type="button"
                                                                        onclick={() => deletePayment(pay)}
                                                                        class="p-1 rounded text-slate-500 hover:text-rose-400 text-xs"
                                                                        title="Eliminar abono"
                                                                    >
                                                                        ✕
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        {:else}
                                                            <span class="text-slate-500 text-xs italic py-2 block">Sin abonos registrados aún.</span>
                                                        {/each}
                                                    </div>
                                                </div>
                                            </div>

                                            {#if order.notes}
                                                <div class="p-3 bg-slate-900 border border-slate-800 rounded-xl text-xs text-slate-400">
                                                    <span class="font-bold text-slate-300">Notas del Pedido: </span>
                                                    <span>{order.notes}</span>
                                                </div>
                                            {/if}
                                        </td>
                                    </tr>
                                {/if}
                            {:else}
                                <tr>
                                    <td colspan="9" class="p-10 text-center text-slate-500 text-xs">
                                        No se encontraron pedidos registrados.
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- VISTA EN TARJETAS (CARDS) -->
        {:else}
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
                                        Total: {formatCurrency(order.total_amount)}
                                    </span>
                                </div>
                            </div>

                            <!-- Badges de Estado Financiero -->
                            <div class="flex flex-wrap items-center gap-2">
                                {#if order.payment_status === 'paid'}
                                    <span class="px-3 py-1.5 rounded-xl font-bold text-xs bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 flex items-center gap-1.5">
                                        <span>✓</span>
                                        <span>Pagado ({formatCurrency(order.total_amount)})</span>
                                    </span>
                                {:else if order.payment_status === 'partial'}
                                    <span class="px-3 py-1.5 rounded-xl font-bold text-xs bg-amber-500/20 text-amber-300 border border-amber-500/40 flex items-center gap-1.5">
                                        <span>⏳</span>
                                        <span>Abonado: {formatCurrency(order.paid_amount)} / Saldo: {formatCurrency(order.pending_balance)}</span>
                                    </span>
                                {:else}
                                    <span class="px-3 py-1.5 rounded-xl font-bold text-xs bg-rose-500/20 text-rose-300 border border-rose-500/40 flex items-center gap-1.5">
                                        <span>⚠️</span>
                                        <span>Sin Abono (Saldo: {formatCurrency(order.total_amount)})</span>
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
        {/if}

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

<!-- MODAL CREAR PEDIDO (SIMPLIFICADO) -->
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
                        <div class="flex items-center justify-between mb-1">
                            <label for="ord-customer" class="font-semibold text-slate-300">Cliente *</label>
                            <button
                                type="button"
                                onclick={() => quickCustomerModalOpen = true}
                                class="text-[10px] text-emerald-400 hover:text-emerald-300 font-bold"
                            >
                                + Nuevo Cliente
                            </button>
                        </div>
                        <select
                            id="ord-customer"
                            bind:value={orderForm.customer_id}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        >
                            <option value="">-- Selecciona un Cliente --</option>
                            {#each localCustomers as c}
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
                                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 flex flex-col sm:flex-row sm:items-center gap-2.5">
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

                                    <div class="flex-1">
                                        <label for="var-sel-{idx}" class="text-[10px] text-slate-400 uppercase font-bold block mb-1">Variante</label>
                                        <select
                                            id="var-sel-{idx}"
                                            bind:value={item.product_variant_id}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs font-mono"
                                        >
                                            {#each selectedProd?.variants ?? [] as v}
                                                <option value={v.id}>
                                                    {v.name_label} {v.price_override ? `(${formatCurrency(v.price_override)})` : ''}
                                                </option>
                                            {/each}
                                        </select>
                                    </div>

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

                <!-- SECCIÓN DE ABONO INICIAL (CHECKBOX COLAPSABLE) -->
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
                                    <CurrencyInput bind:value={orderForm.initial_deposit} id="ord-deposit" required placeholder="0" />
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
                                <span class="font-mono font-bold text-amber-300">{formatCurrency(initialPendingBalance)}</span>
                            </div>
                        </div>
                    {/if}
                </div>

                <!-- TOTAL Y BOTONES -->
                <div class="flex items-center justify-between pt-3 border-t border-slate-800">
                    <div class="text-xs">
                        <span class="text-slate-400">Total del Pedido: </span>
                        <span class="font-mono font-bold text-emerald-400 text-base">{formatCurrency(totalAmount)}</span>
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

<!-- MODAL REGISTRAR ABONO Y VER HISTORIAL DE PAGOS -->
{#if showPaymentModal && selectedOrderForPayment}
    <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-lg w-full p-6 shadow-2xl space-y-5 max-h-[92vh] overflow-y-auto">
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
                        {formatCurrency(selectedOrderForPayment.total_amount)}
                    </span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-bold block">Abonado</span>
                    <span class="font-mono font-bold text-emerald-400 text-sm">
                        {formatCurrency(selectedOrderForPayment.paid_amount)}
                    </span>
                </div>
                <div>
                    <span class="text-[10px] text-slate-400 uppercase font-bold block">Saldo Pendiente</span>
                    <span class="font-mono font-bold text-amber-300 text-sm">
                        {formatCurrency(selectedOrderForPayment.pending_balance)}
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
                            <CurrencyInput bind:value={paymentForm.amount} id="pay-amount" required />
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

                    <div class="flex items-center justify-end gap-2 pt-1">
                        <button
                            type="submit"
                            disabled={paymentProcessing}
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold rounded-xl text-xs shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                        >
                            {paymentProcessing ? 'Guardando Abono...' : 'Guardar Abono'}
                        </button>
                    </div>
                </form>
            {/if}

            <!-- HISTORIAL DE ABONOS REGISTRADOS -->
            <div class="space-y-3 pt-2">
                <span class="font-bold text-white text-xs block">Historial de Pagos Realizados</span>

                {#if !selectedOrderForPayment.payments || selectedOrderForPayment.payments.length === 0}
                    <div class="p-6 text-center border-2 border-dashed border-slate-800 rounded-2xl text-slate-500 text-xs">
                        Este pedido aún no tiene abonos registrados.
                    </div>
                {:else}
                    <div class="space-y-2">
                        {#each selectedOrderForPayment.payments as payment}
                            <div class="p-3 bg-slate-950 rounded-2xl border border-slate-800 flex items-center justify-between">
                                <div class="space-y-0.5">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono font-bold text-emerald-400 text-sm">
                                            {formatCurrency(payment.amount)}
                                        </span>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider bg-slate-800 text-slate-300">
                                            {payment.payment_method}
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-slate-400 flex items-center gap-2 font-mono">
                                        <span>📅 {payment.payment_date}</span>
                                        {#if payment.notes}
                                            <span class="text-slate-500 truncate max-w-xs">• "{payment.notes}"</span>
                                        {/if}
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    {#if payment.voucher_path}
                                        <a
                                            href={`/storage/${payment.voucher_path}`}
                                            target="_blank"
                                            class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-sky-400 text-xs font-semibold border border-slate-700 flex items-center gap-1 transition-colors"
                                        >
                                            <span>📎</span>
                                            <span>Ver Recibo</span>
                                        </a>
                                    {/if}
                                    <button
                                        type="button"
                                        onclick={() => deletePayment(payment)}
                                        class="p-1.5 rounded-lg text-slate-500 hover:text-rose-400 hover:bg-rose-950/30 transition-colors"
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

            <div class="pt-3 border-t border-slate-800 flex justify-end">
                <button
                    type="button"
                    onclick={() => showPaymentModal = false}
                    class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs"
                >
                    Cerrar
                </button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL FLOTANTE DE CREACIÓN RÁPIDA DE CLIENTE -->
<QuickCreateModal
    bind:isOpen={quickCustomerModalOpen}
    type="customer"
    onSuccess={handleQuickCustomerSuccess}
/>
