<script>
    import { page, Link, router } from '@inertiajs/svelte';
    import BrandLogo from '../Components/BrandLogo.svelte';

    let { title = 'gipPyme', children } = $props();

    // Estados reactivos locales con Runes de Svelte 5
    let mobileMenuOpen = $state(false);
    let userMenuOpen = $state(false);
    let notificationsOpen = $state(false);
    let flashDismissed = $state(false);
    let isDark = $state(false);
    let searchQuery = $state('');

    // Datos derivados desde las props compartidas de Inertia
    let auth = $derived(page.props.auth);
    let flash = $derived(page.props.flash);
    let counts = $derived(page.props.counts);
    let currentUrl = $derived(page.url);

    // Inicializar y sincronizar tema Claro / Oscuro con localStorage y <html>
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
        }
    });

    function toggleTheme() {
        isDark = !isDark;
        if (isDark) {
            document.documentElement.classList.add('dark');
            localStorage.setItem('theme', 'dark');
        } else {
            document.documentElement.classList.remove('dark');
            localStorage.setItem('theme', 'light');
        }
    }

    // Elementos de navegación vertical estilizada según la especificación
    let navItems = $derived.by(() => {
        const items = [
            {
                name: 'Dashboard',
                href: '/dashboard',
                badge: null,
                icon: 'dashboard',
                subtitle: 'Vista General y Métricas',
            },
            {
                name: 'Pedidos',
                href: '/orders',
                badge: counts?.active_orders ? `${counts.active_orders}` : null,
                badgeColor: 'bg-indigo-500/15 text-brand-indigo dark:text-indigo-400 border border-indigo-500/20',
                icon: 'orders',
                subtitle: 'Make-to-Order y Entregas',
            },
            {
                name: 'Kanban',
                href: '/kanban',
                badge: null,
                icon: 'kanban',
                subtitle: 'Tablero de Producción',
            },
            {
                name: 'Clientes',
                href: '/customers',
                badge: null,
                icon: 'customers',
                subtitle: 'Gestión y Contactos',
            },
            {
                name: 'Catálogo / Productos',
                href: '/products',
                badge: null,
                icon: 'products',
                subtitle: 'Variantes Ortogonales y BOM',
            },
            {
                name: 'Materia Prima',
                href: '/raw-materials',
                badge: counts?.raw_material_alerts > 0 ? `${counts.raw_material_alerts} alerta` : null,
                badgeColor: 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/25',
                icon: 'materials',
                subtitle: 'Inventario y Kardex',
            },
            {
                name: 'Proveedores',
                href: '/suppliers',
                badge: null,
                icon: 'suppliers',
                subtitle: 'Lead Time y Compras',
            },
            {
                name: 'Tipos de Flujos',
                href: '/workflows',
                badge: null,
                icon: 'workflows',
                subtitle: 'Estados Dinámicos Kanban',
            },
            {
                name: 'Ajustes / Configuración',
                href: '/settings',
                badge: null,
                icon: 'settings',
                subtitle: 'Taller, Equipo y Horarios',
            },
        ];

        // Módulo exclusivo SuperAdmin para Administradores de la Plataforma
        if (auth?.user?.is_super_admin) {
            items.push({
                name: 'Panel Admin SaaS',
                href: '/admin',
                badge: 'Admin',
                badgeColor: 'bg-purple-500/20 text-purple-700 dark:text-purple-300 border border-purple-500/30',
                icon: 'admin',
                subtitle: 'Gestión Global de Tenants',
            });
        }

        return items;
    });

    function isCurrent(href) {
        if (href === '/dashboard' && (currentUrl === '/dashboard' || currentUrl === '/')) {
            return true;
        }
        if (href === '/kanban' && currentUrl.startsWith('/kanban')) {
            return true;
        }
        if (href !== '/dashboard' && href !== '/kanban' && currentUrl.startsWith(href)) {
            return true;
        }
        return false;
    }

    function switchTenant(tenantId) {
        if (!tenantId) return;
        router.post(`/admin/switch-tenant/${tenantId}`);
    }

    function logout() {
        router.post('/logout');
    }

    function handleGlobalSearch(e) {
        e.preventDefault();
        if (!searchQuery.trim()) return;
        // Redirige al módulo de pedidos o productos con el filtro de búsqueda
        router.get('/orders', { search: searchQuery.trim() });
    }

    // Iniciales del usuario para avatar
    let userInitials = $derived.by(() => {
        if (!auth?.user?.name) return 'GP';
        const parts = auth.user.name.trim().split(' ');
        if (parts.length >= 2) {
            return (parts[0][0] + parts[1][0]).toUpperCase();
        }
        return auth.user.name.slice(0, 2).toUpperCase();
    });
</script>

<svelte:head>
    <title>{title ? `${title} · gipPyme` : 'gipPyme · Gestor Inteligente de Pedidos'}</title>
</svelte:head>

<!-- CONTENEDOR RAÍZ: Fondo #F1F5F9 (brand-ice) en Claro y #0B0F17 (dark-bg) en Oscuro -->
<div class="min-h-screen bg-brand-ice dark:bg-dark-bg text-slate-800 dark:text-slate-100 flex antialiased font-sans transition-colors duration-200">
    
    <!-- ========================================================================= -->
    <!-- SIDEBAR IZQUIERDO (ESCRITORIO) -->
    <!-- ========================================================================= -->
    <aside class="hidden md:flex flex-col w-72 bg-white dark:bg-dark-surface border-r border-slate-200/80 dark:border-dark-border shrink-0 z-30 transition-colors duration-200 shadow-xs">
        <!-- 1. Cabecera con BrandLogo.svelte -->
        <div class="h-20 px-5 flex items-center justify-between border-b border-slate-100 dark:border-dark-border">
            <Link href="/dashboard" class="flex items-center group">
                <BrandLogo size="md" layout="horizontal" showSubtitle={true} />
            </Link>
        </div>

        <!-- 2. Información del Taller / Tenant Activo -->
        <div class="p-3.5 mx-3.5 my-3 rounded-xl bg-slate-50 dark:bg-dark-bg/60 border border-slate-200/70 dark:border-dark-border">
            <div class="flex items-center justify-between text-xs mb-1">
                <div class="flex items-center gap-2">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-cyan opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-brand-cyan"></span>
                    </span>
                    <span class="uppercase tracking-wider font-bold text-[10px] text-slate-500 dark:text-slate-400">Taller Activo</span>
                </div>
                {#if auth?.user?.is_super_admin}
                    <span class="text-[9px] px-1.5 py-0.5 rounded font-extrabold uppercase tracking-wider bg-purple-500/15 text-purple-700 dark:text-purple-300 border border-purple-500/25">SuperAdmin</span>
                {/if}
            </div>
            <h2 class="font-bold text-sm text-slate-900 dark:text-slate-100 truncate" title={auth?.tenant?.name}>
                {auth?.tenant?.name ?? 'Mi Taller'}
            </h2>
            <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400 border-t border-slate-200/60 dark:border-dark-border pt-1.5">
                <span>Capacidad taller:</span>
                <span class="font-mono font-semibold text-brand-indigo dark:text-indigo-400">{auth?.tenant?.daily_hours ?? 8}h / día</span>
            </div>

            <!-- Selector de taller rápido si es SuperAdmin -->
            {#if auth?.user?.is_super_admin && auth?.all_tenants && auth?.all_tenants.length > 0}
                <div class="mt-2.5 pt-2 border-t border-slate-200/60 dark:border-dark-border">
                    <label for="switch-tenant-select" class="text-[9px] uppercase font-bold text-purple-600 dark:text-purple-400 block mb-1">
                        Cambiar Taller:
                    </label>
                    <select
                        id="switch-tenant-select"
                        value={auth.tenant?.id}
                        onchange={(e) => switchTenant(e.target.value)}
                        class="w-full bg-white dark:bg-dark-surface border border-purple-300 dark:border-purple-500/40 rounded-lg px-2 py-1 text-slate-900 dark:text-white text-xs focus:ring-1 focus:ring-purple-500"
                    >
                        {#each auth.all_tenants as at}
                            <option value={at.id}>{at.name}</option>
                        {/each}
                    </select>
                </div>
            {/if}
        </div>

        <!-- 3. Navegación vertical estilizada con enlaces tipo píldora -->
        <nav class="flex-1 px-3 space-y-1 overflow-y-auto py-2">
            <div class="px-3 pb-1.5 text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider">
                Módulos de Gestión
            </div>

            {#each navItems as item}
                {@const active = isCurrent(item.href)}
                <Link
                    href={item.href}
                    class="group flex items-center justify-between px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-150 {active
                        ? 'bg-indigo-50/90 dark:bg-indigo-500/10 border border-indigo-200/90 dark:border-indigo-500/30 text-brand-indigo dark:text-indigo-300 font-semibold shadow-xs'
                        : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100/80 dark:hover:bg-dark-surface-elevated hover:text-slate-900 dark:hover:text-slate-100 border border-transparent'}"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <!-- Iconos lineales SVG limpios -->
                        <div class="w-5 h-5 flex items-center justify-center shrink-0 {active ? 'text-brand-indigo dark:text-brand-cyan' : 'text-slate-400 group-hover:text-slate-600 dark:group-hover:text-slate-300'} transition-colors">
                            {#if item.icon === 'dashboard'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                                </svg>
                            {:else if item.icon === 'orders'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                                </svg>
                            {:else if item.icon === 'kanban'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125z" />
                                </svg>
                            {:else if item.icon === 'customers'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                </svg>
                            {:else if item.icon === 'products'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                                </svg>
                            {:else if item.icon === 'materials'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0l4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0l-5.571 3-5.571-3" />
                                </svg>
                            {:else if item.icon === 'suppliers'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V3.75A1.125 1.125 0 0013.125 2.625H3.375A1.125 1.125 0 002.25 3.75v10.5m14.25-6.75h-2.25" />
                                </svg>
                            {:else if item.icon === 'workflows'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                                </svg>
                            {:else if item.icon === 'settings'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            {:else if item.icon === 'admin'}
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                </svg>
                            {/if}
                        </div>

                        <div class="truncate">
                            <span class="block truncate text-xs font-semibold">{item.name}</span>
                            <span class="block text-[10px] {active ? 'text-indigo-600/80 dark:text-indigo-400/80' : 'text-slate-400 dark:text-slate-500'} font-normal truncate">{item.subtitle}</span>
                        </div>
                    </div>

                    {#if item.badge}
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider shrink-0 {item.badgeColor ?? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-300'}">
                            {item.badge}
                        </span>
                    {/if}
                </Link>
            {/each}
        </nav>

        <!-- 4. Tarjeta de Perfil de Usuario en el pie del Sidebar -->
        <div class="p-3 border-t border-slate-200/80 dark:border-dark-border bg-slate-50/50 dark:bg-dark-surface/50">
            <div class="p-2.5 rounded-xl bg-white dark:bg-dark-surface-elevated/70 border border-slate-200/70 dark:border-dark-border flex items-center justify-between gap-2 shadow-xs">
                <!-- Avatar e Información del Usuario -->
                <Link href="/settings" class="flex items-center gap-2.5 min-w-0 group hover:opacity-90 transition-opacity">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-tr from-brand-indigo to-brand-cyan flex items-center justify-center font-bold text-xs text-white shadow-xs shrink-0 ring-2 ring-indigo-500/20">
                        {userInitials}
                    </div>
                    <div class="truncate">
                        <p class="text-xs font-bold text-slate-900 dark:text-slate-100 truncate group-hover:text-brand-indigo dark:group-hover:text-brand-cyan transition-colors">
                            {auth?.user?.name ?? 'Usuario'}
                        </p>
                        <div class="flex items-center gap-1 mt-0.5">
                            {#if auth?.user?.is_super_admin}
                                <span class="text-[9px] px-1.5 py-0.2 rounded font-bold uppercase tracking-wider bg-purple-500/15 text-purple-700 dark:text-purple-300">Admin</span>
                            {:else if auth?.user?.role === 'owner'}
                                <span class="text-[9px] px-1.5 py-0.2 rounded font-bold uppercase tracking-wider bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">Dueño</span>
                            {:else}
                                <span class="text-[9px] px-1.5 py-0.2 rounded font-bold uppercase tracking-wider bg-sky-500/15 text-sky-700 dark:text-sky-300">Trabajador</span>
                            {/if}
                            <span class="text-[10px] text-slate-400 truncate max-w-[70px]">· {auth?.tenant?.name ?? 'Taller'}</span>
                        </div>
                    </div>
                </Link>

                <!-- Botón rápido de cierre de sesión -->
                <button
                    type="button"
                    onclick={logout}
                    class="p-2 rounded-lg text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors shrink-0"
                    title="Cerrar Sesión"
                    aria-label="Cerrar Sesión"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                    </svg>
                </button>
            </div>
        </div>
    </aside>

    <!-- ========================================================================= -->
    <!-- ÁREA PRINCIPAL DERECHA (TOPBAR + CONTENIDO) -->
    <!-- ========================================================================= -->
    <div class="flex-1 flex flex-col min-w-0 min-h-screen">
        
        <!-- HEADER / TOPBAR SUPERIOR -->
        <header class="h-16 px-4 md:px-6 bg-white/80 dark:bg-dark-surface/80 backdrop-blur-md border-b border-slate-200/80 dark:border-dark-border flex items-center justify-between gap-4 sticky top-0 z-20 transition-colors duration-200 shadow-xs">
            
            <!-- Izquierda: Botón hamburguesa móvil + Buscador global -->
            <div class="flex items-center gap-3 flex-1 max-w-xl">
                <!-- Botón Menú Móvil -->
                <button
                    type="button"
                    onclick={() => mobileMenuOpen = !mobileMenuOpen}
                    class="md:hidden p-2 rounded-xl border border-slate-200 dark:border-dark-border text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-dark-surface-elevated"
                    aria-label="Abrir Menú"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <!-- Logotipo en Móvil -->
                <div class="md:hidden">
                    <BrandLogo size="xs" layout="compact" showSubtitle={false} />
                </div>

                <!-- Barra de búsqueda global integrada -->
                <form onsubmit={handleGlobalSearch} class="hidden sm:flex items-center w-full relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>
                    <input
                        type="text"
                        bind:value={searchQuery}
                        placeholder="Búsqueda global... (pedidos, clientes, catálogo)"
                        class="w-full pl-9 pr-14 py-2 bg-slate-100/80 dark:bg-dark-bg text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 text-xs rounded-xl border border-slate-200/80 dark:border-dark-border focus:outline-none focus:border-brand-indigo dark:focus:border-brand-cyan focus:ring-2 focus:ring-brand-indigo/15 dark:focus:ring-brand-cyan/20 transition-all"
                    />
                    <div class="absolute inset-y-0 right-0 pr-2 flex items-center pointer-events-none">
                        <kbd class="px-1.5 py-0.5 text-[9px] font-mono font-semibold text-slate-400 bg-white dark:bg-dark-surface rounded border border-slate-200 dark:border-dark-border">
                            Ctrl+K
                        </kbd>
                    </div>
                </form>
            </div>

            <!-- Derecha: Acciones rápidas, Selector Claro/Oscuro, Campana y Avatar -->
            <div class="flex items-center gap-2 md:gap-3">
                <!-- Selector rápido de tema Claro / Oscuro (Sol / Luna) -->
                <button
                    type="button"
                    onclick={toggleTheme}
                    class="p-2 rounded-xl border border-slate-200/80 dark:border-dark-border bg-slate-50 dark:bg-dark-bg text-slate-600 dark:text-slate-300 hover:text-brand-indigo dark:hover:text-brand-cyan hover:border-brand-indigo/40 transition-all relative group"
                    title={isDark ? "Cambiar a Modo Claro" : "Cambiar a Modo Oscuro"}
                    aria-label="Alternar tema claro y oscuro"
                >
                    {#if isDark}
                        <!-- Icono Sol (para pasar a claro) -->
                        <svg class="w-4 h-4 text-amber-400 animate-spin-slow" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                        </svg>
                    {:else}
                        <!-- Icono Luna (para pasar a oscuro) -->
                        <svg class="w-4 h-4 text-slate-600 hover:text-brand-indigo" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                        </svg>
                    {/if}
                </button>

                <!-- Campana de Notificaciones -->
                <div class="relative">
                    <button
                        type="button"
                        onclick={() => notificationsOpen = !notificationsOpen}
                        class="p-2 rounded-xl border border-slate-200/80 dark:border-dark-border bg-slate-50 dark:bg-dark-bg text-slate-600 dark:text-slate-300 hover:text-brand-indigo dark:hover:text-brand-cyan hover:border-brand-indigo/40 transition-all relative"
                        aria-label="Notificaciones"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                        </svg>
                        {#if (counts?.raw_material_alerts ?? 0) > 0 || (counts?.active_orders ?? 0) > 0}
                            <span class="absolute top-1.5 right-1.5 w-2 h-2 rounded-full bg-brand-cyan"></span>
                        {/if}
                    </button>

                    <!-- Dropdown de Notificaciones -->
                    {#if notificationsOpen}
                        <div class="absolute right-0 mt-2 w-80 rounded-2xl bg-white dark:bg-dark-surface border border-slate-200 dark:border-dark-border shadow-xl py-3 z-50">
                            <div class="px-4 pb-2 border-b border-slate-100 dark:border-dark-border flex items-center justify-between">
                                <span class="font-bold text-xs text-slate-800 dark:text-slate-200">Notificaciones y Alertas</span>
                                <span class="text-[10px] text-brand-cyan font-bold uppercase tracking-wider">gipPyme</span>
                            </div>
                            <div class="p-3 space-y-2 text-xs">
                                {#if (counts?.raw_material_alerts ?? 0) > 0}
                                    <Link href="/raw-materials" onclick={() => notificationsOpen = false} class="block p-2.5 rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200/80 dark:border-rose-900/40 text-rose-800 dark:text-rose-200 hover:opacity-90">
                                        <div class="font-bold flex items-center gap-1.5">
                                            <span>⚠️ Alerta de Stock Mínimo</span>
                                        </div>
                                        <p class="text-[11px] text-rose-600 dark:text-rose-300 mt-0.5">
                                            {counts.raw_material_alerts} materiales han alcanzado el umbral de reposición.
                                        </p>
                                    </Link>
                                {/if}
                                {#if (counts?.active_orders ?? 0) > 0}
                                    <Link href="/orders" onclick={() => notificationsOpen = false} class="block p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/30 border border-indigo-200/80 dark:border-indigo-900/40 text-indigo-800 dark:text-indigo-200 hover:opacity-90">
                                        <div class="font-bold flex items-center gap-1.5">
                                            <span>📦 Pedidos en Proceso</span>
                                        </div>
                                        <p class="text-[11px] text-indigo-600 dark:text-indigo-300 mt-0.5">
                                            {counts.active_orders} pedidos activos en el flujo de producción Make-to-Order.
                                        </p>
                                    </Link>
                                {:else}
                                    <p class="text-center text-slate-400 py-3 text-xs">Sin notificaciones pendientes.</p>
                                {/if}
                            </div>
                        </div>
                    {/if}
                </div>

                <!-- Separador vertical -->
                <div class="h-6 w-px bg-slate-200 dark:bg-dark-border mx-1"></div>

                <!-- Avatar de usuario con Menú Desplegable -->
                <div class="relative">
                    <button
                        type="button"
                        onclick={() => userMenuOpen = !userMenuOpen}
                        class="flex items-center gap-2 p-1.5 rounded-xl border border-transparent hover:border-slate-200 dark:hover:border-dark-border hover:bg-slate-50 dark:hover:bg-dark-surface transition-all"
                    >
                        <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-brand-indigo to-brand-cyan flex items-center justify-center font-bold text-xs text-white shadow-xs">
                            {userInitials}
                        </div>
                        <span class="hidden lg:block text-xs font-bold text-slate-700 dark:text-slate-200 max-w-[120px] truncate">
                            {auth?.user?.name ?? 'Cuenta'}
                        </span>
                        <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <!-- Dropdown de Cuenta -->
                    {#if userMenuOpen}
                        <div class="absolute right-0 mt-2 w-56 rounded-2xl bg-white dark:bg-dark-surface border border-slate-200 dark:border-dark-border shadow-xl py-2 z-50">
                            <div class="px-4 py-2 border-b border-slate-100 dark:border-dark-border">
                                <p class="text-xs font-bold text-slate-900 dark:text-slate-100 truncate">{auth?.user?.name}</p>
                                <p class="text-[11px] text-slate-400 truncate">{auth?.user?.email}</p>
                            </div>
                            <div class="py-1">
                                <Link
                                    href="/settings"
                                    onclick={() => userMenuOpen = false}
                                    class="flex items-center gap-2 px-4 py-2 text-xs text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-dark-surface-elevated transition-colors"
                                >
                                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    <span>Mi Perfil y Taller</span>
                                </Link>
                                {#if auth?.user?.is_super_admin}
                                    <Link
                                        href="/admin"
                                        onclick={() => userMenuOpen = false}
                                        class="flex items-center gap-2 px-4 py-2 text-xs text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-950/30 transition-colors"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                        </svg>
                                        <span>Panel SuperAdmin</span>
                                    </Link>
                                {/if}
                            </div>
                            <div class="border-t border-slate-100 dark:border-dark-border pt-1">
                                <button
                                    type="button"
                                    onclick={() => { userMenuOpen = false; logout(); }}
                                    class="w-full flex items-center gap-2 px-4 py-2 text-xs text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors text-left"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                                    </svg>
                                    <span>Cerrar Sesión</span>
                                </button>
                            </div>
                        </div>
                    {/if}
                </div>
            </div>
        </header>

        <!-- DRAWER MÓVIL DESPLEGABLE -->
        {#if mobileMenuOpen}
            <div class="md:hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex flex-col">
                <div class="bg-white dark:bg-dark-surface border-b border-slate-200 dark:border-dark-border p-4 flex items-center justify-between">
                    <BrandLogo size="sm" layout="horizontal" />
                    <button
                        type="button"
                        onclick={() => mobileMenuOpen = false}
                        class="p-2 rounded-xl bg-slate-100 dark:bg-dark-bg text-slate-500 hover:text-slate-900 dark:hover:text-white"
                    >
                        ✕
                    </button>
                </div>
                <nav class="p-4 space-y-1.5 overflow-y-auto flex-1 bg-white dark:bg-dark-surface">
                    {#each navItems as item}
                        {@const active = isCurrent(item.href)}
                        <Link
                            href={item.href}
                            onclick={() => mobileMenuOpen = false}
                            class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-medium {active
                                ? 'bg-indigo-50 dark:bg-indigo-500/15 text-brand-indigo dark:text-indigo-300 font-bold border border-indigo-200/80 dark:border-indigo-500/30'
                                : 'text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-dark-surface-elevated'}"
                        >
                            <div>
                                <span>{item.name}</span>
                                <span class="block text-xs text-slate-400 dark:text-slate-500">{item.subtitle}</span>
                            </div>
                            {#if item.badge}
                                <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {item.badgeColor ?? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-300'}">
                                    {item.badge}
                                </span>
                            {/if}
                        </Link>
                    {/each}

                    <div class="pt-4 border-t border-slate-200 dark:border-dark-border">
                        <button
                            type="button"
                            onclick={logout}
                            class="w-full p-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-500/30 text-rose-700 dark:text-rose-300 font-bold text-xs text-center"
                        >
                            Cerrar Sesión
                        </button>
                    </div>
                </nav>
            </div>
        {/if}

        <!-- CONTENEDOR PRINCIPAL: Padding suave y tarjetas redondeadas rounded-2xl -->
        <main class="flex-1 flex flex-col min-w-0 overflow-y-auto">
            <!-- NOTIFICACIONES FLASH -->
            {#if (flash?.success || flash?.error) && !flashDismissed}
                <div class="px-4 md:px-8 pt-4 pb-0 max-w-7xl w-full mx-auto">
                    {#if flash?.success}
                        <div class="bg-emerald-500/10 border border-emerald-500/25 text-emerald-800 dark:text-emerald-300 px-4 py-3 rounded-2xl flex items-center justify-between text-xs shadow-xs backdrop-blur-xs">
                            <div class="flex items-center gap-2.5">
                                <span class="w-6 h-6 rounded-full bg-emerald-500 text-white flex items-center justify-center font-bold text-xs shrink-0">✓</span>
                                <span class="font-medium">{flash.success}</span>
                            </div>
                            <button type="button" onclick={() => flashDismissed = true} class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-900 dark:hover:text-white font-bold text-xs px-2 py-1">Cerrar</button>
                        </div>
                    {/if}
                    {#if flash?.error}
                        <div class="bg-rose-500/10 border border-rose-500/25 text-rose-800 dark:text-rose-300 px-4 py-3 rounded-2xl flex items-center justify-between text-xs shadow-xs backdrop-blur-xs">
                            <div class="flex items-center gap-2.5">
                                <span class="w-6 h-6 rounded-full bg-rose-500 text-white flex items-center justify-center font-bold text-xs shrink-0">⚠</span>
                                <span class="font-medium">{flash.error}</span>
                            </div>
                            <button type="button" onclick={() => flashDismissed = true} class="text-rose-600 dark:text-rose-400 hover:text-rose-900 dark:hover:text-white font-bold text-xs px-2 py-1">Cerrar</button>
                        </div>
                    {/if}
                </div>
            {/if}

            <!-- CONTENIDO DE LAS VISTAS -->
            <div class="flex-1 p-4 md:p-6 lg:p-8 w-full max-w-7xl mx-auto">
                {@render children?.()}
            </div>
        </main>
    </div>
</div>
