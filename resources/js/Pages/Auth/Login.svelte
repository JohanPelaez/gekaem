<script>
    import { router } from '@inertiajs/svelte';

    let { canDemoLogin = false, errors = {} } = $props();

    let processing = $state(false);
    let demoProcessing = $state(false);

    let form = $state({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e) {
        e.preventDefault();
        processing = true;
        router.post('/login', form, {
            preserveScroll: true,
            onStart: () => { processing = true; },
            onFinish: () => { processing = false; },
        });
    }

    function quickLogin(role) {
        demoProcessing = true;
        router.post('/demo-login', { role }, {
            onFinish: () => { demoProcessing = false; },
        });
    }
</script>

<svelte:head>
    <title>Iniciar Sesión · Gekaem</title>
</svelte:head>

<div class="min-h-screen bg-slate-950 flex flex-col justify-center items-center p-4 selection:bg-emerald-500 selection:text-white">
    <!-- Fondo decorativo con luces difusas -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-96 h-96 bg-emerald-500/10 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-40 right-10 w-96 h-96 bg-teal-500/10 rounded-full blur-3xl"></div>
    </div>

    <div class="relative w-full max-w-md space-y-6">
        <!-- Logo y Título -->
        <div class="text-center space-y-2">
            <div class="inline-flex w-14 h-14 rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-400 items-center justify-center shadow-xl shadow-emerald-500/25 mb-1">
                <span class="font-black text-2xl text-white tracking-wider">GK</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white">Iniciar Sesión en Gekaem</h1>
            <p class="text-xs text-slate-400">
                Gestión de Procesos y Producción Make-to-Order para Emprendedores
            </p>
        </div>

        <!-- Tarjeta de Login -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-7 shadow-2xl backdrop-blur-xl space-y-5">
            <form onsubmit={submit} class="space-y-4 text-xs">
                <div>
                    <label for="l-email" class="block font-semibold text-slate-300 mb-1.5">Correo Electrónico</label>
                    <input
                        id="l-email"
                        type="email"
                        bind:value={form.email}
                        placeholder="tu-correo@taller.com"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white placeholder-slate-500 text-xs focus:outline-none focus:border-emerald-500 transition-colors"
                    />
                    {#if errors.email}
                        <p class="text-[11px] text-rose-400 mt-1 font-medium">{errors.email}</p>
                    {/if}
                </div>

                <div>
                    <label for="l-pass" class="block font-semibold text-slate-300 mb-1.5">Contraseña</label>
                    <input
                        id="l-pass"
                        type="password"
                        bind:value={form.password}
                        placeholder="••••••••"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white placeholder-slate-500 text-xs focus:outline-none focus:border-emerald-500 transition-colors"
                    />
                    {#if errors.password}
                        <p class="text-[11px] text-rose-400 mt-1 font-medium">{errors.password}</p>
                    {/if}
                </div>

                <div class="flex items-center justify-between text-[11px]">
                    <label class="flex items-center gap-2 text-slate-400 cursor-pointer">
                        <input
                            type="checkbox"
                            bind:checked={form.remember}
                            class="rounded bg-slate-950 border-slate-800 text-emerald-500 focus:ring-0"
                        />
                        <span>Recordarme en este equipo</span>
                    </label>
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    class="w-full py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-all shadow-lg shadow-emerald-600/25 disabled:opacity-50 flex items-center justify-center gap-2"
                >
                    {#if processing}
                        <span class="animate-spin text-sm">⏳</span>
                        <span>Iniciando sesión...</span>
                    {:else}
                        <span>Entrar a mi Taller</span>
                        <span>→</span>
                    {/if}
                </button>
            </form>

            <!-- ACCESO RÁPIDO DE DEMOSTRACIÓN (SOLO LOCAL) -->
            {#if canDemoLogin}
                <div class="pt-4 border-t border-slate-800/80 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] uppercase font-bold tracking-wider text-slate-500">
                            Acceso Rápido de Demostración (Local)
                        </span>
                        <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                            1-Click
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <button
                            type="button"
                            onclick={() => quickLogin('admin')}
                            disabled={demoProcessing}
                            class="p-2 rounded-xl bg-slate-950 hover:bg-slate-800 border border-purple-500/30 text-purple-300 font-semibold text-[11px] transition-colors flex flex-col items-center gap-0.5 disabled:opacity-50"
                        >
                            <span>👑 Admin</span>
                            <span class="text-[9px] text-slate-500">Plataforma</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => quickLogin('owner')}
                            disabled={demoProcessing}
                            class="p-2 rounded-xl bg-slate-950 hover:bg-slate-800 border border-emerald-500/30 text-emerald-300 font-semibold text-[11px] transition-colors flex flex-col items-center gap-0.5 disabled:opacity-50"
                        >
                            <span>🏢 Dueño</span>
                            <span class="text-[9px] text-slate-500">Taller MVP</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => quickLogin('worker')}
                            disabled={demoProcessing}
                            class="p-2 rounded-xl bg-slate-950 hover:bg-slate-800 border border-sky-500/30 text-sky-300 font-semibold text-[11px] transition-colors flex flex-col items-center gap-0.5 disabled:opacity-50"
                        >
                            <span>🔧 Operador</span>
                            <span class="text-[9px] text-slate-500">Trabajador</span>
                        </button>
                    </div>
                </div>
            {/if}
        </div>
    </div>
</div>
