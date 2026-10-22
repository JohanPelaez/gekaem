<script>
    import { router, Link, page } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';
    import CurrencyInput from '../../Components/CurrencyInput.svelte';
    import QuickCreateModal from '../../Components/QuickCreateModal.svelte';
    import OrderDateSelector from '../../Components/OrderDateSelector.svelte';
    import CalendarView from './CalendarView.svelte';
    import ProductFormModal from '../../Components/ProductFormModal.svelte';
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
        search = filters.search ?? '';
        statusFilter = filters.status ?? '';
        customerFilter = filters.customer_id ? String(filters.customer_id) : '';
        paymentStatusFilter = filters.payment_status ?? '';
        deliveryRangeFilter = filters.delivery_range ?? '';
        selectedExactDate = filters.exact_date ?? '';
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

    function openCreateForDate(dateKey) {
        resetOrderForm();
        if (dateKey) {
            orderForm.committed_delivery_at = `${dateKey}T17:00`;
        }
        if (products.length > 0) {
            addItem();
        }
        showCreateModal = true;
    }

    // Clientes locales sincronizados para permitir quick create
    let localCustomers = $state([]);
    $effect(() => {
        localCustomers = [...customers];
    });

    // Productos locales sincronizados para permitir quick create de productos
    let localProducts = $state([]);
    $effect(() => {
        localProducts = [...products];
    });

    let showCreateModal = $state(false);
    let quickCustomerModalOpen = $state(false);
    let processing = $state(false);

    // Estado para edición de pedidos
    let isEditingOrder = $state(false);
    let editingOrderId = $state(null);

    // Estado para modal completo de producto en pedidos (Nota 7, 8, 9)
    let productModalOpen = $state(false);

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

    // Formulario de Creación / Edición de Pedido
    let orderForm = $state({
        customer_id: '',
        committed_delivery_at: '',
        shipping_type: 'contraentrega',
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

    function resetOrderForm() {
        isEditingOrder = false;
        editingOrderId = null;
        orderForm.customer_id = localCustomers[0]?.id ?? '';
        orderForm.committed_delivery_at = '';
        orderForm.shipping_type = 'contraentrega';
        orderForm.notes = '';
        orderForm.items = [];
        orderForm.has_initial_deposit = false;
        orderForm.initial_deposit = '';
        orderForm.payment_method = 'transfer';
        orderForm.voucher = null;
    }

    function openCreate() {
        resetOrderForm();
        if (localProducts.length > 0) {
            addItem();
        }
        showCreateModal = true;
    }

    function openEditOrder(order) {
        resetOrderForm();
        isEditingOrder = true;
        editingOrderId = order.id;
        orderForm.customer_id = order.customer_id;
        orderForm.shipping_type = order.shipping_type || 'contraentrega';
        orderForm.notes = order.notes || '';

        let dt = '';
        if (order.committed_delivery_at) {
            dt = String(order.committed_delivery_at).replace(' ', 'T').slice(0, 16);
        } else if (order.predicted_delivery_at) {
            dt = String(order.predicted_delivery_at).replace(' ', 'T').slice(0, 16);
        }
        orderForm.committed_delivery_at = dt;

        const tasks = order.production_tasks || order.tasks || [];
        if (tasks.length > 0) {
            orderForm.items = tasks.map((t) => {
                const variant = t.product_variant || t.productVariant;
                let prodId = variant?.product_id || variant?.product?.id;
                if (!prodId) {
                    const found = localProducts.find((p) => p.variants?.some((v) => v.id == t.product_variant_id));
                    prodId = found?.id ?? '';
                }
                return {
                    product_id: prodId,
                    product_variant_id: t.product_variant_id,
                    quantity: Number(t.quantity || 1),
                    unit_price: Number(t.unit_price || 0),
                };
            });
        } else if (localProducts.length > 0) {
            addItem();
        }

        showCreateModal = true;
    }

    function handleQuickCustomerSuccess(created) {
        localCustomers = [...localCustomers, created];
        orderForm.customer_id = created.id;
    }

    function addItem() {
        if (localProducts.length === 0) return;
        const firstProd = localProducts[0];
        const firstVariant = firstProd.variants?.[0] ?? null;

        orderForm.items = [
            ...orderForm.items,
            {
                product_id: firstProd.id,
                product_variant_id: firstVariant?.id ?? '',
                quantity: 1,
                unit_price: Number(firstVariant?.price_override ?? firstProd.base_price ?? 0),
            },
        ];
    }

    function removeItem(index) {
        orderForm.items = orderForm.items.filter((_, i) => i !== index);
    }

    function onProductChange(item, productId) {
        item.product_id = Number(productId);
        const prod = localProducts.find((p) => p.id === Number(productId));
        const firstVariant = prod?.variants?.[0] ?? null;
        item.product_variant_id = firstVariant?.id ?? '';
        item.unit_price = Number(firstVariant?.price_override ?? prod?.base_price ?? 0);
    }

    function onVariantChange(item, variantId) {
        item.product_variant_id = Number(variantId);
        const prod = localProducts.find((p) => p.id === Number(item.product_id));
        const variant = prod?.variants?.find((v) => v.id === Number(variantId));
        if (variant) {
            item.unit_price = Number(variant.price_override ?? prod?.base_price ?? 0);
        }
    }

    function openCreateProductModal() {
        productModalOpen = true;
    }

    function handleProductCreated(newProduct) {
        if (!newProduct) return;
        if (!localProducts.some((p) => p.id === newProduct.id)) {
            localProducts = [...localProducts, newProduct];
        }
        const defaultVariant = newProduct.variants?.[0] ?? null;
        const unitPrice = defaultVariant?.price_override !== null && defaultVariant?.price_override !== undefined && defaultVariant?.price_override !== ''
            ? Number(defaultVariant.price_override)
            : Number(newProduct.base_price || 0);

        const lastItemIdx = orderForm.items.length - 1;
        if (lastItemIdx >= 0 && (!orderForm.items[lastItemIdx].product_id || orderForm.items[lastItemIdx].product_id === '')) {
            orderForm.items[lastItemIdx].product_id = newProduct.id;
            orderForm.items[lastItemIdx].product_variant_id = defaultVariant?.id ?? '';
            orderForm.items[lastItemIdx].unit_price = unitPrice;
        } else {
            orderForm.items = [
                ...orderForm.items,
                {
                    product_id: newProduct.id,
                    product_variant_id: defaultVariant?.id ?? '',
                    quantity: 1,
                    unit_price: unitPrice,
                },
            ];
        }
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
            const prod = localProducts.find((p) => p.id == item.product_id);
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
            const prod = localProducts.find((p) => p.id == item.product_id);
            const variant = prod?.variants?.find((v) => v.id == item.product_variant_id);
            const price = item.unit_price !== undefined && item.unit_price !== '' && item.unit_price !== null
                ? Number(item.unit_price)
                : (variant?.price_override !== null && variant?.price_override !== ''
                    ? Number(variant.price_override)
                    : Number(prod?.base_price ?? 0));
            sum += price * Number(item.quantity);
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

        if (!orderForm.committed_delivery_at) {
            alert('Por favor selecciona un día en el calendario para comprometer la entrega.');
            return;
        }

        if (orderForm.items.length === 0) {
            alert('Agrega al menos un producto al pedido.');
            return;
        }

        if (isEditingOrder && editingOrderId) {
            router.put(`/orders/${editingOrderId}`, {
                customer_id: Number(orderForm.customer_id),
                committed_delivery_at: orderForm.committed_delivery_at,
                shipping_type: orderForm.shipping_type,
                notes: orderForm.notes ?? '',
                items: orderForm.items.map((item) => ({
                    product_variant_id: Number(item.product_variant_id),
                    quantity: Number(item.quantity),
                    unit_price: Number(item.unit_price || 0),
                })),
            }, {
                preserveScroll: true,
                onStart: () => { processing = true; },
                onFinish: () => { processing = false; },
                onSuccess: () => {
                    showCreateModal = false;
                    resetOrderForm();
                },
            });
            return;
        }

        const formData = new FormData();
        formData.append('customer_id', String(orderForm.customer_id));
        formData.append('committed_delivery_at', orderForm.committed_delivery_at);
        formData.append('shipping_type', orderForm.shipping_type);
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
            if (item.unit_price !== undefined && item.unit_price !== null) {
                formData.append(`items[${index}][unit_price]`, String(item.unit_price));
            }
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
{#if showCreateModal}
    <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-4xl w-full p-6 shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto overflow-x-hidden">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-base font-bold text-white">
                        {isEditingOrder ? `Editar Pedido #${orders.data?.find(o => o.id === editingOrderId)?.order_number || editingOrderId}` : 'Registrar Nuevo Pedido'}
                    </h3>
                    <p class="text-[11px] text-slate-400">
                        {isEditingOrder ? 'Modifica los productos, entrega y detalles del pedido conservando las etapas en Kanban.' : 'Genera las tarjetas de producción en el Tablero Kanban.'}
                    </p>
                </div>
                <button type="button" onclick={() => showCreateModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitOrder} class="space-y-4 text-xs">
                <!-- CLIENTE Y MODALIDAD DE ENVÍO -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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

                    <fieldset>
                        <legend class="block font-semibold text-slate-300 mb-1">Modalidad de Envío *</legend>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center gap-2 p-2 rounded-xl border cursor-pointer transition-colors {orderForm.shipping_type === 'contraentrega' ? 'bg-slate-800/90 border-emerald-500 text-white' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'}">
                                <input
                                    type="radio"
                                    name="order_shipping_type"
                                    value="contraentrega"
                                    bind:group={orderForm.shipping_type}
                                    class="text-emerald-500 focus:ring-0"
                                />
                                <span class="font-medium text-[11px] leading-tight">🚚 Contraentrega (Paga cliente)</span>
                            </label>
                            <label class="flex items-center gap-2 p-2 rounded-xl border cursor-pointer transition-colors {orderForm.shipping_type === 'envio_incluido' ? 'bg-slate-800/90 border-sky-500 text-white' : 'bg-slate-950 border-slate-800 text-slate-400 hover:border-slate-700'}">
                                <input
                                    type="radio"
                                    name="order_shipping_type"
                                    value="envio_incluido"
                                    bind:group={orderForm.shipping_type}
                                    class="text-sky-500 focus:ring-0"
                                />
                                <span class="font-medium text-[11px] leading-tight">📦 Envío Incluido (Paga empresa)</span>
                            </label>
                        </div>
                    </fieldset>
                </div>

                <!-- SELECTOR VISUAL DE CALENDARIO CON CARGA DIARIA (PUNTOS 4 Y 5) -->
                <div>
                    <OrderDateSelector
                        bind:value={orderForm.committed_delivery_at}
                        {allOrdersByDate}
                        {ordersCountByDate}
                        {allScheduledOrders}
                        {getDerivedStatusBadge}
                    />
                </div>

                <!-- RENGLONES DE PRODUCTOS -->
                <div class="space-y-3 pt-2 border-t border-slate-800">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-xs">Productos del Pedido</span>
                            <p class="text-[11px] text-slate-500">Agrega las variantes y cantidades solicitadas.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                onclick={openCreateProductModal}
                                class="px-2.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-400 font-semibold text-xs border border-slate-700 flex items-center gap-1 transition-colors"
                                title="Crear un producto nuevo con receta BOM y variantes"
                            >
                                <span>+</span>
                                <span>Nuevo Producto</span>
                            </button>
                            <button
                                type="button"
                                onclick={addItem}
                                disabled={localProducts.length === 0}
                                class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs border border-slate-700 flex items-center gap-1.5 disabled:opacity-50"
                            >
                                <span>+</span>
                                <span>Agregar Renglón</span>
                            </button>
                        </div>
                    </div>

                    {#if orderForm.items.length === 0}
                        <div class="p-6 text-center border-2 border-dashed border-slate-800 rounded-xl text-slate-500 text-xs">
                            Agrega al menos un producto al pedido.
                        </div>
                    {:else}
                        <div class="space-y-2">
                            {#each orderForm.items as item, idx}
                                {@const selectedProd = localProducts.find((p) => p.id == item.product_id)}
                                {@const selectedVariant = selectedProd?.variants?.find((v) => v.id == item.product_variant_id)}
                                {@const unitPrice = selectedVariant?.price_override !== null && selectedVariant?.price_override !== '' && selectedVariant?.price_override !== undefined
                                    ? Number(selectedVariant.price_override)
                                    : Number(selectedProd?.base_price ?? 0)}
                                {@const subtotal = (item.unit_price ? Number(item.unit_price) : unitPrice) * Number(item.quantity || 0)}
                                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 grid grid-cols-12 gap-3 items-end">
                                    <div class="col-span-12 sm:col-span-4 min-w-0">
                                        <label for="prod-sel-{idx}" class="text-[10px] text-slate-400 uppercase font-bold block mb-1">Producto</label>
                                        <select
                                            id="prod-sel-{idx}"
                                            bind:value={item.product_id}
                                            onchange={(e) => onProductChange(item, e.target.value)}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs truncate"
                                        >
                                            {#each localProducts as p}
                                                <option value={p.id}>{p.name}</option>
                                            {/each}
                                        </select>
                                    </div>

                                    <div class="col-span-6 sm:col-span-2 min-w-0">
                                        <label for="var-sel-{idx}" class="text-[10px] text-slate-400 uppercase font-bold block mb-1">Variante</label>
                                        <select
                                            id="var-sel-{idx}"
                                            bind:value={item.product_variant_id}
                                            onchange={(e) => onVariantChange(item, e.target.value)}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs font-mono truncate"
                                        >
                                            {#each selectedProd?.variants ?? [] as v}
                                                <option value={v.id}>
                                                    {v.name_label} {v.price_override ? `(${formatCurrency(v.price_override)})` : ''}
                                                </option>
                                            {/each}
                                        </select>
                                    </div>

                                    <div class="col-span-6 sm:col-span-2 min-w-0">
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

                                    {#if hasFinancialAccess}
                                        <div class="col-span-5 sm:col-span-2 min-w-0">
                                            <span class="text-[10px] text-slate-400 uppercase font-bold block mb-1 truncate">Precio Unit.</span>
                                            <div class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2 py-1.5 text-slate-200 text-xs font-mono font-medium truncate">
                                                {formatCurrency(item.unit_price ?? unitPrice)}
                                            </div>
                                        </div>

                                        <div class="col-span-7 sm:col-span-2 min-w-0 flex items-center gap-2">
                                            <div class="flex-1 min-w-0">
                                                <span class="text-[10px] text-slate-400 uppercase font-bold block mb-1 truncate">Subtotal</span>
                                                <div class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2 py-1.5 text-emerald-400 text-xs font-mono font-bold truncate">
                                                    {formatCurrency(subtotal)}
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onclick={() => removeItem(idx)}
                                                class="p-1.5 text-slate-500 hover:text-rose-400 shrink-0 self-end mb-0.5"
                                                title="Quitar producto"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    {:else}
                                        <div class="col-span-12 sm:col-span-4 min-w-0 flex justify-end">
                                            <button
                                                type="button"
                                                onclick={() => removeItem(idx)}
                                                class="p-2 text-slate-500 hover:text-rose-400 shrink-0"
                                                title="Quitar producto"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    {/if}
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

                <!-- SECCIÓN DE ABONO INICIAL (SOLO AL CREAR Y CON PERMISOS FINANCIEROS) -->
                {#if hasFinancialAccess && !isEditingOrder}
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
                            </div>
                        {/if}
                    </div>
                {/if}

                <!-- TOTAL, DESGLOSE DE SALDO EN VIVO Y BOTONES -->
                <div class="pt-3 border-t border-slate-800 space-y-3">
                    {#if hasFinancialAccess}
                        {#if !isEditingOrder && orderForm.has_initial_deposit && Number(orderForm.initial_deposit) > 0}
                            <div class="p-3.5 bg-slate-950 rounded-2xl border border-slate-800 space-y-2 text-xs">
                                <div class="flex justify-between items-center text-slate-400">
                                    <span>Subtotal / Total del Pedido:</span>
                                    <span class="font-mono font-bold text-white text-sm">{formatCurrency(totalAmount)}</span>
                                </div>
                                <div class="flex justify-between items-center text-emerald-400">
                                    <span>Abono Inicial (-):</span>
                                    <span class="font-mono font-bold">- {formatCurrency(orderForm.initial_deposit)}</span>
                                </div>
                                <div class="flex justify-between items-center pt-2 border-t border-slate-800 font-bold">
                                    <span class="text-slate-300">Saldo Faltante a Cobrar:</span>
                                    <span class="font-mono text-sm {initialPendingBalance > 0 ? 'text-amber-400' : 'text-emerald-400'}">
                                        {initialPendingBalance > 0 ? formatCurrency(initialPendingBalance) : '✓ Total Cubierto ($0)'}
                                    </span>
                                </div>
                            </div>
                        {/if}
                    {/if}

                    <div class="flex items-center justify-between">
                        {#if hasFinancialAccess}
                            <div class="text-xs">
                                <span class="text-slate-400">Total del Pedido: </span>
                                <span class="font-mono font-bold text-emerald-400 text-base">{formatCurrency(totalAmount)}</span>
                            </div>
                        {:else}
                            <div></div>
                        {/if}

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
                                {processing ? (isEditingOrder ? 'Guardando...' : 'Creando...') : (isEditingOrder ? 'Guardar Cambios' : 'Crear Pedido')}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
{/if}

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

<!-- MODAL FLOTANTE DE CREACIÓN RÁPIDA DE CLIENTE -->
<QuickCreateModal
    bind:isOpen={quickCustomerModalOpen}
    type="customer"
    onSuccess={handleQuickCustomerSuccess}
/>

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

<!-- MODAL COMPLETO DE PRODUCTO EN CONTEXTO ASYNC (Nota 7, 8, 9) -->
<ProductFormModal
    bind:isOpen={productModalOpen}
    asyncMode={true}
    {productTypes}
    {workflows}
    {rawMaterials}
    {orthogonalAttributes}
    {attributes}
    {unitsOfMeasure}
    onSuccess={handleProductCreated}
/>
