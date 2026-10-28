<script>
    import { untrack } from 'svelte';
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';

    let { suppliers, filters = { search: '' }, errors = {} } = $props();

    let search = $state('');
    $effect(() => {
        const incoming = filters;
        untrack(() => {
            search = incoming.search ?? '';
        });
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
        payment_methods: '',
        notes: '',
    });

    function resetForm() {
        form.name = '';
        form.contact_name = '';
        form.phone = '';
        form.email = '';
        form.lead_time_days = 1;
        form.payment_methods = '';
        form.notes = '';
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
        form.payment_methods = supplier.payment_methods ?? '';
        form.notes = supplier.notes ?? '';
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
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] p-6 rounded-2xl shadow-sm">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold tracking-wider uppercase bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30">
                        Módulo 4
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Directorio de Proveedores</h1>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Gestión de abastecimiento, insumos suministrados y tiempos de reposición (Lead Time).
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto">
                <ViewToggle bind:mode={viewMode} storageKey="gekaem_suppliers_view" />
                <button
                    type="button"
                    onclick={openCreate}
                    class="px-4 py-2.5 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-semibold text-xs transition shadow-sm flex items-center gap-2 shrink-0"
                >
                    <span>+</span>
                    <span>Nuevo Proveedor</span>
                </button>
            </div>
        </div>

        <!-- BUSCADOR -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] p-4 rounded-xl flex items-center gap-3 shadow-sm">
            <span class="text-slate-400 text-sm">🔍</span>
            <label for="s-search" class="sr-only">Buscar proveedor</label>
            <input
                id="s-search"
                type="text"
                bind:value={search}
                oninput={handleSearchInput}
                placeholder="Buscar por razón social, persona de contacto, teléfono o email..."
                class="w-full bg-transparent text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:outline-none"
            />
            {#if search}
                <button
                    type="button"
                    onclick={() => { search = ''; handleSearchInput(); }}
                    class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-white transition-colors"
                >
                    Limpiar
                </button>
            {/if}
        </div>

        <!-- VISTA TABLA (DEFAULT) -->
        {#if viewMode === 'table'}
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50/80 dark:bg-[#0B0F17]/60 text-[#64748B] dark:text-slate-400 uppercase font-semibold border-b border-slate-200 dark:border-[#26334D] text-[10px] tracking-wider">
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
                        <tbody class="divide-y divide-slate-100 dark:divide-[#26334D]/60 text-slate-700 dark:text-slate-300">
                            {#each suppliers.data as supplier (supplier.id)}
                                {@const isExpanded = expandedRows.has(supplier.id)}
                                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors {isExpanded ? 'bg-slate-50/50 dark:bg-slate-800/25' : ''}">
                                    <td class="p-3.5 text-center">
                                        <button
                                            type="button"
                                            onclick={() => toggleRow(supplier.id)}
                                            class="w-6 h-6 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-300 flex items-center justify-center transition-transform {isExpanded ? 'rotate-90 text-[#4F46E5] dark:text-emerald-400' : ''}"
                                            title={isExpanded ? 'Colapsar detalles' : 'Expandir datos completos'}
                                        >
                                            ▶
                                        </button>
                                    </td>
                                    <td class="p-4">
                                        <button
                                            type="button"
                                            class="font-bold text-slate-900 dark:text-white text-sm hover:text-[#4F46E5] dark:hover:text-emerald-400 text-left block transition-colors"
                                            onclick={() => toggleRow(supplier.id)}
                                        >
                                            {supplier.name}
                                        </button>
                                        {#if supplier.payment_methods}
                                            <div class="mt-1">
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-medium bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30">
                                                    <span>💳</span>
                                                    <span>{supplier.payment_methods}</span>
                                                </span>
                                            </div>
                                        {/if}
                                        {#if supplier.notes}
                                            <div class="text-[11px] text-slate-500 dark:text-slate-400 truncate max-w-xs mt-0.5">{supplier.notes}</div>
                                        {/if}
                                    </td>
                                    <td class="p-4 text-slate-700 dark:text-slate-300 font-medium">
                                        {supplier.contact_name ?? 'No especificado'}
                                    </td>
                                    <td class="p-4">
                                        <div class="font-medium text-slate-900 dark:text-slate-100">{supplier.phone ?? 'Sin teléfono'}</div>
                                        <div class="text-slate-500 dark:text-slate-400 text-[11px]">{supplier.email ?? 'Sin email'}</div>
                                    </td>
                                    <td class="p-4 text-center">
                                        <span class="px-2.5 py-1 rounded-full font-mono font-bold text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-500/30">
                                            {supplier.lead_time_days} {supplier.lead_time_days === 1 ? 'día' : 'días'}
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <div class="flex flex-wrap gap-1 max-w-xs">
                                            {#each supplier.raw_materials ?? [] as mat}
                                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                                    {mat.name}
                                                </span>
                                            {:else}
                                                <span class="text-slate-400 dark:text-slate-500 text-[11px]">Sin materiales vinculados</span>
                                            {/each}
                                        </div>
                                    </td>
                                    <td class="p-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                onclick={() => openEdit(supplier)}
                                                class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-medium border border-slate-200 dark:border-[#26334D] transition-colors"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => destroy(supplier)}
                                                class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-rose-100 dark:bg-slate-800 dark:hover:bg-rose-950/60 text-slate-600 hover:text-rose-700 dark:text-slate-400 dark:hover:text-rose-300 text-xs font-medium border border-slate-200 dark:border-[#26334D] transition-colors"
                                            >
                                                Eliminar
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- RENGLÓN EXPANDIDO -->
                                {#if isExpanded}
                                    <tr class="bg-slate-50/90 dark:bg-[#0B0F17]/70 border-b border-slate-200 dark:border-[#26334D]">
                                        <td colspan="7" class="p-4 sm:p-5">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div class="p-3.5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl space-y-1.5 shadow-sm">
                                                    <span class="text-[10px] uppercase font-bold text-[#64748B] dark:text-slate-400 tracking-wider block border-b border-slate-100 dark:border-[#26334D] pb-1">
                                                        Contacto y Despacho
                                                    </span>
                                                    <div class="text-xs space-y-1 pt-1">
                                                        <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">Persona de contacto:</span> <span class="font-medium text-slate-900 dark:text-white">{supplier.contact_name ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">Teléfono:</span> <span class="font-medium text-slate-900 dark:text-white">{supplier.phone ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">Email:</span> <span class="font-medium text-slate-900 dark:text-white">{supplier.email ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">Lead time de entrega:</span> <span class="font-bold text-amber-600 dark:text-amber-300">{supplier.lead_time_days} días</span></div>
                                                        {#if supplier.payment_methods}
                                                            <div class="pt-1.5 border-t border-slate-100 dark:border-[#26334D] text-slate-700 dark:text-slate-300">
                                                                <span class="text-slate-400 block text-[10px] uppercase font-semibold">Medios de Pago:</span>
                                                                <span class="font-medium text-emerald-600 dark:text-emerald-400">{supplier.payment_methods}</span>
                                                            </div>
                                                        {/if}
                                                        {#if supplier.notes}
                                                            <div class="pt-1.5 border-t border-slate-100 dark:border-[#26334D] text-slate-500 dark:text-slate-400 italic text-[11px]">
                                                                "{supplier.notes}"
                                                            </div>
                                                        {/if}
                                                    </div>
                                                </div>

                                                <div class="p-3.5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl space-y-2 shadow-sm">
                                                    <span class="text-[10px] uppercase font-bold text-[#64748B] dark:text-slate-400 tracking-wider block border-b border-slate-100 dark:border-[#26334D] pb-1">
                                                        Materiales Suministrados ({supplier.raw_materials?.length ?? 0})
                                                    </span>
                                                    <div class="space-y-1 max-h-36 overflow-y-auto pt-1">
                                                        {#each supplier.raw_materials ?? [] as mat}
                                                            <div class="flex items-center justify-between p-1.5 bg-slate-50 dark:bg-[#0B0F17] rounded-lg text-xs text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-[#26334D]">
                                                                <span class="font-medium">{mat.name}</span>
                                                                <span class="text-[10px] text-slate-400 font-mono capitalize">({mat.unit_of_measure})</span>
                                                            </div>
                                                        {:else}
                                                            <span class="text-slate-400 dark:text-slate-500 text-xs italic">No tiene materiales asociados en recetas o inventario.</span>
                                                        {/each}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                {/if}
                            {:else}
                                <tr>
                                    <td colspan="7" class="p-10 text-center text-slate-400 dark:text-slate-500 text-xs">
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
                    <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-5 flex flex-col justify-between shadow-sm hover:border-[#4F46E5] dark:hover:border-[#06B6D4] transition">
                        <div>
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="px-2.5 py-1 rounded-full font-mono font-bold text-xs bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-200 dark:border-amber-500/30">
                                    {supplier.lead_time_days} {supplier.lead_time_days === 1 ? 'día' : 'días'}
                                </span>
                                <span class="text-[11px] text-slate-400 font-medium">Lead Time</span>
                            </div>

                            <h3 class="text-base font-bold text-slate-900 dark:text-white">{supplier.name}</h3>

                            <div class="mt-3.5 p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D] space-y-1.5 text-xs">
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500 dark:text-slate-400">Contacto:</span>
                                    <span class="font-medium text-slate-800 dark:text-slate-200">{supplier.contact_name ?? 'No especificado'}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500 dark:text-slate-400">Teléfono:</span>
                                    <span class="font-medium text-slate-800 dark:text-slate-200">{supplier.phone ?? 'N/A'}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500 dark:text-slate-400">Email:</span>
                                    <span class="font-medium text-slate-800 dark:text-slate-200 truncate max-w-[170px]">{supplier.email ?? 'N/A'}</span>
                                </div>
                                {#if supplier.payment_methods}
                                    <div class="flex justify-between items-start gap-1 pt-1 border-t border-slate-200/60 dark:border-[#26334D]/60">
                                        <span class="text-slate-500 dark:text-slate-400 shrink-0">Pagos:</span>
                                        <span class="font-medium text-emerald-600 dark:text-emerald-400 text-right truncate max-w-[160px]">{supplier.payment_methods}</span>
                                    </div>
                                {/if}
                            </div>

                            {#if supplier.notes}
                                <div class="mt-2 text-[11px] text-slate-500 dark:text-slate-400 italic">
                                    "{supplier.notes}"
                                </div>
                            {/if}

                            <!-- Insumos Vinculados -->
                            <div class="mt-3 pt-2.5 border-t border-slate-100 dark:border-[#26334D]">
                                <span class="text-[10px] font-bold text-[#64748B] dark:text-slate-400 uppercase tracking-wider block mb-1">
                                    Materiales ({supplier.raw_materials?.length ?? 0}):
                                </span>
                                <div class="flex flex-wrap gap-1">
                                    {#each supplier.raw_materials ?? [] as mat}
                                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                            {mat.name}
                                        </span>
                                    {:else}
                                        <span class="text-slate-400 text-[10px] italic">Sin materiales</span>
                                    {/each}
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-[#26334D] flex items-center justify-end gap-2">
                            <button
                                type="button"
                                onclick={() => openEdit(supplier)}
                                class="px-3 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-[#26334D]"
                            >
                                Editar
                            </button>
                            <button
                                type="button"
                                onclick={() => destroy(supplier)}
                                class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-rose-100 dark:bg-slate-800 dark:hover:bg-rose-950/60 text-slate-600 hover:text-rose-700 dark:text-slate-400 dark:hover:text-rose-300 text-xs font-semibold border border-slate-200 dark:border-[#26334D]"
                            >
                                Eliminar
                            </button>
                        </div>
                    </div>
                {:else}
                    <div class="col-span-full p-12 text-center bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl text-slate-400 dark:text-slate-500 text-xs shadow-sm">
                        No hay proveedores registrados. Haz clic en "Nuevo Proveedor" para comenzar.
                    </div>
                {/each}
            </div>
        {/if}

        <!-- PAGINACIÓN -->
        {#if suppliers.links && suppliers.links.length > 3}
            <div class="p-4 border-t border-slate-200 dark:border-[#26334D] flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                <div>
                    Mostrando {suppliers.from ?? 0} a {suppliers.to ?? 0} de {suppliers.total} proveedores
                </div>
                <div class="flex items-center gap-1">
                    {#each suppliers.links as link}
                        {#if link.url}
                            <Link
                                href={link.url}
                                class="px-3 py-1.5 rounded-lg border text-xs font-medium {link.active ? 'bg-[#4F46E5] text-white border-[#4F46E5] font-bold' : 'bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 border-slate-200 dark:border-[#26334D] text-slate-700 dark:text-slate-300'}"
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
    <div class="fixed inset-0 z-50 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <h3 class="text-base font-bold text-slate-900 dark:text-white">
                    {editingSupplier ? `Editar Proveedor: ${editingSupplier.name}` : 'Registrar Nuevo Proveedor'}
                </h3>
                <button type="button" onclick={() => showModal = false} class="text-slate-400 hover:text-slate-600 dark:hover:text-white">✕</button>
            </div>

            <form onsubmit={submit} class="space-y-4 text-xs">
                <div>
                    <label for="s-name" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Razón Social / Nombre Comercial *</label>
                    <input
                        id="s-name"
                        type="text"
                        bind:value={form.name}
                        placeholder="Ej. Distribuidora Textil Andina"
                        required
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-500 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="s-contact" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Persona de Contacto</label>
                        <input
                            id="s-contact"
                            type="text"
                            bind:value={form.contact_name}
                            placeholder="Ej. Andrés Ramírez"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                        />
                    </div>
                    <div>
                        <label for="s-phone" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Teléfono / WhatsApp</label>
                        <input
                            id="s-phone"
                            type="text"
                            bind:value={form.phone}
                            placeholder="+57 310 987 6543"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="s-email" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Correo Electrónico</label>
                        <input
                            id="s-email"
                            type="email"
                            bind:value={form.email}
                            placeholder="ventas@proveedor.com"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                        />
                    </div>
                    <div>
                        <label for="s-lead" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Tiempo de Entrega (Días) *</label>
                        <input
                            id="s-lead"
                            type="number"
                            min="0"
                            max="365"
                            bind:value={form.lead_time_days}
                            required
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs font-mono font-bold"
                        />
                    </div>
                </div>

                <div>
                    <label for="s-payment" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Medios de Pago (Ej. Nequi, Bancolombia, Efectivo, Cuenta #)</label>
                    <input
                        id="s-payment"
                        type="text"
                        bind:value={form.payment_methods}
                        placeholder="Ej. Bancolombia Ahorros # 123-456789-00 / Nequi 3001234567"
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                    />
                    {#if errors.payment_methods}
                        <p class="text-[10px] text-rose-500 mt-1">{errors.payment_methods}</p>
                    {/if}
                </div>

                <div>
                    <label for="s-notes" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Notas / Observaciones</label>
                    <textarea
                        id="s-notes"
                        bind:value={form.notes}
                        rows="3"
                        placeholder="Condiciones comerciales, acuerdos de crédito, descuentos por volumen..."
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                    ></textarea>
                    {#if errors.notes}
                        <p class="text-[10px] text-rose-500 mt-1">{errors.notes}</p>
                    {/if}
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-[#26334D]">
                    <button
                        type="button"
                        onclick={() => showModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        class="px-4 py-2 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-semibold shadow-sm disabled:opacity-50 transition"
                    >
                        {editingSupplier ? 'Actualizar Proveedor' : 'Guardar Proveedor'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
