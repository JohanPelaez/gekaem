<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';

    let { suppliers, filters = { search: '' }, errors = {} } = $props();

    let search = $state('');
    $effect(() => {
        search = filters.search ?? '';
    });

    let viewMode = $state('table'); // 'table' | 'cards'
    let expandedRows = $state(new Set());

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

    function toggleRow(id) {
        const next = new Set(expandedRows);
        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }
        expandedRows = next;
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
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Módulo 4
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Directorio de Proveedores</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Gestión de abastecimiento, insumos suministrados y tiempos de reposición (Lead Time).
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto">
                <ViewToggle bind:mode={viewMode} storageKey="gekaem_suppliers_view" />
                <button
                    type="button"
                    onclick={openCreate}
                    class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-colors shadow-lg shadow-emerald-600/20 flex items-center gap-2 shrink-0"
                >
                    <span>+</span>
                    <span>Nuevo Proveedor</span>
                </button>
            </div>
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

        <!-- VISTA TABLA (DEFAULT) -->
        {#if viewMode === 'table'}
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-md">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950/80 text-slate-400 uppercase font-semibold border-b border-slate-800 text-[10px] tracking-wider">
                            <tr>
                                <th class="p-3.5 w-10 text-center"></th>
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
                                {@const isExpanded = expandedRows.has(supplier.id)}
                                <tr class="hover:bg-slate-800/40 transition-colors {isExpanded ? 'bg-slate-800/25' : ''}">
                                    <td class="p-3.5 text-center">
                                        <button
                                            type="button"
                                            onclick={() => toggleRow(supplier.id)}
                                            class="w-6 h-6 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center transition-transform {isExpanded ? 'rotate-90 text-emerald-400' : ''}"
                                            title={isExpanded ? 'Colapsar detalles' : 'Expandir datos completos'}
                                        >
                                            ▶
                                        </button>
                                    </td>
                                    <td class="p-4">
                                        <button
                                            type="button"
                                            class="font-bold text-white text-sm hover:text-emerald-400 text-left block transition-colors"
                                            onclick={() => toggleRow(supplier.id)}
                                        >
                                            {supplier.name}
                                        </button>
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

                                <!-- RENGLÓN EXPANDIDO -->
                                {#if isExpanded}
                                    <tr class="bg-slate-950/70 border-b border-slate-800">
                                        <td colspan="7" class="p-4 sm:p-5">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div class="p-3.5 bg-slate-900 border border-slate-800 rounded-xl space-y-1.5">
                                                    <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block border-b border-slate-800 pb-1">
                                                        Contacto y Despacho
                                                    </span>
                                                    <div class="text-xs space-y-1 pt-1">
                                                        <div class="flex justify-between"><span class="text-slate-400">Persona de contacto:</span> <span class="font-medium text-white">{supplier.contact_name ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-400">Teléfono:</span> <span class="font-medium text-white">{supplier.phone ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-400">Email:</span> <span class="font-medium text-white">{supplier.email ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-400">Lead time de entrega:</span> <span class="font-bold text-amber-300">{supplier.lead_time_days} días</span></div>
                                                    </div>
                                                </div>

                                                <div class="p-3.5 bg-slate-900 border border-slate-800 rounded-xl space-y-2">
                                                    <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block border-b border-slate-800 pb-1">
                                                        Materiales Suministrados ({supplier.raw_materials?.length ?? 0})
                                                    </span>
                                                    <div class="space-y-1 max-h-32 overflow-y-auto pt-1">
                                                        {#each supplier.raw_materials ?? [] as mat}
                                                            <div class="flex items-center justify-between p-1.5 bg-slate-950 rounded-lg text-xs text-slate-300 border border-slate-800">
                                                                <span class="font-medium">{mat.name}</span>
                                                                <span class="text-[10px] text-slate-400 font-mono capitalize">({mat.unit_of_measure})</span>
                                                            </div>
                                                        {:else}
                                                            <span class="text-slate-500 text-xs italic">No tiene materiales asociados en recetas o inventario.</span>
                                                        {/each}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                {/if}
                            {:else}
                                <tr>
                                    <td colspan="7" class="p-10 text-center text-slate-500 text-xs">
                                        No se encontraron proveedores registrados.
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- VISTA CARDS -->
        {:else}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                {#each suppliers.data as supplier (supplier.id)}
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col justify-between shadow-md hover:border-slate-700 transition-colors">
                        <div>
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="px-2.5 py-1 rounded-full font-mono font-bold text-xs bg-amber-500/15 text-amber-300 border border-amber-500/30">
                                    {supplier.lead_time_days} {supplier.lead_time_days === 1 ? 'día' : 'días'}
                                </span>
                                <span class="text-[11px] text-slate-500 font-medium">Lead Time</span>
                            </div>

                            <h3 class="text-base font-bold text-white">{supplier.name}</h3>

                            <div class="mt-3.5 p-3 bg-slate-950/70 rounded-xl border border-slate-800/80 space-y-1.5 text-xs">
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-400">Contacto:</span>
                                    <span class="font-medium text-slate-200">{supplier.contact_name ?? 'No especificado'}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-400">Teléfono:</span>
                                    <span class="font-medium text-slate-200">{supplier.phone ?? 'N/A'}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-400">Email:</span>
                                    <span class="font-medium text-slate-200 truncate max-w-[170px]">{supplier.email ?? 'N/A'}</span>
                                </div>
                            </div>

                            <!-- Insumos Vinculados -->
                            <div class="mt-3 pt-2.5 border-t border-slate-800/60">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">
                                    Materiales ({supplier.raw_materials?.length ?? 0}):
                                </span>
                                <div class="flex flex-wrap gap-1">
                                    {#each supplier.raw_materials ?? [] as mat}
                                        <span class="text-[10px] px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700/60">
                                            {mat.name}
                                        </span>
                                    {:else}
                                        <span class="text-slate-500 text-[10px] italic">Sin materiales</span>
                                    {/each}
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="mt-4 pt-3 border-t border-slate-800 flex items-center justify-end gap-2">
                            <button
                                type="button"
                                onclick={() => openEdit(supplier)}
                                class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold"
                            >
                                Editar
                            </button>
                            <button
                                type="button"
                                onclick={() => destroy(supplier)}
                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 text-slate-400 hover:text-rose-300 text-xs font-semibold"
                            >
                                Eliminar
                            </button>
                        </div>
                    </div>
                {:else}
                    <div class="col-span-full p-12 text-center bg-slate-900 border border-slate-800 rounded-2xl text-slate-500 text-xs">
                        No hay proveedores registrados. Haz clic en "Nuevo Proveedor" para comenzar.
                    </div>
                {/each}
            </div>
        {/if}

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
                                class="px-3 py-1.5 rounded-lg border text-xs font-medium {link.active ? 'bg-emerald-600 text-white border-emerald-600 font-bold' : 'bg-slate-800 border-slate-700 text-slate-300'}"
                            >
                                {@html link.label}
                            </Link>
                        {/if}
                    {/each}
                </div>
            </div>
        {/if}
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
                    <label for="s-name" class="block font-semibold text-slate-300 mb-1">Razón Social / Nombre Comercial *</label>
                    <input
                        id="s-name"
                        type="text"
                        bind:value={form.name}
                        placeholder="Ej. Distribuidora Textil Andina"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="s-contact" class="block font-semibold text-slate-300 mb-1">Persona de Contacto</label>
                        <input
                            id="s-contact"
                            type="text"
                            bind:value={form.contact_name}
                            placeholder="Ej. Andrés Ramírez"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                    <div>
                        <label for="s-phone" class="block font-semibold text-slate-300 mb-1">Teléfono / WhatsApp</label>
                        <input
                            id="s-phone"
                            type="text"
                            bind:value={form.phone}
                            placeholder="+57 310 987 6543"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                    <div>
                        <label for="s-lead" class="block font-semibold text-slate-300 mb-1">Tiempo de Entrega (Días) *</label>
                        <input
                            id="s-lead"
                            type="number"
                            min="0"
                            max="365"
                            bind:value={form.lead_time_days}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono font-bold"
                        />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
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
