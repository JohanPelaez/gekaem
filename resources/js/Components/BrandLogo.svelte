<script>
    /**
     * Componente de Logotipo Oficial gipPyme (Gestor Inteligente de Pedidos)
     * Manual de Marca:
     * - Isotipo 'gip' vectorial en SVG geométrico exacto (viewBox="0 0 180 115")
     * - 'g' en #4F46E5, 'i' en degradado #4F46E5 -> #3B82F6 -> #06B6D4, 'p' en #06B6D4
     * - Soporta modo completo (apilado con isotipo + 'gipPyme' + subtítulo) y collapsed (solo isotipo)
     */
    let {
        collapsed = false,
        size = 'md',
        class: className = '',
    } = $props();
</script>

{#if collapsed}
    <!-- MODO COLAPSADO: Solo Isotipo SVG centrado -->
    <div class="flex items-center justify-center {className}" title="gipPyme - Gestor Inteligente de Pedidos">
        <svg
            viewBox="-6 -10 192 130"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            class="h-7 w-auto mx-auto select-none"
        >
            <defs>
                <linearGradient id="gipGrad" gradientUnits="userSpaceOnUse" x1="15" y1="0" x2="165" y2="0">
                    <stop offset="0%" stop-color="#4F46E5" />
                    <stop offset="45%" stop-color="#3B82F6" />
                    <stop offset="100%" stop-color="#06B6D4" />
                </linearGradient>
            </defs>

            <!-- Letra 'g' (#4F46E5) -->
            <circle cx="42" cy="52" r="24" fill="none" stroke="#4F46E5" stroke-width="14" />
            <path
                d="M 66 28 L 66 78 C 66 96, 52 106, 36 106 C 24 106, 17 100, 14 94"
                fill="none"
                stroke="#4F46E5"
                stroke-width="14"
                stroke-linecap="round"
            />

            <!-- Letra 'i' (Punto y Cuerpo sólido visible) -->
            <circle cx="92" cy="14" r="7.5" fill="#1E88E5" />
            <rect x="85" y="28" width="14" height="52" rx="7" fill="url(#gipGrad)" />

            <!-- Letra 'p' (#06B6D4) -->
            <line x1="118" y1="52" x2="118" y2="105" stroke="#06B6D4" stroke-width="14" stroke-linecap="round" />
            <circle cx="142" cy="52" r="24" fill="none" stroke="#06B6D4" stroke-width="14" />
        </svg>
    </div>
{:else}
    <!-- MODO COMPLETO APILADO: Isotipo + gipPyme + Subtítulo -->
    <div class="flex flex-col items-center text-center select-none {className}">
        <!-- Isotipo centrado arriba -->
        <svg
            viewBox="-6 -10 192 130"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            class="h-11 w-auto mx-auto"
        >
            <defs>
                <linearGradient id="gipGradFull" gradientUnits="userSpaceOnUse" x1="15" y1="0" x2="165" y2="0">
                    <stop offset="0%" stop-color="#4F46E5" />
                    <stop offset="45%" stop-color="#3B82F6" />
                    <stop offset="100%" stop-color="#06B6D4" />
                </linearGradient>
            </defs>

            <!-- Letra 'g' (#4F46E5) -->
            <circle cx="42" cy="52" r="24" fill="none" stroke="#4F46E5" stroke-width="14" />
            <path
                d="M 66 28 L 66 78 C 66 96, 52 106, 36 106 C 24 106, 17 100, 14 94"
                fill="none"
                stroke="#4F46E5"
                stroke-width="14"
                stroke-linecap="round"
            />

            <!-- Letra 'i' (Punto y Cuerpo sólido visible) -->
            <circle cx="92" cy="14" r="7.5" fill="#1E88E5" />
            <rect x="85" y="28" width="14" height="52" rx="7" fill="url(#gipGradFull)" />

            <!-- Letra 'p' (#06B6D4) -->
            <line x1="118" y1="52" x2="118" y2="105" stroke="#06B6D4" stroke-width="14" stroke-linecap="round" />
            <circle cx="142" cy="52" r="24" fill="none" stroke="#06B6D4" stroke-width="14" />
        </svg>

        <!-- Texto gipPyme -->
        <div class="text-2xl font-bold tracking-tight leading-none mt-2 font-sans">
            <span class="text-slate-900 dark:text-white">gip</span><span class="text-[#4F46E5]">Pyme</span>
        </div>

        <!-- Subtítulo oficial en una sola línea -->
        <div class="whitespace-nowrap text-[9px] font-bold tracking-[0.14em] text-[#06B6D4] uppercase mt-1 font-sans">
            GESTOR INTELIGENTE DE PEDIDOS
        </div>
    </div>
{/if}
