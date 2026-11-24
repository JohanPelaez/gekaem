<script>
    import { untrack } from 'svelte';
    import { router, Link, page } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import AddonPurchaseModal from '../../Components/AddonPurchaseModal.svelte';
    import { csrfFetch } from '../../Utils/csrfFetch.js';

    let {
        tenant = {},
        isTrialExpired = false,
        billingHistory = [],
        plansCatalog = null,
        buyAddon = null,
        errors = {},
    } = $props();

    // Modal para registrar o actualizar tarjeta de crédito/débito
    let showCardModal = $state(false);
    let isSubmitting = $state(false);

    // Estado del selector de Plan y Ciclo
    let selectedPlan = $state('basic');
    let selectedCycle = $state('monthly');
    let changingPlan = $state(false);
    let planChangeError = $state('');
    let planChangeSuccess = $state('');

    // Sincronizar selección inicial con los datos actuales del tenant
    let lastSyncedPlanKey = null;
    $effect(() => {
        const key = `${tenant?.plan_type ?? 'basic'}:${tenant?.billing_cycle ?? 'monthly'}`;
        if (key !== lastSyncedPlanKey) {
            lastSyncedPlanKey = key;
            untrack(() => {
                selectedPlan = tenant?.plan_type === 'pro' ? 'pro' : 'basic';
                selectedCycle = tenant?.billing_cycle === 'annual' ? 'annual' : 'monthly';
            });
        }
    });

    // Modal de Compra de Adicionales
    let addonModalOpen = $state(false);
    let addonModalType = $state('worker');
    let addonSuccessToast = $state('');

    // Banner contextual si llega con ?buy_addon=ceo|worker|workflow
    let requestedAddon = $derived.by(() => {
        if (buyAddon === 'ceo' || buyAddon === 'worker' || buyAddon === 'workflow') {
            return buyAddon;
        }
        if (typeof window !== 'undefined') {
            const p = new URLSearchParams(window.location.search).get('buy_addon');
            if (p === 'ceo' || p === 'worker' || p === 'workflow') return p;
        }
        return null;
    });

    let activePlanQuotas = $derived(page.props.planQuotas ?? null);

    let ceoQuota = $derived(
        tenant?.quotas?.ceo ?? activePlanQuotas?.quotas?.ceo ?? { used: 0, base: 1, extra: 0, max: 1, can_add: false }
    );
    let workerQuota = $derived(
        tenant?.quotas?.worker ?? activePlanQuotas?.quotas?.worker ?? { used: 0, base: 0, extra: 0, max: 0, can_add: false }
    );
    let workflowQuota = $derived(
        tenant?.quotas?.workflow ?? activePlanQuotas?.quotas?.workflow ?? { used: 0, base: 2, extra: 0, max: 2, can_add: false }
    );

    const FALLBACK_CATALOG = {
        plans: {
            basic: {
                key: 'basic',
                name: 'Plan Básico',
                monthly_price: 30000,
                annual_price: 288000,
                limits: { ceo: 1, worker: 0, workflows: 2 },
            },
            pro: {
                key: 'pro',
                name: 'Plan Profesional',
                monthly_price: 65000,
                annual_price: 624000,
                limits: { ceo: 1, worker: 3, workflows: 3 },
            },
        },
        addons: {
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
        },
    };

    let catalog = $derived(plansCatalog ?? FALLBACK_CATALOG);
    let basicPlanCfg = $derived(catalog.plans?.basic ?? FALLBACK_CATALOG.plans.basic);
    let proPlanCfg = $derived(catalog.plans?.pro ?? FALLBACK_CATALOG.plans.pro);

    let isTenantAnnual = $derived((tenant?.billing_cycle ?? 'monthly') === 'annual');
    let isSelectedAnnual = $derived(selectedCycle === 'annual');

    let addonCards = $derived([
        {
            ...(catalog.addons?.ceo ?? FALLBACK_CATALOG.addons.ceo),
            icon: '👑',
            extraActive: Number(tenant?.extra_ceo_seats ?? 0),
        },
        {
            ...(catalog.addons?.worker ?? FALLBACK_CATALOG.addons.worker),
            icon: '👷',
            extraActive: Number(tenant?.extra_worker_seats ?? 0),
        },
        {
            ...(catalog.addons?.workflow ?? FALLBACK_CATALOG.addons.workflow),
            icon: '🔀',
            extraActive: Number(tenant?.extra_workflows ?? 0),
        },
    ]);

    let requestedAddonName = $derived.by(() => {
        if (requestedAddon === 'ceo') return 'Usuario CEO - Administrador';
        if (requestedAddon === 'worker') return 'Usuario Operario';
        if (requestedAddon === 'workflow') return 'Flujo de Trabajo';
        return '';
    });

    // Validación reactiva de downgrade seguro (Profesional -> Básico)
    let downgradeIssues = $derived.by(() => {
        if (selectedPlan !== 'basic' || tenant?.plan_type !== 'pro') {
            return [];
        }

        const issues = [];
        const extraCeo = Number(tenant?.extra_ceo_seats ?? 0);
        const extraWorker = Number(tenant?.extra_worker_seats ?? 0);
        const extraWorkflow = Number(tenant?.extra_workflows ?? 0);

        const maxCeoInBasic = 1 + extraCeo;
        const maxWorkerInBasic = 0 + extraWorker;
        const maxWorkflowInBasic = 2 + extraWorkflow;

        if (workerQuota.used > maxWorkerInBasic) {
            const excess = workerQuota.used - maxWorkerInBasic;
            issues.push(
                `Tienes ${workerQuota.used} Operario(s) activo(s) y en Plan Básico tendrías cupo para ${maxWorkerInBasic} (0 base + ${extraWorker} adicionales). Debes reducir ${excess} operario(s) o adquirir ${excess} adicional(es).`
            );
        }

        if (workflowQuota.used > maxWorkflowInBasic) {
            const excess = workflowQuota.used - maxWorkflowInBasic;
            issues.push(
                `Tienes ${workflowQuota.used} Flujo(s) activo(s) y en Plan Básico tendrías cupo para ${maxWorkflowInBasic} (2 base + ${extraWorkflow} adicionales). Debes reducir ${excess} flujo(s) o adquirir ${excess} adicional(es).`
            );
        }

        if (ceoQuota.used > maxCeoInBasic) {
            const excess = ceoQuota.used - maxCeoInBasic;
            issues.push(
                `Tienes ${ceoQuota.used} CEO(s) activo(s) y el límite sería ${maxCeoInBasic}. Debes reducir ${excess} usuario(s) CEO.`
            );
        }

        return issues;
    });

    let hasActiveExtras = $derived(
        Number(tenant?.extra_ceo_seats ?? 0) > 0 ||
        Number(tenant?.extra_worker_seats ?? 0) > 0 ||
        Number(tenant?.extra_workflows ?? 0) > 0
    );

    let hasPlanOrCycleChanged = $derived(
        selectedPlan !== (tenant?.plan_type === 'pro' ? 'pro' : 'basic') ||
        selectedCycle !== (tenant?.billing_cycle === 'annual' ? 'annual' : 'monthly')
    );

    let previewTotalForSelection = $derived.by(() => {
        const priceField = isSelectedAnnual ? 'annual_price' : 'monthly_price';
        const basePrice = selectedPlan === 'pro'
            ? Number(proPlanCfg[priceField] ?? 65000)
            : Number(basicPlanCfg[priceField] ?? 30000);
        const ceoPrice = Number(catalog.addons?.ceo?.[priceField] ?? 20000);
        const workerPrice = Number(catalog.addons?.worker?.[priceField] ?? 15000);
        const workflowPrice = Number(catalog.addons?.workflow?.[priceField] ?? 15000);

        return (
            basePrice +
            Number(tenant?.extra_ceo_seats ?? 0) * ceoPrice +
            Number(tenant?.extra_worker_seats ?? 0) * workerPrice +
            Number(tenant?.extra_workflows ?? 0) * workflowPrice
        );
    });

    function openAddonModal(type) {
        addonModalType = type;
        addonModalOpen = true;
    }

    function handleAddonPurchased(data) {
        addonSuccessToast = data?.message || '¡Adicional habilitado correctamente!';
        router.reload({ preserveScroll: true });
        setTimeout(() => {
            addonSuccessToast = '';
        }, 7000);
    }

    async function submitPlanChange() {
        if (!hasPlanOrCycleChanged || downgradeIssues.length > 0 || changingPlan) {
            return;
        }

        planChangeError = '';
        planChangeSuccess = '';
        changingPlan = true;

        try {
            const res = await csrfFetch('/settings/billing/change-plan', {
                method: 'POST',
                body: JSON.stringify({
                    plan_type: selectedPlan,
                    billing_cycle: selectedCycle,
                }),
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                if (data.errors && typeof data.errors === 'object') {
                    const firstErr = Object.values(data.errors)[0];
                    planChangeError = Array.isArray(firstErr) ? firstErr[0] : String(firstErr);
                } else {
                    planChangeError = data.message || 'No fue posible actualizar tu plan.';
                }
                return;
            }

            planChangeSuccess = data.message || '¡Suscripción actualizada con éxito!';
            router.reload({ preserveScroll: true });
            setTimeout(() => {
                planChangeSuccess = '';
            }, 6000);
        } catch (err) {
            planChangeError = err?.message || 'Error de conexión al cambiar de plan.';
        } finally {
            changingPlan = false;
        }
    }

    let form = $state({
        card_number: '',
        cardholder_name: '',
        expiration_month: '',
        expiration_year: '',
        cvv: '',
        buy_addon: '',
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
        form.buy_addon = requestedAddon ?? '';
        showCardModal = true;
    }

    function closeModal() {
        showCardModal = false;
    }

    function submitCard(e) {
        e.preventDefault();
        isSubmitting = true;
        form.buy_addon = requestedAddon ?? '';

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
        }).format(Number(amount ?? 0));
    }

    function quotaPercent(used, max) {
        if (max <= 0) return used > 0 ? 100 : 0;
        return Math.min(100, Math.round((used / max) * 100));
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
                    Gestiona tu plan, ciclo de facturación (Mensual o Anual 20% OFF), cupos del taller y adicionales.
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
        <!-- BANNER CONTEXTUAL SI LLEGA CON ?buy_addon=ceo|worker|workflow             -->
        <!-- ========================================================================= -->
        {#if requestedAddon}
            {#if !tenant.has_payment_method}
                <div class="p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-[#4F46E5]/15 via-[#4F46E5]/10 to-[#06B6D4]/10 border-2 border-[#4F46E5]/50 shadow-md flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl shrink-0">🛒</span>
                        <div>
                            <h2 class="text-sm font-extrabold text-slate-900 dark:text-white">
                                Registra tu tarjeta para completar la compra de "{requestedAddonName}"
                            </h2>
                            <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5 leading-relaxed">
                                Apenas registres tu tarjeta de crédito o débito en Bold podrás adquirir el adicional de <strong>{requestedAddonName}</strong> al instante.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onclick={openModal}
                        class="px-4 py-2.5 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-extrabold text-xs shadow-md transition-all whitespace-nowrap cursor-pointer shrink-0"
                    >
                        💳 Registrar Tarjeta Ahora
                    </button>
                </div>
            {:else}
                <div class="p-4 sm:p-5 rounded-2xl bg-emerald-500/10 border-2 border-emerald-500/40 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl shrink-0">✅</span>
                        <div>
                            <h2 class="text-sm font-extrabold text-emerald-900 dark:text-emerald-200">
                                ¡Tarjeta lista! Ya puedes completar la compra de "{requestedAddonName}"
                            </h2>
                            <p class="text-xs text-emerald-800/80 dark:text-emerald-300/80 mt-0.5">
                                Haz clic en el botón para activar tu cupo adicional de inmediato.
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onclick={() => openAddonModal(requestedAddon)}
                        class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs shadow-md transition-all whitespace-nowrap cursor-pointer shrink-0"
                    >
                        🛒 Comprar {requestedAddonName}
                    </button>
                </div>
            {/if}
        {/if}

        <!-- TOASTS DE ÉXITO -->
        {#if addonSuccessToast}
            <div class="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 text-xs font-semibold">
                ✓ {addonSuccessToast}
            </div>
        {/if}

        {#if planChangeSuccess}
            <div class="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 text-xs font-semibold">
                ✓ {planChangeSuccess}
            </div>
        {/if}

        <!-- ========================================================================= -->
        <!-- ALERTA CRÍTICA: PERIODO DE PRUEBA EXPIRADO                               -->
        <!-- ========================================================================= -->
        {#if isTrialExpired && !tenant.has_payment_method}
            <div class="p-5 rounded-2xl bg-gradient-to-r from-rose-950/80 via-rose-900/60 to-red-950/80 border-2 border-rose-500/80 shadow-xl shadow-rose-950/50 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
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
                    class="hidden sm:inline-flex px-3.5 py-2 rounded-xl text-xs font-bold text-amber-950 dark:text-amber-100 bg-amber-400 dark:bg-amber-500/20 border border-amber-500/40 hover:bg-amber-300 transition-colors cursor-pointer"
                >
                    + Registrar Tarjeta
                </button>
            </div>
        {/if}

        <!-- ========================================================================= -->
        <!-- SECCIÓN 1: PLAN ACTUAL, DESGLOSE DE COBRO Y BARRAS DE USO DE CUPOS        -->
        <!-- ========================================================================= -->
        <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] shadow-sm space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                        Plan Activo para tu Taller
                    </span>
                    <div class="flex flex-wrap items-center gap-2.5 mt-1">
                        <h2 class="text-2xl font-black text-slate-900 dark:text-white">
                            {tenant.plan_name ?? 'Plan Básico'}
                        </h2>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-extrabold {isTenantAnnual ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700'}">
                            {isTenantAnnual ? 'Ciclo Anual · 20% OFF' : 'Ciclo Mensual'}
                        </span>
                    </div>
                </div>

                <!-- BADGE DE ESTADO -->
                <div>
                    {#if tenant.subscription_status === 'active'}
                        <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30 inline-flex items-center gap-1.5">
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

            <!-- DESGLOSE DEL PRÓXIMO COBRO -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
                <div class="lg:col-span-8 p-4 rounded-2xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200/80 dark:border-[#26334D] space-y-2.5">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 block">
                        Desglose del Próximo Cobro ({isTenantAnnual ? 'Anual · 20% OFF' : 'Mensual'})
                    </span>

                    <div class="divide-y divide-slate-200/70 dark:divide-slate-800 text-xs">
                        <div class="py-2 flex items-center justify-between gap-2">
                            <span class="font-semibold text-slate-800 dark:text-slate-200">
                                Plan Base: {tenant.plan_name ?? 'Plan Básico'}
                            </span>
                            <span class="font-mono font-bold text-slate-900 dark:text-white">
                                {formatCurrency(tenant.plan_price)}
                            </span>
                        </div>

                        {#if Number(tenant.extra_ceo_seats ?? 0) > 0}
                            <div class="py-2 flex items-center justify-between gap-2">
                                <span class="text-slate-600 dark:text-slate-300">
                                    + {tenant.extra_ceo_seats} × Usuario CEO - Administrador ({formatCurrency(tenant.extra_ceo_unit_price)} c/u)
                                </span>
                                <span class="font-mono font-bold text-slate-900 dark:text-white">
                                    {formatCurrency(tenant.extra_ceo_amount)}
                                </span>
                            </div>
                        {/if}

                        {#if Number(tenant.extra_worker_seats ?? 0) > 0}
                            <div class="py-2 flex items-center justify-between gap-2">
                                <span class="text-slate-600 dark:text-slate-300">
                                    + {tenant.extra_worker_seats} × Usuario Operario ({formatCurrency(tenant.extra_worker_unit_price)} c/u)
                                </span>
                                <span class="font-mono font-bold text-slate-900 dark:text-white">
                                    {formatCurrency(tenant.extra_worker_amount)}
                                </span>
                            </div>
                        {/if}

                        {#if Number(tenant.extra_workflows ?? 0) > 0}
                            <div class="py-2 flex items-center justify-between gap-2">
                                <span class="text-slate-600 dark:text-slate-300">
                                    + {tenant.extra_workflows} × Flujo de Trabajo ({formatCurrency(tenant.extra_workflow_unit_price)} c/u)
                                </span>
                                <span class="font-mono font-bold text-slate-900 dark:text-white">
                                    {formatCurrency(tenant.extra_workflow_amount)}
                                </span>
                            </div>
                        {/if}

                        {#if !hasActiveExtras}
                            <div class="py-2 flex items-center justify-between gap-2 text-slate-500 dark:text-slate-400">
                                <span>Adicionales activos</span>
                                <span class="font-mono">$0</span>
                            </div>
                        {/if}
                    </div>
                </div>

                <div class="lg:col-span-4 p-5 rounded-2xl bg-gradient-to-br from-[#4F46E5]/15 via-[#4F46E5]/5 to-transparent border border-[#4F46E5]/30 flex flex-col justify-between">
                    <div>
                        <span class="text-[11px] text-[#4F46E5] dark:text-indigo-400 font-bold uppercase tracking-wider block">
                            Total Próximo Cobro
                        </span>
                        <div class="mt-1.5 flex items-baseline gap-1.5 flex-wrap">
                            <span class="text-2xl sm:text-3xl font-black text-[#4F46E5] dark:text-white font-mono">
                                {formatCurrency(tenant.subscription_total ?? tenant.plan_price)}
                            </span>
                            <span class="text-xs font-bold text-slate-500 dark:text-slate-400">
                                / {isTenantAnnual ? 'año' : 'mes'}
                            </span>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-3 pt-3 border-t border-[#4F46E5]/15">
                        {#if tenant.subscription_status === 'active' && tenant.current_period_ends_at}
                            Próxima renovación: <strong class="text-slate-800 dark:text-slate-200">{tenant.current_period_ends_at}</strong>
                        {:else if tenant.trial_ends_at}
                            Fin de prueba gratis: <strong class="text-slate-800 dark:text-slate-200">{tenant.trial_ends_at}</strong>
                        {:else}
                            Facturación recurrente automática con Bold
                        {/if}
                    </p>
                </div>
            </div>

            <!-- BARRAS DE USO DE CUPOS DEL TALLER -->
            <div class="space-y-3 pt-2 border-t border-slate-100 dark:border-[#26334D]">
                <h3 class="text-xs font-extrabold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    Uso de Cupos del Taller
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Barra 1: Usuarios CEO -->
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-200">
                                👑 Usuarios CEO - Admin
                            </span>
                            <span class="text-xs font-mono font-black text-[#4F46E5] dark:text-indigo-400">
                                {ceoQuota.used} / {ceoQuota.max}
                            </span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-300 {ceoQuota.can_add ? 'bg-[#4F46E5]' : 'bg-amber-500'}"
                                style="width: {quotaPercent(ceoQuota.used, ceoQuota.max)}%"
                            ></div>
                        </div>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            Base: {ceoQuota.base} · Adicionales: {ceoQuota.extra}
                        </p>
                    </div>

                    <!-- Barra 2: Usuarios Operario -->
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-200">
                                👷 Usuarios Operario
                            </span>
                            <span class="text-xs font-mono font-black text-[#0891B2] dark:text-[#06B6D4]">
                                {workerQuota.used} / {workerQuota.max}
                            </span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-300 {workerQuota.can_add ? 'bg-[#06B6D4]' : 'bg-amber-500'}"
                                style="width: {workerQuota.max === 0 ? 100 : quotaPercent(workerQuota.used, workerQuota.max)}%"
                            ></div>
                        </div>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            Base: {workerQuota.base} · Adicionales: {workerQuota.extra}
                        </p>
                    </div>

                    <!-- Barra 3: Flujos de Trabajo -->
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-slate-800 dark:text-slate-200">
                                🔀 Flujos de Trabajo
                            </span>
                            <span class="text-xs font-mono font-black text-emerald-600 dark:text-emerald-400">
                                {workflowQuota.used} / {workflowQuota.max}
                            </span>
                        </div>
                        <div class="w-full h-2 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-300 {workflowQuota.can_add ? 'bg-emerald-500' : 'bg-amber-500'}"
                                style="width: {quotaPercent(workflowQuota.used, workflowQuota.max)}%"
                            ></div>
                        </div>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            Base: {workflowQuota.base} · Adicionales: {workflowQuota.extra}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECCIÓN 2: CAMBIAR DE PLAN (BÁSICO <-> PROFESIONAL) Y CICLO (20% OFF)     -->
        <!-- ========================================================================= -->
        <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] shadow-sm space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-extrabold text-slate-900 dark:text-white">
                        🔄 Cambiar Plan o Ciclo de Facturación
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Cambia entre Plan Básico y Plan Profesional, o pásate al ciclo anual para ahorrar un 20% OFF.
                    </p>
                </div>

                <!-- Toggle Ciclo Mensual vs Anual -->
                <div class="inline-flex items-center p-1 rounded-xl bg-slate-100 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] self-start sm:self-auto">
                    <button
                        type="button"
                        onclick={() => { selectedCycle = 'monthly'; planChangeError = ''; }}
                        class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer {!isSelectedAnnual
                            ? 'bg-white dark:bg-[#4F46E5] text-slate-900 dark:text-white shadow-sm'
                            : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                    >
                        Mensual
                    </button>
                    <button
                        type="button"
                        onclick={() => { selectedCycle = 'annual'; planChangeError = ''; }}
                        class="px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all cursor-pointer flex items-center gap-1.5 {isSelectedAnnual
                            ? 'bg-white dark:bg-[#4F46E5] text-slate-900 dark:text-white shadow-sm'
                            : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                    >
                        <span>Anual</span>
                        <span class="px-1.5 py-0.5 rounded-full text-[9px] font-extrabold uppercase bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
                            20% OFF
                        </span>
                    </button>
                </div>
            </div>

            <!-- Tarjetas de Selección de Plan -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Plan Básico -->
                <button
                    type="button"
                    onclick={() => { selectedPlan = 'basic'; planChangeError = ''; }}
                    class="text-left p-5 rounded-2xl border-2 transition-all cursor-pointer {selectedPlan === 'basic'
                        ? 'border-[#4F46E5] bg-[#4F46E5]/5 dark:bg-[#4F46E5]/10 ring-2 ring-[#4F46E5]/20'
                        : 'border-slate-200 dark:border-[#26334D] bg-slate-50/50 dark:bg-[#0B0F17] hover:border-slate-300 dark:hover:border-slate-600'}"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-extrabold text-slate-900 dark:text-white">
                            {basicPlanCfg.name}
                        </span>
                        {#if tenant.plan_type === 'basic'}
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                Plan Actual
                            </span>
                        {/if}
                    </div>
                    <div class="mt-2 flex items-baseline gap-1.5">
                        <span class="text-2xl font-black text-slate-900 dark:text-white font-mono">
                            {formatCurrency(isSelectedAnnual ? basicPlanCfg.annual_price : basicPlanCfg.monthly_price)}
                        </span>
                        <span class="text-xs text-slate-500 dark:text-slate-400 font-semibold">
                            / {isSelectedAnnual ? 'año' : 'mes'}
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-2">
                        Incluye: <strong>1 CEO</strong> · <strong>0 Operarios</strong> · <strong>2 Flujos de trabajo</strong>.
                    </p>
                </button>

                <!-- Plan Profesional -->
                <button
                    type="button"
                    onclick={() => { selectedPlan = 'pro'; planChangeError = ''; }}
                    class="text-left p-5 rounded-2xl border-2 transition-all cursor-pointer relative {selectedPlan === 'pro'
                        ? 'border-[#4F46E5] bg-[#4F46E5]/5 dark:bg-[#4F46E5]/10 ring-2 ring-[#4F46E5]/20'
                        : 'border-slate-200 dark:border-[#26334D] bg-slate-50/50 dark:bg-[#0B0F17] hover:border-slate-300 dark:hover:border-slate-600'}"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-extrabold text-slate-900 dark:text-white flex items-center gap-1.5">
                            <span>{proPlanCfg.name}</span>
                            <span class="px-2 py-0.5 rounded-full text-[9px] font-extrabold bg-gradient-to-r from-[#4F46E5] to-[#06B6D4] text-white uppercase">
                                ⭐ Popular
                            </span>
                        </span>
                        {#if tenant.plan_type === 'pro'}
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                Plan Actual
                            </span>
                        {/if}
                    </div>
                    <div class="mt-2 flex items-baseline gap-1.5">
                        <span class="text-2xl font-black text-slate-900 dark:text-white font-mono">
                            {formatCurrency(isSelectedAnnual ? proPlanCfg.annual_price : proPlanCfg.monthly_price)}
                        </span>
                        <span class="text-xs text-slate-500 dark:text-slate-400 font-semibold">
                            / {isSelectedAnnual ? 'año' : 'mes'}
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-2">
                        Incluye: <strong>1 CEO</strong> · <strong>3 Operarios</strong> · <strong>3 Flujos de trabajo</strong>.
                    </p>
                </button>
            </div>

            <!-- Advertencia de Downgrade Seguro -->
            {#if downgradeIssues.length > 0}
                <div class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/40 text-amber-900 dark:text-amber-200 text-xs space-y-1.5">
                    <div class="font-extrabold flex items-center gap-1.5">
                        <span>⚠️</span>
                        <span>No es posible bajar a Plan Básico todavía:</span>
                    </div>
                    <ul class="list-disc pl-5 space-y-1">
                        {#each downgradeIssues as issue}
                            <li>{issue}</li>
                        {/each}
                    </ul>
                </div>
            {/if}

            {#if planChangeError}
                <div class="p-3.5 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 text-xs font-semibold">
                    ⚠️ {planChangeError}
                </div>
            {/if}

            <!-- Resumen y Botón de Confirmación -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2 border-t border-slate-100 dark:border-[#26334D]">
                <div class="text-xs text-slate-600 dark:text-slate-300">
                    Nuevo total estimado ({isSelectedAnnual ? 'Anual 20% OFF' : 'Mensual'}):
                    <strong class="text-slate-900 dark:text-white font-mono ml-1">
                        {formatCurrency(previewTotalForSelection)} / {isSelectedAnnual ? 'año' : 'mes'}
                    </strong>
                    {#if hasActiveExtras}
                        <span class="text-[11px] text-slate-500 dark:text-slate-400 block">
                            Conserva tus adicionales activos ({tenant.extra_ceo_seats} CEO, {tenant.extra_worker_seats} Operarios, {tenant.extra_workflows} Flujos).
                        </span>
                    {/if}
                </div>

                <button
                    type="button"
                    onclick={submitPlanChange}
                    disabled={!hasPlanOrCycleChanged || downgradeIssues.length > 0 || changingPlan}
                    class="px-5 py-2.5 rounded-xl text-xs font-extrabold text-white bg-[#4F46E5] hover:bg-[#4338CA] shadow-md shadow-[#4F46E5]/20 transition-all disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer whitespace-nowrap"
                >
                    {#if changingPlan}
                        <span>Guardando cambio...</span>
                    {:else if !hasPlanOrCycleChanged}
                        <span>Configuración Actual</span>
                    {:else}
                        <span>Guardar Cambio de Plan / Ciclo</span>
                    {/if}
                </button>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECCIÓN 3: 🛒 COMPRAR ADICIONALES                                         -->
        <!-- ========================================================================= -->
        <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] shadow-sm space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-extrabold text-slate-900 dark:text-white">
                        🛒 Comprar Adicionales
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Amplía la capacidad de tu taller al instante. Los precios se ajustan automáticamente a tu ciclo {isTenantAnnual ? 'anual (20% OFF)' : 'mensual'}.
                    </p>
                </div>
                <span class="px-2.5 py-1 rounded-lg text-[11px] font-bold bg-indigo-500/10 text-[#4F46E5] dark:text-indigo-300 border border-[#4F46E5]/20 self-start sm:self-auto">
                    Tarifas ciclo {isTenantAnnual ? 'Anual (20% OFF)' : 'Mensual'}
                </span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                {#each addonCards as addon}
                    <div class="p-5 rounded-2xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] flex flex-col justify-between gap-4">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xl">{addon.icon}</span>
                                {#if addon.extraActive > 0}
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-500/30">
                                        {addon.extraActive} activo{addon.extraActive === 1 ? '' : 's'}
                                    </span>
                                {/if}
                            </div>

                            <div>
                                <h3 class="text-sm font-extrabold text-slate-900 dark:text-white">
                                    {addon.name}
                                </h3>
                                <div class="mt-1.5 flex items-baseline gap-1">
                                    <span class="text-xl font-black text-slate-900 dark:text-white font-mono">
                                        {formatCurrency(isTenantAnnual ? addon.annual_price : addon.monthly_price)}
                                    </span>
                                    <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">
                                        / {isTenantAnnual ? 'año' : 'mes'}
                                    </span>
                                </div>
                            </div>

                            <ul class="space-y-1.5 text-[11px] text-slate-600 dark:text-slate-300">
                                {#each addon.features as feat}
                                    <li class="flex items-start gap-1.5">
                                        <span class="text-emerald-600 dark:text-emerald-400 font-bold shrink-0">✓</span>
                                        <span>{feat}</span>
                                    </li>
                                {/each}
                            </ul>
                        </div>

                        <button
                            type="button"
                            onclick={() => openAddonModal(addon.key)}
                            class="w-full py-2.5 px-3 rounded-xl text-xs font-extrabold bg-[#4F46E5]/10 hover:bg-[#4F46E5] text-[#4F46E5] hover:text-white border border-[#4F46E5]/30 transition-all cursor-pointer"
                        >
                            + Comprar {addon.key === 'ceo' ? 'Usuario CEO' : addon.key === 'worker' ? 'Operario' : 'Flujo'}
                        </button>
                    </div>
                {/each}
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- SECCIÓN 4: MÉTODO DE PAGO RECURRENTE (BOLD API)                           -->
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
                        Cobro automático {isTenantAnnual ? 'anual (con 20% OFF)' : 'cada 30 días'} para que tu taller y producción nunca se detengan.
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
                                <span>Cobros Recurrentes {isTenantAnnual ? 'Anuales' : 'Mensuales'}</span>
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
        <!-- SECCIÓN 5: HISTORIAL DE FACTURACIÓN Y RECIBOS                             -->
        <!-- ========================================================================= -->
        <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] shadow-sm space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">
                        Historial de Facturación y Cobros Recurrentes
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Registro de cobros procesados a través de Bold.
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
                        Tus primeros 15 días son gratis. El primer cobro de tu plan se registrará una vez concluido el periodo de prueba.
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
    <!-- MODAL DE COMPRA DE ADICIONALES                                            -->
    <!-- ========================================================================= -->
    <AddonPurchaseModal
        open={addonModalOpen}
        addonType={addonModalType}
        planQuotas={activePlanQuotas}
        onClose={() => (addonModalOpen = false)}
        onPurchased={handleAddonPurchased}
    />

    <!-- ========================================================================= -->
    <!-- MODAL DE TOKENIZACIÓN DE TARJETA CON BOLD                                 -->
    <!-- ========================================================================= -->
    {#if showCardModal}
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-sm">
            <div class="w-full max-w-lg bg-[#0E1524] border border-slate-700/80 rounded-2xl shadow-2xl overflow-hidden text-slate-100">

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
                                Tu tarjeta será habilitada para el cobro {isTenantAnnual ? 'anual (20% OFF)' : 'mensual'} del {tenant.plan_name ?? 'Plan Básico'}.
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
