<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let { customers, filters = { search: '' }, errors = {} } = $props();

    let search = $state('');
    $effect(() => {
        search = filters.search ?? '';
    });
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
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl">
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
            <button
                type="button"
                onclick={openCreate}
                class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-colors shadow-lg shadow-emerald-600/20 flex items-center gap-2 shrink-0 self-start sm:self-auto"
            >
                <span>+</span>
                <span>Nuevo Cliente</span>
            </button>
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

        <!-- TABLA DE CLIENTES -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-md">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950/80 text-slate-400 uppercase font-semibold border-b border-slate-800 text-[10px] tracking-wider">
                        <tr>
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
                            <tr class="hover:bg-slate-800/40 transition-colors">
                                <td class="p-4">
                                    <div class="font-bold text-white text-sm">{customer.name}</div>
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
                        {:else}
                            <tr>
                                <td colspan="6" class="p-10 text-center text-slate-500 text-xs">
                                    No se encontraron clientes registrados con ese criterio.
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>

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
                    <label for="c-name" class="block font-semibold text-slate-300 mb-1">Nombre Completo / Razón Social *</label>
                    <input
                        id="c-name"
                        type="text"
                        bind:value={form.name}
                        placeholder="Ej. Boutique María José"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                    {/if}
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="c-phone" class="block font-semibold text-slate-300 mb-1">Teléfono / WhatsApp</label>
                        <input
                            id="c-phone"
                            type="text"
                            bind:value={form.phone}
                            placeholder="+57 300 000 0000"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                    <div>
                        <label for="c-email" class="block font-semibold text-slate-300 mb-1">Correo Electrónico</label>
                        <input
                            id="c-email"
                            type="email"
                            bind:value={form.email}
                            placeholder="contacto@cliente.com"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="c-tax" class="block font-semibold text-slate-300 mb-1">NIT / Cédula / Tax ID</label>
                        <input
                            id="c-tax"
                            type="text"
                            bind:value={form.tax_id}
                            placeholder="Ej. 900123456-7"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                    <div>
                        <label for="c-address" class="block font-semibold text-slate-300 mb-1">Dirección de Entrega</label>
                        <input
                            id="c-address"
                            type="text"
                            bind:value={form.address}
                            placeholder="Calle 10 # 20-30"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                </div>

                <div>
                    <label for="c-notes" class="block font-semibold text-slate-300 mb-1">Notas Especiales / Preferencias</label>
                    <textarea
                        id="c-notes"
                        bind:value={form.notes}
                        rows="2"
                        placeholder="Instrucciones de empaque, horarios de entrega..."
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    ></textarea>
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
                        {editingCustomer ? 'Actualizar Cliente' : 'Guardar Cliente'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
