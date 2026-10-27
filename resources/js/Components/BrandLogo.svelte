<script>
    /**
     * Componente de Logotipo gipPyme (Gestor Inteligente de Pedidos)
     * Sistema de Marca Oficial:
     * - Isotipo 'gip' vectorial en degradado Azul Eléctrico (#4F46E5) a Cian Vibrante (#06B6D4)
     * - 'gip' en negrita + 'Pyme' en acento de marca
     * - Subtítulo 'GESTOR INTELIGENTE DE PEDIDOS' en tracking ancho
     * Adaptable automáticamente a fondos claros y oscuros.
     */
    let {
        size = 'md',
        layout = 'horizontal', // 'horizontal' | 'stacked' | 'icon'
        showSubtitle = true,
        class: className = '',
    } = $props();

    // ID único para evitar colisiones de gradientes SVG cuando hay múltiples instancias
    const uid = Math.random().toString(36).substring(2, 9);

    const sizes = {
        xs: {
            icon: 'w-6 h-6',
            title: 'text-sm',
            subtitle: 'text-[6.5px] tracking-[0.14em]',
            gap: 'gap-1.5',
            stackedGap: 'gap-1',
        },
        sm: {
            icon: 'w-8 h-8',
            title: 'text-base',
            subtitle: 'text-[7.5px] tracking-[0.16em]',
            gap: 'gap-2',
            stackedGap: 'gap-1.5',
        },
        md: {
            icon: 'w-10 h-10',
            title: 'text-xl',
            subtitle: 'text-[9px] tracking-[0.18em]',
            gap: 'gap-2.5',
            stackedGap: 'gap-2',
        },
        lg: {
            icon: 'w-14 h-14',
            title: 'text-2xl',
            subtitle: 'text-[11px] tracking-[0.2em]',
            gap: 'gap-3.5',
            stackedGap: 'gap-2.5',
        },
        xl: {
            icon: 'w-20 h-20',
            title: 'text-4xl',
            subtitle: 'text-[13px] tracking-[0.22em]',
            gap: 'gap-4',
            stackedGap: 'gap-3',
        },
    };

    let currentSize = $derived(sizes[size] ?? sizes.md);
</script>

<div class="inline-flex items-center select-none {layout === 'stacked' ? `flex-col text-center ${currentSize.stackedGap}` : currentSize.gap} {className}">
    <!-- ISOTIPO VECTORIAL 'gip' -->
    <div class="relative shrink-0 {currentSize.icon} flex items-center justify-center">
        <svg
            viewBox="0 0 154 116"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            class="w-full h-full drop-shadow-xs"
        >
            <defs>
                <!-- Degradado general de Azul Eléctrico a Cian Vibrante -->
                <linearGradient id="gip-grad-{uid}" x1="0%" y1="20%" x2="100%" y2="80%">
                    <stop offset="0%" stop-color="#4F46E5" />
                    <stop offset="40%" stop-color="#6366F1" />
                    <stop offset="70%" stop-color="#06B6D4" />
                    <stop offset="100%" stop-color="#22D3EE" />
                </linearGradient>

                <!-- Degradado específico para 'g' (Azul Eléctrico / Indigo profundo) -->
                <linearGradient id="g-grad-{uid}" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#4338CA" />
                    <stop offset="50%" stop-color="#4F46E5" />
                    <stop offset="100%" stop-color="#6366F1" />
                </linearGradient>

                <!-- Degradado específico para 'i' y 'p' (Cian Vibrante) -->
                <linearGradient id="p-grad-{uid}" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#06B6D4" />
                    <stop offset="70%" stop-color="#0891B2" />
                    <stop offset="100%" stop-color="#06B6D4" />
                </linearGradient>

                <linearGradient id="i-grad-{uid}" x1="0%" y1="0%" x2="0%" y2="100%">
                    <stop offset="0%" stop-color="#22D3EE" />
                    <stop offset="100%" stop-color="#06B6D4" />
                </linearGradient>

                <!-- Sombra de profundidad en intersecciones -->
                <filter id="shadow-{uid}" x="-10%" y="-10%" width="120%" height="120%">
                    <feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.18" flood-color="#000" />
                </filter>
            </defs>

            <!-- LETRA 'g' (Azul Eléctrico / Indigo entrelazado) -->
            <!-- Trazado unificado: Bucle superior + Asta descendente + Gancho inferior curvo -->
            <path
                d="M 40 30 C 29 30 20 39 20 50 C 20 61 29 70 40 70 C 51 70 60 61 60 50 C 60 39 51 30 40 30 Z"
                stroke="url(#g-grad-{uid})"
                stroke-width="13"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <path
                d="M 60 50 L 60 84 C 60 97 49 105 35 105 C 23 105 16 97 16 88"
                stroke="url(#g-grad-{uid})"
                stroke-width="13"
                stroke-linecap="round"
                stroke-linejoin="round"
            />

            <!-- LETRA 'i' (Cian Vibrante) -->
            <!-- Punto circular superior -->
            <circle
                cx="82"
                cy="22"
                r="7"
                fill="url(#i-grad-{uid})"
            />
            <!-- Tallo vertical de 'i' -->
            <path
                d="M 82 43 L 82 70"
                stroke="url(#i-grad-{uid})"
                stroke-width="13"
                stroke-linecap="round"
            />

            <!-- LETRA 'p' (Cian Vibrante) -->
            <!-- Asta descendente -->
            <path
                d="M 108 43 L 108 105"
                stroke="url(#p-grad-{uid})"
                stroke-width="13"
                stroke-linecap="round"
            />
            <!-- Bucle derecho de 'p' -->
            <path
                d="M 108 43 C 122 43 134 50 134 59 C 134 68 122 75 108 75"
                stroke="url(#p-grad-{uid})"
                stroke-width="13"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </div>

    <!-- TEXTO DE LA MARCA (gipPyme + Subtítulo) -->
    {#if layout !== 'icon'}
        <div class="flex flex-col min-w-0 {layout === 'stacked' ? 'items-center' : 'items-start'}">
            <!-- NOMBRE PRINCIPAL: gip + Pyme -->
            <div class="leading-none {currentSize.title} font-sans tracking-tight">
                <span class="font-black text-slate-900 dark:text-white transition-colors duration-200">gip</span><span class="font-bold text-brand-indigo dark:text-indigo-400 transition-colors duration-200">Pyme</span>
            </div>

            <!-- SUBTÍTULO OFICIAL -->
            {#if showSubtitle}
                <span class="font-bold uppercase text-brand-cyan dark:text-cyan-400 mt-1 block leading-tight font-sans {currentSize.subtitle}">
                    GESTOR INTELIGENTE DE PEDIDOS
                </span>
            {/if}
        </div>
    {/if}
</div>
