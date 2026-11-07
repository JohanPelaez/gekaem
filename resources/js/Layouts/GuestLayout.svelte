<script>
    import BrandLogo from '../Components/BrandLogo.svelte';

    let { title = 'gipPyme', children } = $props();

    // Sincronizar tema Claro por defecto
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
</script>

<svelte:head>
    <title>{title}</title>
</svelte:head>

<div class="min-h-screen bg-slate-50 dark:bg-[#0B0F17] text-slate-900 dark:text-slate-100 flex flex-col justify-center items-center p-4 selection:bg-[#4F46E5] selection:text-white relative transition-colors">
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 left-1/2 -translate-x-1/2 w-96 h-96 bg-[#4F46E5]/10 dark:bg-[#4F46E5]/15 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-40 right-10 w-96 h-96 bg-[#06B6D4]/10 dark:bg-[#06B6D4]/15 rounded-full blur-3xl"></div>
    </div>

    <div class="relative w-full max-w-md space-y-6">
        <div class="flex flex-col items-center justify-center pt-2">
            <BrandLogo size="lg" layout="stacked" showSubtitle={true} />
        </div>

        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl shadow-xl p-6 sm:p-8 backdrop-blur-sm">
            {@render children?.()}
        </div>
    </div>
</div>

