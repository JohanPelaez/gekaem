<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';

    let { customers, filters = { search: '' }, errors = {} } = $props();

    let search = $state('');
    $effect(() => {
        search = filters.search ?? '';
    });

    let viewMode = $state('table'); // 'table' | 'cards'
    let expandedRows = $state(new Set());

    let showModal = $state(false);
    let editingCustomer = $state(null);
    let processing = $state(false);

    let form = $state({
        name: '',
        phone: '',
        email: '',
        tax_id: '',
        address: '',
        notes: '',
    });

    function resetForm() {
        form.name = '';
        form.phone = '';
        form.email = '';
        form.tax_id = '';
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
        form.phone = customer.phone ?? '';
        form.email = customer.email ?? '';
        form.tax_id = customer.tax_id ?? '';
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
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Módulo 3
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Directorio de Clientes</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Administra los clientes vinculados a los pedidos de producción. (Nota 5: Todo pedido requiere un cliente).
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto">
                <ViewToggle bind:mode={viewMode} storageKey="gekaem_customers_view" />
                <button
                    type="button"
                    onclick={openCreate}
                    class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-colors shadow-lg shadow-emerald-600/20 flex items-center gap-2 shrink-0"
                >
                    <span>+</span>
                    <span>Nuevo Cliente</span>
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
                placeholder="Buscar por nombre, teléfono, email o NIT/Cédula..."
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
                                <th class="p-4">Cliente</th>
                                <th class="p-4">Contacto</th>
                                <th class="p-4">Identificación</th>
                                <th class="p-4">Dirección</th>
                                <th class="p-4 text-center">Pedidos (US)</th>
                                <th class="p-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-slate-300">
                            {#each customers.data as customer (customer.id)}
                                {@const isExpanded = expandedRows.has(customer.id)}
                                <tr class="hover:bg-slate-800/40 transition-colors {isExpanded ? 'bg-slate-800/25' : ''}">
                                    <td class="p-3.5 text-center">
                                        <button
                                            type="button"
                                            onclick={() => toggleRow(customer.id)}
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
                                            onclick={() => toggleRow(customer.id)}
                                        >
                                            {customer.name}
                                        </button>
                                        {#if customer.notes}
                                            <div class="text-[11px] text-slate-400 truncate max-w-xs">{customer.notes}</div>
                                        {/if}
                                    </td>
                                    <td class="p-4">
                                        <div>{customer.phone ?? 'Sin teléfono'}</div>
                                        <div class="text-slate-400 text-[11px]">{customer.email ?? 'Sin email'}</div>
                                    </td>
                                    <td class="p-4 font-mono text-slate-400">
                                        {customer.tax_id ?? 'N/A'}
                                    </td>
                                    <td class="p-4 text-slate-400 max-w-xs truncate">
                                        {customer.address ?? 'Sin dirección'}
                                    </td>
                                    <td class="p-4 text-center">
                                        <span class="px-2.5 py-1 rounded-full font-mono font-bold text-xs {customer.orders_count > 0 ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-500'}">
                                            {customer.orders_count}
                                        </span>
                                    </td>
                                    <td class="p-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button
                                                type="button"
                                                onclick={() => openEdit(customer)}
                                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition-colors"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                type="button"
                                                onclick={() => destroy(customer)}
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
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                <div class="p-3.5 bg-slate-900 border border-slate-800 rounded-xl space-y-1.5">
                                                    <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block border-b border-slate-800 pb-1">
                                                        Información de Contacto
                                                    </span>
                                                    <div class="text-xs space-y-1 pt-1">
                                                        <div class="flex justify-between"><span class="text-slate-400">Teléfono:</span> <span class="font-medium text-white">{customer.phone ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-400">Email:</span> <span class="font-medium text-white">{customer.email ?? 'N/A'}</span></div>
                                                        <div class="flex justify-between"><span class="text-slate-400">NIT / Doc:</span> <span class="font-mono text-slate-300">{customer.tax_id ?? 'N/A'}</span></div>
                                                    </div>
                                                </div>

                                                <div class="p-3.5 bg-slate-900 border border-slate-800 rounded-xl space-y-1.5">
                                                    <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block border-b border-slate-800 pb-1">
                                                        Ubicación y Entregas
                                                    </span>
                                                    <div class="text-xs text-slate-300 pt-1">
                                                        <p class="font-medium text-white">{customer.address ?? 'Sin dirección registrada.'}</p>
                                                        {#if customer.notes}
                                                            <p class="text-[11px] text-slate-400 mt-2 italic">"{customer.notes}"</p>
                                                        {/if}
                                                    </div>
                                                </div>

                                                <div class="p-3.5 bg-slate-900 border border-slate-800 rounded-xl space-y-1.5 flex flex-col justify-between">
                                                    <div>
                                                        <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block border-b border-slate-800 pb-1">
                                                            Historial de Pedidos
                                                        </span>
                                                        <div class="text-2xl font-mono font-bold text-emerald-400 mt-2">
                                                            {customer.orders_count} {customer.orders_count === 1 ? 'pedido' : 'pedidos'}
                                                        </div>
                                                        <span class="text-[11px] text-slate-500">Registrados en taller</span>
                                                    </div>
                                                    <div class="pt-2">
                                                        <Link
                                                            href={`/orders?search=${encodeURIComponent(customer.name)}`}
                                                            class="text-xs font-semibold text-sky-400 hover:text-sky-300 flex items-center gap-1"
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
                                    <td colspan="7" class="p-10 text-center text-slate-500 text-xs">
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
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col justify-between shadow-md hover:border-slate-700 transition-colors">
                        <div>
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="px-2.5 py-1 rounded-full font-mono font-bold text-xs {customer.orders_count > 0 ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-500'}">
                                    {customer.orders_count} pedidos
                                </span>
                                {#if customer.tax_id}
                                    <span class="font-mono text-slate-400 text-[11px]">NIT: {customer.tax_id}</span>
                                {/if}
                            </div>

                            <h3 class="text-base font-bold text-white">{customer.name}</h3>

                            <div class="mt-3.5 p-3 bg-slate-950/70 rounded-xl border border-slate-800/80 space-y-1.5 text-xs">
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-400">Teléfono:</span>
                                    <span class="font-medium text-slate-200">{customer.phone ?? 'N/A'}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-400">Email:</span>
                                    <span class="font-medium text-slate-200 truncate max-w-[170px]">{customer.email ?? 'N/A'}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-400">Dirección:</span>
                                    <span class="text-slate-300 truncate max-w-[170px]">{customer.address ?? 'N/A'}</span>
                                </div>
                            </div>

                            {#if customer.notes}
                                <div class="mt-2 text-[11px] text-slate-400 italic">
                                    "{customer.notes}"
                                </div>
                            {/if}
                        </div>

                        <!-- Acciones -->
                        <div class="mt-4 pt-3 border-t border-slate-800 flex items-center justify-between">
                            <Link
                                href={`/orders?search=${encodeURIComponent(customer.name)}`}
                                class="text-xs text-sky-400 hover:text-sky-300 font-semibold"
                            >
                                Ver pedidos
                            </Link>
                            <div class="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    onclick={() => openEdit(customer)}
                                    class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold"
                                >
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    onclick={() => destroy(customer)}
                                    class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 text-slate-400 hover:text-rose-300 text-xs font-semibold"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                {:else}
                    <div class="col-span-full p-12 text-center bg-slate-900 border border-slate-800 rounded-2xl text-slate-500 text-xs">
                        No hay clientes registrados. Haz clic en "Nuevo Cliente" para comenzar.
                    </div>
                {/each}
            </div>
        {/if}

        <!-- PAGINACIÓN -->
        {#if customers.links && customers.links.length > 3}
            <div class="p-4 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
                <div>
                    Mostrando {customers.from ?? 0} a {customers.to ?? 0} de {customers.total} clientes
                </div>
                <div class="flex items-center gap-1">
                    {#each customers.links as link}
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

<!-- MODAL CREAR / EDITAR CLIENTE -->
{#if showModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">
                    {editingCustomer ? `Editar Cliente: ${editingCustomer.name}` : 'Registrar Nuevo Cliente'}
                </h3>
                <button type="button" onclick={() => showModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submit} class="space-y-4 text-xs">
                <div>
                    <label for="c-name" class="block font-semibold text-slate-300 mb-1">Nombre o Razón Social *</label>
                    <input
                        id="c-name"
                        type="text"
                        bind:value={form.name}
                        placeholder="Ej. Boutique Urbana / Carlos Mendoza"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="c-phone" class="block font-semibold text-slate-300 mb-1">Teléfono / WhatsApp</label>
                        <input
                            id="c-phone"
                            type="text"
                            bind:value={form.phone}
                            placeholder="+57 300 123 4567"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                    <div>
                        <label for="c-email" class="block font-semibold text-slate-300 mb-1">Correo Electrónico</label>
                        <input
                            id="c-email"
                            type="email"
                            bind:value={form.email}
                            placeholder="cliente@ejemplo.com"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="c-tax" class="block font-semibold text-slate-300 mb-1">Cédula o NIT</label>
                        <input
                            id="c-tax"
                            type="text"
                            bind:value={form.tax_id}
                            placeholder="Ej. 900.123.456-7"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                        />
                    </div>
                    <div>
                        <label for="c-address" class="block font-semibold text-slate-300 mb-1">Dirección de Entrega</label>
                        <input
                            id="c-address"
                            type="text"
                            bind:value={form.address}
                            placeholder="Calle 10 # 40-50, Ciudad"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                </div>

                <div>
                    <label for="c-notes" class="block font-semibold text-slate-300 mb-1">Notas Internas</label>
                    <textarea
                        id="c-notes"
                        bind:value={form.notes}
                        rows="2"
                        placeholder="Observaciones de empaque, preferencias de entrega..."
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    ></textarea>
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
                        {editingCustomer ? 'Actualizar Cliente' : 'Guardar Cliente'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
