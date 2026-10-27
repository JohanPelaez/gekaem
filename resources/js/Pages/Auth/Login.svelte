<script>
    import { router } from '@inertiajs/svelte';
    import BrandLogo from '../../Components/BrandLogo.svelte';

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
    <title>Iniciar Sesión · gipPyme</title>
</svelte:head>

<div class="min-h-screen bg-dark-bg text-slate-100 flex flex-col justify-center items-center p-4 selection:bg-brand-indigo selection:text-white relative">
    <!-- Fondo decorativo con luces difusas de marca -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-96 h-96 bg-brand-indigo/15 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-40 right-10 w-96 h-96 bg-brand-cyan/15 rounded-full blur-3xl"></div>
    </div>

    <div class="relative w-full max-w-md space-y-6">
        <!-- Logotipo Oficial gipPyme en diseño vertical centrado (Stacked) -->
        <div class="flex flex-col items-center justify-center pt-2">
            <BrandLogo size="lg" layout="stacked" showSubtitle={true} />
        </div>

        <!-- Tarjeta de Login con diseño de superficie oscura -->
        <div class="bg-dark-surface/90 border border-dark-border rounded-3xl p-7 shadow-2xl backdrop-blur-xl space-y-5">
            <div class="border-b border-dark-border pb-3 text-center">
                <h1 class="text-lg font-bold tracking-tight text-white">Acceso a la Plataforma</h1>
                <p class="text-xs text-slate-400 mt-0.5">
                    Gestor Inteligente de Pedidos y Producción Make-to-Order
                </p>
            </div>

            <form onsubmit={submit} class="space-y-4 text-xs">
                <div>
                    <label for="l-email" class="block font-semibold text-slate-300 mb-1.5">Correo Electrónico</label>
                    <input
                        id="l-email"
                        type="email"
                        bind:value={form.email}
                        placeholder="tu-correo@taller.com"
                        required
                        class="w-full bg-dark-bg border border-dark-border rounded-xl px-3.5 py-2.5 text-white placeholder-slate-500 text-xs focus:outline-none focus:border-brand-indigo focus:ring-2 focus:ring-brand-indigo/20 transition-all"
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
                        class="w-full bg-dark-bg border border-dark-border rounded-xl px-3.5 py-2.5 text-white placeholder-slate-500 text-xs focus:outline-none focus:border-brand-indigo focus:ring-2 focus:ring-brand-indigo/20 transition-all"
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
                            class="rounded bg-dark-bg border-dark-border text-brand-indigo focus:ring-0"
                        />
                        <span>Recordarme en este equipo</span>
                    </label>
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    class="w-full py-3 px-4 rounded-xl bg-gradient-to-r from-brand-indigo to-indigo-600 hover:from-indigo-600 hover:to-indigo-500 text-white font-bold text-xs transition-all shadow-lg shadow-indigo-600/30 disabled:opacity-50 flex items-center justify-center gap-2"
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
                <div class="pt-4 border-t border-dark-border space-y-2.5">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400">
                            Acceso Rápido de Demostración
                        </span>
                        <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-brand-cyan/20 text-brand-cyan border border-brand-cyan/30">
                            1-Click
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <button
                            type="button"
                            onclick={() => quickLogin('admin')}
                            disabled={demoProcessing}
                            class="p-2 rounded-xl bg-dark-bg hover:bg-dark-surface-elevated border border-purple-500/30 text-purple-300 font-semibold text-[11px] transition-colors flex flex-col items-center gap-0.5 disabled:opacity-50"
                        >
                            <span>👑 Admin</span>
                            <span class="text-[9px] text-slate-400">Plataforma</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => quickLogin('owner')}
                            disabled={demoProcessing}
                            class="p-2 rounded-xl bg-dark-bg hover:bg-dark-surface-elevated border border-brand-indigo/40 text-indigo-300 font-semibold text-[11px] transition-colors flex flex-col items-center gap-0.5 disabled:opacity-50"
                        >
                            <span>🏢 Dueño</span>
                            <span class="text-[9px] text-slate-400">Taller MVP</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => quickLogin('worker')}
                            disabled={demoProcessing}
                            class="p-2 rounded-xl bg-dark-bg hover:bg-dark-surface-elevated border border-brand-cyan/30 text-cyan-300 font-semibold text-[11px] transition-colors flex flex-col items-center gap-0.5 disabled:opacity-50"
                        >
                            <span>🔧 Operador</span>
                            <span class="text-[9px] text-slate-400">Trabajador</span>
                        </button>
                    </div>
                </div>
            {/if}
        </div>
    </div>
</div>
