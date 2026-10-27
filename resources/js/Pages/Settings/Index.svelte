<script>
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let {
        profileUser = null,
        tenant = null,
        teamMembers = [],
        canManageTenant = false,
        initialTab = 'profile',
        errors = {},
    } = $props();

    // Pestaña activa ('profile' | 'tenant' | 'team')
    let activeTab = $state('profile');

    $effect(() => {
        const tab = initialTab;
        untrack(() => {
            if (tab && (tab === 'profile' || (canManageTenant && ['tenant', 'team'].includes(tab)))) {
                activeTab = tab;
            }
        });
    });

    // Estados de procesamiento
    let profileProcessing = $state(false);
    let passwordProcessing = $state(false);
    let settingsProcessing = $state(false);
    let memberProcessing = $state(false);
    let showAddMemberModal = $state(false);

    // Estado y modal para editar miembro del equipo
    let showEditMemberModal = $state(false);
    let editingMember = $state(null);
    let editMemberProcessing = $state(false);
    let editMemberForm = $state({
        name: '',
        email: '',
        role: 'worker',
        password: '',
    });

    // Formulario de Perfil de Usuario
    let profileForm = $state({
        name: '',
        email: '',
    });

    let prevProfileUserId = null;
    $effect(() => {
        const curId = profileUser?.id ?? null;
        if (curId && curId !== prevProfileUserId) {
            prevProfileUserId = curId;
            untrack(() => {
                profileForm.name = profileUser.name ?? '';
                profileForm.email = profileUser.email ?? '';
            });
        }
    });

    // Formulario de Cambio de Contraseña
    let passwordForm = $state({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    // Formulario de Parámetros del Taller
    let settingsForm = $state({
        name: '',
        daily_hours: 8,
        default_buffer_percent: 20,
        parallel_workers: 1,
        workdays: [1, 2, 3, 4, 5, 6],
        currency: 'COP',
    });

    let prevTenantId = null;
    $effect(() => {
        const curTId = tenant?.id ?? null;
        if (curTId && curTId !== prevTenantId) {
            prevTenantId = curTId;
            untrack(() => {
                settingsForm.name = tenant.name ?? '';
                settingsForm.daily_hours = tenant.daily_hours ?? 8;
                settingsForm.default_buffer_percent = tenant.default_buffer_percent ?? 20;
                settingsForm.parallel_workers = tenant.settings?.parallel_workers ?? 1;
                settingsForm.workdays = tenant.settings?.workdays ?? [1, 2, 3, 4, 5, 6];
                settingsForm.currency = tenant.settings?.currency ?? 'COP';
            });
        }
    });

    // Formulario de Nuevo Miembro del Equipo
    let memberForm = $state({
        name: '',
        email: '',
        password: '',
        role: 'worker',
    });

    // Cálculo reactivo de minutos productivos mediante Rune $derived
    let productiveMinutesDerived = $derived(
        Math.round(Number(settingsForm.daily_hours || 0) * 60),
    );

    const weekDays = [
        { id: 1, label: 'Lun', name: 'Lunes' },
        { id: 2, label: 'Mar', name: 'Martes' },
        { id: 3, label: 'Mié', name: 'Miércoles' },
        { id: 4, label: 'Jue', name: 'Jueves' },
        { id: 5, label: 'Vie', name: 'Viernes' },
        { id: 6, label: 'Sáb', name: 'Sábado' },
        { id: 7, label: 'Dom', name: 'Domingo' },
    ];

    function toggleWorkday(dayId) {
        if (settingsForm.workdays.includes(dayId)) {
            settingsForm.workdays = settingsForm.workdays.filter((d) => d !== dayId);
        } else {
            settingsForm.workdays = [...settingsForm.workdays, dayId].sort();
        }
    }

    function submitProfile(e) {
        e.preventDefault();
        profileProcessing = true;
        router.patch('/profile', profileForm, {
            preserveScroll: true,
            onStart: () => { profileProcessing = true; },
            onFinish: () => { profileProcessing = false; },
        });
    }

    function submitPassword(e) {
        e.preventDefault();
        passwordProcessing = true;
        router.put('/profile/password', passwordForm, {
            preserveScroll: true,
            onStart: () => { passwordProcessing = true; },
            onFinish: () => { passwordProcessing = false; },
            onSuccess: () => {
                passwordForm.current_password = '';
                passwordForm.password = '';
                passwordForm.password_confirmation = '';
            },
        });
    }

    function submitSettings(e) {
        e.preventDefault();
        settingsProcessing = true;
        router.patch('/settings/tenant', settingsForm, {
            preserveScroll: true,
            onStart: () => { settingsProcessing = true; },
            onFinish: () => { settingsProcessing = false; },
        });
    }

    function submitMember(e) {
        e.preventDefault();
        memberProcessing = true;
        router.post('/settings/members', memberForm, {
            preserveScroll: true,
            onStart: () => { memberProcessing = true; },
            onFinish: () => { memberProcessing = false; },
            onSuccess: () => {
                showAddMemberModal = false;
                memberForm.name = '';
                memberForm.email = '';
                memberForm.password = '';
                memberForm.role = 'worker';
            },
        });
    }

    function openEditMember(member) {
        editingMember = member;
        editMemberForm.name = member.name ?? '';
        editMemberForm.email = member.email ?? '';
        editMemberForm.role = member.pivot?.role ?? 'worker';
        editMemberForm.password = '';
        showEditMemberModal = true;
    }

    function submitEditMember(e) {
        e.preventDefault();
        if (!editingMember) return;
        editMemberProcessing = true;
        router.put(`/settings/members/${editingMember.id}`, editMemberForm, {
            preserveScroll: true,
            onStart: () => { editMemberProcessing = true; },
            onFinish: () => { editMemberProcessing = false; },
            onSuccess: () => {
                showEditMemberModal = false;
                editingMember = null;
            },
        });
    }

    function removeMember(member) {
        if (confirm(`¿Remover a "${member.name}" de este taller?`)) {
            router.delete(`/settings/members/${member.id}`, {
                preserveScroll: true,
            });
        }
    }
</script>

<AppLayout title="Ajustes y Perfil">
    <div class="space-y-6 max-w-6xl mx-auto">
        <!-- ENCABEZADO -->
        <div class="bg-slate-900 border border-slate-800 p-6 rounded-3xl shadow-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        {canManageTenant ? 'Gestión Integral' : 'Cuenta de Usuario'}
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Ajustes y Configuración</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Administra tu perfil personal, la capacidad operativa del taller y los colaboradores del equipo.
                </p>
            </div>

            <!-- SELECTOR DE PESTAÑAS -->
            <div class="flex items-center gap-1.5 p-1 bg-slate-950 border border-slate-800 rounded-2xl self-start sm:self-auto overflow-x-auto">
                <button
                    type="button"
                    onclick={() => activeTab = 'profile'}
                    class="px-4 py-2 rounded-xl text-xs font-semibold transition-all flex items-center gap-2 {activeTab === 'profile' ? 'bg-emerald-600 text-white shadow-md' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'}"
                >
                    <span>👤</span>
                    <span>Mi Perfil</span>
                </button>

                {#if canManageTenant}
                    <button
                        type="button"
                        onclick={() => activeTab = 'tenant'}
                        class="px-4 py-2 rounded-xl text-xs font-semibold transition-all flex items-center gap-2 {activeTab === 'tenant' ? 'bg-emerald-600 text-white shadow-md' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'}"
                    >
                        <span>⚙️</span>
                        <span>Configuración del Taller</span>
                    </button>

                    <button
                        type="button"
                        onclick={() => activeTab = 'team'}
                        class="px-4 py-2 rounded-xl text-xs font-semibold transition-all flex items-center gap-2 {activeTab === 'team' ? 'bg-emerald-600 text-white shadow-md' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900'}"
                    >
                        <span>👥</span>
                        <span>Equipo de Trabajo</span>
                        {#if teamMembers.length > 0}
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-bold {activeTab === 'team' ? 'bg-emerald-900 text-emerald-200' : 'bg-slate-800 text-slate-300'}">
                                {teamMembers.length}
                            </span>
                        {/if}
                    </button>
                {/if}
            </div>
        </div>

        <!-- CONTENIDO DE LAS PESTAÑAS -->
        {#if activeTab === 'profile'}
            <!-- PESTAÑA 1: MI PERFIL (VISIBLE PARA TODOS) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- FORMULARIO 1: DATOS PERSONALES -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-md space-y-5">
                    <div class="border-b border-slate-800 pb-3 flex items-center justify-between">
                        <div>
                            <h2 class="text-base font-bold text-white flex items-center gap-2">
                                <span>👤</span> Información Personal
                            </h2>
                            <p class="text-xs text-slate-400">Actualiza tu nombre y correo electrónico de acceso.</p>
                        </div>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider border {profileUser?.role === 'owner' ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : profileUser?.is_super_admin ? 'bg-purple-500/20 text-purple-300 border-purple-500/30' : 'bg-sky-500/20 text-sky-300 border-sky-500/30'}">
                            Rol: {profileUser?.role === 'owner' ? 'Dueño' : profileUser?.is_super_admin ? 'SuperAdmin' : 'Trabajador'}
                        </span>
                    </div>

                    <form onsubmit={submitProfile} class="space-y-4 text-xs">
                        <div>
                            <label for="prof-name" class="block font-semibold text-slate-300 mb-1.5">Nombre Completo *</label>
                            <input
                                id="prof-name"
                                type="text"
                                bind:value={profileForm.name}
                                required
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white text-xs focus:outline-none focus:border-emerald-500"
                            />
                            {#if errors.name}
                                <p class="text-[11px] text-rose-400 mt-1">{errors.name}</p>
                            {/if}
                        </div>

                        <div>
                            <label for="prof-email" class="block font-semibold text-slate-300 mb-1.5">Correo Electrónico *</label>
                            <input
                                id="prof-email"
                                type="email"
                                bind:value={profileForm.email}
                                required
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white text-xs focus:outline-none focus:border-emerald-500"
                            />
                            {#if errors.email}
                                <p class="text-[11px] text-rose-400 mt-1">{errors.email}</p>
                            {/if}
                        </div>

                        <div class="pt-2 flex justify-end">
                            <button
                                type="submit"
                                disabled={profileProcessing}
                                class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs shadow-lg shadow-emerald-600/25 transition-all disabled:opacity-50"
                            >
                                {profileProcessing ? 'Guardando...' : 'Actualizar Información'}
                            </button>
                        </div>
                    </form>
                </div>

                <!-- FORMULARIO 2: CAMBIO DE CONTRASEÑA -->
                <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-md space-y-5">
                    <div class="border-b border-slate-800 pb-3">
                        <h2 class="text-base font-bold text-white flex items-center gap-2">
                            <span>🔒</span> Seguridad y Contraseña
                        </h2>
                        <p class="text-xs text-slate-400">Garantiza la seguridad de tu cuenta actualizando tu contraseña periódicamente.</p>
                    </div>

                    <form onsubmit={submitPassword} class="space-y-4 text-xs">
                        <div>
                            <label for="pwd-curr" class="block font-semibold text-slate-300 mb-1.5">Contraseña Actual *</label>
                            <input
                                id="pwd-curr"
                                type="password"
                                bind:value={passwordForm.current_password}
                                required
                                placeholder="••••••••"
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white text-xs focus:outline-none focus:border-emerald-500"
                            />
                            {#if errors.current_password}
                                <p class="text-[11px] text-rose-400 mt-1">{errors.current_password}</p>
                            {/if}
                        </div>

                        <div>
                            <label for="pwd-new" class="block font-semibold text-slate-300 mb-1.5">Nueva Contraseña (mínimo 8 caracteres) *</label>
                            <input
                                id="pwd-new"
                                type="password"
                                bind:value={passwordForm.password}
                                required
                                minlength="8"
                                placeholder="••••••••"
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white text-xs focus:outline-none focus:border-emerald-500"
                            />
                            {#if errors.password}
                                <p class="text-[11px] text-rose-400 mt-1">{errors.password}</p>
                            {/if}
                        </div>

                        <div>
                            <label for="pwd-conf" class="block font-semibold text-slate-300 mb-1.5">Confirmar Nueva Contraseña *</label>
                            <input
                                id="pwd-conf"
                                type="password"
                                bind:value={passwordForm.password_confirmation}
                                required
                                minlength="8"
                                placeholder="••••••••"
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white text-xs focus:outline-none focus:border-emerald-500"
                            />
                        </div>

                        <div class="pt-2 flex justify-end">
                            <button
                                type="submit"
                                disabled={passwordProcessing}
                                class="px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-emerald-400 border border-slate-700 font-semibold text-xs transition-all disabled:opacity-50"
                            >
                                {passwordProcessing ? 'Cambiando...' : 'Cambiar Contraseña'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        {:else if activeTab === 'tenant' && canManageTenant}
            <!-- PESTAÑA 2: CONFIGURACIÓN DEL TALLER (SOLO DUEÑO Y ADMIN) -->
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-md space-y-6">
                <div class="border-b border-slate-800 pb-3">
                    <h2 class="text-base font-bold text-white flex items-center gap-2">
                        <span>⚙️</span> Parámetros Productivos del Taller
                    </h2>
                    <p class="text-xs text-slate-400">
                        Estos valores alimentan el motor MRP ligero, el cálculo de fechas de entrega y el control de holgura.
                    </p>
                </div>

                <form onsubmit={submitSettings} class="space-y-5 text-xs">
                    <div>
                        <label for="t-name" class="block font-semibold text-slate-300 mb-1.5">Nombre Comercial del Taller *</label>
                        <input
                            id="t-name"
                            type="text"
                            bind:value={settingsForm.name}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white text-xs focus:outline-none focus:border-emerald-500 font-medium"
                        />
                        {#if errors.name}
                            <p class="text-[11px] text-rose-400 mt-1">{errors.name}</p>
                        {/if}
                    </div>

                    <!-- Jornada Productiva en Horas y Minutos calculados dinámicamente -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-5 bg-slate-950 rounded-2xl border border-slate-800">
                        <div>
                            <label for="t-hours" class="block font-semibold text-slate-300 mb-1.5">
                                Jornada Laboral Diaria (en Horas) *
                            </label>
                            <div class="flex items-center gap-2.5">
                                <input
                                    id="t-hours"
                                    type="number"
                                    step="0.5"
                                    min="1"
                                    max="24"
                                    bind:value={settingsForm.daily_hours}
                                    required
                                    class="w-32 bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-white font-mono font-bold text-sm focus:outline-none focus:border-emerald-500"
                                />
                                <span class="text-slate-400 font-medium">horas / día</span>
                            </div>
                            <span class="text-[10px] text-slate-500 mt-1.5 block">
                                Tiempo efectivo de trabajo productivo diario por turno.
                            </span>
                        </div>

                        <div class="flex flex-col justify-center">
                            <span class="block font-semibold text-slate-400 mb-1 text-[11px]">Equivalente en Minutos Productivos:</span>
                            <div class="text-2xl font-mono font-black text-amber-300 flex items-center gap-2">
                                <span>⏱</span>
                                <span>{productiveMinutesDerived} min</span>
                            </div>
                            <span class="text-[10px] text-slate-500 mt-1 block">
                                Se almacena como <code class="text-slate-400">daily_productive_minutes</code> para la predicción de pedidos.
                            </span>
                        </div>
                    </div>

                    <!-- Holgura, Trabajadores Paralelos y Moneda -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label for="t-buffer" class="block font-semibold text-slate-300 mb-1.5">Holgura por Defecto (%) *</label>
                            <input
                                id="t-buffer"
                                type="number"
                                min="0"
                                max="100"
                                bind:value={settingsForm.default_buffer_percent}
                                required
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-emerald-400 font-mono font-bold text-xs focus:outline-none focus:border-emerald-500"
                            />
                            <span class="text-[10px] text-slate-500 mt-1 block">Margen de holgura para imprevistos (0 a 100%)</span>
                            {#if errors.default_buffer_percent}
                                <p class="text-[11px] text-rose-400 mt-1">{errors.default_buffer_percent}</p>
                            {/if}
                        </div>

                        <div>
                            <label for="t-workers" class="block font-semibold text-slate-300 mb-1.5">Operarios Simultáneos (Capacidad)</label>
                            <input
                                id="t-workers"
                                type="number"
                                min="1"
                                max="50"
                                bind:value={settingsForm.parallel_workers}
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white font-mono font-bold text-xs focus:outline-none focus:border-emerald-500"
                            />
                            <span class="text-[10px] text-slate-500 mt-1 block">Estaciones o personas trabajando a la vez</span>
                            {#if errors.parallel_workers}
                                <p class="text-[11px] text-rose-400 mt-1">{errors.parallel_workers}</p>
                            {/if}
                        </div>

                        <div>
                            <label for="t-curr" class="block font-semibold text-slate-300 mb-1.5">Moneda del Taller</label>
                            <select
                                id="t-curr"
                                bind:value={settingsForm.currency}
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white text-xs focus:outline-none focus:border-emerald-500"
                            >
                                <option value="COP">COP ($ Pesos Colombianos)</option>
                                <option value="USD">USD ($ Dólares Americanos)</option>
                                <option value="MXN">MXN ($ Pesos Mexicanos)</option>
                                <option value="EUR">EUR (€ Euros)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Días Laborales de la Semana -->
                    <div>
                        <span class="block font-semibold text-slate-300 mb-1.5">Días Laborales de la Semana</span>
                        <div class="flex flex-wrap gap-2">
                            {#each weekDays as day}
                                {@const isChecked = settingsForm.workdays.includes(day.id)}
                                <button
                                    type="button"
                                    onclick={() => toggleWorkday(day.id)}
                                    class="px-3.5 py-2 rounded-xl text-xs font-semibold border transition-all flex items-center gap-1.5 {isChecked ? 'bg-emerald-600 text-white border-emerald-500 shadow-sm' : 'bg-slate-950 text-slate-500 border-slate-800 hover:text-slate-300'}"
                                >
                                    <span>{isChecked ? '✓' : '○'}</span>
                                    <span>{day.name}</span>
                                </button>
                            {/each}
                        </div>
                        <span class="text-[10px] text-slate-500 mt-1.5 block">
                            Los días no seleccionados se consideran días no laborables al predecir la fecha de entrega de los pedidos.
                        </span>
                    </div>

                    <div class="pt-4 border-t border-slate-800 flex justify-end">
                        <button
                            type="submit"
                            disabled={settingsProcessing}
                            class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs shadow-lg shadow-emerald-600/25 transition-all disabled:opacity-50"
                        >
                            {settingsProcessing ? 'Guardando...' : 'Guardar Ajustes del Taller'}
                        </button>
                    </div>
                </form>
            </div>

        {:else if activeTab === 'team' && canManageTenant}
            <!-- PESTAÑA 3: EQUIPO DE TRABAJO (SOLO DUEÑO Y ADMIN) -->
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-md space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-800 pb-3">
                    <div>
                        <h2 class="text-base font-bold text-white flex items-center gap-2">
                            <span>👥</span> Equipo del Taller
                        </h2>
                        <p class="text-xs text-slate-400">
                            Administra los trabajadores operativos y colaboradores asignados a tu taller.
                        </p>
                    </div>
                    <button
                        type="button"
                        onclick={() => showAddMemberModal = true}
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs shadow-md shadow-emerald-600/20 transition-colors flex items-center gap-1.5 self-start sm:self-auto"
                    >
                        <span>+</span>
                        <span>Agregar Miembro</span>
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {#each teamMembers as member (member.id)}
                        {@const isOwner = member.pivot?.role === 'owner'}
                        <div class="p-4 bg-slate-950 rounded-2xl border border-slate-800 flex items-start justify-between text-xs space-y-1">
                            <div class="space-y-1 min-w-0 pr-2">
                                <div class="font-bold text-white truncate text-sm">{member.name}</div>
                                <div class="text-slate-400 text-[11px] font-mono truncate">{member.email}</div>
                                <div class="pt-1">
                                    <span class="inline-block text-[9px] px-2 py-0.5 rounded font-bold uppercase tracking-wider border {isOwner ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : 'bg-sky-500/20 text-sky-300 border-sky-500/30'}">
                                        {isOwner ? 'Dueño / Administrador' : 'Trabajador Operativo'}
                                    </span>
                                </div>
                            </div>

                            <div class="flex items-center gap-1.5 shrink-0 self-center">
                                <button
                                    type="button"
                                    onclick={() => openEditMember(member)}
                                    class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition-colors"
                                    title="Editar datos del miembro"
                                >
                                    Editar
                                </button>
                                {#if member.id !== profileUser?.id}
                                    <button
                                        type="button"
                                        onclick={() => removeMember(member)}
                                        class="text-slate-500 hover:text-rose-400 text-xs p-1 rounded-lg hover:bg-rose-950/20 transition-colors"
                                        title="Remover colaborador del taller"
                                    >
                                        ✕
                                    </button>
                                {/if}
                            </div>
                        </div>
                    {/each}

                    {#if teamMembers.length === 0}
                        <div class="col-span-full p-8 text-center bg-slate-950 rounded-2xl border border-dashed border-slate-800 text-slate-500 text-xs">
                            No hay miembros registrados en este taller.
                        </div>
                    {/if}
                </div>
            </div>
        {/if}
    </div>
</AppLayout>

<!-- MODAL AGREGAR MIEMBRO AL EQUIPO -->
{#if showAddMemberModal}
    <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-sm w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">Agregar Miembro al Equipo</h3>
                <button type="button" onclick={() => showAddMemberModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitMember} class="space-y-4 text-xs">
                <div>
                    <label for="m-name" class="block font-semibold text-slate-300 mb-1">Nombre Completo *</label>
                    <input
                        id="m-name"
                        type="text"
                        bind:value={memberForm.name}
                        placeholder="Ej. Andrés Ramírez"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                </div>

                <div>
                    <label for="m-email" class="block font-semibold text-slate-300 mb-1">Correo Electrónico *</label>
                    <input
                        id="m-email"
                        type="email"
                        bind:value={memberForm.email}
                        placeholder="andres@taller.com"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                </div>

                <div>
                    <label for="m-pass" class="block font-semibold text-slate-300 mb-1">Contraseña Temporal *</label>
                    <input
                        id="m-pass"
                        type="password"
                        bind:value={memberForm.password}
                        placeholder="••••••••"
                        required
                        minlength="6"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                </div>

                <div>
                    <label for="m-role" class="block font-semibold text-slate-300 mb-1">Rol en este Taller *</label>
                    <select
                        id="m-role"
                        bind:value={memberForm.role}
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    >
                        <option value="worker">Trabajador (Módulos Kanban, Pedidos, Productos, Inventario)</option>
                        <option value="owner">Dueño Co-Administrador (Acceso a configuración)</option>
                    </select>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={() => showAddMemberModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={memberProcessing}
                        class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/25 disabled:opacity-50"
                    >
                        Guardar Miembro
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL EDITAR MIEMBRO DEL EQUIPO -->
{#if showEditMemberModal}
    <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-sm w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">Editar Miembro del Equipo</h3>
                <button type="button" onclick={() => showEditMemberModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitEditMember} class="space-y-4 text-xs">
                <div>
                    <label for="em-name" class="block font-semibold text-slate-300 mb-1">Nombre Completo *</label>
                    <input
                        id="em-name"
                        type="text"
                        bind:value={editMemberForm.name}
                        placeholder="Ej. Andrés Ramírez"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div>
                    <label for="em-email" class="block font-semibold text-slate-300 mb-1">Correo Electrónico *</label>
                    <input
                        id="em-email"
                        type="email"
                        bind:value={editMemberForm.email}
                        placeholder="andres@taller.com"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.email}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.email}</p>
                    {/if}
                </div>

                <div>
                    <label for="em-role" class="block font-semibold text-slate-300 mb-1">Rol en este Taller *</label>
                    <select
                        id="em-role"
                        bind:value={editMemberForm.role}
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    >
                        <option value="worker">Trabajador (Módulos Kanban, Pedidos, Productos, Inventario)</option>
                        <option value="owner">Dueño Co-Administrador (Acceso a configuración)</option>
                    </select>
                    {#if errors.role}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.role}</p>
                    {/if}
                </div>

                <div>
                    <label for="em-pass" class="block font-semibold text-slate-300 mb-1">Nueva Contraseña (dejar en blanco para mantener la actual)</label>
                    <input
                        id="em-pass"
                        type="password"
                        bind:value={editMemberForm.password}
                        placeholder="•••••••• (opcional)"
                        minlength="6"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.password}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.password}</p>
                    {/if}
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={() => showEditMemberModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={editMemberProcessing}
                        class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/25 disabled:opacity-50"
                    >
                        {editMemberProcessing ? 'Actualizando...' : 'Actualizar Miembro'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
