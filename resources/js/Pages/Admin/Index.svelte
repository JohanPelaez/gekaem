<script>
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let { tenants = [], users = [], errors = {} } = $props();

    let showUserModal = $state(false);
    let processing = $state(false);
    let switchingId = $state(null);

    // Formulario de creación de usuario en Svelte 5 Rune (Sin prefijo $)
    let userForm = $state({
        name: '',
        email: '',
        password: '',
        role_type: 'owner',
        tenant_mode: 'existing',
        tenant_id: '',
        new_tenant_name: '',
    });

    $effect(() => {
        const incomingTenants = tenants;
        untrack(() => {
            if (incomingTenants.length > 0 && !userForm.tenant_id) {
                userForm.tenant_id = incomingTenants[0].id;
            }
        });
    });

    function resetUserForm() {
        userForm.name = '';
        userForm.email = '';
        userForm.password = '';
        userForm.role_type = 'owner';
        userForm.tenant_mode = 'existing';
        userForm.tenant_id = tenants[0]?.id ?? '';
        userForm.new_tenant_name = '';
    }

    function openCreateUser() {
        resetUserForm();
        showUserModal = true;
    }

    function submitUser(e) {
        e.preventDefault();
        processing = true;
        router.post('/admin/users', userForm, {
            preserveScroll: true,
            onStart: () => { processing = true; },
            onFinish: () => { processing = false; },
            onSuccess: () => {
                showUserModal = false;
                resetUserForm();
            },
        });
    }

    function switchTenant(tenant) {
        switchingId = tenant.id;
        router.post(`/admin/switch-tenant/${tenant.id}`, {}, {
            onFinish: () => { switchingId = null; },
        });
    }
</script>

<AppLayout title="Panel SuperAdmin SaaS">
    <div class="space-y-6">
        <!-- ENCABEZADO SUPERADMIN -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] p-6 rounded-2xl shadow-sm">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-indigo-50 dark:bg-[#4F46E5]/15 text-[#4F46E5] dark:text-indigo-300 border border-indigo-200 dark:border-[#4F46E5]/30">
                        SaaS Platform Beta
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Panel SuperAdmin gipPyme</h1>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Gestión global de emprendimientos (Tenants), control de usuarios y cambio rápido de taller activo para soporte.
                </p>
            </div>
            <button
                type="button"
                onclick={openCreateUser}
                class="px-4 py-2.5 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-medium text-xs transition-colors shadow-sm flex items-center gap-2 shrink-0 self-start sm:self-auto"
            >
                <span>+</span>
                <span>Crear Usuario / Taller</span>
            </button>
        </div>

        <!-- KPI CARDS -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="p-5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl shadow-sm">
                <span class="text-[11px] font-bold text-[#64748B] dark:text-slate-400 uppercase tracking-wider block">Talleres Registrados</span>
                <div class="text-2xl font-mono font-extrabold text-slate-900 dark:text-white mt-1">{tenants.length}</div>
                <span class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5 block">Negocios independientes en plataforma</span>
            </div>

            <div class="p-5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl shadow-sm">
                <span class="text-[11px] font-bold text-[#64748B] dark:text-slate-400 uppercase tracking-wider block">Usuarios Globales</span>
                <div class="text-2xl font-mono font-extrabold text-[#4F46E5] dark:text-indigo-400 mt-1">{users.length}</div>
                <span class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5 block">Admins, Dueños y Trabajadores</span>
            </div>

            <div class="p-5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl shadow-sm">
                <span class="text-[11px] font-bold text-[#64748B] dark:text-slate-400 uppercase tracking-wider block">Total Pedidos en Sistema</span>
                <div class="text-2xl font-mono font-extrabold text-emerald-600 dark:text-emerald-400 mt-1">
                    {tenants.reduce((acc, t) => acc + (t.orders_count ?? 0), 0)}
                </div>
                <span class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5 block">Pedidos operando en la plataforma</span>
            </div>
        </div>

        <!-- TABLA DE TENANTS (TALLERES DE EMPRENDEDORES) -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl overflow-hidden shadow-sm">
            <div class="p-5 border-b border-slate-200 dark:border-[#26334D] flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Talleres y Emprendimientos</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Selecciona cualquier taller para operar como su administrador.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50/80 dark:bg-[#0B0F17]/60 text-[#64748B] dark:text-slate-400 uppercase font-semibold border-b border-slate-200 dark:border-[#26334D] text-[10px] tracking-wider">
                        <tr>
                            <th class="p-4">Taller (Tenant)</th>
                            <th class="p-4 text-center">Jornada Productiva</th>
                            <th class="p-4 text-center">Holgura Base</th>
                            <th class="p-4 text-center">Pedidos</th>
                            <th class="p-4">Equipo Vinculado</th>
                            <th class="p-4 text-right">Acción SuperAdmin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-[#26334D]/60 text-slate-700 dark:text-slate-300">
                        {#each tenants as t (t.id)}
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                                <td class="p-4">
                                    <div class="font-bold text-slate-900 dark:text-white text-sm">{t.name}</div>
                                    <span class="font-mono text-[10px] text-slate-400 dark:text-slate-500">slug: {t.slug}</span>
                                </td>
                                <td class="p-4 text-center font-mono text-slate-600 dark:text-slate-300">
                                    {Math.round(t.daily_productive_minutes / 60)}h ({t.daily_productive_minutes} min/día)
                                </td>
                                <td class="p-4 text-center font-mono text-emerald-600 dark:text-emerald-400 font-bold">
                                    +{t.default_buffer_percent}%
                                </td>
                                <td class="p-4 text-center">
                                    <span class="px-2 py-0.5 rounded-full font-mono font-bold text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700">
                                        {t.orders_count ?? 0}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div class="flex flex-wrap gap-1 max-w-xs">
                                        {#each t.users ?? [] as u}
                                            <span class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded border {u.pivot?.role === 'owner' ? 'bg-emerald-50 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-500/30 font-semibold' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700'}">
                                                {u.name} ({u.pivot?.role === 'owner' ? 'Dueño' : 'Trabajador'})
                                            </span>
                                        {:else}
                                            <span class="text-slate-400 dark:text-slate-500 text-[11px] italic">Sin usuarios asignados</span>
                                        {/each}
                                    </div>
                                </td>
                                <td class="p-4 text-right">
                                    <button
                                        type="button"
                                        onclick={() => switchTenant(t)}
                                        disabled={switchingId === t.id}
                                        class="px-3 py-1.5 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-medium text-xs shadow-sm transition-all disabled:opacity-50 inline-flex items-center gap-1.5"
                                    >
                                        {#if switchingId === t.id}
                                            <span>Cambiando...</span>
                                        {:else}
                                            <span>Administrar Taller</span>
                                            <span>→</span>
                                        {/if}
                                    </button>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TABLA DE USUARIOS GLOBALES -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl overflow-hidden shadow-sm">
            <div class="p-5 border-b border-slate-200 dark:border-[#26334D]">
                <h2 class="text-base font-bold text-slate-900 dark:text-white">Directorio de Usuarios Globales</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Todos los usuarios registrados en la base de datos de gipPyme.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50/80 dark:bg-[#0B0F17]/60 text-[#64748B] dark:text-slate-400 uppercase font-semibold border-b border-slate-200 dark:border-[#26334D] text-[10px] tracking-wider">
                        <tr>
                            <th class="p-4">Usuario</th>
                            <th class="p-4">Correo Electrónico</th>
                            <th class="p-4 text-center">Tipo de Acceso</th>
                            <th class="p-4">Talleres Asignados</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-[#26334D]/60 text-slate-700 dark:text-slate-300">
                        {#each users as u (u.id)}
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors">
                                <td class="p-4 font-bold text-slate-900 dark:text-white">
                                    {u.name}
                                </td>
                                <td class="p-4 font-mono text-slate-600 dark:text-slate-300">
                                    {u.email}
                                </td>
                                <td class="p-4 text-center">
                                    {#if u.is_super_admin}
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-50 dark:bg-indigo-500/15 text-[#4F46E5] dark:text-indigo-300 border border-indigo-200 dark:border-indigo-500/30">
                                            👑 SuperAdmin
                                        </span>
                                    {:else}
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                            Usuario Taller
                                        </span>
                                    {/if}
                                </td>
                                <td class="p-4">
                                    <div class="flex flex-wrap gap-1">
                                        {#each u.tenants ?? [] as t}
                                            <span class="text-[10px] px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                                {t.name} <strong class="text-emerald-600 dark:text-emerald-400">({t.pivot?.role === 'owner' ? 'Dueño' : 'Trabajador'})</strong>
                                            </span>
                                        {:else}
                                            <span class="text-slate-400 dark:text-slate-500 text-[11px] italic">
                                                {u.is_super_admin ? 'Acceso global a todos los talleres' : 'Sin taller'}
                                            </span>
                                        {/each}
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</AppLayout>

<!-- MODAL CREAR USUARIO / ONBOARDING TALLER -->
{#if showUserModal}
    <div class="fixed inset-0 z-50 bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <h3 class="text-base font-bold text-slate-900 dark:text-white">Crear Usuario o Taller Beta</h3>
                <button type="button" onclick={() => showUserModal = false} class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">✕</button>
            </div>

            <form onsubmit={submitUser} class="space-y-4 text-xs">
                <div>
                    <label for="u-name" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Nombre Completo *</label>
                    <input
                        id="u-name"
                        type="text"
                        bind:value={userForm.name}
                        placeholder="Ej. Laura Restrepo"
                        required
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-500 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div>
                    <label for="u-email" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Correo Electrónico *</label>
                    <input
                        id="u-email"
                        type="email"
                        bind:value={userForm.email}
                        placeholder="laura@emprendimiento.com"
                        required
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 text-xs"
                    />
                    {#if errors.email}
                        <p class="text-[10px] text-rose-500 mt-1">{errors.email}</p>
                    {/if}
                </div>

                <div>
                    <label for="u-pass" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Contraseña (Mín. 6 caracteres) *</label>
                    <input
                        id="u-pass"
                        type="password"
                        bind:value={userForm.password}
                        placeholder="••••••••"
                        required
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 text-xs"
                    />
                    {#if errors.password}
                        <p class="text-[10px] text-rose-500 mt-1">{errors.password}</p>
                    {/if}
                </div>

                <div>
                    <label for="u-role" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Rol en la Plataforma *</label>
                    <select
                        id="u-role"
                        bind:value={userForm.role_type}
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 text-xs"
                    >
                        <option value="owner">Dueño de Taller (Emprendedor con acceso total al taller)</option>
                        <option value="worker">Trabajador (Operario de producción y Kanban)</option>
                        <option value="admin">SuperAdmin gipPyme (Acceso administrativo a toda la plataforma)</option>
                    </select>
                </div>

                {#if userForm.role_type !== 'admin'}
                    <div class="p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D] space-y-3">
                        <span class="font-bold text-slate-700 dark:text-slate-300 text-[11px] block">Asignación de Taller:</span>
                        <div class="flex items-center gap-3">
                            <label class="flex items-center gap-1.5 cursor-pointer text-slate-700 dark:text-slate-300">
                                <input
                                    type="radio"
                                    name="tenant_mode"
                                    value="existing"
                                    bind:group={userForm.tenant_mode}
                                    class="text-[#4F46E5] focus:ring-[#4F46E5]"
                                />
                                <span>Taller Existente</span>
                            </label>
                            <label class="flex items-center gap-1.5 cursor-pointer text-slate-700 dark:text-slate-300">
                                <input
                                    type="radio"
                                    name="tenant_mode"
                                    value="new"
                                    bind:group={userForm.tenant_mode}
                                    class="text-[#4F46E5] focus:ring-[#4F46E5]"
                                />
                                <span>Nuevo Taller (Onboarding Beta)</span>
                            </label>
                        </div>

                        {#if userForm.tenant_mode === 'existing'}
                            <div>
                                <label for="u-t-sel" class="block text-slate-500 dark:text-slate-400 text-[10px] mb-1">Seleccionar Taller:</label>
                                <select
                                    id="u-t-sel"
                                    bind:value={userForm.tenant_id}
                                    class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-lg px-2.5 py-1.5 text-slate-900 dark:text-white text-xs"
                                >
                                    {#each tenants as t}
                                        <option value={t.id}>{t.name}</option>
                                    {/each}
                                </select>
                            </div>
                        {:else}
                            <div>
                                <label for="u-t-name" class="block text-slate-500 dark:text-slate-400 text-[10px] mb-1">Nombre del Nuevo Emprendimiento/Taller:</label>
                                <input
                                    id="u-t-name"
                                    type="text"
                                    bind:value={userForm.new_tenant_name}
                                    placeholder="Ej. Confecciones Laura S.A.S."
                                    required
                                    class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-lg px-2.5 py-1.5 text-slate-900 dark:text-white text-xs"
                                />
                            </div>
                        {/if}
                    </div>
                {/if}

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-[#26334D]">
                    <button
                        type="button"
                        onclick={() => showUserModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold text-xs"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        class="px-5 py-2 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-semibold text-xs shadow-sm transition disabled:opacity-50"
                    >
                        Registrar Usuario
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
