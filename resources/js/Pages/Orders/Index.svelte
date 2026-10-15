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
    let predicting = $state(false);

    // Formulario de Pedido en Svelte 5 Rune (Sin prefijo $)
    let orderForm = $state({
        customer_id: '',
        custom_buffer_percent: 20,
        committed_delivery_at: '',
        notes: '',
        items: [],
    });

    // Resultado de la predicción en tiempo real
    let predictionResult = $state(null);

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

    function resetOrderForm() {
        orderForm.customer_id = customers[0]?.id ?? '';
        orderForm.custom_buffer_percent = defaultBufferPercent ?? 20;
        orderForm.committed_delivery_at = '';
        orderForm.notes = '';
        orderForm.items = [];
        predictionResult = null;
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
        triggerPredictionDebounced();
    }

    function removeItem(index) {
        orderForm.items = orderForm.items.filter((_, i) => i !== index);
        triggerPredictionDebounced();
    }

    function onProductChange(item, productId) {
        item.product_id = Number(productId);
        const prod = products.find((p) => p.id === Number(productId));
        item.product_variant_id = prod?.variants?.[0]?.id ?? '';
        triggerPredictionDebounced();
    }

    let predictTimeout;
    function triggerPredictionDebounced() {
        clearTimeout(predictTimeout);
        predictTimeout = setTimeout(() => {
            fetchPrediction();
        }, 350);
    }

    async function fetchPrediction() {
        const validItems = orderForm.items
            .filter((i) => i.product_variant_id && Number(i.quantity) > 0)
            .map((i) => ({
                product_variant_id: Number(i.product_variant_id),
                quantity: Number(i.quantity),
            }));

        if (validItems.length === 0) {
            predictionResult = null;
            return;
        }

        predicting = true;
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const res = await fetch('/orders/predict', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    items: validItems,
                    buffer_percent: Number(orderForm.custom_buffer_percent ?? defaultBufferPercent),
                }),
            });

            if (res.ok) {
                const data = await res.json();
                predictionResult = data;
                // Prellenar fecha comprometida sugerida si aún no se ha digitado
                if (!orderForm.committed_delivery_at && data.predicted_delivery_at) {
                    const dt = new Date(data.predicted_delivery_at);
                    orderForm.committed_delivery_at = dt.toISOString().slice(0, 16);
                }
            }
        } catch (e) {
            console.error('Error al calcular predicción:', e);
        } finally {
            predicting = false;
        }
    }

    // Cálculos instantáneos con Runes de Svelte 5
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

    let calculatedBufferMinutes = $derived.by(() => {
        const base = wipQueueMinutes + totalOrderMinutes;
        const pct = Number(orderForm.custom_buffer_percent ?? defaultBufferPercent);
        const withBuffer = Math.ceil(base * (1 + pct / 100));
        return Math.max(0, withBuffer - base);
    });

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

    function submitOrder(e) {
        e.preventDefault();
        const validItems = orderForm.items.filter((i) => i.product_variant_id && Number(i.quantity) > 0);
        if (validItems.length === 0) {
            alert('Debes agregar al menos un producto con variante y cantidad válida al pedido.');
            return;
        }

        router.post('/orders', orderForm, {
            preserveScroll: true,
            onStart: () => { processing = true; },
            onFinish: () => { processing = false; },
            onSuccess: () => {
                showCreateModal = false;
                resetOrderForm();
            },
        });
    }

    function markDelivered(order) {
        if (confirm(`¿Marcar el pedido #${order.order_number} como entregado al cliente?`)) {
            router.patch(`/orders/${order.id}/deliver`, {}, {
                preserveScroll: true,
            });
        }
    }
</script>

<AppLayout title="Gestión de Pedidos">
    <div class="space-y-6">
        <!-- ENCABEZADO Y ACCIONES -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Módulo 1
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Gestión de Pedidos y Entrega Predictiva</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Predice fechas de entrega con tiempos de holgura en tiempo real (Nota 3), orquestando cada pedido como "US" en el Tablero Kanban.
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto">
                <Link
                    href="/kanban"
                    class="px-3.5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold text-xs transition-colors border border-slate-700 flex items-center gap-1.5"
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
                <span class="text-[11px] font-medium text-slate-400 uppercase tracking-wider block">Cola WIP Activa</span>
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
                <span class="text-[11px] font-medium text-slate-400 uppercase tracking-wider block">Holgura Base</span>
                <div class="text-xl font-mono font-bold text-emerald-400 mt-1">
                    +{defaultBufferPercent}%
                </div>
                <span class="text-[10px] text-slate-500 mt-0.5 block">Margen de imprevistos</span>
            </div>

            <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl">
                <span class="text-[11px] font-medium text-slate-400 uppercase tracking-wider block">Clientes Activos</span>
                <div class="text-xl font-mono font-bold text-sky-400 mt-1">
                    {customers.length}
                </div>
                <span class="text-[10px] text-slate-500 mt-0.5 block">Directorio vinculado</span>
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
                    Listos para Entrega
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
                {@const isOverdue = order.committed_delivery_at && order.predicted_delivery_at && new Date(order.predicted_delivery_at) > new Date(order.committed_delivery_at)}

                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-md hover:border-slate-700 transition-colors">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <!-- Identificación del Pedido -->
                        <div class="space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2.5">
                                <span class="px-2.5 py-1 rounded-lg bg-slate-950 font-mono font-bold text-sm text-emerald-400 border border-slate-800">
                                    #{order.order_number}
                                </span>
                                <h3 class="font-bold text-white text-base">
                                    {order.customer?.name ?? 'Cliente Desconocido'}
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
                                <span class="font-mono text-emerald-400 font-semibold">
                                    Total: ${Number(order.total_amount).toFixed(2)}
                                </span>
                            </div>
                        </div>

                        <!-- Barra de Progreso de Tareas de Producción -->
                        <div class="w-full lg:w-56 space-y-1.5">
                            <div class="flex justify-between items-center text-[11px]">
                                <span class="text-slate-400 font-medium">Progreso en Taller:</span>
                                <span class="font-mono text-white font-bold">{tasksCompleted} / {tasksTotal}</span>
                            </div>
                            <div class="w-full h-2 rounded-full bg-slate-950 border border-slate-800 overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-500 {progressPercent === 100 ? 'bg-emerald-500' : 'bg-sky-500'}"
                                    style="width: {progressPercent}%"
                                ></div>
                            </div>
                            <span class="text-[10px] text-slate-500 block text-right font-mono">{progressPercent}% completado</span>
                        </div>

                        <!-- Métricas de Tiempos y Fechas Predictivas -->
                        <div class="grid grid-cols-2 gap-3 text-xs p-3 bg-slate-950/70 rounded-xl border border-slate-800/80 w-full lg:w-72">
                            <div>
                                <span class="text-[10px] text-slate-500 uppercase font-bold block">Tiempo Fabricación</span>
                                <span class="font-mono text-amber-300 font-semibold">⏱ {order.formatted_time}</span>
                                <span class="text-[9px] text-slate-500 block">+{order.buffer_minutes}m holgura</span>
                            </div>
                            <div>
                                <span class="text-[10px] text-slate-500 uppercase font-bold block">Entrega Predicha</span>
                                <span class="font-mono text-white text-[11px] block">{formatDate(order.predicted_delivery_at)}</span>
                                {#if order.committed_delivery_at}
                                    <span class="text-[10px] font-mono {isOverdue ? 'text-rose-400 font-bold' : 'text-slate-400'}">
                                        Compromiso: {formatDate(order.committed_delivery_at)}
                                    </span>
                                {/if}
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="flex items-center gap-2 self-end lg:self-center shrink-0">
                            <Link
                                href="/kanban"
                                class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold border border-slate-700 transition-colors"
                            >
                                Ver en Kanban
                            </Link>

                            {#if order.derived_status === 'ready'}
                                <button
                                    type="button"
                                    onclick={() => markDelivered(order)}
                                    class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold shadow-lg shadow-emerald-600/20 transition-all flex items-center gap-1"
                                >
                                    <span>✓</span>
                                    <span>Marcar Entregado</span>
                                </button>
                            {/if}
                        </div>
                    </div>

                    <!-- Desglose de Productos / Tareas del Pedido -->
                    {#if order.production_tasks && order.production_tasks.length > 0}
                        <div class="mt-4 pt-3 border-t border-slate-800/80 flex flex-wrap gap-2 items-center">
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Productos (Tareas):</span>
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
                <div class="p-12 text-center bg-slate-900 border border-slate-800 rounded-2xl text-slate-500 text-xs">
                    No se encontraron pedidos registrados con los filtros seleccionados.
                </div>
            {/each}
        </div>

        <!-- PAGINACIÓN -->
        {#if orders.links && orders.links.length > 3}
            <div class="p-4 bg-slate-900 border border-slate-800 rounded-xl flex items-center justify-between text-xs text-slate-400">
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

<!-- MODAL CREAR PEDIDO CON CALCULADORA PREDICTIVA EN TIEMPO REAL -->
{#if showCreateModal}
    <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-4xl w-full p-6 shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-base font-bold text-white">Registrar Nuevo Pedido y Generar Tareas en Kanban</h3>
                    <p class="text-[11px] text-slate-400">Predicción automática considerando la cola del taller y recetas BOM.</p>
                </div>
                <button type="button" onclick={() => showCreateModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitOrder} class="space-y-4 text-xs">
                <!-- CLIENTE OBLIGATORIO (NOTA 5) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="ord-customer" class="block font-semibold text-slate-300 mb-1">Cliente Vinculado (Nota 5) *</label>
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
                        <label for="ord-buffer" class="block font-semibold text-slate-300 mb-1">
                            Margen de Holgura (%) — Taller por defecto: {defaultBufferPercent}%
                        </label>
                        <div class="flex items-center gap-2">
                            <input
                                id="ord-buffer"
                                type="number"
                                min="0"
                                max="100"
                                bind:value={orderForm.custom_buffer_percent}
                                oninput={triggerPredictionDebounced}
                                class="w-24 bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-amber-300 font-mono font-bold text-xs"
                            />
                            <span class="text-slate-500 text-[11px]">Protección contra cuellos de botella e imprevistos.</span>
                        </div>
                    </div>
                </div>

                <!-- RENGLONES DE PRODUCTOS / TAREAS (NOTA 1, 2) -->
                <div class="space-y-3 pt-2 border-t border-slate-800">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-xs">Productos a Fabricar (Tareas de Kanban)</span>
                            <p class="text-[11px] text-slate-500">Cada producto creará una tarjeta independiente en el flujo Kanban asignado.</p>
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
                            Agrega al menos un producto para calcular los tiempos y fecha de entrega.
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
                                                <option value={p.id}>{p.name} ({p.workflow?.name ?? 'Sin flujo'})</option>
                                            {/each}
                                        </select>
                                    </div>

                                    <!-- Selector de Variante Ortogonal -->
                                    <div class="flex-1">
                                        <label for="var-sel-{idx}" class="text-[10px] text-slate-400 uppercase font-bold block mb-1">Variante Ortogonal</label>
                                        <select
                                            id="var-sel-{idx}"
                                            bind:value={item.product_variant_id}
                                            onchange={triggerPredictionDebounced}
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
                                            oninput={triggerPredictionDebounced}
                                            required
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs font-mono font-bold"
                                        />
                                    </div>

                                    <!-- Tiempo unitario estimado -->
                                    <div class="w-28 text-right hidden sm:block">
                                        <span class="text-[10px] text-slate-400 uppercase font-bold block mb-1">Tiempo Unitario</span>
                                        <span class="text-amber-300 font-mono text-xs font-semibold">
                                            ⏱ {selectedProd ? (Number(selectedProd.base_production_minutes) + Number(selectedVariant?.production_minutes_delta ?? 0)) : 0} min
                                        </span>
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

                <!-- PANEL PREDICTIVO EN TIEMPO REAL (MÓDULO 1 & NOTA 3) -->
                <div class="p-4 bg-gradient-to-br from-slate-950 to-slate-900 rounded-2xl border border-emerald-500/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full {predicting ? 'bg-amber-400 animate-ping' : 'bg-emerald-400'}"></span>
                            <span class="font-bold text-white text-xs uppercase tracking-wider">
                                Predicción de Entrega en Vivo (Nota 3)
                            </span>
                        </div>
                        {#if predicting}
                            <span class="text-[10px] text-amber-300 animate-pulse">Calculando calendario...</span>
                        {/if}
                    </div>

                    <!-- Métricas del Desglose Predictivo -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 text-xs">
                        <div class="p-2.5 bg-slate-900/80 rounded-xl border border-slate-800">
                            <span class="text-[10px] text-slate-400 uppercase font-bold block">Cola WIP Activa</span>
                            <span class="font-mono text-amber-300 font-bold text-sm">{formatMinutes(wipQueueMinutes)}</span>
                        </div>
                        <div class="p-2.5 bg-slate-900/80 rounded-xl border border-slate-800">
                            <span class="text-[10px] text-slate-400 uppercase font-bold block">Producción Pedido</span>
                            <span class="font-mono text-white font-bold text-sm">{formatMinutes(totalOrderMinutes)}</span>
                        </div>
                        <div class="p-2.5 bg-slate-900/80 rounded-xl border border-slate-800">
                            <span class="text-[10px] text-slate-400 uppercase font-bold block">Holgura Calculada</span>
                            <span class="font-mono text-emerald-400 font-bold text-sm">+{formatMinutes(calculatedBufferMinutes)}</span>
                        </div>
                        <div class="p-2.5 bg-slate-900/80 rounded-xl border border-slate-800">
                            <span class="text-[10px] text-slate-400 uppercase font-bold block">Total con Holgura</span>
                            <span class="font-mono text-amber-300 font-extrabold text-sm">
                                {formatMinutes(wipQueueMinutes + totalOrderMinutes + calculatedBufferMinutes)}
                            </span>
                        </div>
                    </div>

                    <!-- Fecha Predicha Sugerida -->
                    <div class="p-3 bg-emerald-950/20 border border-emerald-500/40 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <span class="text-[10px] text-emerald-400 uppercase font-extrabold tracking-wider block">
                                Fecha y Hora Sugerida de Entrega
                            </span>
                            <span class="text-sm font-mono font-bold text-white">
                                {predictionResult?.predicted_delivery_at ? formatDate(predictionResult.predicted_delivery_at) : 'Calculando...'}
                            </span>
                        </div>
                        <div class="text-right">
                            <span class="text-[10px] text-slate-400 block">Jornadas hábiles (8:00 AM - 5:00 PM)</span>
                            <span class="text-[10px] text-slate-500 block">Excluye fines de semana automáticamente</span>
                        </div>
                    </div>

                    <!-- ALERTA DE MATERIA PRIMA (MRP DE ESCASEZ) -->
                    {#if predictionResult?.material_shortages && predictionResult.material_shortages.length > 0}
                        <div class="p-3 bg-rose-950/40 border border-rose-500/50 rounded-xl space-y-1.5">
                            <div class="flex items-center gap-1.5 text-rose-300 font-bold text-xs">
                                <span>⚠</span>
                                <span>Alerta de Inventario (Escasez detectada según Receta BOM):</span>
                            </div>
                            <div class="space-y-1">
                                {#each predictionResult.material_shortages as shortage}
                                    <div class="flex items-center justify-between text-[11px] text-rose-200 bg-rose-900/20 p-1.5 rounded-lg">
                                        <span>• {shortage.name}: Faltan <strong>{shortage.shortage} {shortage.unit}</strong> (Disponible: {shortage.available})</span>
                                        {#if shortage.lead_time_days !== null}
                                            <span class="font-mono text-[10px] text-amber-300">
                                                Proveedor tarda {shortage.lead_time_days} {shortage.lead_time_days === 1 ? 'día' : 'días'}
                                            </span>
                                        {/if}
                                    </div>
                                {/each}
                            </div>
                        </div>
                    {/if}
                </div>

                <!-- FECHA COMPROMETIDA Y NOTAS -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-slate-800">
                    <div>
                        <label for="ord-committed" class="block font-semibold text-slate-300 mb-1">
                            Fecha Comprometida con el Cliente (Opcional)
                        </label>
                        <input
                            id="ord-committed"
                            type="datetime-local"
                            bind:value={orderForm.committed_delivery_at}
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                        />
                    </div>
                    <div>
                        <label for="ord-notes" class="block font-semibold text-slate-300 mb-1">Notas / Instrucciones Especiales</label>
                        <input
                            id="ord-notes"
                            type="text"
                            bind:value={orderForm.notes}
                            placeholder="Ej. Empaque individual, entrega en recepción..."
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                </div>

                <!-- BOTONES DE ACCIÓN -->
                <div class="flex items-center justify-between pt-3 border-t border-slate-800">
                    <div class="text-xs">
                        <span class="text-slate-400">Total a Facturar: </span>
                        <span class="font-mono font-bold text-emerald-400 text-sm">${totalAmount.toFixed(2)}</span>
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
                            Crear Pedido y Tareas Kanban
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
{/if}
