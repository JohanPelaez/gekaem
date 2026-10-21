<script>
    /**
     * OrderDateSelector Component (Svelte 5 Runes)
     * Interactive mini-calendar for selecting committed delivery date with daily workshop load visualization
     * and real scheduled order inspection panel.
     */
    let {
        value = $bindable(''),
        allOrdersByDate = {},
        ordersCountByDate = {},
        allScheduledOrders = [],
        label = 'Fecha y Hora de Entrega Comprometida *',
        id = 'committed-delivery-picker',
        required = true,
        getDerivedStatusBadge = (s) => {
            switch (s) {
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
                    return { label: s, color: 'bg-slate-800 text-slate-400 border-slate-700' };
            }
        },
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

    function pad(n) {
        return String(n).padStart(2, '0');
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

    function parseInitialDate(val) {
        if (!val) {
            const d = new Date();
            d.setDate(d.getDate() + 2);
            const y = d.getFullYear();
            const m = d.getMonth();
            const day = d.getDate();
            return {
                year: y,
                month: m,
                dateStr: `${y}-${pad(m + 1)}-${pad(day)}`,
                timeStr: '17:00',
            };
        }
        const cleanDate = normalizeDateKey(val);
        let timeStr = '17:00';
        if (String(val).includes('T')) {
            timeStr = String(val).split('T')[1].slice(0, 5) || '17:00';
        } else if (String(val).includes(' ')) {
            timeStr = String(val).split(' ')[1].slice(0, 5) || '17:00';
        }

        if (cleanDate) {
            const [y, m] = cleanDate.split('-').map(Number);
            return {
                year: y,
                month: m - 1, // 0-indexed month
                dateStr: cleanDate,
                timeStr: timeStr,
            };
        }

        const now = new Date();
        return {
            year: now.getFullYear(),
            month: now.getMonth(),
            dateStr: '',
            timeStr: '17:00',
        };
    }

    const initial = parseInitialDate(value);
    let viewYear = $state(initial.year);
    let viewMonth = $state(initial.month); // 0-11
    let selectedDate = $state(initial.dateStr); // YYYY-MM-DD
    let selectedTime = $state(initial.timeStr); // HH:MM

    // Sincronizar hacia afuera cuando cambie fecha u hora
    $effect(() => {
        if (selectedDate) {
            const fullVal = `${selectedDate}T${selectedTime || '17:00'}`;
            if (value !== fullVal) {
                value = fullVal;
            }
        }
    });

    // Sincronizar hacia adentro si value cambia desde afuera
    $effect(() => {
        if (value) {
            const clean = normalizeDateKey(value);
            if (clean && clean !== selectedDate) {
                selectedDate = clean;
                const [y, m] = clean.split('-').map(Number);
                viewYear = y;
                viewMonth = m - 1;
            }
            let timePart = '';
            if (String(value).includes('T')) {
                timePart = String(value).split('T')[1]?.slice(0, 5);
            } else if (String(value).includes(' ')) {
                timePart = String(value).split(' ')[1]?.slice(0, 5);
            }
            if (timePart && timePart !== selectedTime) {
                selectedTime = timePart;
            }
        }
    });

    // Mapa unificado de pedidos por fecha exacta
    let computedOrdersByDate = $derived.by(() => {
        if (allOrdersByDate && Object.keys(allOrdersByDate).length > 0) {
            return allOrdersByDate;
        }
        const map = {};
        for (const order of allScheduledOrders) {
            const key = getOrderDateKey(order);
            if (!key) continue;
            if (!map[key]) {
                map[key] = [];
            }
            map[key].push(order);
        }
        return map;
    });

    function getOrdersForCell(dateStr) {
        return computedOrdersByDate[dateStr] ?? [];
    }

    const monthNames = [
        'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
    ];
    const dayNames = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];

    let daysInMonth = $derived.by(() => {
        const firstDay = new Date(viewYear, viewMonth, 1);
        const lastDay = new Date(viewYear, viewMonth + 1, 0);
        const numDays = lastDay.getDate();

        // 0 = Domingo, 1 = Lunes, etc. Ajustar a Lunes = 0
        let startingDay = firstDay.getDay() - 1;
        if (startingDay < 0) startingDay = 6;

        const prevMonthLastDay = new Date(viewYear, viewMonth, 0).getDate();
        const days = [];

        // Días de relleno mes anterior
        for (let i = startingDay - 1; i >= 0; i--) {
            const pDay = prevMonthLastDay - i;
            const m = viewMonth === 0 ? 12 : viewMonth;
            const y = viewMonth === 0 ? viewYear - 1 : viewYear;
            const dateStr = `${y}-${pad(m)}-${pad(pDay)}`;
            const cellOrders = getOrdersForCell(dateStr);
            const count = cellOrders.length > 0 ? cellOrders.length : (ordersCountByDate[dateStr] ?? 0);
            days.push({
                dayNumber: pDay,
                dateStr,
                isCurrentMonth: false,
                isPast: isDateInPast(dateStr),
                count,
            });
        }

        // Días mes actual
        for (let d = 1; d <= numDays; d++) {
            const dateStr = `${viewYear}-${pad(viewMonth + 1)}-${pad(d)}`;
            const cellOrders = getOrdersForCell(dateStr);
            const count = cellOrders.length > 0 ? cellOrders.length : (ordersCountByDate[dateStr] ?? 0);
            days.push({
                dayNumber: d,
                dateStr,
                isCurrentMonth: true,
                isPast: isDateInPast(dateStr),
                count,
            });
        }

        // Días de relleno mes siguiente (para completar filas de 7)
        const remaining = 7 - (days.length % 7);
        if (remaining < 7) {
            for (let d = 1; d <= remaining; d++) {
                const m = viewMonth === 11 ? 1 : viewMonth + 2;
                const y = viewMonth === 11 ? viewYear + 1 : viewYear;
                const dateStr = `${y}-${pad(m)}-${pad(d)}`;
                const cellOrders = getOrdersForCell(dateStr);
                const count = cellOrders.length > 0 ? cellOrders.length : (ordersCountByDate[dateStr] ?? 0);
                days.push({
                    dayNumber: d,
                    dateStr,
                    isCurrentMonth: false,
                    isPast: isDateInPast(dateStr),
                    count,
                });
            }
        }

        return days;
    });

    function isDateInPast(dateStr) {
        const today = new Date();
        const todayStr = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
        return dateStr < todayStr;
    }

    function isToday(dateStr) {
        const today = new Date();
        const todayStr = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
        return dateStr === todayStr;
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
        selectedDate = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    }

    function selectDate(day) {
        selectedDate = day.dateStr;
        if (!day.isCurrentMonth) {
            const parts = day.dateStr.split('-');
            viewYear = Number(parts[0]);
            viewMonth = Number(parts[1]) - 1;
        }
    }

    let selectedKey = $derived.by(() => {
        const raw = value || selectedDate || '';
        return normalizeDateKey(raw);
    });

    let selectedDayOrders = $derived.by(() => {
        if (!selectedKey) return [];
        return computedOrdersByDate[selectedKey] ?? (computedOrdersByDate[selectedDate] ?? []);
    });

    let selectedLoadCount = $derived(
        selectedDayOrders.length > 0
            ? selectedDayOrders.length
            : (ordersCountByDate[selectedKey] ?? 0)
    );

    let formattedSelectedDateHuman = $derived.by(() => {
        if (!selectedKey) return 'Sin fecha seleccionada';
        const [y, m, d] = selectedKey.split('-').map(Number);
        const dObj = new Date(y, m - 1, d);
        return dObj.toLocaleDateString('es-CO', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    });
</script>

<div class="space-y-2">
    <div class="flex items-center justify-between">
        <label for={id} class="block font-semibold text-slate-300 text-xs">
            {label}
        </label>
        <button
            type="button"
            onclick={goToToday}
            class="text-[10px] text-emerald-400 hover:text-emerald-300 font-bold transition-colors"
        >
            Ir a Hoy
        </button>
    </div>

    <!-- Mini-Calendario Container -->
    <div class="p-3.5 bg-slate-950 rounded-2xl border border-slate-800 space-y-3">
        <!-- Navegación de Mes / Año -->
        <div class="flex items-center justify-between">
            <button
                type="button"
                onclick={prevMonth}
                class="w-7 h-7 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white flex items-center justify-center text-xs transition-colors border border-slate-800"
                title="Mes anterior"
            >
                ◀
            </button>

            <span class="text-xs font-bold text-white tracking-wide">
                {monthNames[viewMonth]} {viewYear}
            </span>

            <button
                type="button"
                onclick={nextMonth}
                class="w-7 h-7 rounded-lg bg-slate-900 hover:bg-slate-800 text-slate-300 hover:text-white flex items-center justify-center text-xs transition-colors border border-slate-800"
                title="Mes siguiente"
            >
                ▶
            </button>
        </div>

        <!-- Días de la semana -->
        <div class="grid grid-cols-7 gap-1 text-center">
            {#each dayNames as dn}
                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider py-0.5">
                    {dn}
                </div>
            {/each}
        </div>

        <!-- Grid de Días -->
        <div class="grid grid-cols-7 gap-1 text-center">
            {#each daysInMonth as day}
                {@const isSelected = selectedKey === day.dateStr}
                {@const today = isToday(day.dateStr)}
                <button
                    type="button"
                    onclick={() => selectDate(day)}
                    class="relative h-9 rounded-xl flex flex-col items-center justify-center text-xs transition-all border
                        {isSelected
                            ? 'bg-emerald-600 text-white font-extrabold border-emerald-400 shadow-md shadow-emerald-600/30 ring-2 ring-emerald-500/40'
                            : day.isCurrentMonth
                                ? today
                                    ? 'bg-slate-800 text-emerald-400 font-bold border-emerald-500/50 hover:bg-slate-700'
                                    : 'bg-slate-900/80 text-slate-200 border-slate-800/80 hover:bg-slate-800 hover:border-slate-700'
                                : 'bg-slate-950/40 text-slate-600 border-transparent hover:text-slate-400'}"
                    title={`${day.dateStr} — ${day.count} pedidos programados`}
                >
                    <span class="text-[11px] leading-none">{day.dayNumber}</span>

                    <!-- Indicador de carga de pedidos del día -->
                    {#if day.count > 0}
                        <div class="flex items-center gap-0.5 mt-0.5">
                            {#if day.count <= 2}
                                <span class="w-1.5 h-1.5 rounded-full {isSelected ? 'bg-white' : 'bg-emerald-400'}" title={`${day.count} pedidos`}></span>
                            {:else if day.count <= 4}
                                <span class="w-1.5 h-1.5 rounded-full {isSelected ? 'bg-white' : 'bg-amber-400'}" title={`${day.count} pedidos`}></span>
                            {:else}
                                <span class="w-1.5 h-1.5 rounded-full {isSelected ? 'bg-white' : 'bg-rose-400 animate-pulse'}" title={`${day.count} pedidos (Saturado)`}></span>
                            {/if}
                            <span class="text-[8px] font-mono leading-none {isSelected ? 'text-white' : 'text-slate-400'}">{day.count}</span>
                        </div>
                    {/if}
                </button>
            {/each}
        </div>

        <!-- Leyenda de Carga Diaria -->
        <div class="flex items-center justify-between text-[10px] text-slate-400 pt-2 border-t border-slate-800/80">
            <span class="font-semibold text-slate-500">Carga del Taller:</span>
            <div class="flex items-center gap-3">
                <span class="flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                    <span>1-2 pedidos</span>
                </span>
                <span class="flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                    <span>3-4 pedidos</span>
                </span>
                <span class="flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>
                    <span>5+ (Alta)</span>
                </span>
            </div>
        </div>

        <!-- Ajuste de Hora y Resumen de Selección -->
        <div class="pt-2 border-t border-slate-800/80 flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
            <div class="flex items-center gap-2">
                <label for="{id}-time" class="text-[11px] font-semibold text-slate-400 whitespace-nowrap">
                    Hora programada:
                </label>
                <input
                    id="{id}-time"
                    type="time"
                    bind:value={selectedTime}
                    class="bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-white text-xs font-mono focus:outline-none focus:border-emerald-500"
                />
            </div>

            <!-- Resumen Informativo Dinámico -->
            <div class="text-xs">
                {#if selectedKey}
                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[11px] font-medium border
                        {selectedLoadCount >= 5
                            ? 'bg-rose-500/15 text-rose-300 border-rose-500/30 font-bold'
                            : selectedLoadCount >= 3
                                ? 'bg-amber-500/15 text-amber-300 border-amber-500/30 font-semibold'
                                : 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30 font-semibold'}"
                    >
                        <span>📅</span>
                        <span>
                            {formattedSelectedDateHuman} ({selectedTime}) — {selectedLoadCount} {selectedLoadCount === 1 ? 'pedido programado' : 'pedidos programados'}
                        </span>
                    </div>
                {/if}
            </div>
        </div>

        <!-- PANEL DE PEDIDOS DEL DÍA SELECCIONADO (CRÍTICO) -->
        <div class="pt-3 border-t border-slate-800/80 space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-white flex items-center gap-1.5">
                    <span>📋</span>
                    <span>Pedidos para esta fecha ({selectedDayOrders.length}):</span>
                </span>
                <span class="text-[10px] text-slate-500 font-mono">
                    Carga actual del taller
                </span>
            </div>

            {#if selectedDayOrders.length === 0}
                <div class="p-3 bg-emerald-950/20 border border-emerald-500/30 rounded-xl flex items-center gap-2 text-emerald-300 text-xs">
                    <span class="text-sm">✨</span>
                    <span>Día despejado: 0 pedidos comprometidos para esta fecha</span>
                </div>
            {:else}
                <div class="space-y-1.5 max-h-36 overflow-y-auto pr-1">
                    {#each selectedDayOrders as ord (ord.id)}
                        {@const badge = getDerivedStatusBadge(ord.derived_status)}
                        {@const tasks = ord.production_tasks || ord.productionTasks || []}
                        {@const totalItems = tasks.reduce((sum, t) => sum + Number(t.quantity || 1), 0)}
                        {@const timeStr = formatTime(ord.committed_delivery_at || ord.predicted_delivery_at)}
                        <div class="p-2 rounded-xl bg-slate-900 border border-slate-800 flex items-center justify-between gap-2 text-xs hover:border-slate-700 transition-colors">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="font-mono font-bold text-emerald-400 shrink-0">#{ord.order_number}</span>
                                <span class="text-white font-medium truncate">{ord.customer?.name ?? 'Cliente'}</span>
                                {#if timeStr}
                                    <span class="text-[10px] text-sky-400 shrink-0 font-mono">🕒 {timeStr}</span>
                                {/if}
                                {#if tasks.length > 0}
                                    <span class="text-[10px] text-slate-400 shrink-0 font-mono">
                                        ({tasks.length} {tasks.length === 1 ? 'tarea' : 'tareas'}{totalItems > 0 && totalItems !== tasks.length ? ` / ${totalItems} un.` : ''})
                                    </span>
                                {/if}
                            </div>
                            <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider border {badge.color} shrink-0">
                                {badge.label}
                            </span>
                        </div>
                    {/each}
                </div>
            {/if}
        </div>
    </div>

    <!-- Input oculto para validación de formulario nativo si es necesario -->
    <input type="hidden" name="committed_delivery_at" value={value} {required} />
</div>
