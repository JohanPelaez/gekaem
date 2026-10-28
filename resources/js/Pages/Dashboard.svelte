<script>
    import { untrack } from 'svelte';
    import { Link, router, page } from '@inertiajs/svelte';
    import AppLayout from '../Layouts/AppLayout.svelte';
    import OrderFormModal from '../Components/OrderFormModal.svelte';
    import { canViewPrices } from '../Utils/permissions.js';

    let {
        totalOrdersCount = 0,
        totalRevenue = 0,
        completedTasksCount = 0,
        pendingOrdersCount = 0,
        activeCustomersCount = 0,
        recentOrders = [],
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
        allScheduledOrders = [],
        ordersCountByDate = {},
    } = $props();

    let authUser = $derived(page.props.auth?.user);
    let hasFinancialAccess = $derived(canViewPrices(authUser));

    // Modales para Crear / Editar Pedido
    let showOrderModal = $state(false);
    let isEditingOrder = $state(false);
    let selectedOrderForEdit = $state(null);

    // Listas reactivas sincronizadas para Quick Create dentro de OrderFormModal
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

    // Filtros y Búsqueda en Pedidos Recientes
    let searchQuery = $state('');
    let statusFilter = $state('all');
    let sortBy = $state('newest');
    let currentPage = $state(1);
    const pageSize = 8;

    function formatCurrency(val) {
        if (val === null || val === undefined) return '$0';
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
        }).format(Number(val));
    }

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return String(dateStr).slice(0, 10);
        return d.toLocaleDateString('es-CO', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    }

    // Filtrado y Ordenamiento
    let filteredOrders = $derived.by(() => {
        let list = [...(recentOrders || [])];

        // Filtro por búsqueda
        if (searchQuery.trim()) {
            const q = searchQuery.toLowerCase().trim();
            list = list.filter((order) => {
                const orderNum = String(order.order_number || '');
                const customerName = (order.customer?.name || '').toLowerCase();
                const taskItems = (order.productionTasks || order.tasks || [])
                    .map((t) => (t.item_name_snapshot || '').toLowerCase())
                    .join(' ');
                return orderNum.includes(q) || customerName.includes(q) || taskItems.includes(q);
            });
        }

        // Filtro por estado
        if (statusFilter !== 'all') {
            if (statusFilter === 'pending') {
                list = list.filter((o) => o.derived_status === 'pending');
            } else if (statusFilter === 'in_progress') {
                list = list.filter((o) => o.derived_status === 'in_progress');
            } else if (statusFilter === 'ready') {
                list = list.filter((o) => o.derived_status === 'ready' || o.derived_status === 'delivered');
            } else if (statusFilter === 'blocked') {
                list = list.filter((o) => o.derived_status === 'blocked');
            }
        }

        // Ordenamiento
        if (sortBy === 'newest') {
            list.sort((a, b) => Number(b.id || 0) - Number(a.id || 0));
        } else if (sortBy === 'delivery_date') {
            list.sort((a, b) => {
                const dateA = a.committed_delivery_at || a.predicted_delivery_at || '9999-12-31';
                const dateB = b.committed_delivery_at || b.predicted_delivery_at || '9999-12-31';
                return dateA.localeCompare(dateB);
            });
        } else if (sortBy === 'highest_total') {
            list.sort((a, b) => Number(b.total_amount || 0) - Number(a.total_amount || 0));
        }

        return list;
    });

    let totalPages = $derived(Math.max(1, Math.ceil(filteredOrders.length / pageSize)));

    $effect(() => {
        // Reset page if filtered results change
        if (currentPage > totalPages) {
            currentPage = 1;
        }
    });

    let paginatedOrders = $derived.by(() => {
        const start = (currentPage - 1) * pageSize;
        return filteredOrders.slice(start, start + pageSize);
    });

    function getStatusBadge(order) {
        if (order.orderStatus?.name) {
            const hex = order.orderStatus.color || '#64748B';
            return {
                label: order.orderStatus.name,
                style: `background-color: ${hex}18; color: ${hex}; border-color: ${hex}55;`,
            };
        }

        switch (order.derived_status) {
            case 'ready':
                return {
                    label: 'Listo',
                    classes: 'bg-[#06B6D4]/10 text-[#06B6D4] border-[#06B6D4]/40',
                };
            case 'delivered':
                return {
                    label: 'Entregado',
                    classes: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30',
                };
            case 'in_progress':
                return {
                    label: 'En Producción',
                    classes: 'bg-[#4F46E5]/10 text-[#4F46E5] dark:text-indigo-400 border-[#4F46E5]/40',
                };
            case 'blocked':
                return {
                    label: 'Bloqueado',
                    classes: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/30',
                };
            case 'pending':
            default:
                return {
                    label: 'Pendiente',
                    classes: 'bg-[#64748B]/15 text-[#64748B] dark:text-slate-300 border-[#64748B]/40',
                };
        }
    }

    function openCreateModal() {
        selectedOrderForEdit = null;
        isEditingOrder = false;
        showOrderModal = true;
    }

    function openEditModal(order) {
        selectedOrderForEdit = order;
        isEditingOrder = true;
        showOrderModal = true;
    }

    let openActionMenuOrderId = $state(null);
    let menuCoords = $state({ top: 0, right: 0, openUpwards: false, rectTop: 0 });

    function toggleActionMenu(e, orderId) {
        e.stopPropagation();
        if (openActionMenuOrderId === orderId) {
            openActionMenuOrderId = null;
            return;
        }
        const rect = e.currentTarget.getBoundingClientRect();
        const openUpwards = window.innerHeight - rect.bottom < 220;
        menuCoords = {
            top: rect.bottom + 6,
            right: Math.max(12, window.innerWidth - rect.right),
            openUpwards,
            rectTop: rect.top,
        };
        openActionMenuOrderId = orderId;
    }
</script>

<svelte:window onclick={() => openActionMenuOrderId = null} onscroll={() => openActionMenuOrderId = null} />

<AppLayout title="Dashboard - gipPyme">
    <div class="space-y-4 w-full max-w-none">
        <!-- CABECERA PRINCIPAL -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Panel de Pedidos
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    Gestor Inteligente de Pedidos
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    href="/kanban"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-medium border border-slate-200 dark:border-[#26334D] bg-white dark:bg-[#151D2C] text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-[#1C263B] transition-colors shadow-sm"
                >
                    <svg class="w-4 h-4 text-[#4F46E5] dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                    </svg>
                    <span>Tablero Kanban</span>
                </Link>
                <button
                    type="button"
                    onclick={openCreateModal}
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold bg-[#4F46E5] hover:bg-[#4338CA] text-white transition-all shadow-sm"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>Nuevo Pedido</span>
                </button>
            </div>
        </div>


        <!-- FILA DE 4 TARJETAS KPI -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <!-- TARJETA 1: TOTAL PEDIDOS -->
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-5 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[#64748B] dark:text-slate-400">
                        Total Pedidos
                    </span>
                    <span class="p-2 rounded-xl bg-cyan-50 dark:bg-cyan-950/40 text-[#06B6D4]">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {totalOrdersCount}
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between pt-2 border-t border-slate-100 dark:border-[#26334D]/60">
                    <span class="inline-flex items-center text-xs font-semibold text-[#06B6D4] gap-1">
                        <span>↗ En taller</span>
                    </span>
                    <!-- Mini gráfico SVG Cian ascendente -->
                    <svg class="w-24 h-8" viewBox="0 0 100 32" fill="none" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="grad-cyan-kpi" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#06B6D4" stop-opacity="0.4"/>
                                <stop offset="100%" stop-color="#06B6D4" stop-opacity="0.0"/>
                            </linearGradient>
                        </defs>
                        <path d="M0,26 Q30,22 55,14 T100,5 L100,32 L0,32 Z" fill="url(#grad-cyan-kpi)"/>
                        <path d="M0,26 Q30,22 55,14 T100,5" stroke="#06B6D4" stroke-width="2.5" stroke-linecap="round" fill="none"/>
                    </svg>
                </div>
            </div>

            <!-- TARJETA 2: INGRESOS O TAREAS COMPLETADAS -->
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-5 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[#64748B] dark:text-slate-400">
                        {hasFinancialAccess ? 'Ingresos' : 'Tareas Completadas'}
                    </span>
                    <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 text-[#4F46E5] dark:text-indigo-400">
                        {#if hasFinancialAccess}
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        {:else}
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        {/if}
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white truncate">
                        {hasFinancialAccess ? formatCurrency(totalRevenue) : completedTasksCount}
                    </div>
                </div>
                <div class="mt-3 flex items-center justify-between pt-2 border-t border-slate-100 dark:border-[#26334D]/60">
                    <span class="inline-flex items-center text-xs font-semibold text-[#4F46E5] dark:text-indigo-400 gap-1">
                        <span>{hasFinancialAccess ? 'Ventas totales' : 'Listas en Kanban'}</span>
                    </span>
                    <!-- Mini gráfico SVG Onda suave -->
                    <svg class="w-24 h-8" viewBox="0 0 100 32" fill="none" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="grad-indigo-kpi" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#4F46E5" stop-opacity="0.35"/>
                                <stop offset="100%" stop-color="#4F46E5" stop-opacity="0.0"/>
                            </linearGradient>
                        </defs>
                        <path d="M0,20 Q25,8 50,18 T100,7 L100,32 L0,32 Z" fill="url(#grad-indigo-kpi)"/>
                        <path d="M0,20 Q25,8 50,18 T100,7" stroke="#4F46E5" stroke-width="2.5" stroke-linecap="round" fill="none"/>
                    </svg>
                </div>
            </div>

            <!-- TARJETA 3: PEDIDOS PENDIENTES -->
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-5 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[#64748B] dark:text-slate-400">
                        Pedidos Pendientes
                    </span>
                    <span class="p-2 rounded-xl bg-amber-50 dark:bg-amber-950/40 text-amber-500">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {pendingOrdersCount}
                    </div>
                </div>
                <div class="mt-4 pt-2 border-t border-slate-100 dark:border-[#26334D]/60">
                    <div class="flex items-center justify-between text-xs text-[#64748B] dark:text-slate-400 mb-1.5">
                        <span>Por despachar</span>
                        <span class="font-medium">{pendingOrdersCount} activos</span>
                    </div>
                    <!-- Barra horizontal con indicador circular al inicio -->
                    <div class="w-full h-2 bg-slate-200 dark:bg-slate-700/80 rounded-full relative flex items-center">
                        <div class="absolute -left-0.5 w-3 h-3 bg-[#4F46E5] rounded-full ring-2 ring-white dark:ring-[#151D2C] shadow-sm"></div>
                        <div
                            class="h-2 bg-[#4F46E5]/40 rounded-full transition-all"
                            style="width: {totalOrdersCount > 0 ? Math.min(100, Math.max(10, Math.round((pendingOrdersCount / totalOrdersCount) * 100))) : 0}%"
                        ></div>
                    </div>
                </div>
            </div>

            <!-- TARJETA 4: CLIENTES ACTIVOS -->
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-5 shadow-sm flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-[#64748B] dark:text-slate-400">
                        Clientes Activos
                    </span>
                    <span class="p-2 rounded-xl bg-cyan-50 dark:bg-cyan-950/40 text-[#06B6D4]">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3">
                    <div class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {activeCustomersCount}
                    </div>
                </div>
                <div class="mt-4 pt-2 border-t border-slate-100 dark:border-[#26334D]/60">
                    <div class="flex items-center justify-between text-xs text-[#64748B] dark:text-slate-400 mb-1.5">
                        <span>Fidelización</span>
                        <span class="font-medium text-[#06B6D4]">100% registrados</span>
                    </div>
                    <!-- Barra de progreso horizontal con relleno en Cian Vibrante -->
                    <div class="w-full h-2 bg-slate-200 dark:bg-slate-700/80 rounded-full overflow-hidden">
                        <div class="h-full bg-[#06B6D4] rounded-full" style="width: 100%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TARJETA PRINCIPAL: PEDIDOS RECIENTES -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl shadow-sm overflow-hidden">
            <!-- CABECERA DE LA TARJETA -->
            <div class="p-4 sm:p-5 border-b border-slate-200 dark:border-[#26334D] flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        Pedidos Recientes
                    </h2>
                    <p class="text-xs text-[#64748B] dark:text-slate-400">
                        Mostrando {filteredOrders.length} pedidos ordenados según tus preferencias
                    </p>
                </div>

                <!-- CONTROLES: BÚSQUEDA + FILTRO + ORDENAMIENTO -->
                <div class="flex flex-wrap items-center gap-2.5">
                    <!-- Buscador compacto con icono de lupa -->
                    <div class="relative min-w-[200px] flex-1 sm:flex-initial">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </span>
                        <input
                            type="text"
                            bind:value={searchQuery}
                            placeholder="Buscar pedido, cliente..."
                            class="w-full pl-9 pr-3 py-1.5 text-xs bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 rounded-xl focus:border-[#4F46E5] focus:ring-1 focus:ring-[#4F46E5]"
                        />
                    </div>

                    <!-- Selector desplegable de Filtros -->
                    <select
                        bind:value={statusFilter}
                        class="text-xs px-3 py-1.5 bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-700 dark:text-slate-200 rounded-xl focus:border-[#4F46E5] focus:ring-1 focus:ring-[#4F46E5]"
                        aria-label="Filtrar por estado"
                    >
                        <option value="all">Todos los estados</option>
                        <option value="pending">Pendiente</option>
                        <option value="in_progress">Procesando / En Producción</option>
                        <option value="ready">Completado / Listo</option>
                        <option value="blocked">Bloqueado</option>
                    </select>

                    <!-- Selector desplegable de Ordenamiento -->
                    <select
                        bind:value={sortBy}
                        class="text-xs px-3 py-1.5 bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-700 dark:text-slate-200 rounded-xl focus:border-[#4F46E5] focus:ring-1 focus:ring-[#4F46E5]"
                        aria-label="Ordenar pedidos"
                    >
                        <option value="newest">Más recientes</option>
                        <option value="delivery_date">Entrega próxima</option>
                        <option value="highest_total">Mayor total</option>
                    </select>
                </div>
            </div>

            <!-- TABLA ESTILIZADA GIPPYME -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-slate-50/70 dark:bg-[#0B0F17]/50 border-b border-slate-200 dark:border-[#26334D] text-[#64748B] dark:text-slate-400 font-semibold uppercase tracking-wider text-[11px]">
                            <th class="px-4 py-3">ID Pedido</th>
                            <th class="px-4 py-3">Cliente</th>
                            <th class="px-4 py-3">Artículos</th>
                            {#if hasFinancialAccess}
                                <th class="px-4 py-3">Total</th>
                            {/if}
                            <th class="px-4 py-3">Entrega</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-[#26334D]/60 text-slate-700 dark:text-slate-200">
                        {#if paginatedOrders.length === 0}
                            <tr>
                                <td colspan={hasFinancialAccess ? 7 : 6} class="px-4 py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <svg class="w-8 h-8 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                        <span>No se encontraron pedidos con los filtros aplicados.</span>
                                    </div>
                                </td>
                            </tr>
                        {:else}
                            {#each paginatedOrders as order (order.id)}
                                {@const badge = getStatusBadge(order)}
                                {@const tasks = order.productionTasks || order.tasks || []}
                                <tr class="border-b border-slate-100 dark:border-[#26334D]/60 hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                                    <!-- ID Pedido -->
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono font-bold text-slate-900 dark:text-white bg-slate-100 dark:bg-[#0B0F17] px-2 py-0.5 rounded-lg border border-slate-200 dark:border-[#26334D]">
                                                #{order.order_number}
                                            </span>
                                            {#if order.shipping_type === 'contraentrega'}
                                                <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20" title="Pago Contraentrega">
                                                    Contraentrega
                                                </span>
                                            {/if}
                                        </div>
                                    </td>

                                    <!-- Cliente -->
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        <div class="font-semibold text-slate-900 dark:text-white">
                                            {order.customer?.name || 'Cliente no asignado'}
                                        </div>
                                        {#if order.customer?.phone}
                                            <div class="text-[11px] text-[#64748B] dark:text-slate-400">
                                                {order.customer.phone}
                                            </div>
                                        {/if}
                                    </td>

                                    <!-- Artículos -->
                                    <td class="px-4 py-3.5 max-w-xs">
                                        {#if tasks.length > 0}
                                            <div class="space-y-0.5">
                                                {#each tasks.slice(0, 2) as task}
                                                    <div class="text-xs truncate text-slate-800 dark:text-slate-300">
                                                        <span class="font-bold text-[#4F46E5] dark:text-indigo-400">{task.quantity}x</span> {task.item_name_snapshot}
                                                    </div>
                                                {/each}
                                                {#if tasks.length > 2}
                                                    <span class="text-[10px] text-[#64748B] dark:text-slate-400 font-medium">
                                                        +{tasks.length - 2} ítem(s) más
                                                    </span>
                                                {/if}
                                            </div>
                                        {:else}
                                            <span class="text-slate-400 italic">Sin artículos</span>
                                        {/if}
                                    </td>

                                    <!-- Total -->
                                    {#if hasFinancialAccess}
                                        <td class="px-4 py-3.5 whitespace-nowrap font-mono">
                                            <div class="font-bold text-slate-900 dark:text-white">
                                                {formatCurrency(order.total_amount)}
                                            </div>
                                            {#if order.pending_balance > 0}
                                                <div class="text-[10px] text-amber-500 font-medium">
                                                    Saldo: {formatCurrency(order.pending_balance)}
                                                </div>
                                            {:else if Number(order.total_amount) > 0}
                                                <div class="text-[10px] text-emerald-500 font-medium">
                                                    ✓ Pagado
                                                </div>
                                            {/if}
                                        </td>
                                    {/if}

                                    <!-- Entrega -->
                                    <td class="px-4 py-3.5 whitespace-nowrap text-slate-600 dark:text-slate-400">
                                        <div class="text-xs">
                                            {formatDate(order.committed_delivery_at || order.predicted_delivery_at)}
                                        </div>
                                    </td>

                                    <!-- Estado (Píldora estilizada) -->
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        {#if badge.style}
                                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium border" style={badge.style}>
                                                {badge.label}
                                            </span>
                                        {:else}
                                            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium border {badge.classes}">
                                                {badge.label}
                                            </span>
                                        {/if}
                                    </td>

                                    <!-- Acciones -->
                                    <td class="px-4 py-3.5 whitespace-nowrap text-right">
                                        <div class="inline-flex items-center justify-end">
                                            <button
                                                type="button"
                                                onclick={(e) => toggleActionMenu(e, order.id)}
                                                class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#1C263B] transition-colors font-bold text-xs"
                                                title="Más opciones"
                                            >
                                                •••
                                            </button>
                                        </div>

                                        <!-- Menú Contextual Desplegable Fixed -->
                                        {#if openActionMenuOrderId === order.id}
                                            <div
                                                role="menu"
                                                tabindex="-1"
                                                onclick={(e) => e.stopPropagation()}
                                                onkeydown={(e) => { if (e.key === 'Escape') openActionMenuOrderId = null; }}
                                                class="fixed z-[9999] w-52 rounded-xl bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] shadow-xl py-1.5 text-left divide-y divide-slate-100 dark:divide-[#26334D]"
                                                style="right: {menuCoords.right}px; {menuCoords.openUpwards ? `bottom: ${window.innerHeight - menuCoords.rectTop + 6}px;` : `top: ${menuCoords.top}px;`}"
                                            >
                                                <div class="py-0.5">
                                                    <button
                                                        type="button"
                                                        onclick={() => { openActionMenuOrderId = null; openEditModal(order); }}
                                                        class="w-full flex items-center gap-2 px-3 py-1.5 text-xs text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors"
                                                    >
                                                        <span>✏️</span>
                                                        <span>Editar Pedido</span>
                                                    </button>
                                                </div>
                                                <div class="py-0.5">
                                                    <button
                                                        type="button"
                                                        onclick={() => { openActionMenuOrderId = null; router.visit(`/kanban?order_id=${order.id}`); }}
                                                        class="w-full flex items-center gap-2 px-3 py-1.5 text-xs text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors"
                                                    >
                                                        <span>📋</span>
                                                        <span>Ver en Tablero Kanban</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onclick={() => { openActionMenuOrderId = null; router.visit(`/orders?search=${order.order_number}`); }}
                                                        class="w-full flex items-center gap-2 px-3 py-1.5 text-xs text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors"
                                                    >
                                                        <span>🔍</span>
                                                        <span>Detalle en Pedidos</span>
                                                    </button>
                                                </div>
                                            </div>
                                        {/if}
                                    </td>
                                </tr>
                            {/each}
                        {/if}
                    </tbody>
                </table>
            </div>

            <!-- PIE DE TABLA: PAGINACIÓN -->
            <div class="px-4 py-3 border-t border-slate-200 dark:border-[#26334D] bg-slate-50/50 dark:bg-[#0B0F17]/40 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs text-[#64748B] dark:text-slate-400">
                <div>
                    Página <span class="font-bold text-slate-900 dark:text-white">{currentPage}</span> de <span class="font-bold text-slate-900 dark:text-white">{totalPages}</span>
                    <span class="hidden sm:inline">({filteredOrders.length} pedidos en total)</span>
                </div>

                <div class="flex items-center gap-1.5">
                    <button
                        type="button"
                        onclick={() => currentPage = Math.max(1, currentPage - 1)}
                        disabled={currentPage <= 1}
                        class="p-1.5 rounded-lg border border-slate-200 dark:border-[#26334D] hover:bg-slate-100 dark:hover:bg-[#1C263B] disabled:opacity-40 disabled:cursor-not-allowed text-slate-700 dark:text-slate-300"
                        title="Página anterior"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>

                    {#each Array(totalPages) as _, i}
                        {@const p = i + 1}
                        {#if p === currentPage || p === 1 || p === totalPages || Math.abs(p - currentPage) <= 1}
                            <button
                                type="button"
                                onclick={() => currentPage = p}
                                class="w-7 h-7 rounded-lg text-xs font-semibold flex items-center justify-center transition-all {p === currentPage ? 'bg-[#4F46E5] text-white shadow-sm border border-[#4F46E5]' : 'border border-slate-200 dark:border-[#26334D] hover:bg-slate-100 dark:hover:bg-[#1C263B] text-slate-700 dark:text-slate-300'}"
                            >
                                {p}
                            </button>
                        {:else if (p === 2 && currentPage > 3) || (p === totalPages - 1 && currentPage < totalPages - 2)}
                            <span class="px-1 text-slate-400">...</span>
                        {/if}
                    {/each}

                    <button
                        type="button"
                        onclick={() => currentPage = Math.min(totalPages, currentPage + 1)}
                        disabled={currentPage >= totalPages}
                        class="p-1.5 rounded-lg border border-slate-200 dark:border-[#26334D] hover:bg-slate-100 dark:hover:bg-[#1C263B] disabled:opacity-40 disabled:cursor-not-allowed text-slate-700 dark:text-slate-300"
                        title="Página siguiente"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</AppLayout>

<!-- MODAL CREAR / EDITAR PEDIDO DESDE EL DASHBOARD -->
<OrderFormModal
    bind:isOpen={showOrderModal}
    isEditing={isEditingOrder}
    editingOrder={selectedOrderForEdit}
    customers={localCustomers}
    products={localProducts}
    {workflows}
    {productTypes}
    {rawMaterials}
    {orthogonalAttributes}
    {attributes}
    {unitsOfMeasure}
    {allScheduledOrders}
    {ordersCountByDate}
    {hasFinancialAccess}
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
        showOrderModal = false;
        router.reload({ preserveScroll: true });
    }}
    onClose={() => {
        showOrderModal = false;
    }}
/>
