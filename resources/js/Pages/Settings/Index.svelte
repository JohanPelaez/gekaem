<script>
    import { router } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let { tenant, teamMembers = [], errors = {} } = $props();

    let settingsProcessing = $state(false);
    let memberProcessing = $state(false);
    let showAddMemberModal = $state(false);

    // Formulario de Parámetros del Taller en Svelte 5 Rune (Sin prefijo $)
    let settingsForm = $state({
        name: '',
        daily_hours: 8,
        default_buffer_percent: 20,
        parallel_workers: 1,
        workdays: [1, 2, 3, 4, 5, 6],
        currency: 'COP',
    });

    $effect(() => {
        if (tenant) {
            settingsForm.name = tenant.name ?? '';
            settingsForm.daily_hours = tenant.daily_hours ?? 8;
            settingsForm.default_buffer_percent = tenant.default_buffer_percent ?? 20;
            settingsForm.parallel_workers = tenant.settings?.parallel_workers ?? 1;
            settingsForm.workdays = tenant.settings?.workdays ?? [1, 2, 3, 4, 5, 6];
            settingsForm.currency = tenant.settings?.currency ?? 'COP';
        }
    });

    // Formulario de Nuevo Miembro del Taller
    let memberForm = $state({
        name: '',
        email: '',
        password: '',
        role: 'worker',
    });

    let productiveMinutesDerived = $derived(
        Math.round(Number(settingsForm.daily_hours || 0) * 60),
    );

    const weekDays = [
        { id: 1, label: 'Lun' },
        { id: 2, label: 'Mar' },
        { id: 3, label: 'Mié' },
        { id: 4, label: 'Jue' },
        { id: 5, label: 'Vie' },
        { id: 6, label: 'Sáb' },
        { id: 7, label: 'Dom' },
    ];

    function toggleWorkday(dayId) {
        if (settingsForm.workdays.includes(dayId)) {
            settingsForm.workdays = settingsForm.workdays.filter((d) => d !== dayId);
        } else {
            settingsForm.workdays = [...settingsForm.workdays, dayId].sort();
        }
    }

    function submitSettings(e) {
        e.preventDefault();
        settingsProcessing = true;
        router.patch('/settings', settingsForm, {
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

    function removeMember(member) {
        if (confirm(`¿Remover a "${member.name}" de este taller?`)) {
            router.delete(`/settings/members/${member.id}`, {
                preserveScroll: true,
            });
        }
    }
</script>

<AppLayout title="Configuración del Taller">
    <div class="space-y-6">
        <!-- ENCABEZADO -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Ajustes del Taller
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Configuración y Capacidad del Taller</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Define la jornada diaria de trabajo, holguras de producción y administra las cuentas de tu equipo.
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- SECCIÓN 1: CAPACIDAD Y PARÁMETROS DE PRODUCCIÓN -->
            <div class="lg:col-span-2 bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-md space-y-5">
                <div class="border-b border-slate-800 pb-3">
                    <h2 class="text-base font-bold text-white">Parámetros Productivos del Taller</h2>
                    <p class="text-xs text-slate-400">
                        Estos valores alimentan el motor de predicción de fechas de entrega y tiempos en cola WIP.
                    </p>
                </div>

                <form onsubmit={submitSettings} class="space-y-4 text-xs">
                    <div>
                        <label for="set-name" class="block font-semibold text-slate-300 mb-1">Nombre Comercial del Taller *</label>
                        <input
                            id="set-name"
                            type="text"
                            bind:value={settingsForm.name}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3.5 py-2.5 text-white text-xs focus:outline-none focus:border-emerald-500 font-medium"
                        />
                        {#if errors.name}
                            <p class="text-[11px] text-rose-400 mt-1">{errors.name}</p>
                        {/if}
                    </div>

                    <!-- Jornada Productiva en Horas y Minutos calculados -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 bg-slate-950 rounded-2xl border border-slate-800/80">
                        <div>
                            <label for="set-hours" class="block font-semibold text-slate-300 mb-1">
                                Jornada Laboral Diaria (en Horas) *
                            </label>
                            <div class="flex items-center gap-2">
                                <input
                                    id="set-hours"
                                    type="number"
                                    step="0.5"
                                    min="1"
                                    max="24"
                                    bind:value={settingsForm.daily_hours}
                                    required
                                    class="w-32 bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white font-mono font-bold text-sm focus:outline-none focus:border-emerald-500"
                                />
                                <span class="text-slate-400 font-medium">horas / día</span>
                            </div>
                            <span class="text-[10px] text-slate-500 mt-1 block">
                                Ej. 8 horas de producción activa por turno.
                            </span>
                        </div>

                        <div>
                            <span class="block font-semibold text-slate-400 mb-1">Equivalente en Minutos Productivos:</span>
                            <div class="text-2xl font-mono font-black text-amber-300">
                                ⏱ {productiveMinutesDerived} min
                            </div>
                            <span class="text-[10px] text-slate-500 mt-1 block">
                                Se almacena como `daily_productive_minutes` en el motor de cálculo.
                            </span>
                        </div>
                    </div>

                    <!-- Holgura, Trabajadores Paralelos y Moneda -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label for="set-buffer" class="block font-semibold text-slate-300 mb-1">Holgura por Defecto (%) *</label>
                            <input
                                id="set-buffer"
                                type="number"
                                min="0"
                                max="100"
                                bind:value={settingsForm.default_buffer_percent}
                                required
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-emerald-400 font-mono font-bold text-xs"
                            />
                            <span class="text-[10px] text-slate-500 mt-0.5 block">Margen para imprevistos</span>
                        </div>

                        <div>
                            <label for="set-workers" class="block font-semibold text-slate-300 mb-1">Estaciones / Operarios</label>
                            <input
                                id="set-workers"
                                type="number"
                                min="1"
                                max="50"
                                bind:value={settingsForm.parallel_workers}
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white font-mono font-bold text-xs"
                            />
                            <span class="text-[10px] text-slate-500 mt-0.5 block">Capacidad simultánea</span>
                        </div>

                        <div>
                            <label for="set-curr" class="block font-semibold text-slate-300 mb-1">Moneda del Taller</label>
                            <select
                                id="set-curr"
                                bind:value={settingsForm.currency}
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white text-xs"
                            >
                                <option value="COP">COP ($ Pesos Colombianos)</option>
                                <option value="USD">USD ($ Dólar)</option>
                                <option value="MXN">MXN ($ Peso Mexicano)</option>
                                <option value="EUR">EUR (€ Euro)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Días Laborales de la Semana -->
                    <div>
                        <span class="block font-semibold text-slate-300 mb-1.5">Días Laborales Activos del Taller</span>
                        <div class="flex flex-wrap gap-2">
                            {#each weekDays as day}
                                {@const isChecked = settingsForm.workdays.includes(day.id)}
                                <button
                                    type="button"
                                    onclick={() => toggleWorkday(day.id)}
                                    class="px-3 py-1.5 rounded-xl text-xs font-semibold border transition-all {isChecked ? 'bg-emerald-600 text-white border-emerald-500 shadow-sm' : 'bg-slate-950 text-slate-500 border-slate-800 hover:text-slate-300'}"
                                >
                                    {day.label}
                                </button>
                            {/each}
                        </div>
                        <span class="text-[10px] text-slate-500 mt-1 block">
                            Los días no seleccionados no se contarán al predecir la fecha de entrega de los pedidos.
                        </span>
                    </div>

                    <div class="pt-3 border-t border-slate-800 flex justify-end">
                        <button
                            type="submit"
                            disabled={settingsProcessing}
                            class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs shadow-lg shadow-emerald-600/25 transition-all disabled:opacity-50"
                        >
                            {settingsProcessing ? 'Guardando...' : 'Guardar Parámetros del Taller'}
                        </button>
                    </div>
                </form>
            </div>

            <!-- SECCIÓN 2: EQUIPO DEL TALLER -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-md space-y-5">
                <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                    <div>
                        <h2 class="text-base font-bold text-white">Equipo del Taller</h2>
                        <p class="text-xs text-slate-400">Trabajadores y colaboradores.</p>
                    </div>
                    <button
                        type="button"
                        onclick={() => showAddMemberModal = true}
                        class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs border border-slate-700 transition-colors"
                    >
                        + Miembro
                    </button>
                </div>

                <div class="space-y-2">
                    {#each teamMembers as member (member.id)}
                        {@const isOwner = member.pivot?.role === 'owner'}
                        <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 flex items-center justify-between text-xs">
                            <div class="space-y-0.5">
                                <div class="font-bold text-white">{member.name}</div>
                                <div class="text-slate-400 text-[11px] font-mono">{member.email}</div>
                                <span class="inline-block text-[9px] px-2 py-0.2 rounded font-bold uppercase tracking-wider border {isOwner ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : 'bg-sky-500/20 text-sky-300 border-sky-500/30'}">
                                    {isOwner ? 'Dueño / Admin' : 'Trabajador'}
                                </span>
                            </div>

                            {#if member.id !== tenant.owner_id}
                                <button
                                    type="button"
                                    onclick={() => removeMember(member)}
                                    class="text-slate-500 hover:text-rose-400 text-xs p-1"
                                    title="Remover del taller"
                                >
                                    ✕
                                </button>
                            {/if}
                        </div>
                    {/each}
                </div>
            </div>
        </div>
    </div>
</AppLayout>

<!-- MODAL AGREGAR MIEMBRO AL EQUIPO -->
{#if showAddMemberModal}
    <div class="fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-sm w-full p-6 shadow-2xl space-y-4">
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
                    <label for="m-pass" class="block font-semibold text-slate-300 mb-1">Contraseña de Acceso *</label>
                    <input
                        id="m-pass"
                        type="password"
                        bind:value={memberForm.password}
                        placeholder="••••••••"
                        required
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
                        <option value="worker">Trabajador (Solo módulos operativos: Kanban, Pedidos, Productos)</option>
                        <option value="owner">Dueño Co-Administrador (Acceso a configuración y métricas)</option>
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
