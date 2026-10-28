<script>
    import { untrack } from 'svelte';
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';

    let { customers, filters = { search: '' }, errors = {} } = $props();

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
    let editingCustomer = $state(null);
    let processing = $state(false);

    let form = $state({
        name: '',
        document_number: '',
        phone: '',
        email: '',
        tax_id: '',
        department: '',
        city: '',
        neighborhood: '',
        address: '',
        notes: '',
    });

    function resetForm() {
        form.name = '';
        form.document_number = '';
        form.phone = '';
        form.email = '';
        form.tax_id = '';
        form.department = '';
        form.city = '';
        form.neighborhood = '';
        form.address = '';
        form.notes = '';
    }

    let searchTimeout;
    function handleSearchInput() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            router.get(
                '/customers',
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
        editingCustomer = null;
        resetForm();
        showModal = true;
    }

    function openEdit(customer) {
        editingCustomer = customer;
        form.name = customer.name ?? '';
        form.document_number = customer.document_number ?? '';
        form.phone = customer.phone ?? '';
        form.email = customer.email ?? '';
        form.tax_id = customer.tax_id ?? '';
        form.department = customer.department ?? '';
        form.city = customer.city ?? '';
        form.neighborhood = customer.neighborhood ?? '';
        form.address = customer.address ?? '';
        form.notes = customer.notes ?? '';
        showModal = true;
    }

    function submit(e) {
        e.preventDefault();
        if (editingCustomer) {
            router.patch(`/customers/${editingCustomer.id}`, form, {
                preserveScroll: true,
                onStart: () => { processing = true; },
                onFinish: () => { processing = false; },
                onSuccess: () => {
                    showModal = false;
                    resetForm();
                },
            });
        } else {
            router.post('/customers', form, {
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

    function destroy(customer) {
        if (confirm(`¿Eliminar al cliente "${customer.name}"?`)) {
            router.delete(`/customers/${customer.id}`, {
                preserveScroll: true,
            });
        }
    }
</script>

<AppLayout title="Clientes">
    <div class="space-y-6">
        <!-- ENCABEZADO Y ACCIONES -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] p-6 rounded-2xl shadow-sm">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold tracking-wider uppercase bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30">
                        Módulo 3
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Directorio de Clientes</h1>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Administra los clientes vinculados a los pedidos de producción. (Nota 5: Todo pedido requiere un cliente).
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto">
                <ViewToggle bind:mode={viewMode} storageKey="gekaem_customers_view" />
                <button
                    type="button"
                    onclick={openCreate}
                    class="px-4 py-2.5 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-semibold text-xs transition shadow-sm flex items-center gap-2 shrink-0"
                >
                    <span>+</span>
                    <span>Nuevo Cliente</span>
                </button>
            </div>
        </div>

        <!-- BUSCADOR -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] p-4 rounded-xl flex items-center gap-3 shadow-sm">
            <span class="text-slate-400 text-sm">🔍</span>
            <label for="c-search" class="sr-only">Buscar cliente</label>
            <input
                id="c-search"
                type="text"
                bind:value={search}
                oninput={handleSearchInput}
                placeholder="Buscar por nombre, teléfono, email o NIT/Cédula..."
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
                                <th class="p-4">Cliente</th>
                                <th class="p-4">Contacto</th>
                                <th class="p-4">Identificación</th>
                                <th class="p-4">Ubicación / Dirección</th>
                                <th class="p-4 text-center">Pedidos (US)</th>
                                <th class="p-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-[#26334D]/60 text-slate-700 dark:text-slate-300">
                            {#each customers.data as customer (customer.id)}
                                {@const isExpanded = expandedRows.has(customer.id)}
                                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors {isExpanded ? 'bg-slate-50/50 dark:bg-slate-800/25' : ''}">
                                    <td class="p-3.5 text-center">
                                        <button
                                            type="button"
                                            onclick={() => toggleRow(customer.id)}
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
                                            onclick={() => toggleRow(customer.id)}
                                        >
                                            {customer.name}
                                        </button>
                                        {#if customer.notes}
                                            <div class="text-[11px] text-slate-500 dark:text-slate-400 truncate max-w-xs">{customer.notes}</div>
                                        {/if}
                                    </td>
                                    <td class="p-4">
                                        <div class="font-medium text-slate-900 dark:text-slate-100">{customer.phone ?? 'Sin teléfono'}</div>
                                        <div class="text-slate-500 dark:text-slate-400 text-[11px]">{customer.email ?? 'Sin email'}</div>
                                    </td>
                                    <td class="p-4">
                                        {#if customer.document_number || customer.tax_id}
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-medium bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 border border-slate-200 dark:border-slate-700">
                                                {customer.document_number || customer.tax_id}
                                            </span>
                                            {#if customer.document_number && customer.tax_id && customer.document_number !== customer.tax_id}
                                                <div class="text-[10px] text-slate-400 font-sans mt-0.5">NIT: {customer.tax_id}</div>
                                            {/if}
                                        {:else}
                                            <span class="text-slate-400">N/A</span>
                                        {/if}
                                    </td>
                                    <td class="p-4 max-w-xs">
                                        <div class="truncate text-slate-800 dark:text-slate-200">{customer.address ?? 'Sin dirección'}</div>
                                        {#if customer.city || customer.department || customer.neighborhood}
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-medium bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/40">
                                                    {[customer.neighborhood, customer.city, customer.department].filter(Boolean).join(', ')}
                                                </span>
                                            </div>
                                        {/if}
                                    </td>
                                    <td class="p-4 text-center">
                                        <span class="px-2.5 py-1 rounded-full font-mono font-bold text-xs {customer.orders_count > 0 ? 'bg-emerald-50 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30' : 'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500'}">
                                            {customer.orders_count}
                                        </span>
                                    </td>
                                    <td class="p-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                onclick={() => openEdit(customer)}
                                                class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-medium border border-slate-200 dark:border-[#26334D] transition-colors"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => destroy(customer)}
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
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                <div class="p-3.5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl space-y-1.5 shadow-sm">
                                                    <span class="text-[10px] uppercase font-bold text-[#64748B] dark:text-slate-400 tracking-wider block border-b border-slate-100 dark:border-[#26334D] pb-1">
                                                        Información de Contacto
                                                    </span>
                                                    <div class="text-xs space-y-1 pt-1">
                                                        <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">Teléfono:</span> <span class="font-medium text-slate-900 dark:text-white">{customer.phone ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">Email:</span> <span class="font-medium text-slate-900 dark:text-white">{customer.email ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">Cédula / Doc:</span> <span class="font-mono text-slate-800 dark:text-slate-200">{customer.document_number || 'N/A'}</span></div>
                                                        {#if customer.tax_id && customer.tax_id !== customer.document_number}
                                                            <div class="flex justify-between"><span class="text-slate-500 dark:text-slate-400">NIT:</span> <span class="font-mono text-slate-800 dark:text-slate-200">{customer.tax_id}</span></div>
                                                        {/if}
                                                    </div>
                                                </div>

                                                <div class="p-3.5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl space-y-1.5 shadow-sm">
                                                    <span class="text-[10px] uppercase font-bold text-[#64748B] dark:text-slate-400 tracking-wider block border-b border-slate-100 dark:border-[#26334D] pb-1">
                                                        Ubicación y Entregas
                                                    </span>
                                                    <div class="text-xs text-slate-700 dark:text-slate-300 pt-1 space-y-1">
                                                        <div><span class="text-slate-500">Dirección:</span> <span class="font-medium text-slate-900 dark:text-white">{customer.address ?? 'Sin dirección registrada.'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-500">Barrio:</span> <span class="font-medium text-slate-800 dark:text-slate-200">{customer.neighborhood ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-500">Ciudad:</span> <span class="font-medium text-slate-800 dark:text-slate-200">{customer.city ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-500">Departamento:</span> <span class="font-medium text-slate-800 dark:text-slate-200">{customer.department ?? 'N/A'}</span></div>
                                                        {#if customer.notes}
                                                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-2 italic pt-1 border-t border-slate-100 dark:border-[#26334D]">"{customer.notes}"</p>
                                                        {/if}
                                                    </div>
                                                </div>

                                                <div class="p-3.5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl space-y-1.5 flex flex-col justify-between shadow-sm">
                                                    <div>
                                                        <span class="text-[10px] uppercase font-bold text-[#64748B] dark:text-slate-400 tracking-wider block border-b border-slate-100 dark:border-[#26334D] pb-1">
                                                            Historial de Pedidos
                                                        </span>
                                                        <div class="text-2xl font-mono font-bold text-emerald-600 dark:text-emerald-400 mt-2">
                                                            {customer.orders_count} {customer.orders_count === 1 ? 'pedido' : 'pedidos'}
                                                        </div>
                                                        <span class="text-[11px] text-slate-400 dark:text-slate-500">Registrados en taller</span>
                                                    </div>
                                                    <div class="pt-2">
                                                        <Link
                                                            href={`/orders?search=${encodeURIComponent(customer.name)}`}
                                                            class="text-xs font-semibold text-[#4F46E5] dark:text-sky-400 hover:underline flex items-center gap-1"
                                                        >
                                                            <span>Ver pedidos del cliente</span>
                                                            <span>→</span>
                                                        </Link>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                {/if}
                            {:else}
                                <tr>
                                    <td colspan="7" class="p-10 text-center text-slate-400 dark:text-slate-500 text-xs">
                                        No se encontraron clientes registrados.
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
                {#each customers.data as customer (customer.id)}
                    <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl p-5 flex flex-col justify-between shadow-sm hover:border-[#4F46E5] dark:hover:border-[#06B6D4] transition">
                        <div>
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="px-2.5 py-1 rounded-full font-mono font-bold text-xs {customer.orders_count > 0 ? 'bg-emerald-50 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30' : 'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500'}">
                                    {customer.orders_count} pedidos
                                </span>
                                {#if customer.document_number || customer.tax_id}
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full font-mono text-[10px] bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                        Doc: {customer.document_number || customer.tax_id}
                                    </span>
                                {/if}
                            </div>

                            <h3 class="text-base font-bold text-slate-900 dark:text-white">{customer.name}</h3>

                            <div class="mt-3.5 p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D] space-y-1.5 text-xs">
                                {#if customer.document_number}
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-500 dark:text-slate-400">Cédula/Doc:</span>
                                        <span class="font-medium font-mono text-slate-800 dark:text-slate-200">{customer.document_number}</span>
                                    </div>
                                {/if}
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500 dark:text-slate-400">Teléfono:</span>
                                    <span class="font-medium text-slate-800 dark:text-slate-200">{customer.phone ?? 'N/A'}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500 dark:text-slate-400">Email:</span>
                                    <span class="font-medium text-slate-800 dark:text-slate-200 truncate max-w-[170px]">{customer.email ?? 'N/A'}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500 dark:text-slate-400">Dirección:</span>
                                    <span class="text-slate-800 dark:text-slate-300 truncate max-w-[170px]">{customer.address ?? 'N/A'}</span>
                                </div>
                                {#if customer.city || customer.neighborhood || customer.department}
                                    <div class="flex justify-between items-center text-[11px] pt-1 border-t border-slate-200/60 dark:border-[#26334D]/60">
                                        <span class="text-slate-400">Ubicación:</span>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/40 truncate max-w-[170px]">
                                            {[customer.neighborhood, customer.city].filter(Boolean).join(', ')}
                                        </span>
                                    </div>
                                {/if}
                            </div>

                            {#if customer.notes}
                                <div class="mt-2 text-[11px] text-slate-500 dark:text-slate-400 italic">
                                    "{customer.notes}"
                                </div>
                            {/if}
                        </div>

                        <!-- Acciones -->
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-[#26334D] flex items-center justify-between">
                            <Link
                                href={`/orders?search=${encodeURIComponent(customer.name)}`}
                                class="text-xs text-[#4F46E5] dark:text-sky-400 hover:underline font-semibold"
                            >
                                Ver pedidos
                            </Link>
                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    onclick={() => openEdit(customer)}
                                    class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-[#26334D]"
                                >
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    onclick={() => destroy(customer)}
                                    class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-rose-100 dark:bg-slate-800 dark:hover:bg-rose-950/60 text-slate-600 hover:text-rose-700 dark:text-slate-400 dark:hover:text-rose-300 text-xs font-semibold border border-slate-200 dark:border-[#26334D]"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                {:else}
                    <div class="col-span-full p-12 text-center bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl text-slate-400 dark:text-slate-500 text-xs shadow-sm">
                        No hay clientes registrados. Haz clic en "Nuevo Cliente" para comenzar.
                    </div>
                {/each}
            </div>
        {/if}

        <!-- PAGINACIÓN -->
        {#if customers.links && customers.links.length > 3}
            <div class="p-4 border-t border-slate-200 dark:border-[#26334D] flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                <div>
                    Mostrando {customers.from ?? 0} a {customers.to ?? 0} de {customers.total} clientes
                </div>
                <div class="flex items-center gap-1">
                    {#each customers.links as link}
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

<!-- MODAL CREAR / EDITAR CLIENTE -->
{#if showModal}
    <div class="fixed inset-0 z-50 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <h3 class="text-base font-bold text-slate-900 dark:text-white">
                    {editingCustomer ? `Editar Cliente: ${editingCustomer.name}` : 'Registrar Nuevo Cliente'}
                </h3>
                <button type="button" onclick={() => showModal = false} class="text-slate-400 hover:text-slate-600 dark:hover:text-white">✕</button>
            </div>

            <form onsubmit={submit} class="space-y-4 text-xs">
                <div>
                    <label for="c-name" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Nombre o Razón Social *</label>
                    <input
                        id="c-name"
                        type="text"
                        bind:value={form.name}
                        placeholder="Ej. Boutique Urbana / Carlos Mendoza"
                        required
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-500 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="c-doc" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Cédula / NIT / Documento</label>
                        <input
                            id="c-doc"
                            type="text"
                            bind:value={form.document_number}
                            placeholder="Ej. 1020304050 o 900.123.456-7"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs font-mono"
                        />
                        {#if errors.document_number}
                            <p class="text-[10px] text-rose-500 mt-1">{errors.document_number}</p>
                        {/if}
                    </div>
                    <div>
                        <label for="c-phone" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Teléfono / WhatsApp</label>
                        <input
                            id="c-phone"
                            type="text"
                            bind:value={form.phone}
                            placeholder="+57 300 123 4567"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="c-email" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Correo Electrónico</label>
                        <input
                            id="c-email"
                            type="email"
                            bind:value={form.email}
                            placeholder="cliente@ejemplo.com"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                        />
                    </div>
                    <div>
                        <label for="c-address" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Dirección de Entrega</label>
                        <input
                            id="c-address"
                            type="text"
                            bind:value={form.address}
                            placeholder="Calle 10 # 40-50"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label for="c-dept" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Departamento</label>
                        <input
                            id="c-dept"
                            type="text"
                            bind:value={form.department}
                            placeholder="Ej. Antioquia"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                        />
                    </div>
                    <div>
                        <label for="c-city" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Ciudad / Municipio</label>
                        <input
                            id="c-city"
                            type="text"
                            bind:value={form.city}
                            placeholder="Ej. Medellín"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                        />
                    </div>
                    <div>
                        <label for="c-neigh" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Barrio</label>
                        <input
                            id="c-neigh"
                            type="text"
                            bind:value={form.neighborhood}
                            placeholder="Ej. El Poblado"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                        />
                    </div>
                </div>

                <div>
                    <label for="c-notes" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Notas Internas</label>
                    <textarea
                        id="c-notes"
                        bind:value={form.notes}
                        rows="2"
                        placeholder="Observaciones de empaque, preferencias de entrega..."
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 placeholder-slate-400 focus:border-[#4F46E5] focus:ring-2 focus:ring-[#4F46E5]/20 rounded-xl px-3 py-2 text-xs"
                    ></textarea>
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
                        {editingCustomer ? 'Actualizar Cliente' : 'Guardar Cliente'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
