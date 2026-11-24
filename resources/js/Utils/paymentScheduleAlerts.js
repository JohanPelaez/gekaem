/**
 * Pure date and traffic-light alert helpers for scheduled order payments.
 * Always compares pure local YYYY-MM-DD dates to avoid UTC timezone offsets.
 */

export function getTodayLocalDateKey() {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

export function normalizeScheduleDateKey(dateValue) {
    if (!dateValue) return '';
    const match = String(dateValue).trim().match(/^(\d{4}-\d{2}-\d{2})/);
    return match ? match[1] : '';
}

export function diffDaysFromToday(dateKey) {
    const clean = normalizeScheduleDateKey(dateKey);
    if (!clean) return null;

    const todayStr = getTodayLocalDateKey();
    const [ty, tm, td] = todayStr.split('-').map(Number);
    const [sy, sm, sd] = clean.split('-').map(Number);

    const todayUtc = Date.UTC(ty, tm - 1, td);
    const targetUtc = Date.UTC(sy, sm - 1, sd);

    return Math.round((targetUtc - todayUtc) / 86400000);
}

export function formatShortDayMonth(dateKey) {
    const clean = normalizeScheduleDateKey(dateKey);
    if (!clean) return '';
    const [y, m, d] = clean.split('-').map(Number);
    const dateObj = new Date(y, m - 1, d);
    return dateObj.toLocaleDateString('es-CO', {
        day: 'numeric',
        month: 'short',
    }).replace('.', '');
}

export function formatFullScheduleDate(dateKey) {
    const clean = normalizeScheduleDateKey(dateKey);
    if (!clean) return '—';
    const [y, m, d] = clean.split('-').map(Number);
    const dateObj = new Date(y, m - 1, d);
    return dateObj.toLocaleDateString('es-CO', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * Compute traffic-light metadata for a single pending scheduled date.
 */
export function getScheduleDateTrafficLight(scheduledDate) {
    const clean = normalizeScheduleDateKey(scheduledDate);
    const diffDays = diffDaysFromToday(clean);
    if (diffDays === null) return null;

    const dayMonth = formatShortDayMonth(clean);

    if (diffDays < 0) {
        const overdueDays = Math.abs(diffDays);
        return {
            level: 'overdue',
            diffDays,
            dayMonth,
            label: `Cobro vencido (hace ${overdueDays}d)`,
            compactLabel: 'Vencido',
            badgeClass: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/40',
            dotClass: 'bg-rose-500 animate-pulse',
            emoji: '🔴',
        };
    }

    if (diffDays === 0) {
        return {
            level: 'today',
            diffDays,
            dayMonth,
            label: 'Cobrar HOY',
            compactLabel: 'Hoy',
            badgeClass: 'bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-500/40',
            dotClass: 'bg-amber-500 animate-pulse',
            emoji: '🟡',
        };
    }

    if (diffDays >= 1 && diffDays <= 3) {
        return {
            level: 'soon',
            diffDays,
            dayMonth,
            label: `Cobro en ${diffDays}d · ${dayMonth}`,
            compactLabel: `${dayMonth}`,
            badgeClass: 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30',
            dotClass: 'bg-amber-500',
            emoji: '🟡',
        };
    }

    return {
        level: 'upcoming',
        diffDays,
        dayMonth,
        label: `Cobro ${dayMonth}`,
        compactLabel: `${dayMonth}`,
        badgeClass: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border-emerald-500/30',
        dotClass: 'bg-emerald-500',
        emoji: '🟢',
    };
}

/**
 * Evaluate pending payment schedules on an order (when pending balance > 0)
 * and return the nearest traffic-light alert summary, or null if none.
 */
export function getPaymentScheduleAlert(order) {
    if (!order) return null;

    const pendingBalance = Number(order.pending_balance ?? 0);
    if (pendingBalance <= 0.01) return null;

    const schedules = order.payment_schedules || order.paymentSchedules || [];
    if (!Array.isArray(schedules) || schedules.length === 0) return null;

    const pending = schedules
        .filter((s) => s && s.status === 'pending' && normalizeScheduleDateKey(s.scheduled_date))
        .sort((a, b) =>
            normalizeScheduleDateKey(a.scheduled_date).localeCompare(normalizeScheduleDateKey(b.scheduled_date))
        );

    if (pending.length === 0) return null;

    const next = pending[0];
    const traffic = getScheduleDateTrafficLight(next.scheduled_date);
    if (!traffic) return null;

    return {
        ...traffic,
        nextSchedule: next,
        scheduledDate: normalizeScheduleDateKey(next.scheduled_date),
        formattedDate: formatFullScheduleDate(next.scheduled_date),
        amount: Number(next.amount || 0),
        notes: next.notes || '',
        pendingCount: pending.length,
        extraCount: Math.max(0, pending.length - 1),
    };
}

