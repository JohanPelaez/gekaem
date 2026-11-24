<script>
    import { untrack } from 'svelte';
    import CurrencyInput from './CurrencyInput.svelte';
    import { csrfFetch } from '../Utils/csrfFetch.js';
    import {
        getTodayLocalDateKey,
        normalizeScheduleDateKey,
        formatFullScheduleDate,
        getScheduleDateTrafficLight,
    } from '../Utils/paymentScheduleAlerts.js';

    /**
     * Componente reutilizable de Gestión de Abonos y Cobros Programados (Svelte 5 Runes).
     * Se usa tanto en el Modal de Abonos de Orders/Index.svelte como en OrderFormModal.svelte.
     * NO usa etiquetas <form> anidadas para poder incrustarse dentro de OrderFormModal sin conflictos.
     */
    let {
        order = null,
        orderId = null,
        orderTotal = 0,
        payments = [],
        paymentSchedules = [],
        allowVoucher = true,
        compact = false,
        onUpdated = () => {},
    } = $props();

    let effectiveOrderId = $derived(order?.id ?? orderId ?? null);
    let effectiveOrderTotal = $derived(
        order !== null && order !== undefined
            ? Number(order.total_amount ?? orderTotal ?? 0)
            : Number(orderTotal ?? 0)
    );
    let incomingPaymentsProp = $derived(
        order !== null && order !== undefined
            ? (order.payments ?? [])
            : (payments ?? [])
    );
    let incomingSchedulesProp = $derived(
        order !== null && order !== undefined
            ? (order.payment_schedules ?? order.paymentSchedules ?? [])
            : (paymentSchedules ?? [])
    );

    let localPayments = $state([]);
    let localSchedules = $state([]);
    let activeTab = $state('payment'); // 'payment' | 'schedule'

    // Estado del bloque A: Registrar Abono Recibido (Hoy)
    let paymentForm = $state({
        amount: '',
        payment_date: getTodayLocalDateKey(),
        payment_method: 'transfer',
        notes: '',
        voucher: null,
    });
    let paymentLoading = $state(false);
    let paymentError = $state('');
    let paymentSuccess = $state('');
    let confirmDeletePaymentId = $state(null);

    // Estado del bloque B: Programar Nuevo Cobro
    let scheduleForm = $state({
        scheduled_date: getOffsetDateKey(3),
        amount: '',
        notes: '',
    });
    let scheduleLoading = $state(false);
    let scheduleError = $state('');
    let scheduleSuccess = $state('');
    let scheduleActionId = $state(null);
    let confirmDeleteScheduleId = $state(null);
    let editingScheduleId = $state(null);
    let editScheduleForm = $state({
        scheduled_date: '',
        amount: '',
        notes: '',
    });

    function getOffsetDateKey(daysAhead) {
        const d = new Date();
        d.setDate(d.getDate() + daysAhead);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    // Sincronizar props entrantes cuando cambia el pedido o se recargan sus datos
    let prevOrderId = null;
    $effect(() => {
        const incomingOrderId = effectiveOrderId;
        const incomingPayments = incomingPaymentsProp;
        const incomingSchedules = incomingSchedulesProp;

        untrack(() => {
            localPayments = [...(incomingPayments || [])];
            localSchedules = [...(incomingSchedules || [])];

            if (incomingOrderId !== prevOrderId) {
                prevOrderId = incomingOrderId;
                paymentError = '';
                paymentSuccess = '';
                scheduleError = '';
                scheduleSuccess = '';
                confirmDeletePaymentId = null;
                confirmDeleteScheduleId = null;
                editingScheduleId = null;
                editScheduleForm.scheduled_date = '';
                editScheduleForm.amount = '';
                editScheduleForm.notes = '';
                paymentForm.payment_date = getTodayLocalDateKey();
                paymentForm.notes = '';
                paymentForm.voucher = null;
                paymentForm.amount = '';
                scheduleForm.scheduled_date = getOffsetDateKey(3);
                scheduleForm.amount = '';
                scheduleForm.notes = '';
            }
        });
    });

    // Cálculos reactivos financieros con descuentos ya reflejados en effectiveOrderTotal
    let totalOrder = $derived(Math.max(0, Number(effectiveOrderTotal || 0)));

    function notifyParent(nextPayments, nextSchedules) {
        const paidSum = Math.round(
            (nextPayments || []).reduce((sum, p) => sum + Number(p?.amount || 0), 0) * 100
        ) / 100;
        const pendingBal = Math.max(0, Math.round((totalOrder - paidSum) * 100) / 100);

        onUpdated({
            payments: nextPayments,
            paymentSchedules: nextSchedules,
            payment_schedules: nextSchedules,
            total_amount: totalOrder,
            paid_amount: paidSum,
            pending_balance: pendingBal,
        });
    }

    let totalPaid = $derived.by(() =>
        Math.round(
            localPayments.reduce((sum, p) => sum + Number(p?.amount || 0), 0) * 100
        ) / 100
    );

    let remainingBalance = $derived(Math.max(0, Math.round((totalOrder - totalPaid) * 100) / 100));

    let totalPendingScheduled = $derived.by(() =>
        Math.round(
            localSchedules
                .filter((s) => s?.status === 'pending')
                .reduce((sum, s) => sum + Number(s?.amount || 0), 0) * 100
        ) / 100
    );

    let availableToSchedule = $derived(
        Math.max(0, Math.round((totalOrder - totalPaid - totalPendingScheduled) * 100) / 100)
    );

    let sortedSchedules = $derived.by(() => {
        return [...localSchedules].sort((a, b) => {
            const da = normalizeScheduleDateKey(a?.scheduled_date);
            const db = normalizeScheduleDateKey(b?.scheduled_date);
            if (da !== db) return da.localeCompare(db);
            return Number(a?.id || 0) - Number(b?.id || 0);
        });
    });

    // Validaciones en vivo
    let paymentAmountNum = $derived(Number(paymentForm.amount || 0));
    let paymentExceedsBalance = $derived(
        paymentAmountNum > 0 && paymentAmountNum > remainingBalance + 0.01
    );

    let scheduleAmountNum = $derived(Number(scheduleForm.amount || 0));
    let scheduleExceedsAvailable = $derived(
        scheduleAmountNum > 0 && scheduleAmountNum > availableToSchedule + 0.01
    );

    let editScheduleAmountNum = $derived(Number(editScheduleForm.amount || 0));
    let editMaxAllowed = $derived.by(() => {
        if (!editingScheduleId) return 0;
        const otherPending = Math.round(
            localSchedules
                .filter((s) => s?.status === 'pending' && s?.id !== editingScheduleId)
                .reduce((sum, s) => sum + Number(s?.amount || 0), 0) * 100
        ) / 100;
        return Math.max(0, Math.round((totalOrder - totalPaid - otherPending) * 100) / 100);
    });
    let editScheduleExceedsMax = $derived(
        editingScheduleId !== null && editScheduleAmountNum > 0 && editScheduleAmountNum > editMaxAllowed + 0.01
    );

    function formatCurrency(val) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
        }).format(Number(val || 0));
    }

    function formatMethodLabel(method) {
        switch (method) {
            case 'transfer': return 'Transferencia';
            case 'cash': return 'Efectivo';
            case 'card': return 'Tarjeta';
            default: return 'Otro';
        }
    }

    function getXsrfTokenFromCookie() {
        if (typeof document === 'undefined') return '';
        const match = document.cookie.match(new RegExp('(^|;\\s*)XSRF-TOKEN=([^;]*)'));
        return match ? decodeURIComponent(match[2]) : '';
    }

    function handleVoucherFileChange(e) {
        paymentForm.voucher = e.target.files?.[0] ?? null;
    }

    async function handleRegisterPayment() {
        if (!effectiveOrderId) return;
        paymentError = '';
        paymentSuccess = '';

        if (!paymentAmountNum || paymentAmountNum <= 0) {
            paymentError = 'Ingresa un monto válido mayor a 0.';
            return;
        }
        if (paymentExceedsBalance) {
            paymentError = `El abono ingresado supera el saldo pendiente del pedido (${formatCurrency(remainingBalance)}).`;
            return;
        }

        paymentLoading = true;
        try {
            let res;
            if (allowVoucher && paymentForm.voucher) {
                const fd = new FormData();
                fd.append('amount', String(paymentAmountNum));
                fd.append('payment_date', paymentForm.payment_date || getTodayLocalDateKey());
                fd.append('payment_method', paymentForm.payment_method);
                fd.append('notes', (paymentForm.notes || '').trim());
                fd.append('voucher', paymentForm.voucher);

                const headers = {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getXsrfTokenFromCookie(),
                };
                const metaToken = typeof document !== 'undefined'
                    ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                    : null;
                if (metaToken) headers['X-CSRF-TOKEN'] = metaToken;

                res = await fetch(`/orders/${effectiveOrderId}/payments`, {
                    method: 'POST',
                    headers,
                    body: fd,
                    credentials: 'same-origin',
                });
            } else {
                res = await csrfFetch(`/orders/${effectiveOrderId}/payments`, {
                    method: 'POST',
                    body: JSON.stringify({
                        amount: paymentAmountNum,
                        payment_date: paymentForm.payment_date || getTodayLocalDateKey(),
                        payment_method: paymentForm.payment_method,
                        notes: (paymentForm.notes || '').trim() || null,
                    }),
                });
            }

            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const firstErr = data?.errors ? Object.values(data.errors).flat()[0] : null;
                paymentError = firstErr || data.message || 'No se pudo registrar el abono.';
                return;
            }

            if (Array.isArray(data.payments)) {
                localPayments = data.payments;
            } else if (data.payment) {
                localPayments = [data.payment, ...localPayments];
            }
            if (Array.isArray(data.payment_schedules)) {
                localSchedules = data.payment_schedules;
            }

            paymentForm.amount = '';
            paymentForm.notes = '';
            paymentForm.voucher = null;
            paymentSuccess = 'Abono registrado exitosamente.';

            notifyParent(localPayments, localSchedules);
        } catch (err) {
            paymentError = err?.message || 'Error de conexión al registrar abono.';
        } finally {
            paymentLoading = false;
        }
    }

    async function handleDeletePayment(paymentId) {
        paymentLoading = true;
        paymentError = '';
        paymentSuccess = '';
        try {
            const endpoint = effectiveOrderId ? `/orders/${effectiveOrderId}/payments/${paymentId}` : `/payments/${paymentId}`;
            const res = await csrfFetch(endpoint, {
                method: 'DELETE',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                paymentError = data.message || 'No se pudo eliminar el abono.';
                return;
            }

            localPayments = Array.isArray(data.payments)
                ? data.payments
                : localPayments.filter((p) => p.id !== paymentId);
            if (Array.isArray(data.payment_schedules)) {
                localSchedules = data.payment_schedules;
            }
            confirmDeletePaymentId = null;

            notifyParent(localPayments, localSchedules);
        } catch (err) {
            paymentError = err?.message || 'Error de conexión al eliminar abono.';
        } finally {
            paymentLoading = false;
        }
    }

    async function handleCreateSchedule() {
        if (!effectiveOrderId) return;
        scheduleError = '';
        scheduleSuccess = '';

        if (!scheduleForm.scheduled_date) {
            scheduleError = 'Selecciona la fecha del cobro programado.';
            return;
        }
        if (!scheduleAmountNum || scheduleAmountNum <= 0) {
            scheduleError = 'Ingresa un valor válido mayor a 0.';
            return;
        }
        if (scheduleExceedsAvailable) {
            scheduleError = 'No puedes programar este monto: la suma de abonos y cobros programados supera el valor total del pedido con descuentos.';
            return;
        }

        scheduleLoading = true;
        try {
            const res = await csrfFetch(`/orders/${effectiveOrderId}/payment-schedules`, {
                method: 'POST',
                body: JSON.stringify({
                    scheduled_date: scheduleForm.scheduled_date,
                    amount: scheduleAmountNum,
                    notes: (scheduleForm.notes || '').trim() || null,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const firstErr = data?.errors ? Object.values(data.errors).flat()[0] : null;
                scheduleError = firstErr || data.message || 'No se pudo programar el cobro.';
                return;
            }

            if (Array.isArray(data.payments)) {
                localPayments = data.payments;
            }
            if (Array.isArray(data.payment_schedules)) {
                localSchedules = data.payment_schedules;
            } else if (data.schedule) {
                localSchedules = [...localSchedules, data.schedule];
            }

            scheduleForm.amount = '';
            scheduleForm.notes = '';
            scheduleSuccess = 'Cobro programado agregado correctamente.';

            notifyParent(localPayments, localSchedules);
        } catch (err) {
            scheduleError = err?.message || 'Error de conexión al programar el cobro.';
        } finally {
            scheduleLoading = false;
        }
    }

    async function handleMarkSchedulePaid(schedule) {
        if (!effectiveOrderId || !schedule?.id) return;
        scheduleError = '';
        scheduleSuccess = '';
        scheduleActionId = schedule.id;

        try {
            const res = await csrfFetch(`/orders/${effectiveOrderId}/payment-schedules/${schedule.id}/mark-paid`, {
                method: 'POST',
                body: JSON.stringify({
                    payment_method: 'transfer',
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const firstErr = data?.errors ? Object.values(data.errors).flat()[0] : null;
                scheduleError = firstErr || data.message || 'No se pudo marcar el cobro como pagado.';
                return;
            }

            if (Array.isArray(data.payments)) {
                localPayments = data.payments;
            }
            if (Array.isArray(data.payment_schedules)) {
                localSchedules = data.payment_schedules;
            }
            scheduleSuccess = 'Cobro marcado como cobrado y convertido en abono real.';

            notifyParent(localPayments, localSchedules);
        } catch (err) {
            scheduleError = err?.message || 'Error de conexión al marcar cobro.';
        } finally {
            scheduleActionId = null;
        }
    }

    function startEditSchedule(sched) {
        confirmDeleteScheduleId = null;
        scheduleError = '';
        scheduleSuccess = '';
        editingScheduleId = sched.id;
        editScheduleForm.scheduled_date = normalizeScheduleDateKey(sched.scheduled_date) || '';
        editScheduleForm.amount = String(Math.round(Number(sched.amount || 0) * 100) / 100);
        editScheduleForm.notes = sched.notes || '';
    }

    function cancelEditSchedule() {
        editingScheduleId = null;
        scheduleError = '';
    }

    async function handleUpdateSchedule(scheduleId) {
        if (!effectiveOrderId || !scheduleId) return;
        scheduleError = '';
        scheduleSuccess = '';

        if (!editScheduleForm.scheduled_date) {
            scheduleError = 'Selecciona la fecha del cobro programado.';
            return;
        }
        if (!editScheduleAmountNum || editScheduleAmountNum <= 0) {
            scheduleError = 'Ingresa un valor válido mayor a 0.';
            return;
        }
        if (editScheduleExceedsMax) {
            scheduleError = `El monto supera el disponible por programar tras descuentos y abonos (${formatCurrency(editMaxAllowed)}).`;
            return;
        }

        scheduleActionId = scheduleId;
        try {
            const res = await csrfFetch(`/orders/${effectiveOrderId}/payment-schedules/${scheduleId}`, {
                method: 'PUT',
                body: JSON.stringify({
                    scheduled_date: editScheduleForm.scheduled_date,
                    amount: editScheduleAmountNum,
                    notes: (editScheduleForm.notes || '').trim() || null,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const firstErr = data?.errors ? Object.values(data.errors).flat()[0] : null;
                scheduleError = firstErr || data.message || 'No se pudo actualizar el cobro programado.';
                return;
            }

            if (Array.isArray(data.payments)) {
                localPayments = data.payments;
            }
            if (Array.isArray(data.payment_schedules)) {
                localSchedules = data.payment_schedules;
            } else if (data.schedule) {
                localSchedules = localSchedules.map((s) => (s.id === scheduleId ? data.schedule : s));
            }
            editingScheduleId = null;
            scheduleSuccess = data.message || 'Cobro programado actualizado correctamente.';

            notifyParent(localPayments, localSchedules);
        } catch (err) {
            scheduleError = err?.message || 'Error de conexión al actualizar cobro programado.';
        } finally {
            scheduleActionId = null;
        }
    }

    async function handleUnmarkSchedulePaid(schedule) {
        if (!effectiveOrderId || !schedule?.id) return;
        scheduleError = '';
        scheduleSuccess = '';
        confirmDeleteScheduleId = null;
        editingScheduleId = null;
        scheduleActionId = schedule.id;

        try {
            const res = await csrfFetch(`/orders/${effectiveOrderId}/payment-schedules/${schedule.id}/unmark-paid`, {
                method: 'POST',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const firstErr = data?.errors ? Object.values(data.errors).flat()[0] : null;
                scheduleError = firstErr || data.message || 'No se pudo revertir el cobro.';
                return;
            }

            if (Array.isArray(data.payments)) {
                localPayments = data.payments;
            }
            if (Array.isArray(data.payment_schedules)) {
                localSchedules = data.payment_schedules;
            }
            scheduleSuccess = data.message || 'Cobro revertido a pendiente.';

            notifyParent(localPayments, localSchedules);
        } catch (err) {
            scheduleError = err?.message || 'Error de conexión al revertir cobro.';
        } finally {
            scheduleActionId = null;
        }
    }

    async function handleDeleteSchedule(scheduleId) {
        if (!effectiveOrderId || !scheduleId) return;
        scheduleError = '';
        scheduleSuccess = '';
        scheduleActionId = scheduleId;

        try {
            const res = await csrfFetch(`/orders/${effectiveOrderId}/payment-schedules/${scheduleId}`, {
                method: 'DELETE',
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                scheduleError = data.message || 'No se pudo eliminar el cobro programado.';
                return;
            }

            if (Array.isArray(data.payments)) {
                localPayments = data.payments;
            }
            localSchedules = Array.isArray(data.payment_schedules)
                ? data.payment_schedules
                : localSchedules.filter((s) => s.id !== scheduleId);
            confirmDeleteScheduleId = null;
            if (editingScheduleId === scheduleId) {
                editingScheduleId = null;
            }
            scheduleSuccess = data.message || 'Cobro programado eliminado correctamente.';

            notifyParent(localPayments, localSchedules);
        } catch (err) {
            scheduleError = err?.message || 'Error de conexión al eliminar cobro programado.';
        } finally {
            scheduleActionId = null;
        }
    }
</script>

<div class="space-y-4 text-xs">
    <!-- RESUMEN FINANCIERO SUPERIOR (4 INDICADORES CON DESCUENTOS) -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-slate-200 dark:border-[#26334D]">
        <div class="p-2 rounded-xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D]">
            <span class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block leading-tight">
                Total Pedido (con desc.)
            </span>
            <span class="font-mono font-bold text-slate-900 dark:text-white text-sm mt-0.5 block">
                {formatCurrency(totalOrder)}
            </span>
        </div>

        <div class="p-2 rounded-xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D]">
            <span class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block leading-tight">
                Total Abonado
            </span>
            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400 text-sm mt-0.5 block">
                {formatCurrency(totalPaid)}
            </span>
        </div>

        <div class="p-2 rounded-xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D]">
            <span class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block leading-tight">
                Cobros Programados
            </span>
            <span class="font-mono font-bold text-[#4F46E5] dark:text-indigo-400 text-sm mt-0.5 block">
                {formatCurrency(totalPendingScheduled)}
            </span>
        </div>

        <div class="p-2 rounded-xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D]">
            <span class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block leading-tight">
                Disponible por Programar
            </span>
            <span class="font-mono font-bold text-sm mt-0.5 block {availableToSchedule > 0 ? 'text-amber-600 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-400'}">
                {formatCurrency(availableToSchedule)}
            </span>
        </div>
    </div>

    <!-- SELECTOR DE PESTAÑAS: ABONO HOY vs PROGRAMAR COBRO -->
    {#if remainingBalance > 0}
        <div class="space-y-3">
            <div class="flex items-center gap-1.5 p-1 bg-slate-100 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D]">
                <button
                    type="button"
                    onclick={() => { activeTab = 'payment'; paymentError = ''; paymentSuccess = ''; }}
                    class="flex-1 px-3 py-1.5 rounded-lg font-bold text-xs transition-all flex items-center justify-center gap-1.5 {activeTab === 'payment' ? 'bg-white dark:bg-[#151D2C] text-emerald-600 dark:text-emerald-400 shadow-sm border border-slate-200 dark:border-[#26334D]' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                >
                    <span>💳</span>
                    <span>Registrar Abono (Hoy)</span>
                </button>
                <button
                    type="button"
                    onclick={() => { activeTab = 'schedule'; scheduleError = ''; scheduleSuccess = ''; }}
                    class="flex-1 px-3 py-1.5 rounded-lg font-bold text-xs transition-all flex items-center justify-center gap-1.5 {activeTab === 'schedule' ? 'bg-white dark:bg-[#151D2C] text-[#4F46E5] dark:text-indigo-400 shadow-sm border border-slate-200 dark:border-[#26334D]' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                >
                    <span>📅</span>
                    <span>Programar Cobro</span>
                </button>
            </div>

            <!-- BLOQUE A: REGISTRAR ABONO RECIBIDO (HOY) -->
            {#if activeTab === 'payment'}
                <div class="p-3.5 bg-slate-50/80 dark:bg-[#0B0F17]/70 rounded-2xl border border-emerald-500/30 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-bold text-slate-900 dark:text-white text-xs">
                            💳 Registrar Abono Recibido
                        </span>
                        <button
                            type="button"
                            onclick={() => { paymentForm.amount = remainingBalance; paymentError = ''; }}
                            class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 hover:underline"
                        >
                            Completar saldo ({formatCurrency(remainingBalance)})
                        </button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="opm-pay-amount-{effectiveOrderId}" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Monto del Abono ($) *
                            </label>
                            <CurrencyInput
                                id="opm-pay-amount-{effectiveOrderId}"
                                bind:value={paymentForm.amount}
                                placeholder="0"
                            />
                            {#if paymentExceedsBalance}
                                <p class="mt-1.5 text-[11px] font-semibold text-rose-600 dark:text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg px-2.5 py-1.5">
                                    ⚠️ El abono ingresado supera el saldo pendiente del pedido ({formatCurrency(remainingBalance)}).
                                </p>
                            {/if}
                        </div>

                        <div>
                            <label for="opm-pay-date-{effectiveOrderId}" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Fecha de Pago *
                            </label>
                            <input
                                id="opm-pay-date-{effectiveOrderId}"
                                type="date"
                                bind:value={paymentForm.payment_date}
                                class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono text-xs focus:outline-none focus:border-[#4F46E5]"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="opm-pay-method-{effectiveOrderId}" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Método de Pago *
                            </label>
                            <select
                                id="opm-pay-method-{effectiveOrderId}"
                                bind:value={paymentForm.payment_method}
                                class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white text-xs focus:outline-none focus:border-[#4F46E5]"
                            >
                                <option value="transfer">Transferencia / Nequi / Daviplata</option>
                                <option value="cash">Efectivo</option>
                                <option value="card">Tarjeta</option>
                                <option value="other">Otro medio</option>
                            </select>
                        </div>

                        <div>
                            <label for="opm-pay-notes-{effectiveOrderId}" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Notas / Referencia
                            </label>
                            <input
                                id="opm-pay-notes-{effectiveOrderId}"
                                type="text"
                                bind:value={paymentForm.notes}
                                placeholder="Ej. Aprobación #1234..."
                                class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:outline-none focus:border-[#4F46E5]"
                            />
                        </div>
                    </div>

                    {#if allowVoucher}
                        <div>
                            <label for="opm-pay-voucher-{effectiveOrderId}" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Comprobante de Pago (Foto o PDF, máx 5MB)
                            </label>
                            <input
                                id="opm-pay-voucher-{effectiveOrderId}"
                                type="file"
                                accept="image/*,.pdf"
                                onchange={handleVoucherFileChange}
                                class="w-full text-slate-500 dark:text-slate-400 text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-100 dark:file:bg-slate-800 file:text-slate-700 dark:file:text-slate-200 hover:file:bg-slate-200 dark:hover:file:bg-slate-700"
                            />
                        </div>
                    {/if}

                    {#if paymentError}
                        <p class="text-[11px] font-semibold text-rose-600 dark:text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg px-2.5 py-1.5">
                            ⚠️ {paymentError}
                        </p>
                    {/if}
                    {#if paymentSuccess}
                        <p class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/30 rounded-lg px-2.5 py-1.5">
                            ✓ {paymentSuccess}
                        </p>
                    {/if}

                    <div class="flex justify-end">
                        <button
                            type="button"
                            onclick={handleRegisterPayment}
                            disabled={paymentLoading || !paymentAmountNum || paymentAmountNum <= 0 || paymentExceedsBalance}
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-semibold rounded-xl text-xs shadow-sm transition-colors"
                        >
                            {paymentLoading ? 'Guardando Abono...' : '+ Guardar Abono'}
                        </button>
                    </div>
                </div>
            {:else}
                <!-- BLOQUE B: PROGRAMAR NUEVO COBRO DE ABONO -->
                <div class="p-3.5 bg-slate-50/80 dark:bg-[#0B0F17]/70 rounded-2xl border border-[#4F46E5]/30 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-bold text-slate-900 dark:text-white text-xs">
                            📅 Programar Nuevo Cobro de Abono
                        </span>
                        {#if availableToSchedule > 0}
                            <button
                                type="button"
                                onclick={() => { scheduleForm.amount = availableToSchedule; scheduleError = ''; }}
                                class="px-2.5 py-1 rounded-lg bg-[#4F46E5]/10 hover:bg-[#4F46E5]/20 text-[#4F46E5] dark:text-indigo-300 font-semibold text-[11px] border border-[#4F46E5]/30 transition-colors"
                            >
                                Completar saldo ({formatCurrency(availableToSchedule)})
                            </button>
                        {/if}
                    </div>

                    {#if availableToSchedule <= 0}
                        <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-700 dark:text-amber-300 text-[11px] font-medium">
                            ℹ️ Ya tienes el 100% del saldo del pedido cubierto entre abonos recibidos y cobros programados pendientes.
                        </div>
                    {:else}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="opm-sched-date-{effectiveOrderId}" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Fecha del Cobro *
                                </label>
                                <input
                                    id="opm-sched-date-{effectiveOrderId}"
                                    type="date"
                                    bind:value={scheduleForm.scheduled_date}
                                    class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono text-xs focus:outline-none focus:border-[#4F46E5]"
                                />
                                <div class="flex flex-wrap items-center gap-1 mt-1.5">
                                    <button type="button" onclick={() => scheduleForm.scheduled_date = getTodayLocalDateKey()} class="px-2 py-0.5 rounded-md bg-slate-200/70 dark:bg-slate-800 text-[10px] font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700">Hoy</button>
                                    <button type="button" onclick={() => scheduleForm.scheduled_date = getOffsetDateKey(1)} class="px-2 py-0.5 rounded-md bg-slate-200/70 dark:bg-slate-800 text-[10px] font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700">Mañana</button>
                                    <button type="button" onclick={() => scheduleForm.scheduled_date = getOffsetDateKey(7)} class="px-2 py-0.5 rounded-md bg-slate-200/70 dark:bg-slate-800 text-[10px] font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700">+7d</button>
                                    <button type="button" onclick={() => scheduleForm.scheduled_date = getOffsetDateKey(15)} class="px-2 py-0.5 rounded-md bg-slate-200/70 dark:bg-slate-800 text-[10px] font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700">+15d</button>
                                </div>
                            </div>

                            <div>
                                <label for="opm-sched-amount-{effectiveOrderId}" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Valor a Cobrar ($) *
                                </label>
                                <input
                                    id="opm-sched-amount-{effectiveOrderId}"
                                    type="number"
                                    min="1"
                                    step="1"
                                    bind:value={scheduleForm.amount}
                                    placeholder="0"
                                    class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono font-bold text-xs focus:outline-none focus:border-[#4F46E5]"
                                />
                                {#if scheduleExceedsAvailable}
                                    <p class="mt-1.5 text-[11px] font-semibold text-rose-600 dark:text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg px-2.5 py-1.5">
                                        ⚠️ No puedes programar este monto: la suma de abonos y cobros programados supera el valor total del pedido con descuentos.
                                    </p>
                                {/if}
                            </div>
                        </div>

                        <div>
                            <label for="opm-sched-notes-{effectiveOrderId}" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Nota / Recordatorio (Opcional)
                            </label>
                            <input
                                id="opm-sched-notes-{effectiveOrderId}"
                                type="text"
                                bind:value={scheduleForm.notes}
                                placeholder="Ej. Segundo abono antes de despacho"
                                class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:outline-none focus:border-[#4F46E5]"
                            />
                        </div>

                        {#if scheduleError}
                            <p class="text-[11px] font-semibold text-rose-600 dark:text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg px-2.5 py-1.5">
                                ⚠️ {scheduleError}
                            </p>
                        {/if}
                        {#if scheduleSuccess}
                            <p class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/30 rounded-lg px-2.5 py-1.5">
                                ✓ {scheduleSuccess}
                            </p>
                        {/if}

                        <div class="flex justify-end">
                            <button
                                type="button"
                                onclick={handleCreateSchedule}
                                disabled={scheduleLoading || !scheduleForm.scheduled_date || !scheduleAmountNum || scheduleAmountNum <= 0 || scheduleExceedsAvailable}
                                class="px-4 py-2 bg-[#4F46E5] hover:bg-[#4338CA] disabled:opacity-50 text-white font-semibold rounded-xl text-xs shadow-sm transition-colors"
                            >
                                {scheduleLoading ? 'Programando...' : '+ Programar Cobro'}
                            </button>
                        </div>
                    {/if}
                </div>
            {/if}
        </div>
    {:else}
        <div class="p-3 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 text-xs font-semibold flex items-center gap-2">
            <span>✓</span>
            <span>Este pedido se encuentra pagado al 100% ({formatCurrency(totalPaid)}).</span>
        </div>
    {/if}

    <!-- LISTA DE COBROS PROGRAMADOS DEL PEDIDO -->
    <div class="space-y-2">
        <div class="flex items-center justify-between">
            <span class="font-bold text-slate-900 dark:text-white text-xs">
                📅 Cobros Programados ({sortedSchedules.length})
            </span>
            {#if totalPendingScheduled > 0}
                <span class="text-[11px] font-mono font-semibold text-[#4F46E5] dark:text-indigo-400">
                    Pendiente programado: {formatCurrency(totalPendingScheduled)}
                </span>
            {/if}
        </div>

        {#if scheduleError && (remainingBalance <= 0 || activeTab !== 'schedule')}
            <p class="text-[11px] font-semibold text-rose-600 dark:text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg px-2.5 py-1.5">
                ⚠️ {scheduleError}
            </p>
        {/if}
        {#if scheduleSuccess && (remainingBalance <= 0 || activeTab !== 'schedule')}
            <p class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border border-emerald-500/30 rounded-lg px-2.5 py-1.5">
                ✓ {scheduleSuccess}
            </p>
        {/if}

        {#if sortedSchedules.length === 0}
            <div class="p-3.5 text-center border border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-slate-400 text-xs italic">
                No hay cobros programados para este pedido.
            </div>
        {:else}
            <div class="space-y-1.5 {compact ? 'max-h-56' : 'max-h-72'} overflow-y-auto pr-0.5">
                {#each sortedSchedules as sched (sched.id)}
                    {@const traffic = sched.status === 'pending' ? getScheduleDateTrafficLight(sched.scheduled_date) : null}
                    <div class="p-2.5 bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D] space-y-2">
                        {#if editingScheduleId === sched.id && sched.status === 'pending'}
                            <div class="space-y-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-[11px] font-bold text-[#4F46E5] dark:text-indigo-400">
                                        ✏️ Editando Cobro Programado
                                    </span>
                                    <span class="text-[10px] font-mono text-[#64748B] dark:text-slate-400">
                                        Máx. disponible: {formatCurrency(editMaxAllowed)}
                                    </span>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <div>
                                        <label for="opm-edit-date-{sched.id}" class="block text-[11px] font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Fecha del Cobro *
                                        </label>
                                        <input
                                            id="opm-edit-date-{sched.id}"
                                            type="date"
                                            bind:value={editScheduleForm.scheduled_date}
                                            class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-lg px-2.5 py-1.5 text-slate-900 dark:text-white font-mono text-xs focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>

                                    <div>
                                        <label for="opm-edit-amount-{sched.id}" class="block text-[11px] font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                            Valor a Cobrar ($) *
                                        </label>
                                        <input
                                            id="opm-edit-amount-{sched.id}"
                                            type="number"
                                            min="1"
                                            step="1"
                                            bind:value={editScheduleForm.amount}
                                            class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-lg px-2.5 py-1.5 text-slate-900 dark:text-white font-mono font-bold text-xs focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label for="opm-edit-notes-{sched.id}" class="block text-[11px] font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                        Nota
                                    </label>
                                    <input
                                        id="opm-edit-notes-{sched.id}"
                                        type="text"
                                        bind:value={editScheduleForm.notes}
                                        placeholder="Ej. Segundo abono antes de despacho"
                                        class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-lg px-2.5 py-1.5 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:outline-none focus:border-[#4F46E5]"
                                    />
                                </div>

                                {#if editScheduleExceedsMax}
                                    <p class="text-[11px] font-semibold text-rose-600 dark:text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg px-2.5 py-1.5">
                                        ⚠️ El valor ingresado supera el tope disponible por programar tras descuentos y abonos ({formatCurrency(editMaxAllowed)}).
                                    </p>
                                {/if}

                                <div class="flex items-center justify-end gap-1.5">
                                    <button
                                        type="button"
                                        onclick={cancelEditSchedule}
                                        disabled={scheduleActionId === sched.id}
                                        class="px-2.5 py-1 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-[11px] font-semibold transition-colors"
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="button"
                                        onclick={() => handleUpdateSchedule(sched.id)}
                                        disabled={scheduleActionId === sched.id || !editScheduleForm.scheduled_date || !editScheduleAmountNum || editScheduleAmountNum <= 0 || editScheduleExceedsMax}
                                        class="px-3 py-1 rounded-lg bg-[#4F46E5] hover:bg-[#4338CA] disabled:opacity-50 text-white text-[11px] font-bold shadow-sm transition-colors"
                                    >
                                        {scheduleActionId === sched.id ? 'Guardando...' : 'Guardar Cambios'}
                                    </button>
                                </div>
                            </div>
                        {:else}
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="space-y-0.5 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        {#if sched.status === 'paid'}
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/30">
                                                ✓ Cobrado
                                            </span>
                                        {:else if traffic}
                                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-bold border {traffic.badgeClass}">
                                                <span class="w-1.5 h-1.5 rounded-full {traffic.dotClass}"></span>
                                                <span>{traffic.label}</span>
                                            </span>
                                        {/if}
                                        <span class="font-mono font-bold text-slate-900 dark:text-white text-xs">
                                            {formatCurrency(sched.amount)}
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-[#64748B] dark:text-slate-400 flex flex-wrap items-center gap-1.5">
                                        <span class="font-mono">📆 {formatFullScheduleDate(sched.scheduled_date)}</span>
                                        {#if sched.notes}
                                            <span>•</span>
                                            <span class="truncate max-w-[220px]" title={sched.notes}>{sched.notes}</span>
                                        {/if}
                                    </div>
                                </div>

                                {#if sched.status === 'pending'}
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        {#if confirmDeleteScheduleId === sched.id}
                                            <span class="text-[10px] text-rose-500 font-semibold">¿Eliminar?</span>
                                            <button
                                                type="button"
                                                onclick={() => handleDeleteSchedule(sched.id)}
                                                disabled={scheduleActionId === sched.id}
                                                class="px-2 py-1 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-[10px] font-bold"
                                            >
                                                Sí
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => confirmDeleteScheduleId = null}
                                                disabled={scheduleActionId === sched.id}
                                                class="px-2 py-1 rounded-lg bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 text-[10px] font-semibold"
                                            >
                                                No
                                            </button>
                                        {:else}
                                            <button
                                                type="button"
                                                onclick={() => handleMarkSchedulePaid(sched)}
                                                disabled={scheduleActionId === sched.id}
                                                class="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-[11px] font-bold transition-colors shadow-sm"
                                                title="Marcar cobro como recibido y registrarlo como abono"
                                            >
                                                {scheduleActionId === sched.id ? '...' : '✓ Marcar Cobrado'}
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => startEditSchedule(sched)}
                                                disabled={scheduleActionId === sched.id}
                                                class="px-2 py-1 rounded-lg text-slate-600 dark:text-slate-300 hover:text-[#4F46E5] dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 border border-slate-200 dark:border-slate-700 text-[11px] font-semibold transition-colors"
                                                title="Editar cobro programado"
                                            >
                                                ✏️
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => { editingScheduleId = null; confirmDeleteScheduleId = sched.id; }}
                                                disabled={scheduleActionId === sched.id}
                                                class="px-2 py-1 rounded-lg text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 text-[11px] font-semibold transition-colors"
                                                title="Eliminar cobro programado"
                                            >
                                                🗑️
                                            </button>
                                        {/if}
                                    </div>
                                {:else if sched.status === 'paid'}
                                    <div class="flex flex-wrap items-center justify-end gap-1.5 shrink-0">
                                        {#if confirmDeleteScheduleId === sched.id}
                                            <div class="flex flex-wrap items-center gap-1.5 bg-rose-500/10 border border-rose-500/30 rounded-lg px-2 py-1">
                                                <span class="text-[10px] text-rose-600 dark:text-rose-400 font-semibold">
                                                    Se eliminará el cobro programado y su abono asociado. ¿Confirmar?
                                                </span>
                                                <button
                                                    type="button"
                                                    onclick={() => handleDeleteSchedule(sched.id)}
                                                    disabled={scheduleActionId === sched.id}
                                                    class="px-2 py-0.5 rounded-md bg-rose-600 hover:bg-rose-700 text-white text-[10px] font-bold"
                                                >
                                                    Sí
                                                </button>
                                                <button
                                                    type="button"
                                                    onclick={() => confirmDeleteScheduleId = null}
                                                    disabled={scheduleActionId === sched.id}
                                                    class="px-2 py-0.5 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 text-[10px] font-semibold"
                                                >
                                                    No
                                                </button>
                                            </div>
                                        {:else}
                                            <button
                                                type="button"
                                                onclick={() => handleUnmarkSchedulePaid(sched)}
                                                disabled={scheduleActionId === sched.id}
                                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 disabled:opacity-50 text-amber-700 dark:text-amber-300 border border-amber-500/30 text-[11px] font-semibold transition-colors"
                                                title="Deshacer cobro y volver a pendiente"
                                            >
                                                {scheduleActionId === sched.id ? '...' : '↩️ Deshacer'}
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => { editingScheduleId = null; confirmDeleteScheduleId = sched.id; }}
                                                disabled={scheduleActionId === sched.id}
                                                class="px-2 py-1 rounded-lg text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 text-[11px] font-semibold transition-colors"
                                                title="Eliminar cobro programado y su abono asociado"
                                            >
                                                🗑️
                                            </button>
                                        {/if}
                                    </div>
                                {/if}
                            </div>
                        {/if}
                    </div>
                {/each}
            </div>
        {/if}
    </div>

    <!-- HISTORIAL DE ABONOS REALIZADOS -->
    <div class="space-y-2 pt-1 border-t border-slate-200 dark:border-[#26334D]">
        <span class="font-bold text-slate-900 dark:text-white text-xs block">
            Historial de Pagos Realizados ({localPayments.length})
        </span>

        {#if localPayments.length === 0}
            <div class="p-4 text-center border border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-slate-400 text-xs italic">
                Este pedido aún no tiene abonos registrados.
            </div>
        {:else}
            <div class="space-y-1.5 {compact ? 'max-h-44' : 'max-h-56'} overflow-y-auto pr-0.5">
                {#each localPayments as payment (payment.id)}
                    <div class="p-2.5 bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D] flex items-center justify-between gap-2">
                        <div class="space-y-0.5 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400 text-xs">
                                    {formatCurrency(payment.amount)}
                                </span>
                                <span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                    {formatMethodLabel(payment.payment_method)}
                                </span>
                            </div>
                            <div class="text-[11px] text-[#64748B] dark:text-slate-400 flex flex-wrap items-center gap-1.5 font-mono">
                                <span>📅 {normalizeScheduleDateKey(payment.payment_date) || payment.payment_date}</span>
                                {#if payment.notes}
                                    <span class="font-sans text-slate-500 dark:text-slate-400 truncate max-w-[210px]" title={payment.notes}>• {payment.notes}</span>
                                {/if}
                            </div>
                        </div>

                        <div class="flex items-center gap-1.5 shrink-0">
                            {#if payment.voucher_path}
                                <a
                                    href={`/storage/${payment.voucher_path}`}
                                    target="_blank"
                                    class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-sky-600 dark:text-sky-400 text-[10px] font-semibold border border-slate-200 dark:border-slate-700"
                                >
                                    📎 Recibo
                                </a>
                            {/if}

                            {#if confirmDeletePaymentId === payment.id}
                                <span class="text-[10px] text-rose-500 font-semibold">¿Anular?</span>
                                <button
                                    type="button"
                                    onclick={() => handleDeletePayment(payment.id)}
                                    disabled={paymentLoading}
                                    class="px-2 py-0.5 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-[10px] font-bold"
                                >
                                    Sí
                                </button>
                                <button
                                    type="button"
                                    onclick={() => confirmDeletePaymentId = null}
                                    disabled={paymentLoading}
                                    class="px-2 py-0.5 rounded-lg bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 text-[10px] font-semibold"
                                >
                                    No
                                </button>
                            {:else}
                                <button
                                    type="button"
                                    onclick={() => confirmDeletePaymentId = payment.id}
                                    disabled={paymentLoading}
                                    class="px-2 py-1 rounded-lg text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 text-[10px] font-semibold transition-colors"
                                    title="Anular este abono"
                                >
                                    🗑️
                                </button>
                            {/if}
                        </div>
                    </div>
                {/each}
            </div>
        {/if}
    </div>
</div>

