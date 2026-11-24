<script>
    import { router, Link } from '@inertiajs/svelte';
    import BrandLogo from '../../Components/BrandLogo.svelte';

    let { token = '', email: initialEmail = '', errors = {} } = $props();

    let email = $state('');
    let password = $state('');
    let passwordConfirmation = $state('');
    let showPassword = $state(false);
    let showPasswordConfirmation = $state(false);
    let processing = $state(false);
    let emailInitialized = $state(false);

    $effect(() => {
        if (!emailInitialized && initialEmail) {
            email = initialEmail;
            emailInitialized = true;
        }
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

    let hasMinLength = $derived(password.length >= 8);
    let passwordsMatch = $derived(
        password.length > 0 &&
        passwordConfirmation.length > 0 &&
        password === passwordConfirmation
    );
    let passwordsMismatch = $derived(
        passwordConfirmation.length > 0 && password !== passwordConfirmation
    );
    let canSubmit = $derived(!processing && hasMinLength && passwordsMatch && email.trim().length > 0);
    let hasTokenError = $derived(Boolean(errors.email || errors.token));

    function submit(e) {
        e.preventDefault();
        if (!canSubmit) return;

        processing = true;
        router.post(
            '/reset-password',
            {
                token,
                email,
                password,
                password_confirmation: passwordConfirmation,
            },
            {
                preserveScroll: true,
                onStart: () => { processing = true; },
                onFinish: () => { processing = false; },
            },
        );
    }
</script>

<svelte:head>
    <title>Nueva Contraseña · gipPyme</title>
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

        <!-- Tarjeta de Restablecimiento -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl p-7 shadow-xl backdrop-blur-xl space-y-5 text-slate-900 dark:text-slate-100">
            <div class="border-b border-slate-100 dark:border-[#26334D] pb-4 text-center space-y-1.5">
                <h1 class="text-lg font-bold tracking-tight text-slate-900 dark:text-white">
                    Crea tu nueva contraseña
                </h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                    Elige una contraseña segura de al menos 8 caracteres para proteger tu taller en gipPyme.
                </p>
            </div>

            {#if hasTokenError}
                <div class="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/30 text-rose-700 dark:text-rose-300 text-xs space-y-2">
                    <p class="font-medium">
                        {errors.email || errors.token}
                    </p>
                    <Link
                        href="/forgot-password"
                        class="inline-flex items-center gap-1 font-bold text-[#4F46E5] dark:text-[#06B6D4] hover:underline"
                    >
                        <span>Solicitar un nuevo enlace →</span>
                    </Link>
                </div>
            {/if}

            <form onsubmit={submit} class="space-y-4 text-xs">
                <!-- 1. Correo Electrónico -->
                <div>
                    <label for="rp-email" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                        Correo Electrónico
                    </label>
                    <input
                        id="rp-email"
                        type="email"
                        bind:value={email}
                        placeholder="tu-correo@taller.com"
                        required
                        autocomplete="email"
                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3.5 py-2.5 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 transition-all"
                    />
                </div>

                <!-- 2. Nueva Contraseña -->
                <div>
                    <label for="rp-password" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                        Nueva Contraseña
                    </label>
                    <div class="relative">
                        <input
                            id="rp-password"
                            type={showPassword ? 'text' : 'password'}
                            bind:value={password}
                            placeholder="Mínimo 8 caracteres"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl pl-3.5 pr-10 py-2.5 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 transition-all"
                        />
                        <button
                            type="button"
                            onclick={() => { showPassword = !showPassword; }}
                            aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                            class="absolute inset-y-0 right-0 px-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors cursor-pointer"
                        >
                            {#if showPassword}
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            {:else}
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            {/if}
                        </button>
                    </div>

                    <!-- Validación visual en vivo de mínimo 8 caracteres -->
                    <div class="mt-1.5 flex items-center gap-1.5 text-[11px] font-medium {password.length === 0 ? 'text-slate-400 dark:text-slate-500' : hasMinLength ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'}">
                        <span>{hasMinLength ? '✓' : '•'}</span>
                        <span>Mínimo 8 caracteres ({password.length}/8)</span>
                    </div>

                    {#if errors.password}
                        <p class="text-[11px] text-rose-500 mt-1 font-medium">{errors.password}</p>
                    {/if}
                </div>

                <!-- 3. Confirmar Nueva Contraseña -->
                <div>
                    <label for="rp-password-confirm" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                        Confirmar Nueva Contraseña
                    </label>
                    <div class="relative">
                        <input
                            id="rp-password-confirm"
                            type={showPasswordConfirmation ? 'text' : 'password'}
                            bind:value={passwordConfirmation}
                            placeholder="Repite tu nueva contraseña"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl pl-3.5 pr-10 py-2.5 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 transition-all"
                        />
                        <button
                            type="button"
                            onclick={() => { showPasswordConfirmation = !showPasswordConfirmation; }}
                            aria-label={showPasswordConfirmation ? 'Ocultar confirmación' : 'Mostrar confirmación'}
                            class="absolute inset-y-0 right-0 px-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors cursor-pointer"
                        >
                            {#if showPasswordConfirmation}
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                                    <line x1="1" y1="1" x2="23" y2="23"/>
                                </svg>
                            {:else}
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            {/if}
                        </button>
                    </div>

                    {#if passwordsMatch}
                        <p class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-1.5 font-medium flex items-center gap-1">
                            <span>✓</span>
                            <span>Las contraseñas coinciden</span>
                        </p>
                    {:else if passwordsMismatch}
                        <p class="text-[11px] text-rose-500 mt-1.5 font-medium flex items-center gap-1">
                            <span>✕</span>
                            <span>Las contraseñas no coinciden</span>
                        </p>
                    {/if}
                </div>

                <button
                    type="submit"
                    disabled={!canSubmit}
                    class="w-full py-3 px-4 rounded-xl bg-[#4F46E5] hover:bg-indigo-600 text-white font-bold text-xs transition-all shadow-md shadow-indigo-600/30 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 cursor-pointer"
                >
                    {#if processing}
                        <span class="animate-spin text-sm">⏳</span>
                        <span>Guardando...</span>
                    {:else}
                        <span>Actualizar Contraseña</span>
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
