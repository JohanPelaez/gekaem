<script>
    import { router, Link } from '@inertiajs/svelte';

    /**
     * CalendarView Component (Svelte 5 Runes)
     * Monthly interactive calendar for scheduled order deliveries with quick details modal
     * and day context actions.
     */
    let {
        orders = [],
        hasFinancialAccess = true,
        onOpenPaymentModal,
        onMarkDelivered,
        onEditOrder,
        onDayClick,
        formatCurrency = (v) => `$${Number(v || 0).toLocaleString()}`,
        getDerivedStatusBadge = (s) => ({ label: s, color: 'bg-slate-800 text-slate-300' }),
    } = $props();

    function normalizeDateKey(dateValue) {
        if (!dateValue) return '';
        const str = String(dateValue).trim();
        const match = str.match(/^(\d{4}-\d{2}-\d{2})/);
        return match ? match[1] : '';
    }

    const todayObj = new Date();
    let viewYear = $state(todayObj.getFullYear());
    let viewMonth = $state(todayObj.getMonth()); // 0-11
    let selectedOrderForDetail = $state(null);

    const monthNames = [
        'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
    ];
    const dayNames = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    const shortDayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

    function pad(n) {
        return n < 10 ? `0${n}` : `${n}`;
    }

    function prevMonth() {
        if (viewMonth === 0) {
            viewMonth = 11;
            viewYear--;
        } else {
            viewMonth--;
        }
    }

    function nextMonth() {
        if (viewMonth === 11) {
            viewMonth = 0;
            viewYear++;
        } else {
            viewMonth++;
        }
    }

    function goToToday() {
        const now = new Date();
        viewYear = now.getFullYear();
        viewMonth = now.getMonth();
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

    // Agrupar pedidos por fecha YYYY-MM-DD usando getOrderDateKey anti-desfase UTC
    let ordersByDateMap = $derived.by(() => {
        const map = new Map();
        for (const order of orders) {
            const dateKey = getOrderDateKey(order);
            if (!dateKey) continue;
            if (!map.has(dateKey)) {
                map.set(dateKey, []);
            }
            map.get(dateKey).push(order);
        }
        return map;
    });

    // Pedidos sin fecha comprometida ni calculada
    let ordersWithoutDate = $derived.by(() => {
        return orders.filter((o) => !getOrderDateKey(o));
    });

    // Construir los días de la cuadrícula mensual
    let calendarDays = $derived.by(() => {
        const firstDay = new Date(viewYear, viewMonth, 1);
        const lastDay = new Date(viewYear, viewMonth + 1, 0);
        const numDays = lastDay.getDate();

        // 0 = Domingo, 1 = Lunes. Ajustar a 0 = Lunes
        let startingDay = firstDay.getDay() - 1;
        if (startingDay < 0) startingDay = 6;

        const prevMonthLastDay = new Date(viewYear, viewMonth, 0).getDate();
        const days = [];

        // Relleno mes anterior
        for (let i = startingDay - 1; i >= 0; i--) {
            const pDay = prevMonthLastDay - i;
            const m = viewMonth === 0 ? 12 : viewMonth;
            const y = viewMonth === 0 ? viewYear - 1 : viewYear;
            const dateStr = `${y}-${pad(m)}-${pad(pDay)}`;
            days.push({
                dayNumber: pDay,
                dateStr,
                isCurrentMonth: false,
                orders: ordersByDateMap.get(dateStr) || [],
            });
        }

        // Días mes actual
        for (let d = 1; d <= numDays; d++) {
            const dateStr = `${viewYear}-${pad(viewMonth + 1)}-${pad(d)}`;
            days.push({
                dayNumber: d,
                dateStr,
                isCurrentMonth: true,
                orders: ordersByDateMap.get(dateStr) || [],
            });
        }

        // Relleno mes siguiente
        const remaining = 7 - (days.length % 7);
        if (remaining < 7) {
            for (let d = 1; d <= remaining; d++) {
                const m = viewMonth === 11 ? 1 : viewMonth + 2;
                const y = viewMonth === 11 ? viewYear + 1 : viewYear;
                const dateStr = `${y}-${pad(m)}-${pad(d)}`;
                days.push({
                    dayNumber: d,
                    dateStr,
                    isCurrentMonth: false,
                    orders: ordersByDateMap.get(dateStr) || [],
                });
            }
        }

        return days;
    });

    function isToday(dateStr) {
        const now = new Date();
        const todayStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
        return dateStr === todayStr;
    }

    function formatTime(dateStr) {
        if (!dateStr) return '';
        if (String(dateStr).includes('T')) {
            return String(dateStr).split('T')[1].slice(0, 5);
        }
        if (String(dateStr).includes(' ')) {
            return String(dateStr).split(' ')[1].slice(0, 5);
        }
        return '';
    }

    function formatFullDate(dateStr) {
        if (!dateStr) return 'Sin fecha';
        const dateKey = normalizeDateKey(dateStr);
        if (!dateKey) return 'Sin fecha';
        const [y, m, d] = dateKey.split('-').map(Number);
        const dateObj = new Date(y, m - 1, d);
        const timeStr = formatTime(dateStr);
        const formatted = dateObj.toLocaleDateString('es-CO', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        });
        return timeStr ? `${formatted} a las ${timeStr}` : formatted;
    }

    function getStatusBorderClass(status) {
        switch (status) {
            case 'pending':
                return 'border-l-[#64748B] hover:border-[#64748B] bg-slate-50/80 dark:bg-slate-800/40 text-slate-800 dark:text-slate-200';
            case 'in_progress':
                return 'border-l-[#4F46E5] hover:border-[#4F46E5] bg-indigo-50/50 dark:bg-indigo-950/20 text-slate-800 dark:text-slate-200';
            case 'blocked':
                return 'border-l-rose-500 hover:border-rose-400 bg-rose-50/50 dark:bg-rose-950/20 text-slate-800 dark:text-slate-200';
            case 'ready':
                return 'border-l-[#06B6D4] hover:border-[#06B6D4] bg-cyan-50/50 dark:bg-cyan-950/20 text-slate-800 dark:text-slate-200';
            case 'delivered':
                return 'border-l-emerald-500 hover:border-emerald-400 bg-emerald-50/50 dark:bg-emerald-950/20 text-slate-800 dark:text-slate-200';
            default:
                return 'border-l-slate-400 bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200';
        }
    }
</script>

<div class="space-y-5">
    <!-- BARRA SUPERIOR DE NAVEGACIÓN DEL CALENDARIO -->
    <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] p-4 rounded-3xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-sm">
        <div class="flex items-center gap-2">
            <button
                type="button"
                onclick={prevMonth}
                class="px-3 py-1.5 rounded-xl bg-slate-50 hover:bg-slate-100 dark:bg-[#0B0F17] dark:hover:bg-[#1C263B] text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] text-xs font-semibold flex items-center gap-1 transition-colors"
                title="Mes Anterior"
            >
                <span>←</span>
                <span class="hidden sm:inline">Mes Anterior</span>
            </button>

            <button
                type="button"
                onclick={goToToday}
                class="px-3 py-1.5 rounded-xl bg-[#06B6D4]/15 hover:bg-[#06B6D4]/25 text-[#06B6D4] border border-[#06B6D4]/40 text-xs font-bold transition-colors"
            >
                Hoy
            </button>

            <button
                type="button"
                onclick={nextMonth}
                class="px-3 py-1.5 rounded-xl bg-slate-50 hover:bg-slate-100 dark:bg-[#0B0F17] dark:hover:bg-[#1C263B] text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] text-xs font-semibold flex items-center gap-1 transition-colors"
                title="Mes Siguiente"
            >
                <span class="hidden sm:inline">Mes Siguiente</span>
                <span>→</span>
            </button>
        </div>

        <!-- SELECTORES DE MES Y AÑO -->
        <div class="flex items-center gap-2">
            <select
                bind:value={viewMonth}
                class="bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-white font-bold text-xs rounded-xl px-3 py-2 focus:outline-none focus:border-[#4F46E5]"
            >
                {#each monthNames as mName, idx}
                    <option value={idx}>{mName}</option>
                {/each}
            </select>

            <select
                bind:value={viewYear}
                class="bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-white font-mono font-bold text-xs rounded-xl px-3 py-2 focus:outline-none focus:border-[#4F46E5]"
            >
                {#each [viewYear - 2, viewYear - 1, viewYear, viewYear + 1, viewYear + 2] as y}
                    <option value={y}>{y}</option>
                {/each}
            </select>
        </div>
    </div>

    <!-- CUADRÍCULA DEL CALENDARIO -->
    <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl overflow-hidden shadow-sm">
        <!-- Encabezados de días de la semana -->
        <div class="grid grid-cols-7 border-b border-slate-200 dark:border-[#26334D] bg-slate-50/80 dark:bg-[#0B0F17]/80 text-center">
            {#each dayNames as dName, i}
                <div class="py-3 px-2 text-xs font-bold text-[#64748B] dark:text-slate-400 uppercase tracking-wider">
                    <span class="hidden md:inline">{dName}</span>
                    <span class="md:hidden">{shortDayNames[i]}</span>
                </div>
            {/each}
        </div>

        <!-- Celdas de días -->
        <div class="grid grid-cols-7 auto-rows-fr divide-x divide-y divide-slate-200 dark:divide-[#26334D]/80 bg-slate-100/50 dark:bg-slate-950/40">
            {#each calendarDays as day}
                {@const today = isToday(day.dateStr)}
                {@const count = day.orders.length}
                <div
                    class="min-h-[120px] sm:min-h-[140px] p-2 sm:p-2.5 flex flex-col justify-between transition-all cursor-pointer group/cell
                        {day.isCurrentMonth ? 'bg-white dark:bg-[#151D2C]/90 hover:bg-slate-50 dark:hover:bg-[#1C263B]/80' : 'bg-slate-50/70 dark:bg-[#0B0F17]/70 text-slate-400 dark:text-slate-600 hover:bg-slate-100/60 dark:hover:bg-slate-900/40'}
                        {today ? 'ring-2 ring-inset ring-[#06B6D4] bg-[#06B6D4]/5 dark:bg-cyan-950/20' : ''}"
                    onclick={(e) => onDayClick?.(day, e)}
                    oncontextmenu={(e) => { e.preventDefault(); onDayClick?.(day, e); }}
                    role="button"
                    tabindex="0"
                    onkeydown={(e) => { if (e.key === 'Enter') onDayClick?.(day, e); }}
                    title={`Hacer clic para ver opciones del ${day.dateStr}`}
                >
                    <!-- Cabecera del día -->
                    <div class="flex items-center justify-between gap-1 mb-1.5">
                        <div class="flex items-center gap-1.5">
                            <span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded-lg
                                {today
                                    ? 'bg-[#06B6D4] text-white font-black'
                                    : day.isCurrentMonth
                                        ? 'text-slate-900 dark:text-white'
                                        : 'text-slate-400 dark:text-slate-600'}"
                            >
                                {day.dayNumber}
                            </span>
                            {#if today}
                                <span class="text-[8px] font-black uppercase text-[#06B6D4] px-1 py-0.5 rounded bg-[#06B6D4]/15 border border-[#06B6D4]/40 leading-none">
                                    HOY
                                </span>
                            {/if}
                            <span class="opacity-0 group-hover/cell:opacity-100 text-[10px] text-slate-400 hover:text-[#4F46E5] dark:hover:text-indigo-400 transition-opacity" title="Opciones del día">
                                ⋮
                            </span>
                        </div>

                        {#if count > 0}
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold font-mono tracking-tight border flex items-center gap-1
                                {count >= 5
                                    ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/30'
                                    : count >= 3
                                        ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/30'
                                        : 'bg-[#06B6D4]/10 text-[#06B6D4] border-[#06B6D4]/30'}"
                                title={`${count} entregas programadas`}
                            >
                                <span class="w-1.5 h-1.5 rounded-full {count >= 5 ? 'bg-rose-500' : count >= 3 ? 'bg-amber-500' : 'bg-[#06B6D4]'}"></span>
                                <span>{count} {count === 1 ? 'entrega' : 'entregas'}</span>
                            </span>
                        {/if}
                    </div>

                    <!-- Lista de Pedidos en este día -->
                    <div class="space-y-1.5 overflow-y-auto max-h-28 pr-0.5 flex-1">
                        {#each day.orders as order (order.id)}
                            {@const badge = getDerivedStatusBadge(order.derived_status)}
                            <button
                                type="button"
                                onclick={(e) => {
                                    e.stopPropagation();
                                    selectedOrderForDetail = order;
                                }}
                                class="w-full text-left p-1.5 rounded-xl border border-slate-200 dark:border-[#26334D] border-l-4 {getStatusBorderClass(order.derived_status)} transition-all hover:scale-[1.02] shadow-sm group bg-white dark:bg-[#0B0F17]"
                                title={`Ver detalles del Pedido #${order.order_number}`}
                            >
                                <div class="flex items-center justify-between gap-1">
                                    <span class="font-mono font-bold text-[11px] text-slate-900 dark:text-white group-hover:text-[#4F46E5] dark:group-hover:text-indigo-400 transition-colors">
                                        #{order.order_number}
                                    </span>
                                    <span class="text-[9px] font-mono text-[#64748B] dark:text-slate-400">
                                        {formatTime(order.committed_delivery_at || order.predicted_delivery_at)}
                                    </span>
                                </div>
                                <div class="text-[11px] font-semibold text-slate-700 dark:text-slate-300 truncate mt-0.5">
                                    {order.customer?.name ?? 'Cliente'}
                                </div>
                            </button>
                        {/each}
                    </div>
                </div>
            {/each}
        </div>
    </div>

    <!-- SECCIÓN INFERIOR: PEDIDOS SIN FECHA COMPROMETIDA -->
    {#if ordersWithoutDate.length > 0}
        <div class="p-5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl space-y-3 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-2">
                <div class="flex items-center gap-2">
                    <span class="text-amber-500 text-sm">⚠️</span>
                    <h3 class="font-bold text-slate-900 dark:text-white text-sm">
                        Pedidos sin Fecha de Entrega Comprometida ({ordersWithoutDate.length})
                    </h3>
                </div>
                <span class="text-xs text-[#64748B] dark:text-slate-400">
                    Requieren programación de fecha en el taller.
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                {#each ordersWithoutDate as order (order.id)}
                    {@const badge = getDerivedStatusBadge(order.derived_status)}
                    <div class="p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-slate-200 dark:border-[#26334D] flex items-center justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-1.5">
                                <span class="font-mono font-bold text-[#4F46E5] dark:text-indigo-400 text-xs">#{order.order_number}</span>
                                <span class="text-slate-900 dark:text-white font-bold text-xs truncate max-w-[120px]">{order.customer?.name ?? 'Cliente'}</span>
                            </div>
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider border {badge.color} mt-1 inline-block">
                                {badge.label}
                            </span>
                        </div>
                        <button
                            type="button"
                            onclick={() => selectedOrderForDetail = order}
                            class="px-2.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 transition-colors shrink-0 shadow-sm"
                        >
                            Detalle
                        </button>
                    </div>
                {/each}
            </div>
        </div>
    {/if}
</div>

<!-- MODAL DE DETALLE RÁPIDO DE PEDIDO DESDE EL CALENDARIO -->
{#if selectedOrderForDetail}
    {@const order = selectedOrderForDetail}
    {@const badge = getDerivedStatusBadge(order.derived_status)}
    {@const tasks = order.production_tasks || order.tasks || []}
    <div class="fixed inset-0 z-50 bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl max-w-lg w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto text-slate-900 dark:text-slate-100 animate-in fade-in zoom-in-95 duration-100">
            <!-- Encabezado del Modal -->
            <div class="flex items-start justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded font-mono font-bold text-sm bg-slate-100 dark:bg-[#0B0F17] text-[#4F46E5] dark:text-indigo-400 border border-slate-200 dark:border-[#26334D]">
                            #{order.order_number}
                        </span>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">
                            {order.customer?.name ?? 'Cliente'}
                        </h3>
                    </div>
                    {#if order.customer?.phone}
                        <p class="text-xs text-[#64748B] dark:text-slate-400 font-mono mt-0.5">📞 {order.customer.phone}</p>
                    {/if}
                </div>
                <button
                    type="button"
                    onclick={() => selectedOrderForDetail = null}
                    class="text-slate-400 hover:text-slate-700 dark:hover:text-white text-base p-1"
                >
                    ✕
                </button>
            </div>

            <!-- Estado Operativo y Fecha -->
            <div class="p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-slate-200 dark:border-[#26334D] space-y-2 text-xs">
                <div class="flex items-center justify-between">
                    <span class="text-[#64748B] dark:text-slate-400">Estado de Producción:</span>
                    <span class="px-2.5 py-0.5 rounded-full font-bold uppercase tracking-wider text-[10px] border {badge.color}">
                        {badge.label}
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-[#64748B] dark:text-slate-400">Modalidad de Envío:</span>
                    {#if order.shipping_type === 'envio_incluido'}
                        <span class="px-2.5 py-0.5 rounded-full font-bold text-[10px] bg-indigo-500/15 text-[#4F46E5] dark:text-indigo-300 border border-indigo-500/30">
                            📦 Envío Incluido
                        </span>
                    {:else}
                        <span class="px-2.5 py-0.5 rounded-full font-bold text-[10px] bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/30">
                            🚚 Contraentrega
                        </span>
                    {/if}
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-[#64748B] dark:text-slate-400">Entrega Comprometida:</span>
                    <span class="font-mono font-bold text-slate-900 dark:text-white capitalize">
                        📅 {formatFullDate(order.committed_delivery_at || order.predicted_delivery_at)}
                    </span>
                </div>
                {#if order.notes}
                    <div class="pt-1.5 border-t border-slate-200 dark:border-slate-800/80 text-[#64748B] dark:text-slate-400">
                        <span class="font-bold text-slate-700 dark:text-slate-300">Notas: </span>
                        <span>"{order.notes}"</span>
                    </div>
                {/if}
            </div>

            <!-- Resumen Financiero si tiene permisos -->
            {#if hasFinancialAccess}
                <div class="p-3.5 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-slate-200 dark:border-[#26334D] space-y-2 text-xs">
                    <span class="font-bold text-slate-900 dark:text-white text-xs block">Resumen Financiero</span>
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="p-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                            <span class="text-[10px] text-[#64748B] dark:text-slate-400 block font-semibold">Total</span>
                            <span class="font-mono font-bold text-slate-900 dark:text-white text-xs">{formatCurrency(order.total_amount)}</span>
                        </div>
                        <div class="p-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                            <span class="text-[10px] text-[#64748B] dark:text-slate-400 block font-semibold">Abonado</span>
                            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400 text-xs">{formatCurrency(order.paid_amount)}</span>
                        </div>
                        <div class="p-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                            <span class="text-[10px] text-[#64748B] dark:text-slate-400 block font-semibold">Saldo</span>
                            <span class="font-mono font-bold {order.pending_balance > 0 ? 'text-amber-600 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-400'} text-xs">
                                {order.pending_balance > 0 ? formatCurrency(order.pending_balance) : 'Pagado'}
                            </span>
                        </div>
                    </div>
                </div>
            {/if}

            <!-- Productos Solicitados (Tareas) -->
            <div class="space-y-2">
                <span class="font-bold text-slate-900 dark:text-white text-xs block">
                    Productos del Pedido ({tasks.length})
                </span>
                <div class="space-y-1.5 max-h-40 overflow-y-auto">
                    {#each tasks as task}
                        <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] flex items-center justify-between text-xs">
                            <div class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                <span class="font-mono text-[#4F46E5] dark:text-indigo-400">{task.quantity}x</span>
                                <span>{task.item_name_snapshot}</span>
                            </div>
                            {#if task.current_stage || task.currentStage}
                                {@const st = task.current_stage || task.currentStage}
                                <span
                                    class="px-2 py-0.5 rounded text-[10px] font-bold border"
                                    style="color: {st.color}; border-color: {st.color}40; background: {st.color}15"
                                >
                                    {st.name}
                                </span>
                            {/if}
                        </div>
                    {:else}
                        <span class="text-slate-400 text-xs italic py-1 block">Sin tareas registradas.</span>
                    {/each}
                </div>
            </div>

            <!-- Acciones -->
            <div class="pt-3 border-t border-slate-200 dark:border-[#26334D] flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <!-- Botón directo para Ir al Tablero Kanban aislado -->
                    <button
                        type="button"
                        onclick={() => router.visit(`/kanban?order_id=${order.id}`)}
                        class="px-3.5 py-2 rounded-xl bg-cyan-50 dark:bg-cyan-950/40 hover:bg-cyan-100 dark:hover:bg-cyan-900/50 text-[#06B6D4] text-xs font-bold border border-[#06B6D4]/30 flex items-center gap-1.5 transition-colors shadow-sm"
                        title="Abrir este pedido en el Tablero Kanban"
                    >
                        <span>📋</span>
                        <span>Tablero Kanban</span>
                    </button>

                    <!-- Botón Editar Pedido -->
                    <button
                        type="button"
                        onclick={() => {
                            const target = order;
                            selectedOrderForDetail = null;
                            onEditOrder?.(target);
                        }}
                        class="px-3 py-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/40 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 text-[#4F46E5] dark:text-indigo-300 font-semibold text-xs border border-indigo-500/30 flex items-center gap-1.5 transition-colors shadow-sm"
                        title="Editar Pedido"
                    >
                        <span>✏️</span>
                        <span>Editar</span>
                    </button>

                    {#if hasFinancialAccess}
                        <button
                            type="button"
                            onclick={() => {
                                const target = order;
                                selectedOrderForDetail = null;
                                if (typeof onOpenPaymentModal === 'function') {
                                    onOpenPaymentModal(target);
                                }
                            }}
                            class="px-3 py-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 text-emerald-700 dark:text-emerald-400 font-semibold text-xs border border-emerald-500/30 flex items-center gap-1.5 transition-colors shadow-sm"
                        >
                            <span>💳</span>
                            <span>Abonos</span>
                        </button>
                    {/if}

                    {#if order.derived_status === 'ready'}
                        <button
                            type="button"
                            onclick={() => {
                                const target = order;
                                selectedOrderForDetail = null;
                                if (typeof onMarkDelivered === 'function') {
                                    onMarkDelivered(target);
                                }
                            }}
                            class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-colors shadow-sm"
                        >
                            ✓ Entregar
                        </button>
                    {/if}
                </div>

                <button
                    type="button"
                    onclick={() => selectedOrderForDetail = null}
                    class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-semibold text-xs"
                >
                    Cerrar
                </button>
            </div>
        </div>
    </div>
{/if}
