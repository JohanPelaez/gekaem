<script>
    import { router, Link } from '@inertiajs/svelte';
    import BrandLogo from '../../Components/BrandLogo.svelte';

    let { status = null, errors = {} } = $props();

    let email = $state('');
    let processing = $state(false);

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

    function submit(e) {
        e.preventDefault();
        processing = true;
        router.post('/forgot-password', { email }, {
            preserveScroll: true,
            onStart: () => { processing = true; },
            onFinish: () => { processing = false; },
        });
    }
</script>

<svelte:head>
    <title>Recuperar Contraseña · gipPyme</title>
</svelte:head>

<div class="min-h-screen bg-slate-50 dark:bg-[#0B0F17] text-slate-900 dark:text-slate-100 flex flex-col justify-center items-center p-4 selection:bg-[#4F46E5] selection:text-white relative transition-colors">
    <!-- Fondo decorativo con luces difusas de marca -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-96 h-96 bg-[#4F46E5]/10 dark:bg-[#4F46E5]/15 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-40 right-10 w-96 h-96 bg-[#06B6D4]/10 dark:bg-[#06B6D4]/15 rounded-full blur-3xl"></div>
    </div>

    <div class="relative w-full max-w-md space-y-6">
        <!-- Logotipo Oficial gipPyme -->
        <div class="flex flex-col items-center justify-center pt-2">
            <BrandLogo size="lg" layout="stacked" showSubtitle={true} />
        </div>

        <!-- Tarjeta de Recuperación -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl p-7 shadow-xl backdrop-blur-xl space-y-5 text-slate-900 dark:text-slate-100">
            <div class="border-b border-slate-100 dark:border-[#26334D] pb-4 text-center space-y-1.5">
                <h1 class="text-lg font-bold tracking-tight text-slate-900 dark:text-white">
                    ¿Olvidaste tu contraseña?
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                    Ingresa el correo electrónico de tu cuenta y te enviaremos un enlace seguro para crear una nueva contraseña.
                </p>
            </div>

            {#if status}
                <div class="p-3.5 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 text-emerald-700 dark:text-emerald-300 text-xs space-y-1">
                    <p class="font-semibold flex items-start gap-2">
                        <span class="shrink-0">✓</span>
                        <span>{status}</span>
                    </p>
                    <p class="text-[11px] text-emerald-600/90 dark:text-emerald-300/80 pl-5">
                        Revisa tu bandeja de entrada y también tu carpeta de correo no deseado (spam).
                    </p>
                </div>
            {/if}

            <form onsubmit={submit} class="space-y-4 text-xs">
                <div>
                    <label for="email" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                        Correo Electrónico
                    </label>
                    <!-- svelte-ignore a11y_autofocus -->
                    <input
                        id="email"
                        type="email"
                        bind:value={email}
                        placeholder="tu-correo@taller.com"
                        required
                        autofocus
                        autocomplete="email"
                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3.5 py-2.5 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 transition-all"
                    />
                    {#if errors.email}
                        <p class="text-[11px] text-rose-500 mt-1.5 font-medium">{errors.email}</p>
                    {/if}
                </div>

                <button
                    type="submit"
                    disabled={processing}
                    class="w-full py-3 px-4 rounded-xl bg-[#4F46E5] hover:bg-indigo-600 text-white font-bold text-xs transition-all shadow-md shadow-indigo-600/30 disabled:opacity-50 flex items-center justify-center gap-2 cursor-pointer"
                >
                    {#if processing}
                        <span class="animate-spin text-sm">⏳</span>
                        <span>Enviando...</span>
                    {:else}
                        <span>Enviar Enlace de Recuperación</span>
                        <span>→</span>
                    {/if}
                </button>
            </form>

            <div class="pt-2 border-t border-slate-100 dark:border-[#26334D] text-center">
                <Link
                    href="/login"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors"
                >
                    <span>← Volver a Iniciar Sesión</span>
                </Link>
            </div>
        </div>
    </div>
</div>
