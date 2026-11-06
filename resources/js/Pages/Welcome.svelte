<script>
    import { page, Link } from '@inertiajs/svelte';
    import BrandLogo from '../Components/BrandLogo.svelte';

    let {
        canLogin = true,
        canRegister = true,
    } = $props();

    // Usuario autenticado (reactivo vía props de Inertia)
    let authUser = $derived(page.props.auth?.user);

    // Estado reactivo de tema (Claro / Oscuro)
    let isDark = $state(false);

    // Estado del escaparate interactivo de capturas
    // 'split' = Comparador deslizable, 'layered' = Vista dual en capas, 'light' = Solo claro, 'dark' = Solo oscuro
    let showcaseMode = $state('split');
    let sliderPos = $state(50); // Porcentaje de división (0 a 100)
    let activeLayer = $state('light'); // En modo capas: cuál está al frente ('light' | 'dark')
    let isDragging = $state(false);
    let sliderContainerRef = $state(null);

    // Control de fallos de imagen (resguardo inteligente)
    let lightImgFailed = $state(false);
    let darkImgFailed = $state(false);

    // Estado del menú móvil y scroll
    let mobileMenuOpen = $state(false);
    let isScrolled = $state(false);

    // Inicialización del tema y oyentes de ventana
    $effect(() => {
        if (typeof window !== 'undefined') {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                isDark = true;
                document.documentElement.classList.add('dark');
            } else {
                isDark = false;
                document.documentElement.classList.remove('dark');
            }

            const handleScroll = () => {
                isScrolled = window.scrollY > 20;
            };

            window.addEventListener('scroll', handleScroll, { passive: true });
            handleScroll();

            return () => {
                window.removeEventListener('scroll', handleScroll);
            };
        }
    });

    function toggleTheme() {
        isDark = !isDark;
        if (typeof window !== 'undefined') {
            if (isDark) {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            }
        }
    }

    function scrollToSection(id) {
        mobileMenuOpen = false;
        if (typeof document !== 'undefined') {
            const el = document.getElementById(id);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    // Manejo del arrastre del slider en el comparador interactivo
    function handleSliderMove(clientX) {
        if (!sliderContainerRef) return;
        const rect = sliderContainerRef.getBoundingClientRect();
        const offsetX = clientX - rect.left;
        const percentage = Math.min(Math.max((offsetX / rect.width) * 100, 2), 98);
        sliderPos = Math.round(percentage);
    }

    function onPointerDown(e) {
        isDragging = true;
        handleSliderMove(e.clientX);
    }

    function onPointerMove(e) {
        if (isDragging) {
            handleSliderMove(e.clientX);
        }
    }

    function onPointerUp() {
        isDragging = false;
    }

    function toggleLayers() {
        activeLayer = activeLayer === 'light' ? 'dark' : 'light';
    }
</script>

<svelte:head>
    <title>gipPyme — Gestor Inteligente de Pedidos, Producción e Inventario</title>
    <meta
        name="description"
        content="La aplicación moderna y fácil para talleres y pymes: controla cotizaciones, abonos, tablero Kanban por flujos, recetas de materiales (BOM) y fecha real de entrega."
    />
</svelte:head>

<svelte:window onpointermove={onPointerMove} onpointerup={onPointerUp} />

<div class="min-h-screen bg-[#F8FAFC] dark:bg-[#0B0F17] text-slate-800 dark:text-slate-100 transition-colors duration-300 selection:bg-[#4F46E5] selection:text-white font-sans overflow-x-hidden">

    <!-- ========================================================================= -->
    <!-- 1. NAVBAR FLOTANTE CON EFECTO CRISTAL (GLASSMORPHISM)                     -->
    <!-- ========================================================================= -->
    <header
        class="fixed top-0 inset-x-0 z-50 transition-all duration-300 {isScrolled ? 'backdrop-blur-xl bg-white/85 dark:bg-[#0B0F17]/85 border-b border-slate-200/80 dark:border-[#26334D]/90 shadow-sm shadow-slate-900/5' : 'backdrop-blur-lg bg-white/70 dark:bg-[#0B0F17]/70 border-b border-slate-200/50 dark:border-[#26334D]/60'}"
    >
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between gap-4">
            
            <!-- Izquierda: Componente BrandLogo en versión compacta horizontal -->
            <div class="flex items-center">
                <a
                    href="/"
                    class="focus:outline-none focus-visible:ring-2 focus-visible:ring-[#4F46E5] rounded-xl transition-transform active:scale-95"
                    aria-label="gipPyme Inicio"
                >
                    <BrandLogo variant="horizontal" size="md" showSubtitle={true} />
                </a>
            </div>

            <!-- Centro (Desktop): Enlaces de navegación suaves -->
            <nav class="hidden md:flex items-center gap-1 lg:gap-2 text-sm font-medium text-slate-600 dark:text-slate-300">
                <button
                    type="button"
                    onclick={() => scrollToSection('producto')}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Producto
                </button>
                <button
                    type="button"
                    onclick={() => scrollToSection('escaparate-demo')}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Capturas Reales
                </button>
                <button
                    type="button"
                    onclick={() => scrollToSection('modulos')}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Módulos
                </button>
                <button
                    type="button"
                    onclick={() => scrollToSection('flujos')}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Flujos Kanban
                </button>
                <button
                    type="button"
                    onclick={() => scrollToSection('precios')}
                    class="px-3 py-2 rounded-lg hover:text-[#4F46E5] dark:hover:text-[#06B6D4] hover:bg-slate-100/70 dark:hover:bg-[#151D2C]/70 transition-all cursor-pointer"
                >
                    Precios
                </button>
            </nav>

            <!-- Derecha: Tema + Acceso de usuario o CTA comercial -->
            <div class="flex items-center gap-2.5 sm:gap-3">
                <!-- Botón Toggle de Tema Global -->
                <button
                    type="button"
                    onclick={toggleTheme}
                    class="p-2 sm:px-3 sm:py-2 rounded-xl text-xs font-semibold border border-slate-200 dark:border-[#26334D] bg-white dark:bg-[#151D2C] text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-[#1f293d] transition-all flex items-center gap-1.5 shadow-sm"
                    title={isDark ? "Cambiar a Modo Claro" : "Cambiar a Modo Oscuro"}
                    aria-label="Alternar tema claro u oscuro"
                >
                    {#if isDark}
                        <span class="text-amber-400 text-sm">☀️</span>
                        <span class="hidden sm:inline">Claro</span>
                    {:else}
                        <span class="text-indigo-500 text-sm">🌙</span>
                        <span class="hidden sm:inline">Oscuro</span>
                    {/if}
                </button>

                {#if authUser}
                    <!-- Usuario Autenticado: Ir directo a su Taller -->
                    <Link
                        href="/dashboard"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm text-white bg-gradient-to-r from-[#4F46E5] to-[#3B82F6] hover:from-[#4338CA] hover:to-[#2563EB] shadow-lg shadow-[#4F46E5]/25 hover:shadow-[#4F46E5]/40 transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0"
                    >
                        <span>Ir a mi Taller</span>
                        <span class="transition-transform group-hover:translate-x-1">→</span>
                    </Link>
                {:else}
                    <!-- Visitante: Iniciar Sesión y Crear Taller -->
                    {#if canLogin}
                        <Link
                            href="/login"
                            class="hidden sm:inline-flex px-3.5 py-2 text-sm font-semibold text-slate-700 dark:text-slate-300 hover:text-[#4F46E5] dark:hover:text-[#06B6D4] transition-colors"
                        >
                            Iniciar Sesión
                        </Link>
                    {/if}

                    <Link
                        href="/register"
                        class="inline-flex items-center gap-1.5 bg-[#4F46E5] hover:bg-[#4338CA] text-white shadow-lg shadow-[#4F46E5]/25 hover:shadow-[#4F46E5]/40 rounded-xl px-4 sm:px-5 py-2.5 text-sm font-semibold transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0"
                    >
                        <span>Crear mi Taller Gratis</span>
                    </Link>
                {/if}

                <!-- Botón Hamburguesa Móvil -->
                <button
                    type="button"
                    onclick={() => (mobileMenuOpen = !mobileMenuOpen)}
                    class="md:hidden p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-[#151D2C] transition-colors"
                    aria-label="Abrir menú de navegación"
                >
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        {#if mobileMenuOpen}
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        {:else}
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        {/if}
                    </svg>
                </button>
            </div>
        </div>

        <!-- Menú Desplegable Móvil -->
        {#if mobileMenuOpen}
            <div class="md:hidden px-4 pt-2 pb-6 space-y-3 bg-white/95 dark:bg-[#0B0F17]/95 backdrop-blur-2xl border-b border-slate-200 dark:border-[#26334D] transition-all">
                <div class="grid grid-cols-2 gap-2 text-sm font-medium">
                    <button
                        type="button"
                        onclick={() => scrollToSection('producto')}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C]"
                    >
                        📦 Producto
                    </button>
                    <button
                        type="button"
                        onclick={() => scrollToSection('escaparate-demo')}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C]"
                    >
                        🖼️ Capturas Reales
                    </button>
                    <button
                        type="button"
                        onclick={() => scrollToSection('modulos')}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C]"
                    >
                        🧩 Módulos
                    </button>
                    <button
                        type="button"
                        onclick={() => scrollToSection('flujos')}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C]"
                    >
                        🔄 Flujos Kanban
                    </button>
                    <button
                        type="button"
                        onclick={() => scrollToSection('precios')}
                        class="text-left px-3 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-[#151D2C] col-span-2"
                    >
                        🏷️ Planes y Precios
                    </button>
                </div>

                <div class="pt-3 border-t border-slate-200 dark:border-[#26334D] flex flex-col gap-2">
                    {#if authUser}
                        <Link
                            href="/dashboard"
                            class="w-full text-center py-2.5 rounded-xl font-semibold text-white bg-gradient-to-r from-[#4F46E5] to-[#3B82F6]"
                        >
                            Ir a mi Taller →
                        </Link>
                    {:else}
                        <Link
                            href="/login"
                            class="w-full text-center py-2 rounded-xl text-sm font-semibold border border-slate-200 dark:border-[#26334D] text-slate-700 dark:text-slate-200"
                        >
                            Iniciar Sesión
                        </Link>
                        <Link
                            href="/register"
                            class="w-full text-center py-2.5 rounded-xl text-sm font-semibold text-white bg-[#4F46E5]"
                        >
                            Crear mi Taller Gratis
                        </Link>
                    {/if}
                </div>
            </div>
        {/if}
    </header>


    <!-- ========================================================================= -->
    <!-- 2. HERO BANNER DE ALTA CONVERSIÓN CON FONDO ATMOSFÉRICO                  -->
    <!-- ========================================================================= -->
    <section id="producto" class="relative pt-32 sm:pt-40 pb-20 sm:pb-28 overflow-hidden">
        
        <!-- Fondo Atmosférico con Cuadrícula y Auroras Difuminadas -->
        <div class="absolute inset-0 pointer-events-none -z-10">
            <!-- Cuadrícula Técnica Suave -->
            <div
                class="absolute inset-0 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:32px_32px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_0%,#000_70%,transparent_100%)]"
            ></div>

            <!-- Auroras Difusas (#4F46E5 en Azul Eléctrico y #06B6D4 en Cian Vibrante) -->
            <div class="absolute -top-32 left-1/2 -translate-x-3/4 w-[500px] h-[500px] bg-[#4F46E5]/20 dark:bg-[#4F46E5]/25 rounded-full blur-3xl opacity-75"></div>
            <div class="absolute top-10 left-1/2 -translate-x-1/4 w-[450px] h-[450px] bg-[#06B6D4]/20 dark:bg-[#06B6D4]/25 rounded-full blur-3xl opacity-70"></div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative">

            <!-- Píldora Superior Animada -->
            <div class="inline-flex items-center gap-2.5 px-4 py-1.5 rounded-full bg-slate-100/90 dark:bg-[#151D2C]/90 border border-slate-200/80 dark:border-[#26334D] text-xs font-semibold text-slate-800 dark:text-slate-200 shadow-sm backdrop-blur-md mb-8 hover:scale-105 transition-transform duration-200 cursor-default">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#06B6D4] opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-[#06B6D4]"></span>
                </span>
                <span>✨ Gestor Inteligente de Pedidos, Producción e Inventario para Talleres y Pymes</span>
            </div>

            <!-- Título Principal Impactante -->
            <h1 class="text-4xl sm:text-6xl lg:text-7xl font-bold tracking-tight text-slate-900 dark:text-white max-w-5xl mx-auto leading-[1.12]">
                La app fácil para organizar, controlar y
                <span class="bg-gradient-to-r from-[#4F46E5] via-[#3B82F6] to-[#06B6D4] bg-clip-text text-transparent">
                    despachar tus pedidos sin complicaciones.
                </span>
            </h1>

            <!-- Subtítulo persuasivo -->
            <p class="mt-6 sm:mt-8 text-lg sm:text-xl text-[#64748B] dark:text-slate-300 max-w-3xl mx-auto font-normal leading-relaxed">
                Controla desde la cotización y el abono inicial hasta cada etapa del tablero Kanban, el consumo exacto de materia prima (BOM) y la fecha real de entrega en un solo lugar.
            </p>

            <!-- Doble CTA Comercial -->
            <div class="mt-9 sm:mt-11 flex flex-col sm:flex-row items-center justify-center gap-4">
                {#if authUser}
                    <Link
                        href="/dashboard"
                        class="w-full sm:w-auto px-8 py-4 rounded-xl text-base font-bold text-white bg-gradient-to-r from-[#4F46E5] via-[#4338CA] to-[#3B82F6] hover:opacity-95 shadow-xl shadow-[#4F46E5]/30 hover:shadow-[#4F46E5]/40 transition-all duration-200 hover:-translate-y-0.5 flex items-center justify-center gap-2 group"
                    >
                        <span>Ir a mi Panel de Control</span>
                        <span class="transition-transform group-hover:translate-x-1 font-mono text-lg">→</span>
                    </Link>
                {:else}
                    <Link
                        href="/register"
                        class="w-full sm:w-auto px-8 py-4 rounded-xl text-base font-bold text-white bg-gradient-to-r from-[#4F46E5] via-[#4338CA] to-[#3B82F6] hover:opacity-95 shadow-xl shadow-[#4F46E5]/30 hover:shadow-[#4F46E5]/40 transition-all duration-200 hover:-translate-y-0.5 flex items-center justify-center gap-2 group"
                    >
                        <span>Empezar Ahora — Prueba Gratis</span>
                        <span class="transition-transform group-hover:translate-x-1 font-mono text-lg">→</span>
                    </Link>
                {/if}

                <button
                    type="button"
                    onclick={() => scrollToSection('escaparate-demo')}
                    class="w-full sm:w-auto px-7 py-4 rounded-xl text-base font-semibold text-slate-700 dark:text-slate-200 bg-white/90 dark:bg-[#151D2C]/90 border border-slate-200/90 dark:border-[#26334D] hover:bg-slate-100 dark:hover:bg-[#1f293d] shadow-sm transition-all flex items-center justify-center gap-2 cursor-pointer"
                >
                    <span>Explorar Demo en Vivo</span>
                    <span class="text-xs text-[#06B6D4]">▼</span>
                </button>
            </div>

            <!-- Tres micro-garantías rápidas -->
            <div class="mt-6 flex flex-wrap items-center justify-center gap-y-2 gap-x-6 text-xs sm:text-sm font-medium text-slate-500 dark:text-slate-400">
                <span class="flex items-center gap-1.5">
                    <span class="text-emerald-500 font-bold">✓</span> Control de Abonos y Saldos
                </span>
                <span class="text-slate-300 dark:text-slate-700 hidden sm:inline">•</span>
                <span class="flex items-center gap-1.5">
                    <span class="text-[#06B6D4] font-bold">✓</span> Tablero Kanban por Flujos
                </span>
                <span class="text-slate-300 dark:text-slate-700 hidden sm:inline">•</span>
                <span class="flex items-center gap-1.5">
                    <span class="text-[#4F46E5] font-bold">✓</span> Recetas y Códigos de Barras
                </span>
            </div>


            <!-- ================================================================= -->
            <!-- 3. ESCAPARATE INTERACTIVO PARA LAS 2 CAPTURAS REALES               -->
            <!-- ================================================================= -->
            <div id="escaparate-demo" class="mt-14 sm:mt-20 max-w-6xl mx-auto relative text-left">
                
                <!-- Barra de Control del Escaparate -->
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mb-4 bg-white/70 dark:bg-[#151D2C]/70 backdrop-blur-xl p-2 sm:p-2.5 rounded-2xl border border-slate-200/80 dark:border-[#26334D] shadow-sm">
                    <div class="flex items-center gap-1.5">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 px-2">
                            Explorar Interfaz Real:
                        </span>
                    </div>

                    <!-- Selector de Modos de Vista -->
                    <div class="flex items-center gap-1 bg-slate-100 dark:bg-[#0B0F17] p-1 rounded-xl w-full sm:w-auto justify-center overflow-x-auto">
                        <button
                            type="button"
                            onclick={() => (showcaseMode = 'split')}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 {showcaseMode === 'split' ? 'bg-white dark:bg-[#1f293d] text-[#4F46E5] dark:text-[#06B6D4] shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                            title="Desliza para comparar Modo Claro y Modo Oscuro"
                        >
                            <span>🌗</span>
                            <span>Comparador (Split)</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => (showcaseMode = 'layered')}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 {showcaseMode === 'layered' ? 'bg-white dark:bg-[#1f293d] text-[#4F46E5] dark:text-[#06B6D4] shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                            title="Composición en capas superpuestas"
                        >
                            <span>✨</span>
                            <span>Vista en Capas</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => (showcaseMode = 'light')}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 {showcaseMode === 'light' ? 'bg-white dark:bg-[#1f293d] text-[#4F46E5] dark:text-[#06B6D4] shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                            title="Ver únicamente la versión en Modo Claro"
                        >
                            <span>☀️</span>
                            <span>Solo Claro</span>
                        </button>

                        <button
                            type="button"
                            onclick={() => (showcaseMode = 'dark')}
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 {showcaseMode === 'dark' ? 'bg-white dark:bg-[#1f293d] text-[#4F46E5] dark:text-[#06B6D4] shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'}"
                            title="Ver únicamente la versión en Modo Oscuro"
                        >
                            <span>🌙</span>
                            <span>Solo Oscuro</span>
                        </button>
                    </div>
                </div>

                <!-- Contenedor Principal del Marco de Navegador Mac/SaaS -->
                <div class="relative group">
                    
                    <!-- Tarjeta Flotante 1 (Arriba a la Derecha): Pedido #3 Pagado -->
                    <div class="floating-badge-top absolute -top-5 sm:-top-7 -right-3 sm:-right-6 z-40 hidden md:flex items-center gap-3 p-3.5 bg-white/95 dark:bg-[#151D2C]/95 backdrop-blur-xl border border-slate-200/90 dark:border-[#26334D] rounded-2xl shadow-2xl shadow-[#4F46E5]/15 pointer-events-auto">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-lg shrink-0">
                            📦
                        </div>
                        <div class="text-left">
                            <div class="text-xs font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                <span>Pedido #3</span>
                                <span class="text-slate-400 font-normal truncate max-w-[150px]">• María F. Gómez</span>
                            </div>
                            <div class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 flex items-center gap-2 mt-0.5">
                                <span>✓ Pagado · $58</span>
                                <span class="px-1.5 py-0.5 rounded bg-emerald-500/15 text-[10px] font-bold">Listo para Entrega</span>
                            </div>
                        </div>
                    </div>

                    <!-- Tarjeta Flotante 2 (Abajo a la Izquierda): Capacidad del Taller -->
                    <div class="floating-badge-bottom absolute -bottom-5 sm:-bottom-7 -left-3 sm:-left-6 z-40 hidden md:flex items-center gap-3 p-3.5 bg-white/95 dark:bg-[#151D2C]/95 backdrop-blur-xl border border-slate-200/90 dark:border-[#26334D] rounded-2xl shadow-2xl shadow-[#06B6D4]/15 pointer-events-auto">
                        <div class="w-10 h-10 rounded-xl bg-cyan-500/15 border border-cyan-500/30 flex items-center justify-center text-lg shrink-0">
                            ⚡
                        </div>
                        <div class="text-left">
                            <div class="text-xs font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                <span>Capacidad en Tiempo Real</span>
                                <span class="relative flex h-2 w-2">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#06B6D4] opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2 w-2 bg-[#06B6D4]"></span>
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-500 dark:text-slate-300 mt-0.5">
                                2 Pedidos en cola • 100% Trazabilidad Kanban
                            </div>
                        </div>
                    </div>

                    <!-- Marco de Navegador Estilo macOS / SaaS para Split, Solo Claro y Solo Oscuro -->
                    {#if showcaseMode !== 'layered'}
                        <div class="rounded-2xl sm:rounded-3xl border border-slate-200/80 dark:border-[#26334D] bg-white dark:bg-[#151D2C] shadow-2xl shadow-slate-900/10 dark:shadow-black/50 overflow-hidden ring-1 ring-slate-900/5">
                            
                            <!-- Barra Superior de Navegador con 3 Puntos y URL -->
                            <div class="h-11 sm:h-12 bg-slate-100/90 dark:bg-[#0B0F17]/90 px-4 flex items-center justify-between border-b border-slate-200/80 dark:border-[#26334D]">
                                <!-- Puntos Mac -->
                                <div class="flex items-center gap-2 w-20">
                                    <span class="w-3 h-3 rounded-full bg-[#EF4444] inline-block shadow-sm"></span>
                                    <span class="w-3 h-3 rounded-full bg-[#F59E0B] inline-block shadow-sm"></span>
                                    <span class="w-3 h-3 rounded-full bg-[#10B981] inline-block shadow-sm"></span>
                                </div>

                                <!-- Barra de URL Simulada -->
                                <div class="flex-1 max-w-sm sm:max-w-md mx-auto">
                                    <div class="bg-white dark:bg-[#151D2C] rounded-lg px-3 py-1 flex items-center justify-center gap-2 text-xs font-mono text-slate-500 dark:text-slate-400 border border-slate-200/60 dark:border-[#26334D]/70 shadow-inner">
                                        <svg class="w-3.5 h-3.5 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                        </svg>
                                        <span class="truncate">https://app.gippyme.com/dashboard</span>
                                        <span class="text-slate-300 dark:text-slate-600 hidden sm:inline">↻</span>
                                    </div>
                                </div>

                                <!-- Estado del Taller en la barra -->
                                <div class="w-20 text-right">
                                    <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 bg-slate-200/60 dark:bg-[#1f293d] px-2 py-0.5 rounded-full uppercase tracking-wider hidden sm:inline">
                                        En Vivo
                                    </span>
                                </div>
                            </div>

                            <!-- ========================================================= -->
                            <!-- ÁREA VISUAL SEGÚN EL MODO SELECCIONADO                    -->
                            <!-- ========================================================= -->

                            <!-- MODO 1: COMPARADOR INTERACTIVO SPLIT (Por Defecto) -->
                            {#if showcaseMode === 'split'}
                                <div
                                    bind:this={sliderContainerRef}
                                    onpointerdown={onPointerDown}
                                    role="presentation"
                                    class="relative select-none overflow-hidden cursor-ew-resize bg-slate-950 aspect-[16/9] w-full"
                                >
                                    <!-- Imagen Fondo: MODO OSCURO -->
                                    {#if !darkImgFailed}
                                        <img
                                            src="/images/landing/dashboard-dark.png"
                                            alt="Panel de Pedidos gipPyme en Modo Oscuro"
                                            onerror={() => (darkImgFailed = true)}
                                            class="w-full h-full object-cover sm:object-contain pointer-events-none select-none block"
                                        />
                                    {:else}
                                        <!-- Mockup Fallback Modo Oscuro -->
                                        <div class="w-full h-full p-6 bg-[#0B0F17] text-white flex flex-col justify-between">
                                            <div class="flex justify-between items-center border-b border-[#26334D] pb-3">
                                                <div class="font-bold text-lg">Panel de Pedidos • Modo Oscuro</div>
                                                <div class="text-xs bg-[#4F46E5] px-2 py-1 rounded">3 Pedidos Activos</div>
                                            </div>
                                            <div class="grid grid-cols-4 gap-3 my-4">
                                                <div class="p-3 bg-[#151D2C] rounded-xl border border-[#26334D]">Total: 3</div>
                                                <div class="p-3 bg-[#151D2C] rounded-xl border border-[#26334D]">Ingresos: $775</div>
                                                <div class="p-3 bg-[#151D2C] rounded-xl border border-[#26334D]">Pendientes: 2</div>
                                                <div class="p-3 bg-[#151D2C] rounded-xl border border-[#26334D]">Clientes: 3</div>
                                            </div>
                                            <div class="text-xs text-slate-400">Captura real cargada desde /images/landing/dashboard-dark.png</div>
                                        </div>
                                    {/if}

                                    <!-- Imagen Superpuesta Recortada Dinámicamente: MODO CLARO -->
                                    <div
                                        class="absolute inset-0 pointer-events-none overflow-hidden"
                                        style="clip-path: inset(0 {100 - sliderPos}% 0 0);"
                                    >
                                        {#if !lightImgFailed}
                                            <img
                                                src="/images/landing/dashboard-light.png"
                                                alt="Panel de Pedidos gipPyme en Modo Claro"
                                                onerror={() => (lightImgFailed = true)}
                                                class="w-full h-full object-cover sm:object-contain pointer-events-none select-none block"
                                            />
                                        {:else}
                                            <!-- Mockup Fallback Modo Claro -->
                                            <div class="w-full h-full p-6 bg-[#F8FAFC] text-slate-800 flex flex-col justify-between">
                                                <div class="flex justify-between items-center border-b border-slate-200 pb-3">
                                                    <div class="font-bold text-lg text-slate-900">Panel de Pedidos • Modo Claro</div>
                                                    <div class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded">3 Pedidos Activos</div>
                                                </div>
                                                <div class="grid grid-cols-4 gap-3 my-4">
                                                    <div class="p-3 bg-white rounded-xl border border-slate-200 shadow-sm">Total: 3</div>
                                                    <div class="p-3 bg-white rounded-xl border border-slate-200 shadow-sm">Ingresos: $775</div>
                                                    <div class="p-3 bg-white rounded-xl border border-slate-200 shadow-sm">Pendientes: 2</div>
                                                    <div class="p-3 bg-white rounded-xl border border-slate-200 shadow-sm">Clientes: 3</div>
                                                </div>
                                                <div class="text-xs text-slate-500">Captura real cargada desde /images/landing/dashboard-light.png</div>
                                            </div>
                                        {/if}
                                    </div>

                                    <!-- Línea Divisoria y Tirador de Arrastre -->
                                    <div
                                        class="absolute top-0 bottom-0 pointer-events-none z-30"
                                        style="left: {sliderPos}%;"
                                    >
                                        <!-- Línea vertical luminosa -->
                                        <div class="w-0.5 h-full bg-white dark:bg-[#4F46E5] shadow-[0_0_12px_rgba(79,70,229,0.9)] -translate-x-1/2"></div>
                                        
                                        <!-- Botón circular central con flechas opuestas -->
                                        <div
                                            class="absolute top-1/2 -translate-y-1/2 -translate-x-1/2 w-10 sm:w-11 h-10 sm:h-11 rounded-full bg-[#4F46E5] text-white shadow-xl shadow-[#4F46E5]/60 flex items-center justify-center font-bold text-sm border-2 border-white dark:border-[#0B0F17] hover:scale-110 active:scale-95 transition-transform"
                                        >
                                            <span class="select-none tracking-tighter text-xs sm:text-sm font-sans">◀ ▶</span>
                                        </div>
                                    </div>

                                    <!-- Etiquetas Flotantes en el Comparador -->
                                    <div class="absolute top-3.5 left-3.5 z-20 pointer-events-none">
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold bg-white/90 text-slate-800 shadow-md backdrop-blur-md border border-slate-200/80">
                                            ☀️ Modo Claro
                                        </span>
                                    </div>
                                    <div class="absolute top-3.5 right-3.5 z-20 pointer-events-none">
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold bg-[#0B0F17]/90 text-white shadow-md backdrop-blur-md border border-[#26334D]/80">
                                            🌙 Modo Oscuro
                                        </span>
                                    </div>

                                    <!-- Input Range transparente para máxima accesibilidad y soporte táctil fluido -->
                                    <input
                                        type="range"
                                        min="2"
                                        max="98"
                                        bind:value={sliderPos}
                                        class="absolute inset-0 w-full h-full opacity-0 cursor-ew-resize z-20"
                                        aria-label="Arrastra para comparar Modo Claro y Modo Oscuro"
                                    />
                                </div>

                            <!-- MODO 3A: SOLO CLARO A PANTALLA COMPLETA -->
                            {:else if showcaseMode === 'light'}
                                <div class="relative bg-white aspect-[16/9] w-full">
                                    <img
                                        src="/images/landing/dashboard-light.png"
                                        alt="Panel de Pedidos en Modo Claro Completo"
                                        class="w-full h-full object-cover sm:object-contain block select-none"
                                    />
                                    <div class="absolute bottom-4 right-4">
                                        <span class="px-3 py-1 rounded-lg text-xs font-bold bg-white/95 text-slate-800 shadow-md border border-slate-200/80">
                                            ☀️ Vista 100% Modo Claro
                                        </span>
                                    </div>
                                </div>

                            <!-- MODO 3B: SOLO OSCURO A PANTALLA COMPLETA -->
                            {:else if showcaseMode === 'dark'}
                                <div class="relative bg-[#0B0F17] aspect-[16/9] w-full">
                                    <img
                                        src="/images/landing/dashboard-dark.png"
                                        alt="Panel de Pedidos en Modo Oscuro Completo"
                                        class="w-full h-full object-cover sm:object-contain block select-none"
                                    />
                                    <div class="absolute bottom-4 right-4">
                                        <span class="px-3 py-1 rounded-lg text-xs font-bold bg-[#151D2C]/95 text-white shadow-md border border-[#26334D]">
                                            🌙 Vista 100% Modo Oscuro
                                        </span>
                                    </div>
                                </div>
                            {/if}

                        </div>

                    <!-- MODO 2: VISTA DUAL EN CAPAS CON DESFASE DIAGONAL AMPLIO -->
                    {:else}
                        <div class="relative w-full pb-20 sm:pb-32 pt-4">
                            
                            <!-- CAPA TRASERA (Back Layer — 78% de ancho, arriba a la izquierda) -->
                            <div
                                class="relative z-10 w-[85%] sm:w-[78%] mr-auto rounded-2xl overflow-hidden transition-all duration-500 cursor-pointer opacity-90 hover:opacity-100 hover:-translate-y-1.5 ring-2 ring-[#4F46E5]/40 shadow-xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D]"
                                onclick={toggleLayers}
                                role="button"
                                tabindex="0"
                                onkeydown={(e) => (e.key === 'Enter' || e.key === ' ') && toggleLayers()}
                                title="Clic para traer esta capa al frente"
                            >
                                <!-- Barra Superior de Ventana Capa Trasera -->
                                <div class="h-10 sm:h-11 px-3 sm:px-4 flex items-center justify-between border-b {activeLayer === 'light' ? 'bg-[#0B0F17] border-[#26334D] text-slate-200' : 'bg-slate-100 border-slate-200 text-slate-700'}">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2.5 h-2.5 rounded-full bg-[#EF4444] inline-block shadow-sm"></span>
                                        <span class="w-2.5 h-2.5 rounded-full bg-[#F59E0B] inline-block shadow-sm"></span>
                                        <span class="w-2.5 h-2.5 rounded-full bg-[#10B981] inline-block shadow-sm"></span>
                                        <span class="text-xs font-semibold ml-1.5 flex items-center gap-1.5 {activeLayer === 'light' ? 'text-slate-300' : 'text-slate-700'}">
                                            {#if activeLayer === 'light'}
                                                <span>🌙 Modo Oscuro</span>
                                            {:else}
                                                <span>☀️ Modo Claro</span>
                                            {/if}
                                            <span class="hidden sm:inline text-[11px] opacity-60">• Capa Trasera</span>
                                        </span>
                                    </div>

                                    <button
                                        type="button"
                                        onclick={(e) => { e.stopPropagation(); toggleLayers(); }}
                                        class="bg-[#4F46E5] hover:bg-[#4338CA] text-white text-xs px-3 py-1 rounded-full font-semibold shadow transition-all hover:scale-105 active:scale-95"
                                    >
                                        👆 Clic para traer al frente
                                    </button>
                                </div>

                                <!-- Imagen de la Capa Trasera -->
                                <div class="relative bg-slate-900/5 dark:bg-black/20">
                                    {#if activeLayer === 'light'}
                                        <img
                                            src="/images/landing/dashboard-dark.png"
                                            alt="gipPyme Dashboard Modo Oscuro (Capa Trasera)"
                                            class="w-full h-auto block select-none pointer-events-none"
                                        />
                                    {:else}
                                        <img
                                            src="/images/landing/dashboard-light.png"
                                            alt="gipPyme Dashboard Modo Claro (Capa Trasera)"
                                            class="w-full h-auto block select-none pointer-events-none"
                                        />
                                    {/if}
                                </div>
                            </div>

                            <!-- CAPA FRONTAL (Front Layer — 80% de ancho, desplazada abajo a la derecha) -->
                            <div
                                class="relative z-20 w-[88%] sm:w-[80%] ml-auto -mt-28 sm:-mt-52 lg:-mt-64 rounded-2xl overflow-hidden transition-all duration-500 shadow-[0_30px_80px_-15px_rgba(15,23,42,0.65)] ring-2 ring-[#06B6D4]/70 bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D]"
                            >
                                <!-- Barra Superior de Ventana Capa Frontal -->
                                <div class="h-10 sm:h-12 px-3 sm:px-4 flex items-center justify-between border-b {activeLayer === 'light' ? 'bg-slate-100/90 border-slate-200 text-slate-800' : 'bg-[#0B0F17]/90 border-[#26334D] text-white'}">
                                    <div class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full bg-[#EF4444] inline-block shadow-sm"></span>
                                        <span class="w-3 h-3 rounded-full bg-[#F59E0B] inline-block shadow-sm"></span>
                                        <span class="w-3 h-3 rounded-full bg-[#10B981] inline-block shadow-sm"></span>
                                        <span class="text-xs sm:text-sm font-bold ml-1.5 flex items-center gap-1.5 {activeLayer === 'light' ? 'text-slate-800' : 'text-white'}">
                                            {#if activeLayer === 'light'}
                                                <span>☀️ Modo Claro</span>
                                            {:else}
                                                <span>🌙 Modo Oscuro</span>
                                            {/if}
                                            <span class="text-xs font-semibold text-[#06B6D4] hidden sm:inline">(Al Frente)</span>
                                        </span>
                                    </div>

                                    <!-- URL simulada central en pantallas medianas/grandes -->
                                    <div class="hidden md:flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-mono {activeLayer === 'light' ? 'bg-white border-slate-200 text-slate-600' : 'bg-[#151D2C] border-[#26334D] text-slate-300'} border shadow-inner">
                                        <span class="text-emerald-500 text-xs">🔒</span>
                                        <span>https://app.gippyme.com/dashboard</span>
                                    </div>

                                    <!-- Botón Rápido Invertir Capas -->
                                    <button
                                        type="button"
                                        onclick={toggleLayers}
                                        class="inline-flex items-center gap-1.5 px-3 sm:px-4 py-1 sm:py-1.5 rounded-full text-xs font-semibold bg-[#4F46E5] hover:bg-[#4338CA] text-white shadow-md shadow-[#4F46E5]/30 hover:scale-105 active:scale-95 transition-all cursor-pointer"
                                    >
                                        <span>🔄 Invertir Capas</span>
                                    </button>
                                </div>

                                <!-- Imagen de la Capa Frontal -->
                                <div class="relative bg-slate-900/5 dark:bg-black/20">
                                    {#if activeLayer === 'light'}
                                        <img
                                            src="/images/landing/dashboard-light.png"
                                            alt="gipPyme Dashboard Modo Claro (Capa Frontal)"
                                            class="w-full h-auto block select-none"
                                        />
                                    {:else}
                                        <img
                                            src="/images/landing/dashboard-dark.png"
                                            alt="gipPyme Dashboard Modo Oscuro (Capa Frontal)"
                                            class="w-full h-auto block select-none"
                                        />
                                    {/if}
                                </div>
                            </div>

                        </div>
                    {/if}
                </div>

                <!-- Resumen de Navegación del Escaparate -->
                <p class="mt-4 text-center text-xs text-[#64748B] dark:text-slate-400">
                    {#if showcaseMode === 'layered'}
                        💡 <strong>Tip interactivo:</strong> Haz clic en la ventana trasera o en el botón <strong>"🔄 Invertir Capas"</strong> para alternar qué tema está en primer plano.
                    {:else if showcaseMode === 'split'}
                        💡 <strong>Tip interactivo:</strong> En el modo comparador, arrastra el control ◀ ▶ horizontalmente para ver la perfecta transición entre paleta clara y paleta oscura.
                    {:else}
                        💡 <strong>Tip interactivo:</strong> Puedes alternar entre <strong>Solo Claro</strong>, <strong>Solo Oscuro</strong> o el <strong>Comparador</strong> desde la barra superior.
                    {/if}
                </p>

            </div>

        </div>
    </section>


    <!-- ========================================================================= -->
    <!-- 4. SECCIÓN DE VALOR & MÓDULOS CLAVE (ANCLAJES DE NAVEGACIÓN)              -->
    <!-- ========================================================================= -->
    <section id="modulos" class="py-20 sm:py-28 border-t border-slate-200/70 dark:border-[#26334D]/70 bg-white/50 dark:bg-[#0B0F17]/50 backdrop-blur-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto">
                <span class="text-xs font-bold uppercase tracking-wider text-[#4F46E5] dark:text-[#06B6D4]">
                    Diseñado para la vida real de un taller
                </span>
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white mt-2">
                    Todo lo que necesitas para operar sin desorden
                </h2>
                <p class="mt-4 text-base sm:text-lg text-[#64748B] dark:text-slate-300">
                    gipPyme reemplaza cuadernos, hojas de cálculo dispersas y chats de WhatsApp con un flujo ágil e intuitivo.
                </p>
            </div>

            <!-- Grilla de los 4 Pilares del Sistema -->
            <div class="mt-14 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Tarjeta 1 -->
                <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D] shadow-sm hover:shadow-md transition-shadow">
                    <div class="w-12 h-12 rounded-xl bg-[#4F46E5]/10 text-[#4F46E5] flex items-center justify-center text-2xl mb-5">
                        📋
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Pedidos y Cotizaciones</h3>
                    <p class="mt-2 text-sm text-[#64748B] dark:text-slate-300 leading-relaxed">
                        Registra abonos, saldos contraentrega, estados dinámicos y genera el compromiso con fecha exacta predicha por holgura.
                    </p>
                </div>

                <!-- Tarjeta 2 -->
                <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D] shadow-sm hover:shadow-md transition-shadow">
                    <div class="w-12 h-12 rounded-xl bg-[#06B6D4]/10 text-[#06B6D4] flex items-center justify-center text-2xl mb-5">
                        📊
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Tablero Kanban con US</h3>
                    <p class="mt-2 text-sm text-[#64748B] dark:text-slate-300 leading-relaxed">
                        Los pedidos actúan como carriles Swimlanes y los productos como tareas en movimiento con límites WIP por etapa.
                    </p>
                </div>

                <!-- Tarjeta 3 -->
                <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D] shadow-sm hover:shadow-md transition-shadow">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center text-2xl mb-5">
                        📦
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Materia Prima y BOM</h3>
                    <p class="mt-2 text-sm text-[#64748B] dark:text-slate-300 leading-relaxed">
                        Recetas exactas de consumo por producto, alertas de inventario mínimo y vinculación flexible con proveedores.
                    </p>
                </div>

                <!-- Tarjeta 4 -->
                <div class="p-6 rounded-2xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D] shadow-sm hover:shadow-md transition-shadow">
                    <div class="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-500 flex items-center justify-center text-2xl mb-5">
                        🏷️
                    </div>
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Variantes y Códigos</h3>
                    <p class="mt-2 text-sm text-[#64748B] dark:text-slate-300 leading-relaxed">
                        Maneja tallas, colores o acabados ortogonales, con códigos de barras listos para escáner y despacho rápido.
                    </p>
                </div>
            </div>
        </div>
    </section>


    <!-- ========================================================================= -->
    <!-- 5. SECCIÓN DE FLUJOS KANBAN                                               -->
    <!-- ========================================================================= -->
    <section id="flujos" class="py-20 sm:py-28 border-t border-slate-200/70 dark:border-[#26334D]/70">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-gradient-to-br from-indigo-900/10 via-slate-900/5 to-cyan-900/10 dark:from-[#151D2C] dark:to-[#0B0F17] rounded-3xl p-8 sm:p-14 border border-slate-200/80 dark:border-[#26334D] flex flex-col lg:flex-row items-center justify-between gap-10">
                <div class="max-w-xl text-left">
                    <span class="text-xs font-bold uppercase tracking-wider text-[#06B6D4]">
                        Flujos a la medida de tu producción
                    </span>
                    <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white mt-2">
                        Etapas dinámicas adaptadas a cada tipo de producto
                    </h2>
                    <p class="mt-4 text-base text-[#64748B] dark:text-slate-300 leading-relaxed">
                        Crea flujos específicos: diseño, corte, confección, ensamblado, control de calidad y empaque. Cada columna sincroniza automáticamente el estado global del pedido.
                    </p>

                    <div class="mt-8 flex flex-col sm:flex-row items-center gap-4">
                        <Link
                            href="/register"
                            class="w-full sm:w-auto px-6 py-3 rounded-xl font-bold text-sm text-white bg-[#4F46E5] hover:bg-[#4338CA] shadow-lg shadow-[#4F46E5]/25 transition-all text-center"
                        >
                            Comenzar con Flujos Preconfigurados
                        </Link>
                    </div>
                </div>

                <div class="w-full lg:w-auto flex-1 max-w-lg space-y-3">
                    <div class="p-4 rounded-xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D] shadow-sm flex items-center gap-4">
                        <span class="w-8 h-8 rounded-lg bg-amber-500/20 text-amber-500 font-bold flex items-center justify-center text-sm">1</span>
                        <div>
                            <div class="text-sm font-bold text-slate-900 dark:text-white">Cola Pendiente</div>
                            <div class="text-xs text-slate-500">Cálculo de minutos en cola en base a la jornada de 8 horas.</div>
                        </div>
                    </div>
                    <div class="p-4 rounded-xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D] shadow-sm flex items-center gap-4">
                        <span class="w-8 h-8 rounded-lg bg-indigo-500/20 text-indigo-500 font-bold flex items-center justify-center text-sm">2</span>
                        <div>
                            <div class="text-sm font-bold text-slate-900 dark:text-white">En Producción / Taller</div>
                            <div class="text-xs text-slate-500">Consumo de materia prima y trazabilidad por trabajador.</div>
                        </div>
                    </div>
                    <div class="p-4 rounded-xl bg-white dark:bg-[#151D2C] border border-slate-200/80 dark:border-[#26334D] shadow-sm flex items-center gap-4">
                        <span class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-500 font-bold flex items-center justify-center text-sm">3</span>
                        <div>
                            <div class="text-sm font-bold text-slate-900 dark:text-white">Listo & Despachado</div>
                            <div class="text-xs text-slate-500">Registro de entrega final, cobro de saldo y archivo automático.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>


    <!-- ========================================================================= -->
    <!-- 6. SECCIÓN DE PRECIOS SIMPLE Y TRANSPARENTE                               -->
    <!-- ========================================================================= -->
    <section id="precios" class="py-20 sm:py-28 border-t border-slate-200/70 dark:border-[#26334D]/70 bg-white/40 dark:bg-[#0B0F17]/40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <span class="text-xs font-bold uppercase tracking-wider text-[#4F46E5] dark:text-[#06B6D4]">
                Planes Transparentes
            </span>
            <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-900 dark:text-white mt-2">
                Empieza gratis y escala con tu taller
            </h2>
            <p class="mt-4 text-base text-[#64748B] dark:text-slate-300 max-w-2xl mx-auto">
                Sin tarjetas de crédito obligatorias para probar. Configura tu taller en 2 minutos.
            </p>

            <div class="mt-14 max-w-4xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-8 text-left">
                <!-- Plan Emprendedor / Gratuito -->
                <div class="p-8 rounded-3xl bg-white dark:bg-[#151D2C] border border-slate-200/90 dark:border-[#26334D] shadow-sm flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Prueba & Arranque</span>
                        <h3 class="text-2xl font-bold text-slate-900 dark:text-white mt-1">Plan Taller Semilla</h3>
                        <div class="mt-4 flex items-baseline gap-1">
                            <span class="text-4xl font-extrabold text-slate-900 dark:text-white">$0</span>
                            <span class="text-xs text-slate-500">/ para siempre</span>
                        </div>
                        <ul class="mt-6 space-y-3 text-sm text-slate-600 dark:text-slate-300">
                            <li class="flex items-center gap-2">✓ Hasta 50 pedidos activos</li>
                            <li class="flex items-center gap-2">✓ Tablero Kanban completo</li>
                            <li class="flex items-center gap-2">✓ Catálogo y Variantes</li>
                            <li class="flex items-center gap-2">✓ Control de abonos y saldos</li>
                        </ul>
                    </div>
                    <Link
                        href="/register"
                        class="mt-8 w-full py-3 rounded-xl font-bold text-sm text-center border border-slate-300 dark:border-[#26334D] text-slate-800 dark:text-white hover:bg-slate-100 dark:hover:bg-[#1f293d] transition-colors block"
                    >
                        Comenzar Gratis
                    </Link>
                </div>

                <!-- Plan Taller Pro -->
                <div class="p-8 rounded-3xl bg-gradient-to-b from-indigo-50/50 to-white dark:from-[#1E293B] dark:to-[#151D2C] border-2 border-[#4F46E5] shadow-xl shadow-[#4F46E5]/10 flex flex-col justify-between relative">
                    <span class="absolute -top-3.5 right-6 px-3 py-1 rounded-full text-[11px] font-bold text-white bg-gradient-to-r from-[#4F46E5] to-[#06B6D4]">
                        Recomendado para Talleres
                    </span>
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-[#4F46E5] dark:text-[#06B6D4]">Producción Continua</span>
                        <h3 class="text-2xl font-bold text-slate-900 dark:text-white mt-1">Plan Taller Pro</h3>
                        <div class="mt-4 flex items-baseline gap-1">
                            <span class="text-4xl font-extrabold text-slate-900 dark:text-white">$19</span>
                            <span class="text-xs text-slate-500">USD / mes</span>
                        </div>
                        <ul class="mt-6 space-y-3 text-sm text-slate-600 dark:text-slate-300">
                            <li class="flex items-center gap-2">✓ <strong>Pedidos Ilimitados</strong></li>
                            <li class="flex items-center gap-2">✓ <strong>Múltiples Flujos Kanban</strong></li>
                            <li class="flex items-center gap-2">✓ <strong>Recetas BOM y Kardex</strong></li>
                            <li class="flex items-center gap-2">✓ Códigos de barras y escaneo</li>
                            <li class="flex items-center gap-2">✓ Múltiples trabajadores en taller</li>
                        </ul>
                    </div>
                    <Link
                        href="/register"
                        class="mt-8 w-full py-3 rounded-xl font-bold text-sm text-center text-white bg-[#4F46E5] hover:bg-[#4338CA] shadow-lg shadow-[#4F46E5]/25 transition-all block"
                    >
                        Probar Pro Gratis por 14 Días
                    </Link>
                </div>
            </div>
        </div>
    </section>


    <!-- ========================================================================= -->
    <!-- 7. FOOTER LIMPIO Y ELEGANTE                                               -->
    <!-- ========================================================================= -->
    <footer class="py-12 border-t border-slate-200 dark:border-[#26334D] bg-white dark:bg-[#0B0F17] text-slate-500 text-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-6">
            <div class="flex items-center gap-3">
                <BrandLogo variant="horizontal" size="sm" showSubtitle={false} />
                <span class="text-slate-400">|</span>
                <span>© {new Date().getFullYear()} gipPyme. Todos los derechos reservados.</span>
            </div>

            <div class="flex items-center gap-6">
                <button type="button" onclick={() => scrollToSection('producto')} class="hover:text-[#4F46E5]">Inicio</button>
                <button type="button" onclick={() => scrollToSection('escaparate-demo')} class="hover:text-[#4F46E5]">Capturas</button>
                <button type="button" onclick={() => scrollToSection('modulos')} class="hover:text-[#4F46E5]">Módulos</button>
                <Link href="/login" class="hover:text-[#4F46E5]">Iniciar Sesión</Link>
                <Link href="/register" class="hover:text-[#4F46E5] font-semibold text-slate-800 dark:text-slate-200">Registrarse</Link>
            </div>
        </div>
    </footer>

</div>

<style>
    /* Animaciones suaves para las tarjetas flotantes en desktop */
    @keyframes badgeFloat1 {
        0%, 100% {
            transform: translateY(0px);
        }
        50% {
            transform: translateY(-8px);
        }
    }

    @keyframes badgeFloat2 {
        0%, 100% {
            transform: translateY(0px);
        }
        50% {
            transform: translateY(8px);
        }
    }

    .floating-badge-top {
        animation: badgeFloat1 4.5s ease-in-out infinite;
    }

    .floating-badge-bottom {
        animation: badgeFloat2 5.5s ease-in-out infinite;
    }
</style>