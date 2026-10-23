<script>
    import { untrack } from 'svelte';
    import { router, Link, page } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';
    import CurrencyInput from '../../Components/CurrencyInput.svelte';
    import OrderFormModal from '../../Components/OrderFormModal.svelte';
    import CalendarView from './CalendarView.svelte';
    import { canViewPrices } from '../../Utils/permissions.js';
    import { csrfFetch } from '../../Utils/csrfFetch.js';

    let {
        orders,
        calendarOrders = [],
        allScheduledOrders = [],
        ordersCountByDate = {},
        customers = [],
        products = [],
        workflows = [],
        productTypes = [],
        rawMaterials = [],
        orthogonalAttributes = [],
        attributes = [],
        unitsOfMeasure = [],
        wipQueueMinutes = 0,
        dailyProductiveMinutes = 480,
        defaultBufferPercent = 20,
        filters = { search: '', status: '', customer_id: '', payment_status: '', delivery_range: '', exact_date: '' },
        errors = {},
    } = $props();

    // Sincronización reactiva local para clientes y productos
    let localCustomers = $state([]);
    $effect(() => {
        const incoming = customers;
        untrack(() => {
            localCustomers = [...(incoming || [])];
        });
    });

    let localProducts = $state([]);
    $effect(() => {
        const incoming = products;
        untrack(() => {
            localProducts = [...(incoming || [])];
        });
    });

    function normalizeDateKey(dateValue) {
        if (!dateValue) return '';
        const str = String(dateValue).trim();
        const match = str.match(/^(\d{4}-\d{2}-\d{2})/);
        return match ? match[1] : '';
    }

    function getOrderDateKey(order) {
        if (!order) return '';
        if (order.delivery_date_key && /^\d{4}-\d{2}-\d{2}$/.test(order.delivery_date_key)) {
            return order.delivery_date_key;
        }
        const raw = order.committed_delivery_at || order.predicted_delivery_at || order.delivery_date || '';
        if (!raw) return '';
        const match = String(raw).trim().match(/^(\d{4}-\d{2}-\d{2})/);
        return match ? match[1] : '';
    }

    // Mapa reactivo global como fuente única de verdad para ambos calendarios
    let allOrdersByDate = $derived.by(() => {
        const map = {};
        const combined = [
            ...(Array.isArray(allScheduledOrders) ? allScheduledOrders : []),
            ...(Array.isArray(calendarOrders) ? calendarOrders : []),
            ...(Array.isArray(orders) ? orders : (orders?.data ?? [])),
        ];

        const seenIds = new Set();
        for (const order of combined) {
            if (!order || seenIds.has(order.id)) continue;
            seenIds.add(order.id);
            const key = getOrderDateKey(order);
            if (!key) continue;
            if (!map[key]) {
                map[key] = [];
            }
            map[key].push(order);
        }
        return map;
    });

    function formatExactDateHuman(dateKey) {
        if (!dateKey) return '';
        const clean = normalizeDateKey(dateKey);
        if (!clean) return '';
        const [y, m, d] = clean.split('-').map(Number);
        const dateObj = new Date(y, m - 1, d);
        return dateObj.toLocaleDateString('es-CO', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    }

    function formatDayTitle(dateKey) {
        if (!dateKey) return '';
        const clean = normalizeDateKey(dateKey);
        if (!clean) return '';
        const [y, m, d] = clean.split('-').map(Number);
        const dateObj = new Date(y, m - 1, d);
        const str = dateObj.toLocaleDateString('es-CO', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    let authUser = $derived(page.props.auth?.user);
    let hasFinancialAccess = $derived(canViewPrices(authUser));

    let search = $state('');
    let statusFilter = $state('');
    let customerFilter = $state('');
    let paymentStatusFilter = $state('');
    let deliveryRangeFilter = $state('');
    let selectedExactDate = $state('');

    $effect(() => {
        const incoming = filters;
        untrack(() => {
            search = incoming.search ?? '';
            statusFilter = incoming.status ?? '';
            customerFilter = incoming.customer_id ? String(incoming.customer_id) : '';
            paymentStatusFilter = incoming.payment_status ?? '';
            deliveryRangeFilter = incoming.delivery_range ?? '';
            selectedExactDate = incoming.exact_date ?? '';
        });
    });

    let viewMode = $state('table'); // 'table' | 'cards' | 'calendar'
    let expandedRows = $state(new Set());

    // Estado reactivo para el menú contextual del día en el calendario
    let dayContextMenu = $state({
        open: false,
        dateKey: '',
        orders: [],
        x: 0,
        y: 0,
    });

    // Modal de desglose detallado de pedidos para un día específico
    let dayOrdersModal = $state({
        open: false,
        dateKey: '',
        orders: [],
    });

    function handleCalendarDayClick(day, event) {
        const clickX = event.clientX || (typeof window !== 'undefined' ? window.innerWidth / 2 : 200);
        const clickY = event.clientY || (typeof window !== 'undefined' ? window.innerHeight / 2 : 200);
        const menuWidth = 280;
        const menuHeight = 260;
        const screenW = typeof window !== 'undefined' ? window.innerWidth : 1024;
        const screenH = typeof window !== 'undefined' ? window.innerHeight : 768;
        const x = Math.min(Math.max(10, clickX), screenW - menuWidth - 20);
        const y = Math.min(Math.max(10, clickY), screenH - menuHeight - 20);

        const dateKey = day.dateStr;
        const dayOrders = allOrdersByDate[dateKey] || day.orders || [];

        dayContextMenu = {
            open: true,
            dateKey: dateKey,
            orders: dayOrders,
            x,
            y,
        };
    }

    function closeContextMenu() {
        dayContextMenu.open = false;
    }

    function handleContextCreateOrder() {
        const date = dayContextMenu.dateKey;
        closeContextMenu();
        openCreateForDate(date);
    }

    function handleContextViewOrders() {
        const date = dayContextMenu.dateKey;
        const ords = allOrdersByDate[date] || dayContextMenu.orders || [];
        closeContextMenu();
        dayOrdersModal = {
            open: true,
            dateKey: date,
            orders: ords,
        };
    }

    function handleContextFilterTable() {
        const date = dayContextMenu.dateKey;
        closeContextMenu();
        viewMode = 'table';
        selectedExactDate = date;
        applyFilters();
    }

    function handleContextGoKanban() {
        const date = dayContextMenu.dateKey;
        closeContextMenu();
        router.visit(`/kanban?date=${date}`);
    }

    let showCreateModal = $state(false);
    let isEditingOrder = $state(false);
    let selectedOrderForEdit = $state(null);
    let initialDateForModal = $state('');

    function openCreate() {
        isEditingOrder = false;
        selectedOrderForEdit = null;
        initialDateForModal = '';
        showCreateModal = true;
    }

    function openCreateForDate(dateKey) {
        isEditingOrder = false;
        selectedOrderForEdit = null;
        initialDateForModal = dateKey || '';
        showCreateModal = true;
    }

    function openEditOrder(order) {
        isEditingOrder = true;
        selectedOrderForEdit = order;
        initialDateForModal = '';
        showCreateModal = true;
    }

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
            {
                search,
                status: statusFilter,
                customer_id: customerFilter,
                payment_status: hasFinancialAccess ? paymentStatusFilter : '',
                delivery_range: deliveryRangeFilter,
                exact_date: selectedExactDate,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function setStatus(st) {
        statusFilter = st;
        applyFilters();
    }

    function clearExactDate() {
        selectedExactDate = '';
        applyFilters();
    }

    function clearFilters() {
        search = '';
        statusFilter = '';
        customerFilter = '';
        paymentStatusFilter = '';
        deliveryRangeFilter = '';
        selectedExactDate = '';
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

    function onPaymentVoucherChange(e) {
        const file = e.target.files?.[0] ?? null;
        paymentForm.voucher = file;
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
        const clean = normalizeDateKey(dateStr);
        if (!clean) return 'N/A';
        const [y, m, d] = clean.split('-').map(Number);
        const dateObj = new Date(y, m - 1, d);
        let timeStr = '';
        if (String(dateStr).includes('T')) {
            timeStr = String(dateStr).split('T')[1].slice(0, 5);
        } else if (String(dateStr).includes(' ')) {
            timeStr = String(dateStr).split(' ')[1].slice(0, 5);
        }
        const formatted = dateObj.toLocaleDateString('es-CO', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
        return timeStr ? `${formatted} ${timeStr}` : formatted;
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

    function getShippingTypeBadge(type) {
        if (type === 'envio_incluido') {
            return { label: '📦 Envío Incluido', color: 'bg-sky-500/15 text-sky-300 border-sky-500/30' };
        }
        return { label: '🚚 Contraentrega', color: 'bg-slate-800 text-slate-300 border-slate-700' };
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
                <ViewToggle bind:mode={viewMode} storageKey="gekaem_orders_view" showCalendar={true} />
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

        <!-- BARRA DE FILTROS Y BUSCADOR COMBINADOS -->
        <div class="bg-slate-900 border border-slate-800 p-4 rounded-3xl space-y-3 shadow-lg">
            <!-- Fila 1: Pestañas de estado operativo y Buscador rápido -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
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

                <!-- Buscador por #pedido o cliente -->
                <div class="flex items-center gap-2 bg-slate-950 border border-slate-800 px-3 py-1.5 rounded-xl w-full md:w-72">
                    <span class="text-slate-500 text-xs">🔍</span>
                    <label for="search-orders" class="sr-only">Buscar por # o cliente</label>
                    <input
                        id="search-orders"
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

            <!-- Fila 2: Filtros desplegables secundarios -->
            <div class="flex flex-wrap items-center gap-2.5 pt-2 border-t border-slate-800/80 text-xs">
                <!-- Filtro por Cliente -->
                <div class="flex items-center gap-1.5">
                    <label for="filter-ord-cust" class="text-slate-500 font-semibold text-[11px]">Cliente:</label>
                    <select
                        id="filter-ord-cust"
                        bind:value={customerFilter}
                        onchange={applyFilters}
                        class="bg-slate-950 border border-slate-800 text-slate-300 rounded-xl px-2.5 py-1.5 text-xs focus:outline-none focus:border-emerald-500"
                    >
                        <option value="">Todos los Clientes</option>
                        {#each localCustomers as c}
                            <option value={String(c.id)}>{c.name}</option>
                        {/each}
                    </select>
                </div>

                <!-- Filtro por Estado de Pago (SOLO SI TIENE PERMISOS FINANCIEROS) -->
                {#if hasFinancialAccess}
                    <div class="flex items-center gap-1.5">
                        <label for="filter-ord-pay" class="text-slate-500 font-semibold text-[11px]">Estado de Pago:</label>
                        <select
                            id="filter-ord-pay"
                            bind:value={paymentStatusFilter}
                            onchange={applyFilters}
                            class="bg-slate-950 border border-slate-800 text-slate-300 rounded-xl px-2.5 py-1.5 text-xs focus:outline-none focus:border-emerald-500"
                        >
                            <option value="">Todos los Abonos</option>
                            <option value="unpaid">Sin Abono</option>
                            <option value="partial">Abono Parcial</option>
                            <option value="paid">Pagado Total</option>
                        </select>
                    </div>
                {/if}

                <!-- Filtro por Rango de Entrega -->
                <div class="flex items-center gap-1.5">
                    <label for="filter-ord-delivery" class="text-slate-500 font-semibold text-[11px]">Entrega:</label>
                    <select
                        id="filter-ord-delivery"
                        bind:value={deliveryRangeFilter}
                        onchange={applyFilters}
                        class="bg-slate-950 border border-slate-800 text-slate-300 rounded-xl px-2.5 py-1.5 text-xs focus:outline-none focus:border-emerald-500"
                    >
                        <option value="">Cualquier Fecha</option>
                        <option value="delayed">Atrasados</option>
                        <option value="today">Hoy</option>
                        <option value="this_week">Esta Semana</option>
                        <option value="this_month">Este Mes</option>
                    </select>
                </div>

                <!-- Chip de Fecha Exacta Seleccionada (desde el Calendario) -->
                {#if selectedExactDate}
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 font-semibold text-xs">
                        <span>📅 Entrega: {formatExactDateHuman(selectedExactDate)}</span>
                        <button
                            type="button"
                            onclick={clearExactDate}
                            class="hover:text-white p-0.5 rounded-full hover:bg-emerald-500/30 transition-colors ml-1"
                            title="Quitar filtro de fecha exacta"
                        >
                            ✕
                        </button>
                    </div>
                {/if}

                {#if search || statusFilter || customerFilter || paymentStatusFilter || deliveryRangeFilter || selectedExactDate}
                    <button
                        type="button"
                        onclick={clearFilters}
                        class="px-2.5 py-1 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white text-xs transition-colors font-medium ml-auto"
                        title="Limpiar todos los filtros"
                    >
                        Limpiar Filtros
                    </button>
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
                                <th class="p-3.5">Envío</th>
                                <th class="p-3.5">Entrega Comprometida</th>
                                {#if hasFinancialAccess}
                                    <th class="p-3.5 text-right">Total</th>
                                    <th class="p-3.5 text-right">Saldo</th>
                                {/if}
                                <th class="p-3.5 text-center">Progreso</th>
                                <th class="p-3.5 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-slate-300">
                            {#each orders.data as order (order.id)}
                                {@const badge = getDerivedStatusBadge(order.derived_status)}
                                {@const shippingBadge = getShippingTypeBadge(order.shipping_type)}
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
                                    <td class="p-3.5">
                                        <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold border {shippingBadge.color}">
                                            {shippingBadge.label}
                                        </span>
                                    </td>
                                    <td class="p-3.5 font-mono text-slate-300">
                                        📅 {formatDate(order.committed_delivery_at || order.predicted_delivery_at)}
                                    </td>
                                    {#if hasFinancialAccess}
                                        <td class="p-3.5 text-right font-mono font-bold text-white">
                                            {formatCurrency(order.total_amount)}
                                        </td>
                                        <td class="p-3.5 text-right font-mono font-bold {order.pending_balance > 0 ? 'text-amber-400' : 'text-emerald-400'}">
                                            {order.pending_balance > 0 ? formatCurrency(order.pending_balance) : '✓ Pagado'}
                                        </td>
                                    {/if}
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
                                                onclick={() => openEditOrder(order)}
                                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white border border-slate-700 text-xs font-semibold transition-colors flex items-center gap-1"
                                                title="Editar pedido"
                                            >
                                                <span>✏️</span>
                                                <span>Editar</span>
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => router.visit(`/kanban?order_id=${order.id}`)}
                                                class="px-2.5 py-1 rounded-lg bg-sky-950/60 hover:bg-sky-900/60 text-sky-400 hover:text-sky-300 border border-sky-800/60 text-xs font-semibold transition-colors flex items-center gap-1"
                                                title="Ver en Tablero Kanban aislado"
                                            >
                                                <span>📋</span>
                                                <span>Tablero</span>
                                            </button>
                                            {#if hasFinancialAccess}
                                                <button
                                                    type="button"
                                                    onclick={() => openPaymentModal(order)}
                                                    class="px-2.5 py-1 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 border border-emerald-500/30 text-xs font-semibold transition-colors"
                                                    title="Registrar Abono o Ver Pagos"
                                                >
                                                    💳 Abonos
                                                </button>
                                            {/if}
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
                                        <td colspan="10" class="p-4 sm:p-6 space-y-4">
                                            <div class="flex items-center gap-2 pb-2 border-b border-slate-800 text-xs">
                                                <span class="text-slate-400">Modalidad de Envío:</span>
                                                <span class="px-2.5 py-0.5 rounded-full font-semibold border {shippingBadge.color}">
                                                    {shippingBadge.label}
                                                </span>
                                            </div>
                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                                <!-- Productos y Tareas de Producción -->
                                                <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl space-y-2.5">
                                                    <div class="flex items-center justify-between border-b border-slate-800 pb-1.5">
                                                        <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                                            Productos Solicitados ({order.production_tasks?.length ?? 0} ítems)
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onclick={() => router.visit(`/kanban?order_id=${order.id}`)}
                                                            class="text-[11px] font-semibold text-sky-400 hover:text-sky-300 flex items-center gap-1 transition-colors"
                                                            title="Abrir Tablero Kanban de este Pedido"
                                                        >
                                                            <span>📋 Ver en Tablero ➔</span>
                                                        </button>
                                                    </div>
                                                    <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                                        {#each order.production_tasks ?? [] as task}
                                                            <div class="flex items-center justify-between p-2 rounded-xl bg-slate-950/80 border border-slate-800 text-xs">
                                                                <div>
                                                                    <div class="font-bold text-white flex items-center gap-1.5">
                                                                        <span class="font-mono text-emerald-400">{task.quantity}x</span>
                                                                        <span>{task.item_name_snapshot}</span>
                                                                    </div>
                                                                    <span class="text-[10px] text-slate-500 font-mono">
                                                                        Estimado: {task.estimated_minutes} min {#if hasFinancialAccess}• Unitario: {formatCurrency(task.unit_price)}{/if}
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

                                                <!-- Historial de Abonos y Comprobantes (SOLO SI TIENE PERMISOS FINANCIEROS) -->
                                                {#if hasFinancialAccess}
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
                                                {/if}
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
                                    <td colspan="10" class="p-10 text-center text-slate-500 text-xs">
                                        No se encontraron pedidos registrados.
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- VISTA EN TARJETAS (CARDS) -->
        {:else if viewMode === 'cards'}
            <div class="space-y-4">
                {#each orders.data as order (order.id)}
                    {@const badge = getDerivedStatusBadge(order.derived_status)}
                    {@const shippingBadge = getShippingTypeBadge(order.shipping_type)}
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
                                    <span class="text-[10px] px-2.5 py-0.5 rounded-full font-semibold border {shippingBadge.color}">
                                        {shippingBadge.label}
                                    </span>
                                </div>
                                <div class="flex flex-wrap items-center gap-3 text-xs text-slate-400">
                                    {#if order.customer?.phone}
                                        <span>📞 {order.customer.phone}</span>
                                    {/if}
                                    {#if order.notes}
                                        <span class="italic max-w-sm truncate text-slate-500">"{order.notes}"</span>
                                    {/if}
                                    {#if hasFinancialAccess}
                                        <span class="font-mono text-white font-bold">
                                            Total: {formatCurrency(order.total_amount)}
                                        </span>
                                    {/if}
                                </div>
                            </div>

                            <!-- Badges de Estado Financiero (SOLO SI TIENE PERMISOS) -->
                            {#if hasFinancialAccess}
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
                            {/if}

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
                                <button
                                    type="button"
                                    onclick={() => openEditOrder(order)}
                                    class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white text-xs font-bold border border-slate-700 transition-colors flex items-center gap-1.5 shadow-sm"
                                    title="Editar este pedido"
                                >
                                    <span>✏️</span>
                                    <span>Editar</span>
                                </button>

                                <button
                                    type="button"
                                    onclick={() => router.visit(`/kanban?order_id=${order.id}`)}
                                    class="px-3.5 py-2 rounded-xl bg-sky-950/70 hover:bg-sky-900/70 text-sky-400 hover:text-sky-300 text-xs font-bold border border-sky-800/70 hover:border-sky-500/60 transition-colors flex items-center gap-1.5 shadow-sm"
                                    title="Abrir tablero Kanban aislado para este pedido"
                                >
                                    <span>📋</span>
                                    <span>Ver en Tablero</span>
                                    <span>➔</span>
                                </button>

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

        <!-- VISTA EN CALENDARIO (PUNTO 10) -->
        {:else if viewMode === 'calendar'}
            <CalendarView
                orders={calendarOrders && calendarOrders.length > 0 ? calendarOrders : (allScheduledOrders.length > 0 ? allScheduledOrders : (orders.data || []))}
                {hasFinancialAccess}
                onOpenPaymentModal={openPaymentModal}
                onMarkDelivered={markDelivered}
                onEditOrder={openEditOrder}
                onDayClick={handleCalendarDayClick}
                {formatCurrency}
                {getDerivedStatusBadge}
            />
        {/if}

        <!-- PAGINACIÓN (SOLO PARA VISTA TABLA Y TARJETAS) -->
        {#if viewMode !== 'calendar' && orders.links && orders.links.length > 3}
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

<!-- MODAL CREAR / EDITAR PEDIDO -->
<OrderFormModal
    bind:isOpen={showCreateModal}
    isEditing={isEditingOrder}
    editingOrder={selectedOrderForEdit}
    initialDate={initialDateForModal}
    customers={localCustomers}
    products={localProducts}
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
    onCustomerCreated={(c) => {
        if (!localCustomers.some((x) => x.id === c.id)) {
            localCustomers = [...localCustomers, c];
        }
    }}
    onProductCreated={(p) => {
        if (!localProducts.some((x) => x.id === p.id)) {
            localProducts = [...localProducts, p];
        }
    }}
    onSuccess={() => {
        showCreateModal = false;
        router.reload({ preserveScroll: true });
    }}
    onClose={() => {
        showCreateModal = false;
    }}
/>

<!-- MODAL REGISTRAR ABONO Y VER HISTORIAL DE PAGOS (SOLO VISIBLE CON PERMISOS) -->
{#if showPaymentModal && selectedOrderForPayment && hasFinancialAccess}
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


<!-- SVELTE WINDOW EVENT PARA TECLA ESCAPE -->
<svelte:window onkeydown={(e) => {
    if (e.key === 'Escape') {
        if (dayContextMenu.open) closeContextMenu();
        if (dayOrdersModal.open) dayOrdersModal.open = false;
    }
}} />

<!-- MENÚ CONTEXTUAL AL HACER CLIC EN UN DÍA DEL CALENDARIO (PUNTO 3) -->
{#if dayContextMenu.open}
    <div
        class="fixed inset-0 z-40 bg-slate-950/20 backdrop-blur-[1px]"
        onclick={closeContextMenu}
        role="button"
        tabindex="-1"
        onkeydown={(e) => { if (e.key === 'Escape') closeContextMenu(); }}
    ></div>

    <div
        class="fixed z-50 w-72 bg-slate-900 border border-slate-700/80 rounded-2xl shadow-2xl p-2.5 space-y-1 text-xs animate-in fade-in zoom-in-95 duration-100"
        style="left: {dayContextMenu.x}px; top: {dayContextMenu.y}px;"
    >
        <!-- Cabecera del Menú -->
        <div class="px-3 py-2 border-b border-slate-800 flex items-center justify-between">
            <div class="min-w-0 pr-2">
                <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block">Opciones del Día</span>
                <span class="font-bold text-white text-xs block truncate" title={formatDayTitle(dayContextMenu.dateKey)}>
                    {formatDayTitle(dayContextMenu.dateKey)}
                </span>
            </div>
            <button
                type="button"
                onclick={closeContextMenu}
                class="text-slate-400 hover:text-white p-1 rounded-lg"
            >
                ✕
            </button>
        </div>

        <!-- Opciones -->
        <div class="space-y-1 pt-1">
            <!-- 1. Crear nuevo pedido para este día -->
            <button
                type="button"
                onclick={handleContextCreateOrder}
                class="w-full text-left px-3 py-2 rounded-xl hover:bg-emerald-600/20 text-slate-200 hover:text-emerald-300 flex items-center gap-2.5 transition-colors font-medium group"
            >
                <span class="text-emerald-400 group-hover:scale-110 transition-transform text-sm">➕</span>
                <span>Crear nuevo pedido para este día</span>
            </button>

            <!-- 2. Ver pedidos de este día -->
            <button
                type="button"
                onclick={handleContextViewOrders}
                class="w-full text-left px-3 py-2 rounded-xl hover:bg-sky-600/20 text-slate-200 hover:text-sky-300 flex items-center justify-between transition-colors font-medium group"
            >
                <div class="flex items-center gap-2.5">
                    <span class="text-sky-400 group-hover:scale-110 transition-transform text-sm">📋</span>
                    <span>Ver pedidos de este día</span>
                </div>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-mono font-bold {dayContextMenu.orders.length > 0 ? 'bg-slate-800 text-white border border-slate-700' : 'text-slate-500'}">
                    {dayContextMenu.orders.length}
                </span>
            </button>

            <!-- 3. Filtrar en Tabla por esta fecha -->
            <button
                type="button"
                onclick={handleContextFilterTable}
                class="w-full text-left px-3 py-2 rounded-xl hover:bg-purple-600/20 text-slate-200 hover:text-purple-300 flex items-center gap-2.5 transition-colors font-medium group"
            >
                <span class="text-purple-400 group-hover:scale-110 transition-transform text-sm">▦</span>
                <span>Filtrar en Tabla por esta fecha</span>
            </button>

            <!-- 4. Ver entregas del día en Kanban -->
            <button
                type="button"
                onclick={handleContextGoKanban}
                class="w-full text-left px-3 py-2 rounded-xl hover:bg-amber-600/20 text-slate-200 hover:text-amber-300 flex items-center gap-2.5 transition-colors font-medium group border-t border-slate-800/80 pt-2"
            >
                <span class="text-amber-400 group-hover:scale-110 transition-transform text-sm">🏭</span>
                <span>Ver entregas del día en Kanban</span>
            </button>
        </div>
    </div>
{/if}

<!-- MODAL DE DESGLOSE DE PEDIDOS DEL DÍA -->
{#if dayOrdersModal.open}
    <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in-95 duration-150">
            <!-- Encabezado del modal -->
            <div class="flex items-start justify-between border-b border-slate-800 pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-lg font-mono font-bold text-xs bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                            📅 {formatExactDateHuman(dayOrdersModal.dateKey)}
                        </span>
                        <span class="text-xs text-slate-400">({dayOrdersModal.orders.length} {dayOrdersModal.orders.length === 1 ? 'pedido programado' : 'pedidos programados'})</span>
                    </div>
                    <h3 class="text-lg font-bold text-white mt-1 capitalize">
                        {formatDayTitle(dayOrdersModal.dateKey)}
                    </h3>
                </div>
                <button
                    type="button"
                    onclick={() => dayOrdersModal.open = false}
                    class="text-slate-400 hover:text-white text-base p-1"
                >
                    ✕
                </button>
            </div>

            <!-- Listado de Pedidos del Día -->
            {#if dayOrdersModal.orders.length === 0}
                <div class="p-8 text-center bg-slate-950 rounded-2xl border border-slate-800/80 text-slate-400 text-xs space-y-2">
                    <span class="text-3xl block">✨</span>
                    <span class="font-bold text-white block">No hay pedidos comprometidos para este día</span>
                    <p class="text-[11px] text-slate-500">Puedes aprovechar la capacidad del taller registrando un nuevo pedido.</p>
                </div>
            {:else}
                <div class="space-y-3">
                    {#each dayOrdersModal.orders as order (order.id)}
                        {@const badge = getDerivedStatusBadge(order.derived_status)}
                        {@const shippingBadge = getShippingTypeBadge(order.shipping_type)}
                        {@const tasks = order.production_tasks || order.tasks || []}
                        <div class="p-4 bg-slate-950 rounded-2xl border border-slate-800 hover:border-slate-700 transition-colors space-y-3">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono font-bold text-emerald-400 text-sm">
                                        #{order.order_number}
                                    </span>
                                    <span class="font-bold text-white text-sm">
                                        {order.customer?.name ?? 'Cliente'}
                                    </span>
                                    {#if order.customer?.phone}
                                        <span class="text-[11px] font-mono text-slate-400">📞 {order.customer.phone}</span>
                                    {/if}
                                </div>
                                <div class="flex items-center gap-1.5 self-start sm:self-auto">
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border {badge.color}">
                                        {badge.label}
                                    </span>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-semibold border {shippingBadge.color}">
                                        {shippingBadge.label}
                                    </span>
                                </div>
                            </div>

                            {#if hasFinancialAccess}
                                <div class="flex items-center gap-4 text-xs font-mono text-slate-400 pt-1 border-t border-slate-800/60">
                                    <span>Total: <strong class="text-white">{formatCurrency(order.total_amount)}</strong></span>
                                    <span>Abonado: <strong class="text-emerald-400">{formatCurrency(order.paid_amount)}</strong></span>
                                    <span>Saldo: <strong class="{order.pending_balance > 0 ? 'text-amber-400' : 'text-emerald-400'}">{formatCurrency(order.pending_balance)}</strong></span>
                                </div>
                            {/if}

                            <!-- Tareas / Productos -->
                            {#if tasks.length > 0}
                                <div class="flex flex-wrap gap-1.5 items-center">
                                    {#each tasks as task}
                                        <span class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded-lg bg-slate-900 border border-slate-800 text-slate-300 font-mono">
                                            <span class="font-bold text-white">{task.quantity}x</span>
                                            <span>{task.item_name_snapshot}</span>
                                        </span>
                                    {/each}
                                </div>
                            {/if}

                            <!-- Acciones rápidas -->
                            <div class="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-slate-800/60">
                                <button
                                    type="button"
                                    onclick={() => {
                                        dayOrdersModal.open = false;
                                        openEditOrder(order);
                                    }}
                                    class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 flex items-center gap-1"
                                    title="Editar pedido"
                                >
                                    <span>✏️</span>
                                    <span>Editar</span>
                                </button>
                                <button
                                    type="button"
                                    onclick={() => {
                                        dayOrdersModal.open = false;
                                        router.visit(`/kanban?order_id=${order.id}`);
                                    }}
                                    class="px-3 py-1.5 rounded-xl bg-sky-950/70 hover:bg-sky-900/70 text-sky-400 text-xs font-bold border border-sky-800/70 flex items-center gap-1"
                                >
                                    <span>📋</span>
                                    <span>Kanban</span>
                                </button>
                                {#if hasFinancialAccess}
                                    <button
                                        type="button"
                                        onclick={() => {
                                            dayOrdersModal.open = false;
                                            openPaymentModal(order);
                                        }}
                                        class="px-3 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-emerald-400 text-xs font-semibold border border-slate-700"
                                    >
                                        💳 Abonos
                                    </button>
                                {/if}
                                {#if order.derived_status === 'ready'}
                                    <button
                                        type="button"
                                        onclick={() => {
                                            dayOrdersModal.open = false;
                                            markDelivered(order);
                                        }}
                                        class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold"
                                    >
                                        ✓ Entregar
                                    </button>
                                {/if}
                            </div>
                        </div>
                    {/each}
                </div>
            {/if}

            <!-- Pie del Modal con Acción de Crear Pedido -->
            <div class="pt-3 border-t border-slate-800 flex items-center justify-between">
                <button
                    type="button"
                    onclick={() => {
                        const targetDate = dayOrdersModal.dateKey;
                        dayOrdersModal.open = false;
                        openCreateForDate(targetDate);
                    }}
                    class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-colors flex items-center gap-1.5 shadow-md shadow-emerald-600/20"
                >
                    <span>➕</span>
                    <span>Crear pedido para este día</span>
                </button>

                <button
                    type="button"
                    onclick={() => dayOrdersModal.open = false}
                    class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs"
                >
                    Cerrar
                </button>
            </div>
        </div>
    </div>
{/if}


