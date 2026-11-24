<script>
    import { Link, router } from '@inertiajs/svelte';
    import { csrfFetch } from '../Utils/csrfFetch.js';

    let {
        open = false,
        addonType = 'worker',
        planQuotas = null,
        onClose = () => {},
        onPurchased = () => {},
    } = $props();

    let purchasing = $state(false);
    let errorMessage = $state('');

    const FALLBACK_ADDONS = {
        ceo: {
            key: 'ceo',
            name: 'Usuario CEO - Administrador',
            monthly_price: 20000,
            annual_price: 192000,
            features: [
                'Acceso a la gestión de todos los módulos de la aplicación',
                'Visibilidad de valores de los pedidos y productos',
            ],
        },
        worker: {
            key: 'worker',
            name: 'Usuario Operario',
            monthly_price: 15000,
            annual_price: 144000,
            features: [
                'Acceso restringido a la visibilidad de valores y Dashboard',
                'No podrá gestionar los módulos de la aplicación',
                'Acceso a los tableros de Kanban',
                'Solicitud de materias primas',
            ],
        },
        workflow: {
            key: 'workflow',
            name: 'Flujo de Trabajo',
            monthly_price: 15000,
            annual_price: 144000,
            features: [
                'Flujo de trabajo para la gestión operativa de un nuevo producto o servicio',
            ],
        },
    };

    let normalizedType = $derived(
        addonType === 'ceo' || addonType === 'worker' || addonType === 'workflow'
            ? addonType
            : 'worker'
    );

    let addonInfo = $derived(
        planQuotas?.addons?.[normalizedType] ?? FALLBACK_ADDONS[normalizedType]
    );

    let quotaInfo = $derived(
        planQuotas?.quotas?.[normalizedType] ?? { used: 0, base: 0, extra: 0, max: 0, can_add: false }
    );

    let planName = $derived(planQuotas?.plan_name ?? 'Plan Básico');
    let planType = $derived(planQuotas?.plan_type ?? 'basic');
    let billingCycle = $derived(planQuotas?.billing_cycle === 'annual' ? 'annual' : 'monthly');
    let isAnnual = $derived(billingCycle === 'annual');
    let hasPaymentMethod = $derived(Boolean(planQuotas?.has_payment_method));
    let isTrialing = $derived(planQuotas?.subscription_status === 'trialing');

    let activePrice = $derived(
        isAnnual ? Number(addonInfo.annual_price ?? 0) : Number(addonInfo.monthly_price ?? 0)
    );

    let dynamicTitle = $derived.by(() => {
        if (normalizedType === 'ceo') return 'Necesitas un Usuario CEO adicional';
        if (normalizedType === 'workflow') return 'Necesitas un Flujo de Trabajo adicional';
        return 'Necesitas un Usuario Operario adicional';
    });

    let showProUpgradeHint = $derived(
        planType === 'basic' && (normalizedType === 'worker' || normalizedType === 'workflow')
    );

    function formatCOP(value) {
        return '$' + new Intl.NumberFormat('es-CO', {
            maximumFractionDigits: 0,
        }).format(Number(value ?? 0));
    }

    function handleClose() {
        if (purchasing) return;
        errorMessage = '';
        onClose();
    }

    async function handlePurchase() {
        if (purchasing) return;
        purchasing = true;
        errorMessage = '';

        try {
            const res = await csrfFetch('/billing/addons/purchase', {
                method: 'POST',
                body: JSON.stringify({
                    addon_type: normalizedType,
                    quantity: 1,
                    billing_cycle: billingCycle,
                }),
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                if (data.requires_payment_method && data.redirect_url) {
                    errorMessage = data.message || 'Debes registrar una tarjeta en Facturación primero.';
                    return;
                }
                errorMessage = data.message || 'No fue posible procesar la compra del adicional. Intenta nuevamente.';
                return;
            }

            if (typeof onPurchased === 'function') {
                onPurchased(data);
            }

            router.reload({
                only: ['planQuotas', 'auth'],
                preserveScroll: true,
                preserveState: true,
            });

            onClose();
        } catch (err) {
            errorMessage = err?.message || 'Error de conexión al procesar la compra.';
        } finally {
            purchasing = false;
        }
    }
</script>

{#if open}
    <div
        class="fixed inset-0 z-[70] bg-slate-950/75 backdrop-blur-sm flex items-center justify-center p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="addon-modal-title"
    >
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-3xl max-w-lg w-full p-6 shadow-2xl space-y-5 max-h-[92vh] overflow-y-auto">
            <!-- Cabecera de Aviso -->
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 dark:border-[#26334D] pb-4">
                <div class="space-y-1.5">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-amber-500/15 text-amber-700 dark:text-amber-300 border border-amber-500/30">
                        🔒 LÍMITE DE PLAN ALCANZADO
                    </span>
                    <h3 id="addon-modal-title" class="text-lg font-extrabold text-slate-900 dark:text-white leading-tight">
                        {dynamicTitle}
                    </h3>
                    <p class="text-xs text-slate-600 dark:text-slate-400">
                        Uso actual en tu <strong class="text-slate-900 dark:text-white">{planName}</strong>:
                        <span class="font-mono font-bold text-[#4F46E5] dark:text-indigo-400">{quotaInfo.used} de {quotaInfo.max}</span> incluidos
                    </p>
                </div>
                <button
                    type="button"
                    onclick={handleClose}
                    disabled={purchasing}
                    aria-label="Cerrar ventana"
                    class="text-slate-400 hover:text-slate-700 dark:hover:text-white p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                >
                    ✕
                </button>
            </div>

            <!-- Banner de Error si ocurre -->
            {#if errorMessage}
                <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 text-xs font-medium">
                    {errorMessage}
                </div>
            {/if}

            <!-- Tarjeta Destacada del Adicional -->
            <div class="rounded-2xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] p-4 space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-[#4F46E5] dark:text-[#06B6D4] block">
                            Adicional Oficial gipPyme
                        </span>
                        <h4 class="text-sm font-extrabold text-slate-900 dark:text-white mt-0.5">
                            {addonInfo.name}
                        </h4>
                    </div>
                    <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold uppercase tracking-wider bg-indigo-500/10 text-[#4F46E5] dark:text-indigo-300 border border-[#4F46E5]/25">
                        Ciclo {isAnnual ? 'Anual' : 'Mensual'}
                    </span>
                </div>

                <!-- Tarifas Mensual y Anual (20% OFF) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <div class="p-3 rounded-xl border transition-all {!isAnnual
                        ? 'bg-[#4F46E5]/10 border-[#4F46E5] ring-1 ring-[#4F46E5]/30'
                        : 'bg-white dark:bg-[#151D2C] border-slate-200 dark:border-[#26334D] opacity-80'}">
                        <div class="flex items-center justify-between gap-1">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Valor Mensual
                            </span>
                            {#if !isAnnual}
                                <span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded-full bg-[#4F46E5] text-white">
                                    Tu ciclo
                                </span>
                            {/if}
                        </div>
                        <div class="mt-1 text-base font-black text-slate-900 dark:text-white font-mono">
                            {formatCOP(addonInfo.monthly_price)}
                            <span class="text-[11px] font-sans font-semibold text-slate-500 dark:text-slate-400">COP / mes</span>
                        </div>
                    </div>

                    <div class="p-3 rounded-xl border transition-all {isAnnual
                        ? 'bg-[#4F46E5]/10 border-[#4F46E5] ring-1 ring-[#4F46E5]/30'
                        : 'bg-white dark:bg-[#151D2C] border-slate-200 dark:border-[#26334D] opacity-80'}">
                        <div class="flex items-center justify-between gap-1">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Valor Anual
                            </span>
                            <span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded-full {isAnnual ? 'bg-[#4F46E5] text-white' : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30'}">
                                20% OFF
                            </span>
                        </div>
                        <div class="mt-1 text-base font-black text-slate-900 dark:text-white font-mono">
                            {formatCOP(addonInfo.annual_price)}
                            <span class="text-[11px] font-sans font-semibold text-slate-500 dark:text-slate-400">COP / año</span>
                        </div>
                    </div>
                </div>

                <!-- Lista de Características Exactas -->
                <div class="space-y-1.5 pt-1 border-t border-slate-200 dark:border-[#26334D]">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                        Qué incluye este adicional
                    </span>
                    <ul class="space-y-1.5">
                        {#each (addonInfo.features || []) as feature}
                            <li class="flex items-start gap-2 text-xs text-slate-700 dark:text-slate-300">
                                <span class="text-emerald-600 dark:text-emerald-400 font-black mt-0.5 shrink-0">✓</span>
                                <span>{feature}</span>
                            </li>
                        {/each}
                    </ul>
                </div>
            </div>

            <!-- Sugerencia Inteligente de Upgrade (solo Plan Básico + Operario o Flujo) -->
            {#if showProUpgradeHint}
                <div class="p-4 rounded-2xl bg-[#4F46E5]/10 dark:bg-[#4F46E5]/15 border border-[#4F46E5]/40 space-y-2.5">
                    <p class="text-xs text-slate-800 dark:text-indigo-100 leading-relaxed font-medium">
                        💡 ¿Sabías que el <strong>Plan Profesional ($65.000/mes)</strong> ya incluye <strong>3 Operarios y 3 Flujos de Trabajo</strong>? Puede salirte más económico.
                    </p>
                    <Link
                        href="/settings/billing"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-[#151D2C] hover:bg-slate-50 dark:hover:bg-slate-800 text-[#4F46E5] dark:text-indigo-300 border border-[#4F46E5]/30 text-xs font-bold transition-colors"
                    >
                        <span>Ver Plan Profesional</span>
                        <span>→</span>
                    </Link>
                </div>
            {/if}

            <!-- Aviso y CTA Principal -->
            {#if hasPaymentMethod}
                <div class="space-y-3 pt-1">
                    {#if isTrialing}
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl p-2.5">
                            ✨ Estás en periodo de prueba con tarjeta registrada ({planQuotas?.card_brand ?? 'Tarjeta'} •••• {planQuotas?.card_last_four ?? '****'}). El cupo se activa de inmediato y su valor se sumará a tu renovación al terminar los 15 días.
                        </p>
                    {:else}
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl p-2.5">
                            💳 Se procesará el cobro inmediato en tu tarjeta {planQuotas?.card_brand ?? ''} terminada en <strong>{planQuotas?.card_last_four ?? '****'}</strong> y tu cupo quedará habilitado al instante.
                        </p>
                    {/if}

                    <div class="flex flex-col-reverse sm:flex-row sm:items-center justify-end gap-2">
                        <button
                            type="button"
                            onclick={handleClose}
                            disabled={purchasing}
                            class="px-4 py-2.5 rounded-xl text-xs font-semibold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] transition-colors cursor-pointer"
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            onclick={handlePurchase}
                            disabled={purchasing}
                            class="px-5 py-2.5 rounded-xl text-xs font-extrabold bg-[#4F46E5] hover:bg-[#4338CA] text-white shadow-md shadow-[#4F46E5]/25 transition-all disabled:opacity-50 cursor-pointer flex items-center justify-center gap-1.5"
                        >
                            {#if purchasing}
                                <span>Procesando compra...</span>
                            {:else}
                                <span>💳 Comprar Adicional Ahora ({formatCOP(activePrice)} COP)</span>
                            {/if}
                        </button>
                    </div>
                </div>
            {:else}
                <div class="space-y-3 pt-1">
                    <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-800 dark:text-amber-200 text-xs font-semibold">
                        Registra tu tarjeta en Facturación para habilitar la compra de adicionales.
                    </div>
                    <div class="flex flex-col-reverse sm:flex-row sm:items-center justify-end gap-2">
                        <button
                            type="button"
                            onclick={handleClose}
                            class="px-4 py-2.5 rounded-xl text-xs font-semibold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] transition-colors cursor-pointer"
                        >
                            Cancelar
                        </button>
                        <Link
                            href={`/settings/billing?buy_addon=${normalizedType}`}
                            class="px-5 py-2.5 rounded-xl text-xs font-extrabold bg-[#4F46E5] hover:bg-[#4338CA] text-white shadow-md shadow-[#4F46E5]/25 transition-all flex items-center justify-center gap-1.5"
                        >
                            <span>💳 Ir a Registrar Tarjeta y Comprar</span>
                        </Link>
                    </div>
                </div>
            {/if}
        </div>
    </div>
{/if}
