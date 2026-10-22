<script>
    // 1. $props() recibe los datos que envía Laravel desde el controlador o ruta
    let { proyecto, versionPhp, baseDatos, pedidosEnCola } = $props();

    // 2. $state() crea variables reactivas locales en Svelte 5
    let minutosProducto = $state(45);
    let porcentajeHolgura = $state(20);

    // 3. $derived() calcula valores automáticamente cuando cambia un $state
    let tiempoTotalEstimado = $derived(
        Math.round(
            (pedidosEnCola + minutosProducto) * (1 + porcentajeHolgura / 100),
        ),
    );
</script>

<div
    class="min-h-screen bg-slate-900 text-white flex items-center justify-center p-6"
>
    <div
        class="max-w-lg w-full bg-slate-800 border border-slate-700 rounded-2xl p-8 shadow-xl space-y-6"
    >
        <div
            class="flex items-center justify-between border-b border-slate-700 pb-4"
        >
            <div>
                <span
                    class="text-xs font-semibold uppercase tracking-wider text-emerald-400"
                >
                    Laravel + Inertia + Svelte 5
                </span>
                <h1 class="text-3xl font-bold mt-1">{proyecto}</h1>
            </div>
            <span
                class="px-3 py-1 bg-emerald-500/20 text-emerald-300 text-xs rounded-full font-medium"
            >
                Conectado
            </span>
        </div>

        <!-- Datos que vienen desde el Backend (PHP / Laravel) -->
        <div
            class="bg-slate-900/60 rounded-xl p-4 text-sm space-y-2 border border-slate-700/60"
        >
            <p class="text-slate-400 font-medium">
                Datos enviados por Laravel (Props):
            </p>
            <div class="flex justify-between">
                <span class="text-slate-400">Motor PHP:</span>
                <span class="font-mono text-amber-300">v{versionPhp}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-400">Base de Datos MySQL:</span>
                <span class="font-mono text-emerald-300">{baseDatos}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-400">Cola actual en taller (WIP):</span>
                <span class="font-mono text-sky-300">{pedidosEnCola} min</span>
            </div>
        </div>

        <!-- Prueba interactiva de Runes en Svelte 5 -->
        <div class="space-y-4">
            <div>
                <label for="sim-prod-time" class="block text-sm text-slate-300 mb-1">
                    Tiempo de producción del nuevo producto: <strong
                        >{minutosProducto} min</strong
                    >
                </label>
                <input
                    id="sim-prod-time"
                    type="range"
                    min="10"
                    max="240"
                    bind:value={minutosProducto}
                    class="w-full accent-emerald-500"
                />
            </div>

            <div>
                <label for="sim-buffer" class="block text-sm text-slate-300 mb-1">
                    Tiempo de holgura: <strong>{porcentajeHolgura}%</strong>
                </label>
                <input
                    id="sim-buffer"
                    type="range"
                    min="0"
                    max="100"
                    bind:value={porcentajeHolgura}
                    class="w-full accent-emerald-500"
                />
            </div>
        </div>

        <!-- Resultado reactivo con $derived -->
        <div
            class="bg-emerald-950/50 border border-emerald-700/50 rounded-xl p-4 text-center"
        >
            <span class="text-xs text-emerald-300 uppercase tracking-wide">
                Tiempo total para entrega (Cola + Producto + Holgura)
            </span>
            <p class="text-3xl font-extrabold text-emerald-400 mt-1">
                {tiempoTotalEstimado} minutos
            </p>
        </div>
    </div>
</div>
