<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let { suppliers, filters = { search: '' }, errors = {} } = $props();

    let search = $state('');
    $effect(() => {
        search = filters.search ?? '';
    });
    let showModal = $state(false);
    let editingSupplier = $state(null);
    let processing = $state(false);

    let form = $state({
        name: '',
        contact_name: '',
        phone: '',
        email: '',
        lead_time_days: 1,
    });

    function resetForm() {
        form.name = '';
        form.contact_name = '';
        form.phone = '';
        form.email = '';
        form.lead_time_days = 1;
    }

    let searchTimeout;
    function handleSearchInput() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            router.get(
                '/suppliers',
                { search },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    }

    function openCreate() {
        editingSupplier = null;
        resetForm();
        showModal = true;
    }

    function openEdit(supplier) {
        editingSupplier = supplier;
        form.name = supplier.name ?? '';
        form.contact_name = supplier.contact_name ?? '';
        form.phone = supplier.phone ?? '';
        form.email = supplier.email ?? '';
        form.lead_time_days = supplier.lead_time_days ?? 1;
        showModal = true;
    }

    function submit(e) {
        e.preventDefault();
        if (editingSupplier) {
            router.patch(`/suppliers/${editingSupplier.id}`, form, {
                preserveScroll: true,
                onStart: () => { processing = true; },
                onFinish: () => { processing = false; },
                onSuccess: () => {
                    showModal = false;
                    resetForm();
                },
            });
        } else {
            router.post('/suppliers', form, {
                preserveScroll: true,
                onStart: () => { processing = true; },
                onFinish: () => { processing = false; },
                onSuccess: () => {
                    showModal = false;
                    resetForm();
                },
            });
        }
    }

    function destroy(supplier) {
        if (confirm(`¿Eliminar al proveedor "${supplier.name}"?`)) {
            router.delete(`/suppliers/${supplier.id}`, {
                preserveScroll: true,
            });
        }
    }
</script>

<AppLayout title="Proveedores">
    <div class="space-y-6">
        <!-- ENCABEZADO Y ACCIONES -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Módulo 4
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Directorio de Proveedores</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Gestiona los proveedores de insumos y sus tiempos de reposición (Lead Time) utilizados en el cálculo MRP de escasez.
                </p>
            </div>
            <button
                type="button"
                onclick={openCreate}
                class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-colors shadow-lg shadow-emerald-600/20 flex items-center gap-2 shrink-0 self-start sm:self-auto"
            >
                <span>+</span>
                <span>Nuevo Proveedor</span>
            </button>
        </div>

        <!-- BUSCADOR -->
        <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl flex items-center gap-3">
            <span class="text-slate-400 text-sm">🔍</span>
            <input
                type="text"
                bind:value={search}
                oninput={handleSearchInput}
                placeholder="Buscar por razón social, persona de contacto, teléfono o email..."
                class="w-full bg-transparent text-white placeholder-slate-500 text-xs focus:outline-none"
            />
            {#if search}
                <button
                    type="button"
                    onclick={() => { search = ''; handleSearchInput(); }}
                    class="text-xs text-slate-500 hover:text-white"
                >
                    Limpiar
                </button>
            {/if}
        </div>

        <!-- TABLA DE PROVEEDORES -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-md">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950/80 text-slate-400 uppercase font-semibold border-b border-slate-800 text-[10px] tracking-wider">
                        <tr>
                            <th class="p-4">Proveedor</th>
                            <th class="p-4">Persona de Contacto</th>
                            <th class="p-4">Contacto Directo</th>
                            <th class="p-4 text-center">Tiempo Reposición (Lead Time)</th>
                            <th class="p-4">Materiales Suministrados</th>
                            <th class="p-4 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-slate-300">
                        {#each suppliers.data as supplier (supplier.id)}
                            <tr class="hover:bg-slate-800/40 transition-colors">
                                <td class="p-4">
                                    <div class="font-bold text-white text-sm">{supplier.name}</div>
                                </td>
                                <td class="p-4 text-slate-300">
                                    {supplier.contact_name ?? 'No especificado'}
                                </td>
                                <td class="p-4">
                                    <div>{supplier.phone ?? 'Sin teléfono'}</div>
                                    <div class="text-slate-400 text-[11px]">{supplier.email ?? 'Sin email'}</div>
                                </td>
                                <td class="p-4 text-center">
                                    <span class="px-2.5 py-1 rounded-full font-mono font-bold text-xs bg-amber-500/15 text-amber-300 border border-amber-500/30">
                                        {supplier.lead_time_days} {supplier.lead_time_days === 1 ? 'día' : 'días'}
                                    </span>
                                </td>
                                <td class="p-4">
                                    <div class="flex flex-wrap gap-1 max-w-xs">
                                        {#each supplier.raw_materials ?? [] as mat}
                                            <span class="text-[10px] px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700/60">
                                                {mat.name}
                                            </span>
                                        {:else}
                                            <span class="text-slate-600 text-[11px]">Sin materiales vinculados</span>
                                        {/each}
                                    </div>
                                </td>
                                <td class="p-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            onclick={() => openEdit(supplier)}
                                            class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition-colors"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            onclick={() => destroy(supplier)}
                                            class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 text-slate-400 hover:text-rose-300 text-xs font-medium transition-colors"
                                        >
                                            Eliminar
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        {:else}
                            <tr>
                                <td colspan="6" class="p-10 text-center text-slate-500 text-xs">
                                    No se encontraron proveedores registrados con ese criterio.
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>

            <!-- PAGINACIÓN -->
            {#if suppliers.links && suppliers.links.length > 3}
                <div class="p-4 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
                    <div>
                        Mostrando {suppliers.from ?? 0} a {suppliers.to ?? 0} de {suppliers.total} proveedores
                    </div>
                    <div class="flex items-center gap-1">
                        {#each suppliers.links as link}
                            {#if link.url}
                                <Link
                                    href={link.url}
                                    class="px-3 py-1.5 rounded-lg border text-xs font-medium transition-colors {link.active ? 'bg-emerald-600 text-white border-emerald-600 font-bold' : 'bg-slate-800 border-slate-700 text-slate-300 hover:bg-slate-700'}"
                                >
                                    {@html link.label}
                                </Link>
                            {:else}
                                <span class="px-2 py-1 text-slate-600">{@html link.label}</span>
                            {/if}
                        {/each}
                    </div>
                </div>
            {/if}
        </div>
    </div>
</AppLayout>

<!-- MODAL CREAR / EDITAR PROVEEDOR -->
{#if showModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">
                    {editingSupplier ? `Editar Proveedor: ${editingSupplier.name}` : 'Registrar Nuevo Proveedor'}
                </h3>
                <button type="button" onclick={() => showModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submit} class="space-y-4 text-xs">
                <div>
                    <label for="s-name" class="block font-semibold text-slate-300 mb-1">Nombre Comercial / Razón Social *</label>
                    <input
                        id="s-name"
                        type="text"
                        bind:value={form.name}
                        placeholder="Ej. Distribuidora Textil del Valle"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div>
                    <label for="s-contact" class="block font-semibold text-slate-300 mb-1">Nombre del Asesor / Contacto</label>
                    <input
                        id="s-contact"
                        type="text"
                        bind:value={form.contact_name}
                        placeholder="Ej. Hernán Darío Correa"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="s-phone" class="block font-semibold text-slate-300 mb-1">Teléfono / WhatsApp</label>
                        <input
                            id="s-phone"
                            type="text"
                            bind:value={form.phone}
                            placeholder="+57 300 123 4567"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                    <div>
                        <label for="s-email" class="block font-semibold text-slate-300 mb-1">Correo Electrónico</label>
                        <input
                            id="s-email"
                            type="email"
                            bind:value={form.email}
                            placeholder="ventas@proveedor.com"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                </div>

                <div>
                    <label for="s-lead" class="block font-semibold text-slate-300 mb-1">
                        Tiempo de Reposición (Lead Time en Días) *
                    </label>
                    <input
                        id="s-lead"
                        type="number"
                        min="0"
                        max="365"
                        bind:value={form.lead_time_days}
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                    />
                    <p class="text-[10px] text-slate-500 mt-1">
                        Días que tarda el proveedor en entregar un pedido desde que se solicita.
                    </p>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={() => showModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                    >
                        {editingSupplier ? 'Actualizar Proveedor' : 'Guardar Proveedor'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
