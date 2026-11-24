<script>
    import { untrack } from 'svelte';
    import { Link, router } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import OrderPaymentsManager from '../../Components/OrderPaymentsManager.svelte';
    import { csrfFetch } from '../../Utils/csrfFetch.js';

    let {
        orders = [],
        activeStatuses = [],
        customers = [],
    } = $props();

    // Copia local reactiva para remover pedidos instantáneamente al devolverlos al tablero
    let localOrders = $state([]);
    $effect(() => {
        const incoming = orders;
        untrack(() => {
            localOrders = JSON.parse(JSON.stringify(incoming || []));
        });
    });

    // Filtros reactivos
    let searchQuery = $state('');
    let customerFilter = $state('');
    let paymentFilter = $state('all'); // 'all' | 'paid' | 'pending'
    let dateRangeFilter = $state('all'); // 'all' | '7d' | '30d' | 'this_month'

    // Modal "Devolver Pedido al Tablero"
    let restoreModalOpen = $state(false);
    let orderToRestore = $state(null);
    let selectedTargetStatusId = $state('');
    let isRestoring = $state(false);
    let restoreError = $state('');

    // Modal rápido de Abonos / Pagos (útil para envíos contraentrega con saldo pendiente)
    let paymentModalOpen = $state(false);
    let selectedOrderForPayment = $state(null);

    // Toast de éxito / error
    let toast = $state({ visible: false, type: 'success', message: '' });
    let toastTimer = null;

    function showToast(message, type = 'success') {
        if (toastTimer) clearTimeout(toastTimer);
        toast = { visible: true, type, message };
        toastTimer = setTimeout(() => {
            toast = { visible: false, type: 'success', message: '' };
        }, 5500);
    }

    function getOrderTasks(order) {
        return order?.production_tasks ?? order?.productionTasks ?? order?.tasks ?? [];
    }

    function formatCurrency(val) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
        }).format(Number(val) || 0);
    }

    function formatShippedDate(dateStr) {
        if (!dateStr) return 'Sin fecha';
        const d = new Date(dateStr);
        if (Number.isNaN(d.getTime())) return 'Sin fecha';
        return d.toLocaleDateString('es-CO', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function getPendingBalance(order) {
        if (order?.pending_balance !== undefined && order?.pending_balance !== null) {
            return Math.max(0, Number(order.pending_balance));
        }
        const total = Number(order?.total_amount || 0);
        const paid = Number(order?.paid_amount || 0);
        return Math.max(0, total - paid);
    }

    function isOrderPaid(order) {
        return getPendingBalance(order) <= 0.01;
    }

    // KPIs de cabecera
    let totalShippedCount = $derived(localOrders.length);
    let pendingPaymentOrders = $derived(localOrders.filter((o) => !isOrderPaid(o)));
    let pendingPaymentCount = $derived(pendingPaymentOrders.length);
    let totalPendingAmount = $derived(
        pendingPaymentOrders.reduce((sum, o) => sum + getPendingBalance(o), 0)
    );

    // Listado filtrado reactivo
    let filteredOrders = $derived.by(() => {
        const term = searchQuery.trim().toLowerCase();
        const now = new Date();
        const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());

        return localOrders.filter((order) => {
            // 1. Buscador por # pedido, cliente o producto
            if (term) {
                const orderNum = String(order.order_number || '').toLowerCase();
                const orderNumHash = `#${orderNum}`;
                const custName = (order.customer?.name || '').toLowerCase();
                const custPhone = (order.customer?.phone || '').toLowerCase();
                const tasks = getOrderTasks(order);
                const matchesTask = tasks.some((t) => {
                    const snap = (t.item_name_snapshot || '').toLowerCase();
                    const prodName = (t.product_variant?.product?.name || t.productVariant?.product?.name || '').toLowerCase();
                    return snap.includes(term) || prodName.includes(term);
                });

                const matches =
                    orderNum.includes(term) ||
                    orderNumHash.includes(term) ||
                    custName.includes(term) ||
                    custPhone.includes(term) ||
                    matchesTask;

                if (!matches) return false;
            }

            // 2. Filtro por Cliente
            if (customerFilter && Number(order.customer_id) !== Number(customerFilter)) {
                return false;
            }

            // 3. Filtro por Estado de Pago
            if (paymentFilter === 'paid' && !isOrderPaid(order)) {
                return false;
            }
            if (paymentFilter === 'pending' && isOrderPaid(order)) {
                return false;
            }

            // 4. Filtro por rango de fecha de envío
            if (dateRangeFilter !== 'all') {
                const rawDate = order.shipped_at || order.updated_at;
                if (!rawDate) return false;
                const shippedDate = new Date(rawDate);
                if (Number.isNaN(shippedDate.getTime())) return false;

                if (dateRangeFilter === '7d') {
                    const limit = new Date(startOfToday);
                    limit.setDate(limit.getDate() - 7);
                    if (shippedDate < limit) return false;
                } else if (dateRangeFilter === '30d') {
                    const limit = new Date(startOfToday);
                    limit.setDate(limit.getDate() - 30);
                    if (shippedDate < limit) return false;
                } else if (dateRangeFilter === 'this_month') {
                    if (
                        shippedDate.getFullYear() !== now.getFullYear() ||
                        shippedDate.getMonth() !== now.getMonth()
                    ) {
                        return false;
                    }
                }
            }

            return true;
        });
    });

    let hasActiveFilters = $derived(
        Boolean(searchQuery.trim() || customerFilter || paymentFilter !== 'all' || dateRangeFilter !== 'all')
    );

    function clearFilters() {
        searchQuery = '';
        customerFilter = '';
        paymentFilter = 'all';
        dateRangeFilter = 'all';
    }

    function openRestoreModal(order) {
        orderToRestore = order;
        restoreError = '';
        // Preseleccionar la última columna activa (ej. Listo para Entrega) o la primera disponible
        const readyStatus = activeStatuses.find((s) => s.system_key === 'ready');
        const fallback = activeStatuses[activeStatuses.length - 1] ?? activeStatuses[0] ?? null;
        selectedTargetStatusId = String((readyStatus ?? fallback)?.id ?? '');
        restoreModalOpen = true;
    }

    function closeRestoreModal() {
        if (isRestoring) return;
        restoreModalOpen = false;
        orderToRestore = null;
        selectedTargetStatusId = '';
        restoreError = '';
    }

    async function confirmRestore() {
        if (!orderToRestore || !selectedTargetStatusId) {
            restoreError = 'Selecciona una columna de destino en el Tablero de Pedidos.';
            return;
        }

        isRestoring = true;
        restoreError = '';

        try {
            const res = await csrfFetch(`/orders/${orderToRestore.id}/restore`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    order_status_id: Number(selectedTargetStatusId),
                }),
            });

            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                throw new Error(data.message || 'No se pudo devolver el pedido al tablero.');
            }

            const restoredId = orderToRestore.id;
            const targetName =
                data.target_status?.name ||
                activeStatuses.find((s) => Number(s.id) === Number(selectedTargetStatusId))?.name ||
                'el tablero';
            const orderNum = orderToRestore.order_number;

            localOrders = localOrders.filter((o) => o.id !== restoredId);
            restoreModalOpen = false;
            orderToRestore = null;
            selectedTargetStatusId = '';

            showToast(`↩️ Pedido #${orderNum} devuelto a la columna "${targetName}" del Tablero de Pedidos.`, 'success');
        } catch (err) {
            restoreError = err.message || 'Error al devolver el pedido al tablero.';
        } finally {
            isRestoring = false;
        }
    }

    function openPaymentModal(order) {
        selectedOrderForPayment = order;
        paymentModalOpen = true;
    }

    function closePaymentModal() {
        paymentModalOpen = false;
        selectedOrderForPayment = null;
    }

    function handlePaymentsUpdated() {
        router.reload({
            preserveScroll: true,
            only: ['orders'],
            onSuccess: (pageData) => {
                const updatedList = pageData?.props?.orders || [];
                if (selectedOrderForPayment) {
                    const fresh = updatedList.find((o) => o.id === selectedOrderForPayment.id);
                    if (fresh) {
                        selectedOrderForPayment = fresh;
                    }
                }
            },
        });
    }
</script>

<AppLayout title="Historial de Pedidos">
    <div class="space-y-5 w-full max-w-none min-w-0">
        <!-- CABECERA Y KPIS RÁPIDOS -->
        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        Historial de Pedidos
                    </h1>
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-50 dark:bg-[#4F46E5]/15 text-[#4F46E5] dark:text-indigo-300 border border-indigo-200 dark:border-[#4F46E5]/30">
                        Exclusivo CEO
                    </span>
                </div>
                <p class="text-xs text-[#64748B] dark:text-slate-400 mt-1">
                    Consulta todos los pedidos marcados como enviados y devuélvelos al tablero si fue necesario corregir su estado.
                </p>
            </div>

            <!-- KPIs de Cabecera -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 shrink-0">
                <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl px-4 py-3 shadow-xs flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-[#64748B] dark:text-slate-400">
                            Total Enviados
                        </p>
                        <p class="text-lg font-black text-slate-900 dark:text-white font-mono leading-tight">
                            {totalShippedCount}
                        </p>
                    </div>
                </div>

                <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl px-4 py-3 shadow-xs flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl {pendingPaymentCount > 0 ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800/40 text-amber-600 dark:text-amber-400' : 'bg-slate-50 dark:bg-slate-800/60 border-slate-200 dark:border-slate-700 text-slate-500'} border flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-[#64748B] dark:text-slate-400">
                            Con Saldo Pendiente
                        </p>
                        <div class="flex items-baseline gap-2">
                            <span class="text-lg font-black font-mono leading-tight {pendingPaymentCount > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-white'}">
                                {pendingPaymentCount}
                            </span>
                            {#if totalPendingAmount > 0}
                                <span class="text-[11px] font-mono font-bold text-rose-600 dark:text-rose-400">
                                    ({formatCurrency(totalPendingAmount)})
                                </span>
                            {/if}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- BARRA DE FILTROS REACTIVOS -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] p-4 rounded-3xl shadow-sm space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <!-- Buscador -->
                <div>
                    <label for="history-search" class="block text-[11px] font-semibold text-[#64748B] dark:text-slate-400 mb-1">
                        Buscar por # Pedido, Cliente o Producto
                    </label>
                    <input
                        id="history-search"
                        type="text"
                        bind:value={searchQuery}
                        placeholder="Ej. #104, Camila, Camiseta..."
                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-slate-100 text-xs placeholder:text-slate-400 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20"
                    />
                </div>

                <!-- Cliente -->
                <div>
                    <label for="history-customer" class="block text-[11px] font-semibold text-[#64748B] dark:text-slate-400 mb-1">
                        Cliente
                    </label>
                    <select
                        id="history-customer"
                        bind:value={customerFilter}
                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-slate-100 text-xs focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20"
                    >
                        <option value="">Todos los Clientes</option>
                        {#each customers as cust (cust.id)}
                            <option value={cust.id}>{cust.name}</option>
                        {/each}
                    </select>
                </div>

                <!-- Estado de Pago -->
                <div>
                    <label for="history-payment" class="block text-[11px] font-semibold text-[#64748B] dark:text-slate-400 mb-1">
                        Estado de Pago
                    </label>
                    <select
                        id="history-payment"
                        bind:value={paymentFilter}
                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-slate-100 text-xs focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20"
                    >
                        <option value="all">Todos</option>
                        <option value="paid">✓ Pagados</option>
                        <option value="pending">⏳ Con Saldo Pendiente</option>
                    </select>
                </div>

                <!-- Fecha de Envío -->
                <div>
                    <label for="history-date-range" class="block text-[11px] font-semibold text-[#64748B] dark:text-slate-400 mb-1">
                        Fecha de Envío
                    </label>
                    <select
                        id="history-date-range"
                        bind:value={dateRangeFilter}
                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-slate-100 text-xs focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20"
                    >
                        <option value="all">Todas las fechas</option>
                        <option value="7d">Últimos 7 días</option>
                        <option value="30d">Últimos 30 días</option>
                        <option value="this_month">Este mes</option>
                    </select>
                </div>
            </div>

            <div class="pt-2 border-t border-slate-100 dark:border-[#26334D]/60 flex items-center justify-between gap-3 text-xs">
                <span class="text-[#64748B] dark:text-slate-400 text-[11px]">
                    Mostrando <strong class="text-slate-800 dark:text-slate-200">{filteredOrders.length}</strong> de {localOrders.length} pedido(s) en historial
                </span>
                <div class="flex items-center gap-3">
                    {#if hasActiveFilters}
                        <button
                            type="button"
                            onclick={clearFilters}
                            class="text-rose-600 dark:text-rose-400 hover:underline text-[11px] font-semibold"
                        >
                            Limpiar Filtros
                        </button>
                    {/if}
                    <Link
                        href="/kanban"
                        class="text-[#4F46E5] dark:text-indigo-400 hover:underline text-[11px] font-semibold"
                    >
                        ← Ir al Tablero de Pedidos
                    </Link>
                </div>
            </div>
        </div>

        <!-- LISTADO DE PEDIDOS ENVIADOS -->
        {#if filteredOrders.length === 0}
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl p-12 text-center shadow-sm space-y-3">
                <div class="w-14 h-14 mx-auto rounded-2xl bg-slate-100 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] flex items-center justify-center text-slate-400">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                    </svg>
                </div>
                <h3 class="text-sm font-bold text-slate-900 dark:text-white">
                    {localOrders.length === 0 ? 'Aún no hay pedidos en el Historial' : 'No hay resultados con los filtros seleccionados'}
                </h3>
                <p class="text-xs text-[#64748B] dark:text-slate-400 max-w-md mx-auto">
                    {localOrders.length === 0
                        ? 'Cuando arrastres un pedido a la columna Enviado (u otra columna marcada como Historial) en el Tablero de Pedidos, aparecerá registrado aquí.'
                        : 'Prueba ajustando el buscador, el cliente, el estado de pago o el rango de fechas.'}
                </p>
            </div>
        {:else}
            <!-- Vista Tabla (Escritorio) -->
            <div class="hidden lg:block bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50/80 dark:bg-[#0B0F17]/60 border-b border-slate-200 dark:border-[#26334D] text-[10px] font-bold text-[#64748B] dark:text-slate-400 uppercase tracking-wider">
                            <tr>
                                <th class="p-4"># Pedido / Envío</th>
                                <th class="p-4">Cliente</th>
                                <th class="p-4">Productos</th>
                                <th class="p-4">Total y Saldo</th>
                                <th class="p-4">Fecha de Envío</th>
                                <th class="p-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-[#26334D]/60 text-slate-900 dark:text-slate-100">
                            {#each filteredOrders as order (order.id)}
                                {@const tasks = getOrderTasks(order)}
                                {@const pending = getPendingBalance(order)}
                                {@const paid = isOrderPaid(order)}
                                <tr class="hover:bg-slate-50/70 dark:hover:bg-[#0B0F17]/40 transition-colors">
                                    <!-- # Pedido y Badge Envío -->
                                    <td class="p-4 align-top">
                                        <div class="flex flex-col gap-1.5">
                                            <span class="font-mono text-sm font-black text-[#4F46E5] dark:text-sky-400">
                                                #{order.order_number}
                                            </span>
                                            {#if order.shipping_type === 'envio_incluido'}
                                                <span class="inline-flex w-fit items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/40">
                                                    Envío Incluido
                                                </span>
                                            {:else}
                                                <span class="inline-flex w-fit items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800/40">
                                                    Contraentrega
                                                </span>
                                            {/if}
                                        </div>
                                    </td>

                                    <!-- Cliente -->
                                    <td class="p-4 align-top">
                                        <p class="font-bold text-slate-900 dark:text-white text-xs">
                                            {order.customer?.name ?? 'Cliente'}
                                        </p>
                                        {#if order.customer?.phone}
                                            <p class="text-[11px] text-[#64748B] dark:text-slate-400 mt-0.5 font-mono">
                                                📞 {order.customer.phone}
                                            </p>
                                        {/if}
                                    </td>

                                    <!-- Productos -->
                                    <td class="p-4 align-top max-w-xs">
                                        <div class="space-y-1">
                                            {#each tasks.slice(0, 3) as task (task.id)}
                                                <div class="flex items-start gap-1.5 text-xs">
                                                    <span class="font-mono font-bold text-slate-900 dark:text-white bg-slate-100 dark:bg-slate-800 px-1.5 py-0.2 rounded shrink-0 text-[11px]">
                                                        {Math.round(Number(task.quantity))}x
                                                    </span>
                                                    <span class="text-slate-700 dark:text-slate-300 line-clamp-1" title={task.item_name_snapshot}>
                                                        {task.item_name_snapshot}
                                                    </span>
                                                </div>
                                            {/each}
                                            {#if tasks.length > 3}
                                                <span class="text-[10px] text-[#64748B] dark:text-slate-400 font-semibold block">
                                                    +{tasks.length - 3} producto(s) más
                                                </span>
                                            {/if}
                                        </div>
                                    </td>

                                    <!-- Total y Saldo -->
                                    <td class="p-4 align-top">
                                        <p class="font-mono font-extrabold text-slate-900 dark:text-white text-xs">
                                            {formatCurrency(order.total_amount)}
                                        </p>
                                        {#if paid}
                                            <span class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/40">
                                                ✓ Pagado
                                            </span>
                                        {:else}
                                            <span class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-800/40 font-mono">
                                                Saldo: {formatCurrency(pending)}
                                            </span>
                                        {/if}
                                    </td>

                                    <!-- Fecha de Envío -->
                                    <td class="p-4 align-top">
                                        <div class="flex flex-col gap-1">
                                            <span class="text-xs font-medium text-slate-800 dark:text-slate-200">
                                                {formatShippedDate(order.shipped_at || order.updated_at)}
                                            </span>
                                            {#if order.order_status?.name}
                                                <span
                                                    class="inline-flex w-fit items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold"
                                                    style="background-color: {order.order_status.color || '#10b981'}18; color: {order.order_status.color || '#10b981'}; border: 1px solid {order.order_status.color || '#10b981'}35;"
                                                >
                                                    {order.order_status.name}
                                                </span>
                                            {/if}
                                        </div>
                                    </td>

                                    <!-- Acciones -->
                                    <td class="p-4 align-top text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                onclick={() => openPaymentModal(order)}
                                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] text-[11px] font-semibold transition"
                                                title="Ver o registrar abonos de este pedido"
                                            >
                                                💳 Abonos
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => openRestoreModal(order)}
                                                class="px-3 py-1.5 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white text-[11px] font-bold shadow-xs transition flex items-center gap-1"
                                            >
                                                <span>↩️</span>
                                                <span>Devolver al Tablero</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Vista Tarjetas (Móvil / Tablet) -->
            <div class="lg:hidden grid grid-cols-1 md:grid-cols-2 gap-3">
                {#each filteredOrders as order (order.id)}
                    {@const tasks = getOrderTasks(order)}
                    {@const pending = getPendingBalance(order)}
                    {@const paid = isOrderPaid(order)}
                    <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-4 shadow-sm space-y-3">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-sm font-black text-[#4F46E5] dark:text-sky-400">
                                        #{order.order_number}
                                    </span>
                                    {#if order.shipping_type === 'envio_incluido'}
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/40">
                                            Incluido
                                        </span>
                                    {:else}
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800/40">
                                            Contraentrega
                                        </span>
                                    {/if}
                                </div>
                                <h3 class="text-sm font-bold text-slate-900 dark:text-white mt-1">
                                    {order.customer?.name ?? 'Cliente'}
                                </h3>
                                {#if order.customer?.phone}
                                    <p class="text-[11px] text-[#64748B] dark:text-slate-400 font-mono">
                                        📞 {order.customer.phone}
                                    </p>
                                {/if}
                            </div>

                            <div class="text-right">
                                <p class="font-mono font-extrabold text-xs text-slate-900 dark:text-white">
                                    {formatCurrency(order.total_amount)}
                                </p>
                                {#if paid}
                                    <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/40">
                                        ✓ Pagado
                                    </span>
                                {:else}
                                    <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-800/40 font-mono">
                                        Saldo: {formatCurrency(pending)}
                                    </span>
                                {/if}
                            </div>
                        </div>

                        <div class="space-y-1 pt-2 border-t border-slate-100 dark:border-[#26334D]/60">
                            {#each tasks.slice(0, 3) as task (task.id)}
                                <div class="flex items-center gap-1.5 text-xs">
                                    <span class="font-mono font-bold text-[11px] bg-slate-100 dark:bg-slate-800 px-1.5 rounded text-slate-900 dark:text-white">
                                        {Math.round(Number(task.quantity))}x
                                    </span>
                                    <span class="text-slate-700 dark:text-slate-300 truncate">{task.item_name_snapshot}</span>
                                </div>
                            {/each}
                            {#if tasks.length > 3}
                                <span class="text-[10px] text-[#64748B] dark:text-slate-400">+{tasks.length - 3} producto(s) más</span>
                            {/if}
                        </div>

                        <div class="flex items-center justify-between text-[11px] text-[#64748B] dark:text-slate-400 pt-2 border-t border-slate-100 dark:border-[#26334D]/60">
                            <span>📦 Enviado: <strong class="text-slate-700 dark:text-slate-200">{formatShippedDate(order.shipped_at || order.updated_at)}</strong></span>
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <button
                                type="button"
                                onclick={() => openPaymentModal(order)}
                                class="flex-1 px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] text-xs font-semibold transition"
                            >
                                💳 Abonos
                            </button>
                            <button
                                type="button"
                                onclick={() => openRestoreModal(order)}
                                class="flex-1 px-3 py-2 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white text-xs font-bold shadow-xs transition"
                            >
                                ↩️ Devolver al Tablero
                            </button>
                        </div>
                    </div>
                {/each}
            </div>
        {/if}
    </div>
</AppLayout>

<!-- MODAL ESTILIZADO: DEVOLVER PEDIDO AL TABLERO -->
{#if restoreModalOpen && orderToRestore}
    <div class="fixed inset-0 z-50 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl max-w-lg w-full p-6 shadow-2xl space-y-5 text-slate-900 dark:text-slate-100">
            <!-- Cabecera -->
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 dark:border-[#26334D] pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-[#4F46E5]/15 border border-indigo-200 dark:border-[#4F46E5]/30 text-[#4F46E5] dark:text-indigo-300 flex items-center justify-center text-base shrink-0">
                        ↩️
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">
                            Devolver Pedido al Tablero
                        </h3>
                        <p class="text-xs text-[#64748B] dark:text-slate-400">
                            Pedido <strong class="font-mono text-[#4F46E5] dark:text-sky-400">#{orderToRestore.order_number}</strong> — {orderToRestore.customer?.name ?? 'Cliente'}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    onclick={closeRestoreModal}
                    class="text-slate-400 hover:text-slate-700 dark:hover:text-white p-1 rounded-lg"
                    aria-label="Cerrar modal"
                >
                    ✕
                </button>
            </div>

            <!-- Selección de Estado de Destino -->
            <div class="space-y-3">
                <div>
                    <label for="restore-target-status" class="block text-xs font-bold text-slate-800 dark:text-slate-200 mb-1.5">
                        Seleccionar estado de destino en el Tablero de Pedidos *
                    </label>
                    <select
                        id="restore-target-status"
                        bind:value={selectedTargetStatusId}
                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2.5 text-slate-900 dark:text-white text-xs font-semibold focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20"
                    >
                        {#each activeStatuses as status (status.id)}
                            <option value={String(status.id)}>{status.name}</option>
                        {/each}
                    </select>
                </div>

                <!-- Píldoras visuales con el color HEX de cada columna -->
                {#if activeStatuses.length > 0}
                    <div class="flex flex-wrap gap-2 pt-1">
                        {#each activeStatuses as status (status.id)}
                            {@const isSelected = String(selectedTargetStatusId) === String(status.id)}
                            <button
                                type="button"
                                onclick={() => selectedTargetStatusId = String(status.id)}
                                class="px-3 py-1.5 rounded-xl text-xs font-bold flex items-center gap-2 transition-all border {isSelected
                                    ? 'ring-2 ring-[#4F46E5] bg-indigo-50/70 dark:bg-[#4F46E5]/20 border-[#4F46E5] text-slate-900 dark:text-white shadow-xs'
                                    : 'bg-slate-50 dark:bg-[#0B0F17] border-slate-200 dark:border-[#26334D] text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-600'}"
                            >
                                <span
                                    class="w-2.5 h-2.5 rounded-full shrink-0"
                                    style="background-color: {status.color || '#64748b'};"
                                ></span>
                                <span>{status.name}</span>
                            </button>
                        {/each}
                    </div>
                {/if}

                <div class="p-3 rounded-2xl bg-indigo-50/70 dark:bg-[#0B0F17] border border-indigo-200/70 dark:border-[#26334D] text-[11px] text-slate-700 dark:text-slate-300 leading-relaxed">
                    El pedido saldrá del Historial y reaparecerá inmediatamente en la columna seleccionada del Tablero de Pedidos.
                </div>

                {#if restoreError}
                    <p class="text-xs text-rose-600 dark:text-rose-400 font-semibold">{restoreError}</p>
                {/if}
            </div>

            <!-- Botones -->
            <div class="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-200 dark:border-[#26334D]">
                <button
                    type="button"
                    onclick={closeRestoreModal}
                    disabled={isRestoring}
                    class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] text-xs font-semibold transition"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    onclick={confirmRestore}
                    disabled={isRestoring || !selectedTargetStatusId}
                    class="px-4 py-2 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white text-xs font-bold shadow-sm transition disabled:opacity-50"
                >
                    {isRestoring ? 'Devolviendo...' : 'Confirmar y Devolver al Tablero'}
                </button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL RÁPIDO DE ABONOS Y COBROS -->
{#if paymentModalOpen && selectedOrderForPayment}
    <div class="fixed inset-0 z-50 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto text-slate-900 dark:text-slate-100">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">
                        Abonos — Pedido #{selectedOrderForPayment.order_number}
                    </h3>
                    <p class="text-xs text-[#64748B] dark:text-slate-400">
                        {selectedOrderForPayment.customer?.name ?? 'Cliente'} · Total {formatCurrency(selectedOrderForPayment.total_amount)}
                    </p>
                </div>
                <button
                    type="button"
                    onclick={closePaymentModal}
                    class="text-slate-400 hover:text-slate-700 dark:hover:text-white p-1 rounded-lg"
                    aria-label="Cerrar modal de abonos"
                >
                    ✕
                </button>
            </div>

            <OrderPaymentsManager
                orderId={selectedOrderForPayment.id}
                orderTotal={Number(selectedOrderForPayment.total_amount || 0)}
                payments={selectedOrderForPayment.payments || []}
                paymentSchedules={selectedOrderForPayment.payment_schedules || selectedOrderForPayment.paymentSchedules || []}
                onUpdated={handlePaymentsUpdated}
            />
        </div>
    </div>
{/if}

<!-- TOAST FLOTANTE -->
{#if toast.visible}
    <div class="fixed bottom-5 right-5 z-50 max-w-md bg-slate-900 dark:bg-[#151D2C] text-white border border-slate-700 dark:border-[#26334D] rounded-2xl px-4 py-3 shadow-2xl flex items-center gap-3">
        <span class="text-xs font-semibold leading-snug flex-1">{toast.message}</span>
        <Link
            href="/kanban"
            class="px-2.5 py-1 rounded-lg bg-[#4F46E5] hover:bg-[#4338CA] text-white text-[11px] font-bold shrink-0 transition"
        >
            Ir al Tablero
        </Link>
        <button
            type="button"
            onclick={() => toast = { visible: false, type: 'success', message: '' }}
            class="text-slate-400 hover:text-white text-xs font-bold px-1 shrink-0"
            aria-label="Cerrar notificación"
        >
            ✕
        </button>
    </div>
{/if}

