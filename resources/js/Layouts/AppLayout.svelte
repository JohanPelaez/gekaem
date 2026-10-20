<script>
    import { page, Link, router } from '@inertiajs/svelte';

    let { title = 'Gekaem', children } = $props();

    let mobileMenuOpen = $state(false);
    let flashDismissed = $state(false);

    let auth = $derived(page.props.auth);
    let flash = $derived(page.props.flash);
    let counts = $derived(page.props.counts);
    let currentUrl = $derived(page.url);

    let navItems = $derived.by(() => {
        const items = [
            {
                name: 'Tablero Kanban',
                href: '/kanban',
                badge: null,
                icon: 'kanban',
                subtitle: 'Módulo 5',
            },
            {
                name: 'Pedidos (US)',
                href: '/orders',
                badge: counts?.active_orders ? `${counts.active_orders} activos` : null,
                badgeColor: 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30',
                icon: 'orders',
                subtitle: 'Módulo 1',
            },
            {
                name: 'Productos y Variantes',
                href: '/products',
                badge: null,
                icon: 'products',
                subtitle: 'Módulo 2',
            },
            {
                name: 'Materia Prima',
                href: '/raw-materials',
                badge: counts?.raw_material_alerts > 0 ? `${counts.raw_material_alerts} alerta` : null,
                badgeColor: 'bg-rose-500/20 text-rose-300 border border-rose-500/30',
                icon: 'materials',
                subtitle: 'Módulo 6',
            },
            {
                name: 'Tipos de Flujos',
                href: '/workflows',
                badge: null,
                icon: 'workflows',
                subtitle: 'Módulo 7',
            },
            {
                name: 'Clientes',
                href: '/customers',
                badge: null,
                icon: 'customers',
                subtitle: 'Módulo 3',
            },
            {
                name: 'Proveedores',
                href: '/suppliers',
                badge: null,
                icon: 'suppliers',
                subtitle: 'Módulo 4',
            },
        ];

        // Módulo de Configuración para Dueños y Admins o Perfil para Trabajadores
        if (auth?.user?.is_super_admin || auth?.user?.role === 'owner') {
            items.push({
                name: 'Configuración y Equipo',
                href: '/settings',
                badge: null,
                icon: 'settings',
                subtitle: 'Ajustes del Taller y Perfil',
            });
        } else {
            items.push({
                name: 'Mi Perfil',
                href: '/profile',
                badge: null,
                icon: 'settings',
                subtitle: 'Ajustes de mi cuenta',
            });
        }

        // Módulo SuperAdmin para Administradores de la Plataforma
        if (auth?.user?.is_super_admin) {
            items.push({
                name: 'Panel Admin SaaS',
                href: '/admin',
                badge: 'Beta',
                badgeColor: 'bg-purple-500/20 text-purple-300 border border-purple-500/30',
                icon: 'admin',
                subtitle: 'Plataforma Global',
            });
        }

        return items;
    });

    function isCurrent(href) {
        if (href === '/kanban' && (currentUrl === '/' || currentUrl.startsWith('/kanban'))) {
            return true;
        }
        return currentUrl.startsWith(href);
    }

    function switchTenant(tenantId) {
        if (!tenantId) return;
        router.post(`/admin/switch-tenant/${tenantId}`);
    }

    function logout() {
        router.post('/logout');
    }
</script>

<svelte:head>
    <title>{title ? `${title} · Gekaem` : 'Gekaem · Producción Make-to-Order'}</title>
</svelte:head>

<div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col md:flex-row antialiased selection:bg-emerald-500 selection:text-white">
    <!-- BARRA LATERAL ESCRITORIO -->
    <aside class="hidden md:flex flex-col w-72 bg-slate-900 border-r border-slate-800/80 shrink-0">
        <!-- Logo y Marca -->
        <div class="p-5 border-b border-slate-800 flex items-center justify-between">
            <Link href="/kanban" class="flex items-center gap-3 group">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-400 flex items-center justify-center shadow-lg shadow-emerald-500/20 group-hover:scale-105 transition-transform">
                    <span class="font-black text-xl text-white tracking-wider">GK</span>
                </div>
                <div>
                    <div class="flex items-center gap-1.5">
                        <span class="font-bold text-lg text-white tracking-tight">Gekaem</span>
                        <span class="px-1.5 py-0.5 text-[10px] uppercase font-bold tracking-wider bg-emerald-500/20 text-emerald-400 rounded border border-emerald-500/30">MVP</span>
                    </div>
                    <span class="text-[11px] text-slate-400 block -mt-0.5">Make-to-Order + Kanban</span>
                </div>
            </Link>
        </div>

        <!-- Tenant Activo (Taller del Emprendedor) -->
        <div class="p-4 mx-3 my-3 bg-slate-950/60 rounded-xl border border-slate-800/60">
            <div class="flex items-center justify-between text-xs text-slate-400 mb-1">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="uppercase tracking-wider font-semibold text-[10px]">Taller Activo</span>
                </div>
                {#if auth?.user?.is_super_admin}
                    <span class="text-[9px] uppercase font-extrabold text-purple-400 font-mono">SuperAdmin</span>
                {/if}
            </div>
            <h2 class="font-semibold text-sm text-slate-200 truncate" title={auth?.tenant?.name}>
                {auth?.tenant?.name ?? 'Taller Creativo'}
            </h2>
            <div class="mt-2 flex items-center justify-between text-[11px] text-slate-400 border-t border-slate-800/80 pt-2">
                <span>Capacidad diaria:</span>
                <span class="font-mono text-emerald-400 font-semibold">{auth?.tenant?.daily_hours ?? 8}h / día</span>
            </div>
            <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                <span>Holgura base:</span>
                <span class="font-mono text-amber-400 font-semibold">{auth?.tenant?.default_buffer_percent ?? 20}%</span>
            </div>

            <!-- Selector de Taller para SuperAdmin -->
            {#if auth?.user?.is_super_admin && auth?.all_tenants && auth?.all_tenants.length > 0}
                <div class="mt-2.5 pt-2 border-t border-slate-800">
                    <label for="sw-taller" class="text-[9px] uppercase font-bold text-purple-400 block mb-1">👑 Cambiar Taller:</label>
                    <select
                        id="sw-taller"
                        value={auth.tenant?.id}
                        onchange={(e) => switchTenant(e.target.value)}
                        class="w-full bg-slate-900 border border-purple-500/40 rounded-lg px-2 py-1 text-white text-[11px]"
                    >
                        {#each auth.all_tenants as at}
                            <option value={at.id}>{at.name}</option>
                        {/each}
                    </select>
                </div>
            {/if}
        </div>

        <!-- Menú de Navegación -->
        <nav class="flex-1 px-3 space-y-1 overflow-y-auto py-2">
            <div class="px-3 pb-1 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                Módulos del Sistema
            </div>
            {#each navItems as item}
                {@const active = isCurrent(item.href)}
                <Link
                    href={item.href}
                    class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium transition-all group {active ? 'bg-emerald-600 text-white shadow-md shadow-emerald-600/30 font-semibold' : 'text-slate-300 hover:bg-slate-800 hover:text-white'}"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="w-2 h-2 rounded-full transition-transform {active ? 'bg-white scale-125' : 'bg-slate-600 group-hover:bg-slate-400'}"></span>
                        <div class="truncate">
                            <span class="block truncate">{item.name}</span>
                            <span class="block text-[10px] {active ? 'text-emerald-100' : 'text-slate-500 group-hover:text-slate-400'} -mt-0.5 font-normal">{item.subtitle}</span>
                        </div>
                    </div>
                    {#if item.badge}
                        <span class="text-[10px] px-2 py-0.5 rounded-full font-bold uppercase tracking-wider shrink-0 {item.badgeColor ?? 'bg-slate-700 text-slate-300'}">
                            {item.badge}
                        </span>
                    {/if}
                </Link>
            {/each}
        </nav>

        <!-- Usuario Conectado y Cerrar Sesión -->
        <div class="p-3.5 border-t border-slate-800 bg-slate-900/60 flex items-center justify-between">
            <Link href="/profile" class="flex items-center gap-2.5 min-w-0 group hover:opacity-90 transition-opacity" title="Ver mi perfil">
                <div class="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-xs text-slate-200 group-hover:border-emerald-500 transition-colors">
                    {auth?.user?.name ? auth.user.name.slice(0, 2).toUpperCase() : 'GK'}
                </div>
                <div class="truncate">
                    <div class="flex items-center gap-1.5">
                        <p class="text-xs font-semibold text-slate-200 truncate group-hover:text-emerald-400 transition-colors">{auth?.user?.name ?? 'Usuario'}</p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        {#if auth?.user?.is_super_admin}
                            <span class="text-[9px] px-1.5 py-0.2 rounded font-bold uppercase tracking-wider bg-purple-500/20 text-purple-300 border border-purple-500/30">Admin Gekaem</span>
                        {:else if auth?.user?.role === 'owner'}
                            <span class="text-[9px] px-1.5 py-0.2 rounded font-bold uppercase tracking-wider bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Dueño</span>
                        {:else}
                            <span class="text-[9px] px-1.5 py-0.2 rounded font-bold uppercase tracking-wider bg-sky-500/20 text-sky-300 border border-sky-500/30">Trabajador</span>
                        {/if}
                    </div>
                </div>
            </Link>

            <!-- Botón Salir / Logout -->
            <button
                type="button"
                onclick={logout}
                class="p-2 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-950/30 transition-colors"
                title="Cerrar Sesión"
            >
                <span class="text-xs font-bold font-mono">Salir ➔</span>
            </button>
        </div>
    </aside>

    <!-- BARRA SUPERIOR PARA MÓVILES -->
    <header class="md:hidden bg-slate-900 border-b border-slate-800 px-4 py-3 flex items-center justify-between sticky top-0 z-40">
        <Link href="/kanban" class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-emerald-600 to-teal-400 flex items-center justify-center text-white font-black text-sm">
                GK
            </div>
            <span class="font-bold text-white tracking-tight">Gekaem</span>
        </Link>

        <div class="flex items-center gap-3">
            <span class="text-xs text-emerald-400 font-mono font-medium truncate max-w-[150px]">{auth?.tenant?.name}</span>
            <button
                type="button"
                onclick={() => mobileMenuOpen = !mobileMenuOpen}
                class="p-2 rounded-lg bg-slate-800 border border-slate-700 text-slate-300 hover:text-white"
                aria-label="Menú"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d={mobileMenuOpen ? "M6 18L18 6M6 6l12 12" : "M4 6h16M4 12h16M4 18h16"} />
                </svg>
            </button>
        </div>
    </header>

    <!-- MENÚ DESPLEGABLE MÓVIL -->
    {#if mobileMenuOpen}
        <div class="md:hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex flex-col">
            <div class="bg-slate-900 border-b border-slate-800 p-4 flex items-center justify-between">
                <span class="font-bold text-white text-base">Menú de Navegación</span>
                <button
                    type="button"
                    onclick={() => mobileMenuOpen = false}
                    class="p-2 rounded-lg bg-slate-800 text-slate-400 hover:text-white"
                >
                    ✕
                </button>
            </div>
            <nav class="p-4 space-y-1 overflow-y-auto flex-1">
                {#each navItems as item}
                    {@const active = isCurrent(item.href)}
                    <Link
                        href={item.href}
                        onclick={() => mobileMenuOpen = false}
                        class="flex items-center justify-between px-4 py-3 rounded-xl text-sm font-medium {active ? 'bg-emerald-600 text-white font-semibold' : 'text-slate-300 hover:bg-slate-800'}"
                    >
                        <div>
                            <span>{item.name}</span>
                            <span class="block text-xs {active ? 'text-emerald-100' : 'text-slate-500'}">{item.subtitle}</span>
                        </div>
                        {#if item.badge}
                            <span class="text-[10px] px-2 py-0.5 rounded-full font-bold {item.badgeColor ?? 'bg-slate-700 text-slate-300'}">
                                {item.badge}
                            </span>
                        {/if}
                    </Link>
                {/each}

                <button
                    type="button"
                    onclick={logout}
                    class="w-full mt-4 p-3 rounded-xl bg-rose-950/40 border border-rose-500/30 text-rose-300 font-semibold text-xs text-center"
                >
                    Cerrar Sesión
                </button>
            </nav>
        </div>
    {/if}

    <!-- CONTENIDO PRINCIPAL -->
    <main class="flex-1 flex flex-col min-w-0 overflow-y-auto">
        <!-- NOTIFICACIONES FLASH -->
        {#if (flash?.success || flash?.error) && !flashDismissed}
            <div class="p-4 pb-0">
                {#if flash?.success}
                    <div class="bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 px-4 py-3 rounded-xl flex items-center justify-between text-sm shadow-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-emerald-400 font-bold">✓</span>
                            <span>{flash.success}</span>
                        </div>
                        <button type="button" onclick={() => flashDismissed = true} class="text-emerald-400 hover:text-white text-xs font-semibold px-2">Cerrar</button>
                    </div>
                {/if}
                {#if flash?.error}
                    <div class="bg-rose-500/15 border border-rose-500/30 text-rose-300 px-4 py-3 rounded-xl flex items-center justify-between text-sm shadow-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-rose-400 font-bold">⚠</span>
                            <span>{flash.error}</span>
                        </div>
                        <button type="button" onclick={() => flashDismissed = true} class="text-rose-400 hover:text-white text-xs font-semibold px-2">Cerrar</button>
                    </div>
                {/if}
            </div>
        {/if}

        <div class="flex-1 p-4 md:p-8">
            {@render children?.()}
        </div>
    </main>
</div>
