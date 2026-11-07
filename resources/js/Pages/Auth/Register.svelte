<script>
    import { router, Link } from '@inertiajs/svelte';
    import BrandLogo from '../../Components/BrandLogo.svelte';

    let { initialPlan = 'standard', errors = {} } = $props();

    let selectedPlan = $state('standard');
    let processing = $state(false);
    let showCardAccordion = $state(false);

    let form = $state({
        owner_name: '',
        workshop_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        plan: 'standard',
        with_card: false,
        card_number: '',
        cardholder_name: '',
        expiration_month: '',
        expiration_year: '',
        cvv: '',
    });

    $effect(() => {
        if (typeof window !== 'undefined') {
            const savedTheme = localStorage.getItem('gip_theme') || localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        }
    });

    // Detectar si hay errores en datos de la tarjeta para mantener abierto el acordeón
    $effect(() => {
        if (errors.card_number || errors.expiration_month || errors.expiration_year || errors.cvv) {
            showCardAccordion = true;
            form.with_card = true;
        }
    });

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

    // Detectar prop initialPlan o parámetro ?plan en la URL
    $effect(() => {
        const planToUse = initialPlan === 'pro' ? 'pro' : 'standard';
        selectPlan(planToUse);

        if (typeof window !== 'undefined') {
            const params = new URLSearchParams(window.location.search);
            const p = params.get('plan');
            if (p === 'pro') {
                selectPlan('pro');
            } else if (p === 'standard') {
                selectPlan('standard');
            }
        }
    });

    function selectPlan(plan) {
        selectedPlan = plan;
        form.plan = plan;
    }

    function submit(e) {
        e.preventDefault();
        processing = true;
        router.post('/register', form, {
            preserveScroll: true,
            onStart: () => {
                processing = true;
            },
            onFinish: () => {
                processing = false;
            },
        });
    }
</script>

<svelte:head>
    <title>Crear mi Taller · 15 Días de Prueba Gratis · gipPyme</title>
</svelte:head>

<div class="min-h-screen bg-slate-900 text-slate-100 flex flex-col selection:bg-[#4F46E5] selection:text-white">
    <div class="grid grid-cols-1 lg:grid-cols-12 min-h-screen">
        
        <!-- ========================================================================= -->
        <!-- COLUMNA IZQUIERDA: INSPIRACIÓN, BENEFICIOS Y MARCA (SPLIT SCREEN)        -->
        <!-- ========================================================================= -->
        <aside class="lg:col-span-5 bg-[#0B0F17] p-8 sm:p-12 lg:p-14 flex flex-col justify-between relative overflow-hidden border-b lg:border-b-0 lg:border-r border-slate-800/80">
            <!-- Luces difusas atmosféricas de marca -->
            <div class="absolute -top-32 -left-32 w-80 h-80 bg-[#4F46E5]/20 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute bottom-10 right-0 w-80 h-80 bg-[#06B6D4]/15 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute inset-0 bg-[radial-gradient(#1E293B_1px,transparent_1px)] [background-size:24px_24px] opacity-25 pointer-events-none"></div>

            <div class="relative z-10 space-y-8">
                <!-- Logotipo gipPyme con enlace a Inicio -->
                <div class="flex items-center justify-between">
                    <Link
                        href="/"
                        class="focus:outline-none focus-visible:ring-2 focus-visible:ring-[#4F46E5] rounded-xl transition-transform active:scale-95 inline-block"
                        title="Ir a inicio gipPyme"
                    >
                        <BrandLogo layout="horizontal" size="md" showSubtitle={true} />
                    </Link>

                    <Link
                        href="/"
                        class="text-xs font-semibold text-slate-400 hover:text-white flex items-center gap-1.5 transition-colors"
                    >
                        <span>← Volver</span>
                    </Link>
                </div>

                <!-- Titular potente y propuesta de valor -->
                <div class="space-y-4 pt-4">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-bold bg-[#4F46E5]/20 text-[#818CF8] border border-[#4F46E5]/40 shadow-sm">
                        <span>✨</span>
                        <span>15 días de prueba total sin costo</span>
                    </div>

                    <h1 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight leading-tight">
                        Empieza tus 15 días de prueba gratis hoy mismo
                    </h1>

                    <p class="text-sm text-slate-300 leading-relaxed">
                        La plataforma Make-to-Order diseñada para talleres creativos y productivos que quieren entregar siempre a tiempo y sin caos.
                    </p>
                </div>

                <!-- Lista de beneficios destacados con checkmarks -->
                <div class="space-y-4 pt-2">
                    <div class="flex items-start gap-3.5 p-3.5 rounded-2xl bg-white/[0.03] border border-white/5 backdrop-blur-sm">
                        <div class="w-7 h-7 rounded-full bg-emerald-500/20 border border-emerald-500/40 text-emerald-400 flex items-center justify-center font-bold text-sm shrink-0">
                            ✓
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-white leading-snug">Sin tarjeta de crédito</h2>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Empieza sin ataduras ni compromisos bancarios. Pruébalo libremente en tu taller.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3.5 p-3.5 rounded-2xl bg-white/[0.03] border border-white/5 backdrop-blur-sm">
                        <div class="w-7 h-7 rounded-full bg-[#4F46E5]/20 border border-[#4F46E5]/40 text-[#818CF8] flex items-center justify-center font-bold text-sm shrink-0">
                            ✓
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-white leading-snug">Acceso instantáneo a los 5 módulos</h2>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Pedidos con abonos, Recetas BOM, Inventario de Materia Prima, Clientes y Proveedores.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3.5 p-3.5 rounded-2xl bg-white/[0.03] border border-white/5 backdrop-blur-sm">
                        <div class="w-7 h-7 rounded-full bg-[#06B6D4]/20 border border-[#06B6D4]/40 text-[#06B6D4] flex items-center justify-center font-bold text-sm shrink-0">
                            ✓
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-white leading-snug">Soporte dedicado</h2>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Acompañamiento personalizado por WhatsApp para ayudarte a configurar tu primer flujo Kanban.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Testimonio / Social Proof inferior -->
            <div class="relative z-10 pt-10 mt-8 border-t border-slate-800/80">
                <div class="p-4 rounded-2xl bg-gradient-to-br from-white/[0.05] to-transparent border border-white/10 space-y-2">
                    <div class="flex items-center gap-1 text-amber-400 text-xs">
                        ★★★★★
                    </div>
                    <p class="text-xs text-slate-300 italic leading-relaxed">
                        “Con gipPyme eliminamos los pedidos perdidos en libretas y ahora sabemos exactamente qué día entregar cada producto.”
                    </p>
                    <div class="text-[11px] font-semibold text-slate-400">
                        — Taller de Marroquinería & Confección Creativa
                    </div>
                </div>
            </div>
        </aside>

        <!-- ========================================================================= -->
        <!-- COLUMNA DERECHA: FORMULARIO DE ALTA Y CONFIGURACIÓN DEL TALLER            -->
        <!-- ========================================================================= -->
        <main class="lg:col-span-7 bg-slate-950 flex flex-col justify-center px-6 py-10 sm:px-12 sm:py-14 lg:px-16 xl:px-20 relative">
            <div class="max-w-xl w-full mx-auto space-y-8">
                
                <!-- Encabezado del Formulario -->
                <div class="space-y-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-[#06B6D4]">
                        Alta Inmediata de Taller
                    </span>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                        Crea la cuenta de tu taller
                    </h2>
                    <p class="text-xs sm:text-sm text-slate-400">
                        Configura los datos del propietario y taller para ingresar al instante a tu nuevo espacio de trabajo.
                    </p>
                </div>

                <!-- Formulario -->
                <form onsubmit={submit} class="space-y-5">
                    
                    <!-- 1. Nombre del Propietario / Dueño -->
                    <div>
                        <label for="owner_name" class="block text-xs font-bold text-slate-200 mb-1.5">
                            Nombre del Propietario / Dueño <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input
                                id="owner_name"
                                type="text"
                                bind:value={form.owner_name}
                                placeholder="ej. Carlos Rodríguez"
                                required
                                class="w-full bg-[#0B0F17] border border-slate-700/80 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                            />
                        </div>
                        {#if errors.owner_name}
                            <p class="text-[11px] text-rose-400 font-semibold mt-1.5 flex items-center gap-1">
                                <span>⚠️</span> {errors.owner_name}
                            </p>
                        {/if}
                    </div>

                    <!-- 2. Nombre del Taller o Negocio -->
                    <div>
                        <label for="workshop_name" class="block text-xs font-bold text-slate-200 mb-1.5">
                            Nombre del Taller o Negocio <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input
                                id="workshop_name"
                                type="text"
                                bind:value={form.workshop_name}
                                placeholder="ej. Taller Creativo Gekaem"
                                required
                                class="w-full bg-[#0B0F17] border border-slate-700/80 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                            />
                        </div>
                        <p class="text-[11px] text-slate-500 mt-1">
                            Este nombre aparecerá en tus remisiones, órdenes de producción y tableros Kanban.
                        </p>
                        {#if errors.workshop_name}
                            <p class="text-[11px] text-rose-400 font-semibold mt-1.5 flex items-center gap-1">
                                <span>⚠️</span> {errors.workshop_name}
                            </p>
                        {/if}
                    </div>

                    <!-- 3. Correo Electrónico -->
                    <div>
                        <label for="email" class="block text-xs font-bold text-slate-200 mb-1.5">
                            Correo Electrónico <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <input
                                id="email"
                                type="email"
                                bind:value={form.email}
                                placeholder="ej. contacto@tallercreativo.com"
                                required
                                class="w-full bg-[#0B0F17] border border-slate-700/80 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                            />
                        </div>
                        {#if errors.email}
                            <p class="text-[11px] text-rose-400 font-semibold mt-1.5 flex items-center gap-1">
                                <span>⚠️</span> {errors.email}
                            </p>
                        {/if}
                    </div>

                    <!-- 4. Contraseña y Confirmar Contraseña (2 columnas en sm:) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="password" class="block text-xs font-bold text-slate-200 mb-1.5">
                                Contraseña <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="password"
                                type="password"
                                bind:value={form.password}
                                placeholder="Mínimo 8 caracteres"
                                required
                                class="w-full bg-[#0B0F17] border border-slate-700/80 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                            />
                            {#if errors.password}
                                <p class="text-[11px] text-rose-400 font-semibold mt-1.5 flex items-center gap-1">
                                    <span>⚠️</span> {errors.password}
                                </p>
                            {/if}
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-xs font-bold text-slate-200 mb-1.5">
                                Confirmar Contraseña <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="password_confirmation"
                                type="password"
                                bind:value={form.password_confirmation}
                                placeholder="Repite tu contraseña"
                                required
                                class="w-full bg-[#0B0F17] border border-slate-700/80 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                            />
                            {#if errors.password_confirmation}
                                <p class="text-[11px] text-rose-400 font-semibold mt-1.5 flex items-center gap-1">
                                    <span>⚠️</span> {errors.password_confirmation}
                                </p>
                            {/if}
                        </div>
                    </div>

                    <!-- 5. Selector de Plan inicial -->
                    <div class="pt-2">
                        <div class="flex items-center justify-between mb-2">
                            <span class="block text-xs font-bold text-slate-200">
                                Selector de Plan Inicial <span class="text-rose-500">*</span>
                            </span>
                            <span class="text-[11px] text-emerald-400 font-bold">
                                Ambos con 15 días gratis
                            </span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            
                            <!-- Opción 1: Plan Estándar -->
                            <button
                                type="button"
                                onclick={() => selectPlan('standard')}
                                class="relative text-left p-4 rounded-2xl border transition-all duration-200 {form.plan === 'standard' ? 'bg-[#151D2C] border-[#4F46E5] ring-2 ring-[#4F46E5]/40 shadow-lg shadow-[#4F46E5]/10' : 'bg-[#0B0F17] border-slate-800 hover:border-slate-700'}"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-extrabold text-white">Plan Estándar</span>
                                    <span class="w-4 h-4 rounded-full border flex items-center justify-center text-[10px] {form.plan === 'standard' ? 'bg-[#4F46E5] border-[#4F46E5] text-white' : 'border-slate-600'}">
                                        {#if form.plan === 'standard'}✓{/if}
                                    </span>
                                </div>

                                <div class="mt-2">
                                    <div class="text-lg font-black text-white leading-tight">
                                        $30.000 COP
                                    </div>
                                    <div class="text-[11px] text-slate-400 font-medium">
                                        por usuario / mes
                                    </div>
                                </div>

                                <p class="text-[11px] text-slate-400 mt-2 leading-snug">
                                    Ideal para talleres individuales o dueños que operan directamente su taller.
                                </p>
                            </button>

                            <!-- Opción 2: Plan Taller Pro (5 Usuarios) -->
                            <button
                                type="button"
                                onclick={() => selectPlan('pro')}
                                class="relative text-left p-4 rounded-2xl border transition-all duration-200 {form.plan === 'pro' ? 'bg-[#151D2C] border-[#4F46E5] ring-2 ring-[#4F46E5]/40 shadow-xl shadow-[#4F46E5]/20' : 'bg-[#0B0F17] border-slate-800 hover:border-slate-700'}"
                            >
                                <div class="absolute -top-2.5 right-3 px-2 py-0.5 rounded-full text-[9px] font-extrabold bg-gradient-to-r from-[#4F46E5] to-[#06B6D4] text-white uppercase tracking-wider shadow">
                                    🔥 Más Popular
                                </div>

                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-extrabold text-white">Plan Taller Pro</span>
                                    <span class="w-4 h-4 rounded-full border flex items-center justify-center text-[10px] {form.plan === 'pro' ? 'bg-[#4F46E5] border-[#4F46E5] text-white' : 'border-slate-600'}">
                                        {#if form.plan === 'pro'}✓{/if}
                                    </span>
                                </div>

                                <div class="mt-2">
                                    <div class="text-lg font-black text-white leading-tight">
                                        $75.000 COP
                                    </div>
                                    <div class="text-[11px] text-[#06B6D4] font-bold">
                                        Hasta 5 Usuarios incluidos
                                    </div>
                                </div>

                                <p class="text-[11px] text-slate-400 mt-2 leading-snug">
                                    Para talleres en crecimiento con equipo operativo. Ahorro del 50%.
                                </p>
                            </button>
                        </div>
                    </div>

                    <!-- 6. Acordeón Opcional: Agregar Método de Pago Bold -->
                    <div class="pt-2">
                        <div class="rounded-2xl border {showCardAccordion ? 'border-[#4F46E5]/60 bg-[#121927]' : 'border-slate-800 bg-[#0B0F17]'} transition-all duration-200 overflow-hidden">
                            <button
                                type="button"
                                onclick={() => {
                                    showCardAccordion = !showCardAccordion;
                                    form.with_card = showCardAccordion;
                                }}
                                class="w-full flex items-center justify-between p-4 text-left transition-colors hover:bg-white/[0.02]"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-xl {showCardAccordion ? 'bg-[#4F46E5] text-white' : 'bg-slate-800 text-slate-300'} flex items-center justify-center text-base shrink-0 shadow-sm transition-colors">
                                        💳
                                    </div>
                                    <div>
                                        <div class="text-xs sm:text-sm font-bold text-white flex items-center gap-2">
                                            <span>Agregar método de pago ahora</span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                                Opcional
                                            </span>
                                        </div>
                                        <p class="text-[11px] text-slate-400 mt-0.5">
                                            Configura tu tarjeta de forma segura con Bold y asegura continuidad para tu taller.
                                        </p>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-400 font-bold transition-transform duration-200 {showCardAccordion ? 'rotate-180 text-white' : ''}">
                                    ▼
                                </span>
                            </button>

                            {#if showCardAccordion}
                                <div class="px-4 pb-5 pt-1 space-y-4 border-t border-slate-800/80">
                                    <!-- Aviso de Cobro $0 Hoy -->
                                    <div class="p-3.5 rounded-xl bg-gradient-to-r from-[#4F46E5]/15 to-[#06B6D4]/10 border border-[#4F46E5]/30 flex items-start gap-3">
                                        <span class="text-base shrink-0">🛡️</span>
                                        <div class="text-xs text-slate-300 space-y-1">
                                            <p class="font-bold text-white">
                                                No se te hará ningún cobro hoy. Tus primeros 15 días son 100% gratis.
                                            </p>
                                            <p class="text-slate-400 text-[11px]">
                                                El cobro automático del plan comenzará únicamente al finalizar tu prueba. Tokenización segura protegida por Bold.
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Marcas Aceptadas para pagos recurrentes -->
                                    <div class="flex items-center justify-between text-[11px] text-slate-400 px-1">
                                        <span>Tarjetas autorizadas para suscripción:</span>
                                        <div class="flex items-center gap-1.5 font-bold">
                                            <span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-200">Visa</span>
                                            <span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-200">Mastercard</span>
                                            <span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-200">Amex</span>
                                            <span class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-200">Diners</span>
                                        </div>
                                    </div>

                                    <!-- Número de Tarjeta y Marca Detectada -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <label for="card_number" class="text-xs font-bold text-slate-200">
                                                Número de Tarjeta (Crédito o Débito)
                                            </label>
                                            {#if detectedBrand}
                                                <span class="text-[11px] font-extrabold text-[#06B6D4] flex items-center gap-1">
                                                    <span>💳</span> {detectedBrand}
                                                </span>
                                            {/if}
                                        </div>
                                        <input
                                            id="card_number"
                                            type="text"
                                            inputmode="numeric"
                                            maxlength="23"
                                            value={form.card_number}
                                            oninput={formatCardNumber}
                                            placeholder="4000 1234 5678 9010"
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
                                        <label for="cardholder_name" class="block text-xs font-bold text-slate-200 mb-1.5">
                                            Nombre del Titular (Como aparece en la tarjeta)
                                        </label>
                                        <input
                                            id="cardholder_name"
                                            type="text"
                                            bind:value={form.cardholder_name}
                                            placeholder="ej. CARLOS MENDOZA"
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
                                            <label for="exp_month" class="block text-xs font-bold text-slate-200 mb-1.5">
                                                Mes (MM)
                                            </label>
                                            <input
                                                id="exp_month"
                                                type="text"
                                                inputmode="numeric"
                                                maxlength="2"
                                                bind:value={form.expiration_month}
                                                placeholder="12"
                                                class="w-full bg-[#0B0F17] border border-slate-700 rounded-xl px-3 py-3 text-sm text-center text-white font-mono placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                                            />
                                            {#if errors.expiration_month}
                                                <p class="text-[10px] text-rose-400 font-semibold mt-1">
                                                    {errors.expiration_month}
                                                </p>
                                            {/if}
                                        </div>

                                        <div>
                                            <label for="exp_year" class="block text-xs font-bold text-slate-200 mb-1.5">
                                                Año (AA / AAAA)
                                            </label>
                                            <input
                                                id="exp_year"
                                                type="text"
                                                inputmode="numeric"
                                                maxlength="4"
                                                bind:value={form.expiration_year}
                                                placeholder="2028"
                                                class="w-full bg-[#0B0F17] border border-slate-700 rounded-xl px-3 py-3 text-sm text-center text-white font-mono placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                                            />
                                            {#if errors.expiration_year}
                                                <p class="text-[10px] text-rose-400 font-semibold mt-1">
                                                    {errors.expiration_year}
                                                </p>
                                            {/if}
                                        </div>

                                        <div>
                                            <label for="cvv" class="block text-xs font-bold text-slate-200 mb-1.5">
                                                CVV
                                            </label>
                                            <input
                                                id="cvv"
                                                type="password"
                                                inputmode="numeric"
                                                maxlength="4"
                                                bind:value={form.cvv}
                                                placeholder="•••"
                                                class="w-full bg-[#0B0F17] border border-slate-700 rounded-xl px-3 py-3 text-sm text-center text-white font-mono placeholder-slate-500 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/25 transition-all"
                                            />
                                            {#if errors.cvv}
                                                <p class="text-[10px] text-rose-400 font-semibold mt-1">
                                                    {errors.cvv}
                                                </p>
                                            {/if}
                                        </div>
                                    </div>
                                </div>
                            {/if}
                        </div>
                    </div>

                    <!-- Botón Principal de Envío con Gradiente en #4F46E5 -->
                    <div class="pt-3">
                        <button
                            type="submit"
                            disabled={processing}
                            class="w-full py-4 px-6 rounded-2xl font-bold text-sm text-white bg-gradient-to-r from-[#4F46E5] via-[#4338CA] to-[#06B6D4] hover:from-[#4338CA] hover:to-[#0891B2] shadow-xl shadow-[#4F46E5]/25 hover:shadow-[#4F46E5]/40 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center gap-2 cursor-pointer"
                        >
                            {#if processing}
                                <span class="animate-spin text-base">⏳</span>
                                <span>Activando tu taller y prueba gratis...</span>
                            {:else}
                                <span>Crear mi Taller y Activar Prueba de 15 Días</span>
                                <span class="text-base font-mono">→</span>
                            {/if}
                        </button>
                    </div>

                    <!-- Garantías y Términos -->
                    <div class="text-center pt-1">
                        <p class="text-[11px] text-slate-500">
                            Al registrarte aceptas los Términos de Servicio. No requerimos tarjeta de crédito para iniciar la prueba.
                        </p>
                    </div>

                    <!-- Enlace Inferior a Login -->
                    <div class="pt-4 border-t border-slate-800 text-center">
                        <p class="text-xs text-slate-400">
                            ¿Ya tienes una cuenta activa?
                            <Link
                                href="/login"
                                class="font-bold text-[#818CF8] hover:text-white underline underline-offset-4 ml-1 transition-colors"
                            >
                                Inicia Sesión
                            </Link>
                        </p>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>
