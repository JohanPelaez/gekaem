<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let { rawMaterials, allSuppliers = [], filters = { search: '' }, errors = {} } = $props();

    let search = $state('');
    $effect(() => {
        search = filters.search ?? '';
    });
    let showMaterialModal = $state(false);
    let showMovementModal = $state(false);
    let editingMaterial = $state(null);
    let adjustingMaterial = $state(null);

    let materialProcessing = $state(false);
    let movementProcessing = $state(false);

    // Formulario de Materia Prima en Svelte 5 Rune
    let materialForm = $state({
        name: '',
        sku: '',
        unit_of_measure: 'metros',
        current_stock: 0,
        min_stock_alert: 0,
        unit_cost: 0,
        suppliers: [],
    });

    function resetMaterialForm() {
        materialForm.name = '';
        materialForm.sku = '';
        materialForm.unit_of_measure = 'metros';
        materialForm.current_stock = 0;
        materialForm.min_stock_alert = 0;
        materialForm.unit_cost = 0;
        materialForm.suppliers = [];
    }

    // Formulario de Movimiento / Ajuste en Svelte 5 Rune
    let movementForm = $state({
        type: 'purchase',
        quantity: 1,
        direction: 'add',
        notes: '',
    });

    function resetMovementForm() {
        movementForm.type = 'purchase';
        movementForm.quantity = 1;
        movementForm.direction = 'add';
        movementForm.notes = '';
    }

    let searchTimeout;
    function handleSearchInput() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            router.get(
                '/raw-materials',
                { search },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    }

    function openCreate() {
        editingMaterial = null;
        resetMaterialForm();
        materialForm.unit_of_measure = 'metros';
        materialForm.suppliers = [];
        showMaterialModal = true;
    }

    function openEdit(mat) {
        editingMaterial = mat;
        materialForm.name = mat.name ?? '';
        materialForm.sku = mat.sku ?? '';
        materialForm.unit_of_measure = mat.unit_of_measure ?? 'metros';
        materialForm.current_stock = Number(mat.current_stock ?? 0);
        materialForm.min_stock_alert = Number(mat.min_stock_alert ?? 0);
        materialForm.unit_cost = Number(mat.unit_cost ?? 0);
        materialForm.suppliers = (mat.suppliers ?? []).map((s) => ({
            supplier_id: s.id,
            purchase_price: s.pivot?.purchase_price ?? '',
            is_preferred: Boolean(s.pivot?.is_preferred),
        }));
        showMaterialModal = true;
    }

    function addSupplierRow() {
        if (allSuppliers.length === 0) return;
        materialForm.suppliers = [
            ...materialForm.suppliers,
            {
                supplier_id: allSuppliers[0].id,
                purchase_price: '',
                is_preferred: materialForm.suppliers.length === 0,
            },
        ];
    }

    function removeSupplierRow(index) {
        materialForm.suppliers = materialForm.suppliers.filter((_, i) => i !== index);
    }

    function submitMaterial(e) {
        e.preventDefault();
        if (editingMaterial) {
            router.patch(`/raw-materials/${editingMaterial.id}`, materialForm, {
                preserveScroll: true,
                onStart: () => { materialProcessing = true; },
                onFinish: () => { materialProcessing = false; },
                onSuccess: () => {
                    showMaterialModal = false;
                    resetMaterialForm();
                },
            });
        } else {
            router.post('/raw-materials', materialForm, {
                preserveScroll: true,
                onStart: () => { materialProcessing = true; },
                onFinish: () => { materialProcessing = false; },
                onSuccess: () => {
                    showMaterialModal = false;
                    resetMaterialForm();
                },
            });
        }
    }

    function openAdjust(mat) {
        adjustingMaterial = mat;
        resetMovementForm();
        showMovementModal = true;
    }

    function submitMovement(e) {
        e.preventDefault();
        if (!adjustingMaterial) return;

        router.post(`/raw-materials/${adjustingMaterial.id}/movements`, movementForm, {
            preserveScroll: true,
            onStart: () => { movementProcessing = true; },
            onFinish: () => { movementProcessing = false; },
            onSuccess: () => {
                showMovementModal = false;
                resetMovementForm();
            },
        });
    }

    function destroy(mat) {
        if (confirm(`¿Eliminar la materia prima "${mat.name}"?`)) {
            router.delete(`/raw-materials/${mat.id}`, {
                preserveScroll: true,
            });
        }
    }
</script>

<AppLayout title="Materia Prima e Inventario">
    <div class="space-y-6">
        <!-- ENCABEZADO Y ACCIONES -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Módulo 6
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Materia Prima e Inventario</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Control de stock físico, inventario reservado por pedidos en cola, costos unitarios y proveedores (Nota 4).
                </p>
            </div>
            <button
                type="button"
                onclick={openCreate}
                class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-colors shadow-lg shadow-emerald-600/20 flex items-center gap-2 shrink-0 self-start sm:self-auto"
            >
                <span>+</span>
                <span>Nueva Materia Prima</span>
            </button>
        </div>

        <!-- BUSCADOR -->
        <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl flex items-center gap-3">
            <span class="text-slate-400 text-sm">🔍</span>
            <input
                type="text"
                bind:value={search}
                oninput={handleSearchInput}
                placeholder="Buscar materia prima por nombre, código SKU o unidad de medida..."
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

        <!-- TABLA DE INVENTARIO -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-md">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950/80 text-slate-400 uppercase font-semibold border-b border-slate-800 text-[10px] tracking-wider">
                        <tr>
                            <th class="p-4">Material / SKU</th>
                            <th class="p-4 text-center">Unidad</th>
                            <th class="p-4 text-right">Stock Actual</th>
                            <th class="p-4 text-right">Stock Reservado</th>
                            <th class="p-4 text-right">Disponible</th>
                            <th class="p-4 text-center">Alerta Mínima</th>
                            <th class="p-4 text-right">Costo Unitario</th>
                            <th class="p-4">Proveedores (Nota 4)</th>
                            <th class="p-4 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-slate-300">
                        {#each rawMaterials.data as mat (mat.id)}
                            {@const current = Number(mat.current_stock)}
                            {@const reserved = Number(mat.reserved_stock)}
                            {@const available = current - reserved}
                            {@const isLowStock = current <= Number(mat.min_stock_alert)}
                            <tr class="hover:bg-slate-800/40 transition-colors {isLowStock ? 'bg-rose-950/10' : ''}">
                                <td class="p-4">
                                    <div class="font-bold text-white text-sm">{mat.name}</div>
                                    <span class="font-mono text-[10px] text-slate-500">{mat.sku ?? 'Sin SKU'}</span>
                                </td>
                                <td class="p-4 text-center font-mono text-slate-400">
                                    {mat.unit_of_measure}
                                </td>
                                <td class="p-4 text-right font-mono font-bold text-slate-200">
                                    {current.toFixed(2)}
                                </td>
                                <td class="p-4 text-right font-mono text-amber-300">
                                    {reserved.toFixed(2)}
                                </td>
                                <td class="p-4 text-right font-mono font-bold {available <= 0 ? 'text-rose-400' : 'text-emerald-400'}">
                                    {available.toFixed(2)}
                                </td>
                                <td class="p-4 text-center">
                                    {#if isLowStock}
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full font-mono font-bold text-[10px] bg-rose-500/20 text-rose-300 border border-rose-500/30 animate-pulse">
                                            ⚠ Min: {Number(mat.min_stock_alert)}
                                        </span>
                                    {:else}
                                        <span class="font-mono text-slate-400 text-[11px]">
                                            Min: {Number(mat.min_stock_alert)}
                                        </span>
                                    {/if}
                                </td>
                                <td class="p-4 text-right font-mono text-slate-300">
                                    ${Number(mat.unit_cost).toFixed(2)}
                                </td>
                                <td class="p-4">
                                    <div class="flex flex-wrap gap-1 max-w-xs">
                                        {#each mat.suppliers ?? [] as sup}
                                            <span class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded {sup.pivot?.is_preferred ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 font-semibold' : 'bg-slate-800 text-slate-400 border border-slate-700/60'}">
                                                {#if sup.pivot?.is_preferred}★{/if}
                                                {sup.name}
                                                {#if sup.pivot?.purchase_price}
                                                    <span class="font-mono text-[9px] text-slate-400">(${Number(sup.pivot.purchase_price).toFixed(2)})</span>
                                                {/if}
                                            </span>
                                        {:else}
                                            <span class="text-[10px] text-slate-500 italic">Sin proveedor (Producción propia/local)</span>
                                        {/each}
                                    </div>
                                </td>
                                <td class="p-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            type="button"
                                            onclick={() => openAdjust(mat)}
                                            class="px-2.5 py-1 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 border border-emerald-500/30 text-xs font-semibold transition-colors"
                                            title="Registrar Compra / Ajuste de Stock"
                                        >
                                            ± Stock
                                        </button>
                                        <button
                                            type="button"
                                            onclick={() => openEdit(mat)}
                                            class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition-colors"
                                        >
                                            Editar
                                        </button>
                                        <button
                                            type="button"
                                            onclick={() => destroy(mat)}
                                            class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 text-slate-500 hover:text-rose-300 text-xs font-medium transition-colors"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        {:else}
                            <tr>
                                <td colspan="9" class="p-10 text-center text-slate-500 text-xs">
                                    No se encontraron materias primas registradas.
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>

            <!-- PAGINACIÓN -->
            {#if rawMaterials.links && rawMaterials.links.length > 3}
                <div class="p-4 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
                    <div>
                        Mostrando {rawMaterials.from ?? 0} a {rawMaterials.to ?? 0} de {rawMaterials.total} insumos
                    </div>
                    <div class="flex items-center gap-1">
                        {#each rawMaterials.links as link}
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

<!-- MODAL CREAR / EDITAR MATERIA PRIMA (Nota 4: 0, 1 o múltiples proveedores) -->
{#if showMaterialModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">
                    {editingMaterial ? `Editar Insumo: ${editingMaterial.name}` : 'Registrar Nueva Materia Prima'}
                </h3>
                <button type="button" onclick={() => showMaterialModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitMaterial} class="space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="m-name" class="block font-semibold text-slate-300 mb-1">Nombre del Material *</label>
                        <input
                            id="m-name"
                            type="text"
                            bind:value={materialForm.name}
                            placeholder="Ej. Tela Drill Algodón"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                        {#if errors.name}
                            <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                        {/if}
                    </div>
                    <div>
                        <label for="m-sku" class="block font-semibold text-slate-300 mb-1">Código SKU / Referencia</label>
                        <input
                            id="m-sku"
                            type="text"
                            bind:value={materialForm.sku}
                            placeholder="Ej. MAT-DRL-01"
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label for="m-unit" class="block font-semibold text-slate-300 mb-1">Unidad de Medida *</label>
                        <select
                            id="m-unit"
                            bind:value={materialForm.unit_of_measure}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        >
                            <option value="metros">metros (m)</option>
                            <option value="unidades">unidades (u)</option>
                            <option value="conos">conos</option>
                            <option value="kilos">kilos (kg)</option>
                            <option value="gramos">gramos (g)</option>
                            <option value="litros">litros (L)</option>
                        </select>
                    </div>

                    {#if !editingMaterial}
                        <div>
                            <label for="m-stock" class="block font-semibold text-slate-300 mb-1">Stock Inicial *</label>
                            <input
                                id="m-stock"
                                type="number"
                                step="0.001"
                                min="0"
                                bind:value={materialForm.current_stock}
                                required
                                class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                            />
                        </div>
                    {/if}

                    <div>
                        <label for="m-alert" class="block font-semibold text-slate-300 mb-1">Alerta Mínima *</label>
                        <input
                            id="m-alert"
                            type="number"
                            step="0.001"
                            min="0"
                            bind:value={materialForm.min_stock_alert}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                        />
                    </div>

                    <div>
                        <label for="m-cost" class="block font-semibold text-slate-300 mb-1">Costo Unitario ($) *</label>
                        <input
                            id="m-cost"
                            type="number"
                            step="0.01"
                            min="0"
                            bind:value={materialForm.unit_cost}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                        />
                    </div>
                </div>

                <!-- SECCIÓN DE PROVEEDORES OPCIONALES (NOTA 4) -->
                <div class="pt-3 border-t border-slate-800 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-xs">Proveedores Asociados (Nota 4: 0, 1 o múltiples)</span>
                            <p class="text-[11px] text-slate-500">Puedes dejarlo sin proveedor si el insumo es de elaboración interna.</p>
                        </div>
                        <button
                            type="button"
                            onclick={addSupplierRow}
                            disabled={allSuppliers.length === 0}
                            class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs border border-slate-700 flex items-center gap-1 disabled:opacity-50"
                        >
                            <span>+</span>
                            <span>Agregar Proveedor</span>
                        </button>
                    </div>

                    {#if materialForm.suppliers.length === 0}
                        <div class="p-3 text-center bg-slate-950/60 rounded-xl border border-dashed border-slate-800 text-slate-500 text-[11px]">
                            Ningún proveedor vinculado. El material operará como insumo local.
                        </div>
                    {:else}
                        <div class="space-y-2">
                            {#each materialForm.suppliers as supRow, idx}
                                <div class="flex items-center gap-2 p-2.5 bg-slate-950 rounded-xl border border-slate-800">
                                    <div class="flex-1">
                                        <select
                                            bind:value={supRow.supplier_id}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs"
                                        >
                                            {#each allSuppliers as s}
                                                <option value={s.id}>{s.name}</option>
                                            {/each}
                                        </select>
                                    </div>
                                    <div class="w-28">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            placeholder="Precio compra"
                                            bind:value={supRow.purchase_price}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs font-mono"
                                        />
                                    </div>
                                    <label class="flex items-center gap-1.5 text-[11px] text-slate-300 whitespace-nowrap cursor-pointer">
                                        <input
                                            type="checkbox"
                                            bind:checked={supRow.is_preferred}
                                            class="rounded bg-slate-900 border-slate-700 text-emerald-500"
                                        />
                                        <span>Preferido</span>
                                    </label>
                                    <button
                                        type="button"
                                        onclick={() => removeSupplierRow(idx)}
                                        class="p-1.5 rounded-lg text-slate-500 hover:text-rose-400"
                                        title="Quitar proveedor"
                                    >
                                        ✕
                                    </button>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={() => showMaterialModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={materialProcessing}
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                    >
                        {editingMaterial ? 'Actualizar Insumo' : 'Guardar Insumo'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL REGISTRO RÁPIDO DE MOVIMIENTO / AJUSTE DE STOCK -->
{#if showMovementModal && adjustingMaterial}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-base font-bold text-white">Entrada / Ajuste de Stock</h3>
                    <p class="text-[11px] text-emerald-400 font-medium">{adjustingMaterial.name} (Stock Actual: {Number(adjustingMaterial.current_stock)} {adjustingMaterial.unit_of_measure})</p>
                </div>
                <button type="button" onclick={() => showMovementModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitMovement} class="space-y-4 text-xs">
                <div>
                    <label for="mov-type" class="block font-semibold text-slate-300 mb-1">Tipo de Movimiento *</label>
                    <select
                        id="mov-type"
                        bind:value={movementForm.type}
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    >
                        <option value="purchase">purchase — Entrada por Compra a Proveedor</option>
                        <option value="adjustment">adjustment — Ajuste de Inventario Físico</option>
                        <option value="return">return — Devolución a Stock</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="mov-dir" class="block font-semibold text-slate-300 mb-1">Operación *</label>
                        <select
                            id="mov-dir"
                            bind:value={movementForm.direction}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        >
                            <option value="add">Sumar (+) al Stock</option>
                            <option value="subtract">Restar (-) del Stock</option>
                        </select>
                    </div>
                    <div>
                        <label for="mov-qty" class="block font-semibold text-slate-300 mb-1">Cantidad ({adjustingMaterial.unit_of_measure}) *</label>
                        <input
                            id="mov-qty"
                            type="number"
                            step="0.001"
                            min="0.001"
                            bind:value={movementForm.quantity}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono font-bold"
                        />
                    </div>
                </div>

                <div>
                    <label for="mov-notes" class="block font-semibold text-slate-300 mb-1">Motivo / Factura / Observaciones</label>
                    <textarea
                        id="mov-notes"
                        bind:value={movementForm.notes}
                        rows="2"
                        placeholder="Ej. Factura #4589 de Telas del Valle, compra mensual..."
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    ></textarea>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={() => showMovementModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={movementProcessing}
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                    >
                        Registrar Movimiento
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
