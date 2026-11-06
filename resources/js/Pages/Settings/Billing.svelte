<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let {
        tenant = {},
        isTrialExpired = false,
        billingHistory = [],
        errors = {},
    } = $props();

    // Modal para registrar o actualizar tarjeta de crédito/débito
    let showCardModal = $state(false);
    let isSubmitting = $state(false);
    let isUpgrading = $state(false);

    function upgradeToPro() {
        if (confirm('¿Deseas cambiar tu taller al Plan Taller Pro por $75.000 COP/mes con 5 usuarios incluidos?')) {
            isUpgrading = true;
            router.post('/settings/billing/upgrade', {}, {
                preserveScroll: true,
                onFinish: () => { isUpgrading = false; },
            });
        }
    }

    let form = $state({
        card_number: '',
        cardholder_name: '',
        expiration_month: '',
        expiration_year: '',
        cvv: '',
    });

    // Detectar marca en tiempo real al escribir
    let detectedBrand = $derived.by(() => {
        const num = form.card_number.replace(/\D/g, '');
        if (/^4/.test(num)) return 'Visa';
        if (/^(5[1-5]|2[2-7])/.test(num)) return 'Mastercard';
        if (/^3[47]/.test(num)) return 'Amex';
        if (/^3(?:0[0-5]|[68])/.test(num)) return 'Diners';
        return null;
    });

    function formatCardNumber(e) {
        let value = e.target.value.replace(/\D/g, '').substring(0, 19);
        let formatted = value.match(/.{1,4}/g)?.join(' ') ?? value;
        form.card_number = formatted;
    }

    function openModal() {
        form.card_number = '';
        form.cardholder_name = '';
        form.expiration_month = '';
        form.expiration_year = '';
        form.cvv = '';
        showCardModal = true;
    }

    function closeModal() {
        showCardModal = false;
    }

    function submitCard(e) {
        e.preventDefault();
        isSubmitting = true;

        router.post('/settings/billing/payment-method', form, {
            preserveScroll: true,
            onStart: () => {
                isSubmitting = true;
            },
            onFinish: () => {
                isSubmitting = false;
            },
            onSuccess: () => {
                showCardModal = false;
                form.card_number = '';
                form.cardholder_name = '';
                form.expiration_month = '';
                form.expiration_year = '';
                form.cvv = '';
            },
        });
    }

    function formatCurrency(amount) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
        }).format(amount);
    }
</script>

<svelte:head>
    <title>Suscripción y Pagos · Bold · gipPyme</title>
</svelte:head>

<AppLayout title="Suscripción y Pagos">
    <div class="space-y-6 w-full max-w-6xl mx-auto pb-12">
        
        <!-- ENCABEZADO Y TABS DE AJUSTES -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200 dark:border-slate-800 pb-4">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 dark:text-white flex items-center gap-2">
                    <span>💳 Suscripción y Facturación</span>
                    <span class="px-2 py-0.5 text-[10px] font-black uppercase rounded-full bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                        Bold Pasarela
                    </span>
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Gestiona el plan de tu taller, métodos de pago recurrentes con Bold y cuenta oficial de recaudo.
                </p>
            </div>

            <!-- NAVEGACIÓN ENTRE PESTAÑAS DE CONFIGURACIÓN -->
            <div class="flex items-center gap-1 p-1 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl self-start sm:self-auto overflow-x-auto shadow-sm">
                <Link
                    href="/settings?tab=profile"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5"
                >
                    <span>👤</span>
                    <span>Mi Perfil</span>
                </Link>

                <Link
                    href="/settings?tab=tenant"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5"
                >
                    <span>⚙️</span>
                    <span>Taller</span>
                </Link>

                <Link
                    href="/settings?tab=team"
                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5"
                >
                    <span>👥</span>
                    <span>Equipo</span>
                </Link>

                <div class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-[#4F46E5] text-white shadow-sm flex items-center gap-1.5">
                    <span>💳</span>
                    <span>Suscripción</span>
                </div>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- ALERTA CRÍTICA: PERIODO DE PRUEBA EXPIRADO                               -->
        <!-- ========================================================================= -->
        {#if isTrialExpired && !tenant.has_payment_method}
            <div class="p-5 rounded-2xl bg-gradient-to-r from-rose-950/80 via-rose-900/60 to-red-950/80 border-2 border-rose-500/80 shadow-xl shadow-rose-950/50 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 animate-pulse">
                <div class="flex items-start gap-3.5">
                    <div class="w-10 h-10 rounded-xl bg-rose-500/20 border border-rose-500/40 text-rose-300 flex items-center justify-center text-xl shrink-0">
                        ⚠️
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold text-white">
                            Tu periodo de prueba de 15 días ha finalizado
                        </h2>
                        <p class="text-xs sm:text-sm text-rose-200 mt-1 leading-relaxed">
                            Para continuar gestionando tu taller y acceder a tus pedidos, por favor agrega un método de pago válido.
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    onclick={openModal}
                    class="px-5 py-3 rounded-xl bg-white text-rose-900 font-extrabold text-xs sm:text-sm hover:bg-rose-50 shadow-lg transition-transform active:scale-95 shrink-0 whitespace-nowrap cursor-pointer"
                >
                    💳 Agregar Tarjeta Ahora
                </button>
            </div>
        {:else if !isTrialExpired && !tenant.has_payment_method}
            <!-- AVISO PREVENTIVO DURANTE LA PRUEBA -->
            <div class="p-4 rounded-2xl bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent border border-amber-500/30 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <span class="text-xl">⏳</span>
                    <div>
                        <h3 class="text-xs sm:text-sm font-bold text-amber-900 dark:text-amber-300">
                            Prueba Gratuita en curso: {tenant.trial_days_remaining} {tenant.trial_days_remaining === 1 ? 'día restante' : 'días restantes'}
                        </h3>
                        <p class="text-[11px] text-slate-600 dark:text-slate-400 mt-0.5">
                            Tu prueba vence el <strong>{tenant.trial_ends_at}</strong>. Agrega tu tarjeta para garantizar que tu producción no se detenga.
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    onclick={openModal}
                    class="hidden sm:inline-flex px-3.5 py-2 rounded-xl text-xs font-bold text-amber-950 dark:text-amber-100 bg-amber-400 dark:bg-amber-500/20 border border-amber-500/40 hover:bg-amber-300 transition-colors"
                >
                    + Registrar Tarjeta
                </button>
            </div>
        {/if}

        <!-- ========================================================================= -->
        <!-- SECCIÓN 1: PLAN ACTUAL DEL TALLER                                        -->
        <!-- ========================================================================= -->
        <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] shadow-sm space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                        Plan Activo para tu Taller
                    </span>
                    <h2 class="text-2xl font-black text-slate-900 dark:text-white mt-1">
                        {tenant.plan_name}
                    </h2>
                </div>

                <!-- BADGE DE ESTADO -->
                <div>
                    {#if tenant.subscription_status === 'active'}
                        <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-ping"></span>
                            Suscripción Activa
                        </span>
                    {:else if isTrialExpired && !tenant.has_payment_method}
                        <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/30">
                            🛑 Prueba Expirada
                        </span>
                    {:else}
                        <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-[#4F46E5]/15 text-[#4F46E5] dark:text-indigo-400 border border-[#4F46E5]/30">
                            ✨ Periodo de Prueba (15 Días)
                        </span>
                    {/if}
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 pt-2">
                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200/80 dark:border-slate-800/80">
                    <span class="text-[11px] text-slate-500 dark:text-slate-400 block">Tarifa Base Plan</span>
                    <span class="text-xl font-black text-slate-900 dark:text-white">
                        {formatCurrency(tenant.plan_price)}
                    </span>
                    <span class="text-xs text-slate-500 dark:text-slate-400 ml-1">/ mes</span>
                </div>

                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200/80 dark:border-slate-800/80">
                    <span class="text-[11px] text-slate-500 dark:text-slate-400 block">Cupos y Usuarios</span>
                    <span class="text-sm font-bold text-slate-800 dark:text-slate-200">
                        {tenant.active_users_count ?? 1} activos / {tenant.paid_seats ?? 1} pagados
                    </span>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400 block mt-0.5">
                        {tenant.available_seats ?? 0} {tenant.available_seats === 1 ? 'cupo libre' : 'cupos libres'} en este ciclo
                    </span>
                </div>

                <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200/80 dark:border-slate-800/80">
                    <span class="text-[11px] text-slate-500 dark:text-slate-400 block">Usuarios Extra ($15.000 c/u)</span>
                    <span class="text-sm font-bold text-slate-800 dark:text-slate-200">
                        {tenant.extra_seats_count ?? 0} extras ({formatCurrency(tenant.extra_seats_amount ?? 0)})
                    </span>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400 block mt-0.5">
                        Base incluye {tenant.included_base_seats ?? 1}
                    </span>
                </div>

                <div class="p-3.5 rounded-xl bg-gradient-to-br from-[#4F46E5]/10 to-transparent border border-[#4F46E5]/30">
                    <span class="text-[11px] text-[#4F46E5] dark:text-indigo-400 font-bold block">Próximo Cobro Total</span>
                    <span class="text-xl font-black text-[#4F46E5] dark:text-white">
                        {formatCurrency(tenant.total_monthly_amount ?? tenant.plan_price)}
                    </span>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400 block mt-0.5">
                        Facturación mensual recurrente
                    </span>
                </div>
            </div>

            <!-- BANNER RECOMENDACIÓN DE UPGRADE A PRO SI ESTÁ EN ESTÁNDAR -->
            {#if tenant.plan_type === 'standard'}
                <div class="p-4 rounded-2xl bg-gradient-to-r from-[#4F46E5]/15 via-[#4F46E5]/10 to-[#06B6D4]/10 border border-[#4F46E5]/40 flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-2">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl shrink-0">🚀</span>
                        <div>
                            <h4 class="text-sm font-black text-slate-900 dark:text-white">¿Tu equipo está creciendo? Pasa al Plan Taller Pro</h4>
                            <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5 leading-relaxed">
                                Obtén <strong>5 usuarios incluidos</strong> por <strong>$75.000 COP/mes</strong> en lugar de pagar $15.000 COP adicionales por cada uno.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onclick={upgradeToPro}
                        disabled={isUpgrading}
                        class="px-4 py-2.5 rounded-xl font-bold text-xs text-white bg-[#4F46E5] hover:bg-[#4338CA] shadow-md shadow-[#4F46E5]/25 transition-all whitespace-nowrap cursor-pointer disabled:opacity-50 shrink-0"
                    >
                        {#if isUpgrading}
                            <span>Actualizando...</span>
                        {:else}
                            <span>Actualizar a Plan Taller Pro ($75.000/mes)</span>
                        {/if}
                    </button>
                </div>
            {/if}
        </div>

        <!-- ========================================================================= -->
        <!-- SECCIÓN 2: SUSCRIPCIÓN Y MÉTODO DE PAGO (MÉTODO PRINCIPAL BOLD API)       -->
        <!-- ========================================================================= -->
        <div class="w-full max-w-2xl mx-auto">
            <div class="p-6 sm:p-8 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] shadow-sm space-y-6">
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Método Principal Automático
                        </span>
                        <span class="text-xs font-extrabold text-[#06B6D4] flex items-center gap-1">
                            <span>⚡</span> Bold Tokenized API
                        </span>
                    </div>

                    <h3 class="text-lg font-black text-slate-900 dark:text-white mt-1">
                        Tarjeta de Crédito o Débito Recurrente
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Cobro automático cada 30 días para que tu taller y producción nunca se detengan.
                    </p>

                    {#if tenant.has_payment_method}
                        <div class="mt-5 p-5 rounded-xl bg-gradient-to-br from-slate-900 to-[#121927] border border-slate-700 text-white relative overflow-hidden shadow-lg">
                            <div class="absolute -right-6 -bottom-6 w-32 h-32 bg-[#4F46E5]/20 rounded-full blur-xl pointer-events-none"></div>

                            <div class="flex items-center justify-between mb-6">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-300">
                                    {tenant.card_brand ?? 'Tarjeta'}
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                    ✓ Activa para Recurrencia
                                </span>
                            </div>

                            <div class="font-mono text-xl font-black tracking-widest text-slate-100 mb-3">
                                •••• •••• •••• {tenant.card_last_four ?? '••••'}
                            </div>

                            <div class="text-[11px] text-slate-400 flex items-center justify-between pt-2 border-t border-slate-800">
                                <span>Cobros Recurrentes Mensuales</span>
                                <span class="text-emerald-400 font-bold flex items-center gap-1">
                                    <span>🔒</span> Protegido por Bold
                                </span>
                            </div>
                        </div>
                    {:else}
                        <div class="mt-5 p-6 rounded-xl bg-slate-50 dark:bg-[#0B0F17] border border-dashed border-slate-300 dark:border-slate-800 text-center space-y-2.5">
                            <div class="text-3xl">💳</div>
                            <h4 class="text-sm font-bold text-slate-700 dark:text-slate-300">
                                Ninguna tarjeta registrada para cobro automático
                            </h4>
                            <p class="text-xs text-slate-500 dark:text-slate-400 max-w-sm mx-auto leading-relaxed">
                                Registra tu tarjeta Visa, Mastercard, Amex o Diners. No se te cobra nada hoy si estás en periodo de prueba.
                            </p>
                        </div>
                    {/if}
                </div>

                <div class="pt-2">
                    <button
                        type="button"
                        onclick={openModal}
                        class="w-full py-3.5 px-5 rounded-xl font-bold text-sm text-white bg-gradient-to-r from-[#4F46E5] to-[#4338CA] hover:from-[#4338CA] hover:to-[#3730A3] shadow-md shadow-[#4F46E5]/20 hover:shadow-[#4F46E5]/30 transition-all flex items-center justify-center gap-2 cursor-pointer"
                    >
                        <span>💳</span>
                        <span>{tenant.has_payment_method ? 'Actualizar Tarjeta / Método de Pago' : 'Registrar Tarjeta de Crédito o Débito'}</span>
                    </button>
                    <p class="text-[11px] text-center text-slate-500 dark:text-slate-400 mt-2.5">
                        Tokenización segura y encriptada mediante la pasarela en línea de Bold.
                    </p>
                </div>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECCIÓN 3: HISTORIAL DE FACTURACIÓN Y RECIBOS                            -->
        <!-- ========================================================================= -->
        <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] shadow-sm space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">
                        Historial de Facturación y Cobros Recurrentes
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Registro de cobros mensuales procesados a través de Bold.
                    </p>
                </div>
            </div>

            {#if billingHistory.length === 0}
                <div class="p-8 text-center border border-dashed border-slate-200 dark:border-slate-800 rounded-xl space-y-2">
                    <span class="text-3xl">🧾</span>
                    <h3 class="text-xs font-bold text-slate-700 dark:text-slate-300">
                        No hay cobros generados todavía
                    </h3>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                        Tus primeros 15 días son gratis. El primer cobro mensual de tu plan se registrará una vez concluido el periodo de prueba.
                    </p>
                </div>
            {:else}
                <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 dark:bg-[#0B0F17] text-slate-600 dark:text-slate-400 font-bold uppercase text-[10px] border-b border-slate-200 dark:border-slate-800">
                            <tr>
                                <th class="py-3 px-4">Referencia</th>
                                <th class="py-3 px-4">Fecha</th>
                                <th class="py-3 px-4">Concepto</th>
                                <th class="py-3 px-4">Método</th>
                                <th class="py-3 px-4">Monto</th>
                                <th class="py-3 px-4">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800 text-slate-800 dark:text-slate-200">
                            {#each billingHistory as item}
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                    <td class="py-3.5 px-4 font-mono font-bold text-[#4F46E5] dark:text-indigo-400">
                                        {item.id}
                                    </td>
                                    <td class="py-3.5 px-4 text-slate-500 dark:text-slate-400">
                                        {item.date}
                                    </td>
                                    <td class="py-3.5 px-4 font-medium text-slate-900 dark:text-white">
                                        {item.description}
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="inline-flex items-center gap-1 font-mono text-[11px]">
                                            <span>💳</span>
                                            <span>{item.card_brand ?? 'Tarjeta'} •••• {item.card_last_four ?? '••••'}</span>
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 font-bold text-slate-900 dark:text-white">
                                        {formatCurrency(item.amount)}
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                            {item.status}
                                        </span>
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            {/if}
        </div>

    </div>

    <!-- ========================================================================= -->
    <!-- MODAL DE TOKENIZACIÓN DE TARJETA CON BOLD                                -->
    <!-- ========================================================================= -->
    {#if showCardModal}
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm">
            <div class="w-full max-w-lg bg-[#0E1524] border border-slate-700/80 rounded-2xl shadow-2xl overflow-hidden text-slate-100 animate-in fade-in zoom-in-95 duration-150">
                
                <!-- Encabezado Modal -->
                <div class="p-5 border-b border-slate-800 flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-extrabold text-white flex items-center gap-2">
                            <span>💳 Actualizar Tarjeta / Método de Pago</span>
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">
                            Pasarela de pagos en línea Bold para suscripción recurrente.
                        </p>
                    </div>

                    <button
                        type="button"
                        onclick={closeModal}
                        class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center text-sm transition-colors cursor-pointer"
                    >
                        ✕
                    </button>
                </div>

                <!-- Formulario -->
                <form onsubmit={submitCard} class="p-5 space-y-4">
                    
                    <!-- Aviso de Cobro -->
                    <div class="p-3.5 rounded-xl bg-[#4F46E5]/15 border border-[#4F46E5]/30 flex items-start gap-2.5">
                        <span class="text-base shrink-0">🛡️</span>
                        <div class="text-xs text-slate-300 leading-relaxed">
                            <strong class="text-white">Garantía de Suscripción:</strong>
                            {#if !isTrialExpired}
                                No se realizará ningún cobro hoy mientras continúe tu periodo de 15 días gratis.
                            {:else}
                                Tu tarjeta será habilitada para el cobro mensual del plan {tenant.plan_name}.
                            {/if}
                        </div>
                    </div>

                    <!-- Marcas autorizadas recurrentes -->
                    <div class="flex items-center justify-between text-[11px] text-slate-400">
                        <span>Marcas habilitadas para pagos recurrentes:</span>
                        <div class="flex items-center gap-1 font-bold">
                            <span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-200">Visa</span>
                            <span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-200">Mastercard</span>
                            <span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-200">Amex</span>
                            <span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-200">Diners</span>
                        </div>
                    </div>

                    <!-- Número de Tarjeta y Brand -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="modal_card_number" class="text-xs font-bold text-slate-200">
                                Número de Tarjeta (Crédito o Débito) <span class="text-rose-500">*</span>
                            </label>
                            {#if detectedBrand}
                                <span class="text-[11px] font-extrabold text-[#06B6D4] flex items-center gap-1">
                                    <span>💳</span> {detectedBrand}
                                </span>
                            {/if}
                        </div>
                        <input
                            id="modal_card_number"
                            type="text"
                            inputmode="numeric"
                            maxlength="23"
                            value={form.card_number}
                            oninput={formatCardNumber}
                            placeholder="4000 1234 5678 9010"
                            required
                            class="w-full bg-[#0B0F17] border border-slate-700 rounded-xl px-4 py-3 text-sm text-white font-mono placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                        />
                        {#if errors.card_number}
                            <p class="text-[11px] text-rose-400 font-semibold mt-1.5 flex items-center gap-1">
                                <span>⚠️</span> {errors.card_number}
                            </p>
                        {/if}
                    </div>

                    <!-- Nombre del Titular -->
                    <div>
                        <label for="modal_cardholder_name" class="block text-xs font-bold text-slate-200 mb-1.5">
                            Nombre del Titular (Como figura en la tarjeta) <span class="text-rose-500">*</span>
                        </label>
                        <input
                            id="modal_cardholder_name"
                            type="text"
                            bind:value={form.cardholder_name}
                            placeholder="ej. CARLOS MENDOZA"
                            required
                            class="w-full bg-[#0B0F17] border border-slate-700 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                        />
                        {#if errors.cardholder_name}
                            <p class="text-[11px] text-rose-400 font-semibold mt-1.5 flex items-center gap-1">
                                <span>⚠️</span> {errors.cardholder_name}
                            </p>
                        {/if}
                    </div>

                    <!-- Expiración y CVV -->
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label for="modal_exp_month" class="block text-xs font-bold text-slate-200 mb-1.5">
                                Mes (MM) <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="modal_exp_month"
                                type="text"
                                inputmode="numeric"
                                maxlength="2"
                                bind:value={form.expiration_month}
                                placeholder="12"
                                required
                                class="w-full bg-[#0B0F17] border border-slate-700 rounded-xl px-3 py-3 text-sm text-center text-white font-mono placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                            />
                            {#if errors.expiration_month}
                                <p class="text-[10px] text-rose-400 font-semibold mt-1">
                                    {errors.expiration_month}
                                </p>
                            {/if}
                        </div>

                        <div>
                            <label for="modal_exp_year" class="block text-xs font-bold text-slate-200 mb-1.5">
                                Año (AAAA) <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="modal_exp_year"
                                type="text"
                                inputmode="numeric"
                                maxlength="4"
                                bind:value={form.expiration_year}
                                placeholder="2028"
                                required
                                class="w-full bg-[#0B0F17] border border-slate-700 rounded-xl px-3 py-3 text-sm text-center text-white font-mono placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                            />
                            {#if errors.expiration_year}
                                <p class="text-[10px] text-rose-400 font-semibold mt-1">
                                    {errors.expiration_year}
                                </p>
                            {/if}
                        </div>

                        <div>
                            <label for="modal_cvv" class="block text-xs font-bold text-slate-200 mb-1.5">
                                CVV <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="modal_cvv"
                                type="password"
                                inputmode="numeric"
                                maxlength="4"
                                bind:value={form.cvv}
                                placeholder="•••"
                                required
                                class="w-full bg-[#0B0F17] border border-slate-700 rounded-xl px-3 py-3 text-sm text-center text-white font-mono placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                            />
                            {#if errors.cvv}
                                <p class="text-[10px] text-rose-400 font-semibold mt-1">
                                    {errors.cvv}
                                </p>
                            {/if}
                        </div>
                    </div>

                    <!-- Botones de Acción -->
                    <div class="pt-3 flex items-center justify-end gap-3 border-t border-slate-800">
                        <button
                            type="button"
                            onclick={closeModal}
                            disabled={isSubmitting}
                            class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-300 hover:text-white hover:bg-slate-800 transition-colors cursor-pointer"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            disabled={isSubmitting}
                            class="px-5 py-2.5 rounded-xl font-bold text-xs text-white bg-gradient-to-r from-[#4F46E5] to-[#06B6D4] hover:from-[#4338CA] hover:to-[#0891B2] shadow-lg shadow-[#4F46E5]/25 transition-all active:scale-95 disabled:opacity-50 cursor-pointer flex items-center gap-2"
                        >
                            {#if isSubmitting}
                                <span class="animate-spin">⏳</span>
                                <span>Tokenizando con Bold...</span>
                            {:else}
                                <span>Guardar Tarjeta y Habilitar Taller</span>
                                <span>→</span>
                            {/if}
                        </button>
                    </div>

                </form>

            </div>
        </div>
    {/if}

</AppLayout>
