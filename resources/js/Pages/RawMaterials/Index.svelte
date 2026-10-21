<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';
    import CurrencyInput from '../../Components/CurrencyInput.svelte';
    import QuickCreateModal from '../../Components/QuickCreateModal.svelte';
    import { csrfFetch } from '../../Utils/csrfFetch.js';

    let {
        rawMaterials,
        allSuppliers = [],
        unitsOfMeasure = [],
        filters = { search: '' },
        errors = {},
    } = $props();

    let search = $state('');
    $effect(() => {
        search = filters.search ?? '';
    });

    let viewMode = $state('table'); // 'table' | 'cards'
    let expandedRows = $state(new Set());

    // Proveedores locales sincronizados para permitir quick create
    let localSuppliers = $state([]);
    $effect(() => {
        localSuppliers = [...allSuppliers];
    });

    // Unidades de medida locales sincronizadas
    let localUnitsOfMeasure = $state([]);
    $effect(() => {
        localUnitsOfMeasure = Array.isArray(unitsOfMeasure) ? [...unitsOfMeasure] : [];
    });

    let showMaterialModal = $state(false);
    let showMovementModal = $state(false);
    let showUomModal = $state(false);
    let quickSupplierModalOpen = $state(false);
    let quickUomModalOpen = $state(false);

    // Estado para gestión completa de UOM en modal
    let newUomName = $state('');
    let newUomAbbr = $state('');
    let uomLoading = $state(false);
    let uomError = $state('');
    let editingUomId = $state(null);
    let editUomName = $state('');
    let editUomAbbr = $state('');

    let editingMaterial = $state(null);
    let adjustingMaterial = $state(null);

    let materialProcessing = $state(false);
    let movementProcessing = $state(false);

    function getDefaultUomName() {
        if (!localUnitsOfMeasure || localUnitsOfMeasure.length === 0) return 'Unidad';
        const first = localUnitsOfMeasure[0];
        return typeof first === 'object' && first !== null ? first.name : String(first);
    }

    // Formulario de Materia Prima en Svelte 5 Rune
    let materialForm = $state({
        name: '',
        sku: '',
        unit_of_measure: 'Unidad',
        current_stock: 0,
        min_stock_alert: 0,
        unit_cost: 0,
        suppliers: [],
    });

    function resetMaterialForm() {
        materialForm.name = '';
        materialForm.sku = '';
        materialForm.unit_of_measure = getDefaultUomName();
        materialForm.current_stock = 0;
        materialForm.min_stock_alert = 0;
        materialForm.unit_cost = 0;
        materialForm.suppliers = [];
    }

    async function handleCreateUom(e) {
        e?.preventDefault();
        if (!newUomName.trim()) return;
        uomLoading = true;
        uomError = '';
        try {
            const res = await csrfFetch('/quick/units-of-measure', {
                method: 'POST',
                body: JSON.stringify({
                    name: newUomName.trim(),
                    abbreviation: newUomAbbr.trim() || null,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                uomError = data.message || 'Error al crear la unidad de medida.';
                return;
            }
            localUnitsOfMeasure = [...localUnitsOfMeasure, data];
            newUomName = '';
            newUomAbbr = '';
        } catch (err) {
            uomError = err?.message || 'Error de conexión.';
        } finally {
            uomLoading = false;
        }
    }

    function startEditUom(uom) {
        editingUomId = uom.id;
        editUomName = uom.name;
        editUomAbbr = uom.abbreviation || '';
    }

    function cancelEditUom() {
        editingUomId = null;
        editUomName = '';
        editUomAbbr = '';
    }

    async function handleUpdateUom(uomId) {
        if (!editUomName.trim()) return;
        uomLoading = true;
        uomError = '';
        try {
            const res = await csrfFetch(`/units-of-measure/${uomId}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    name: editUomName.trim(),
                    abbreviation: editUomAbbr.trim() || null,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                uomError = data.message || 'Error al actualizar la unidad de medida.';
                return;
            }
            localUnitsOfMeasure = localUnitsOfMeasure.map((item) => (item.id === uomId ? data : item));
            cancelEditUom();
        } catch (err) {
            uomError = err?.message || 'Error de conexión.';
        } finally {
            uomLoading = false;
        }
    }

    async function handleDeleteUom(uom) {
        if (!confirm(`¿Eliminar la unidad de medida "${uom.name}"?`)) return;
        uomLoading = true;
        uomError = '';
        try {
            const res = await csrfFetch(`/units-of-measure/${uom.id}`, {
                method: 'DELETE',
            });
            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                uomError = data.message || 'Error al eliminar la unidad de medida.';
                return;
            }
            localUnitsOfMeasure = localUnitsOfMeasure.filter((item) => item.id !== uom.id);
        } catch (err) {
            uomError = err?.message || 'Error de conexión.';
        } finally {
            uomLoading = false;
        }
    }

    function handleQuickUomSuccess(created) {
        localUnitsOfMeasure = [...localUnitsOfMeasure, created];
        materialForm.unit_of_measure = created.name;
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
        editingMaterial = null;
        resetMaterialForm();
        materialForm.unit_of_measure = getDefaultUomName();
        materialForm.suppliers = [];
        showMaterialModal = true;
    }

    function openEdit(mat) {
        editingMaterial = mat;
        materialForm.name = mat.name ?? '';
        materialForm.sku = mat.sku ?? '';
        materialForm.unit_of_measure = mat.unit_of_measure ?? 'unidad';
        materialForm.current_stock = Number(mat.current_stock ?? 0);
        materialForm.min_stock_alert = Number(mat.min_stock_alert ?? 0);
        materialForm.unit_cost = Number(mat.unit_cost ?? 0);
        materialForm.suppliers = (mat.suppliers ?? []).map((s) => ({
            supplier_id: s.id,
            purchase_price: s.pivot?.purchase_price ? Number(s.pivot.purchase_price) : '',
            is_preferred: Boolean(s.pivot?.is_preferred),
        }));
        showMaterialModal = true;
    }

    function addSupplierRow() {
        if (localSuppliers.length === 0) {
            quickSupplierModalOpen = true;
            return;
        }
        materialForm.suppliers = [
            ...materialForm.suppliers,
            {
                supplier_id: localSuppliers[0].id,
                purchase_price: '',
                is_preferred: materialForm.suppliers.length === 0,
            },
        ];
    }

    function handleQuickSupplierSuccess(created) {
        localSuppliers = [...localSuppliers, created];
        materialForm.suppliers = [
            ...materialForm.suppliers,
            {
                supplier_id: created.id,
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
        if (confirm(`¿Estás seguro de eliminar el material "${mat.name}"?`)) {
            router.delete(`/raw-materials/${mat.id}`, {
                preserveScroll: true,
            });
        }
    }

    function formatCurrency(amount) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
        }).format(Number(amount || 0));
    }
</script>

<AppLayout title="Materia Prima e Inventario">
    <div class="space-y-6">
        <!-- ENCABEZADO Y ACCIONES -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Módulo 6
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Materia Prima e Inventario</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Control de stock, insumos primordiales para recetas BOM y vinculación con proveedores.
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto">
                <ViewToggle bind:mode={viewMode} storageKey="gekaem_raw_materials_view" />
                <button
                    type="button"
                    onclick={() => (showUomModal = true)}
                    class="px-3.5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 font-semibold text-xs transition-colors flex items-center gap-1.5 shrink-0"
                >
                    <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
                    </svg>
                    <span>Unidades de Medida</span>
                </button>
                <button
                    type="button"
                    onclick={openCreate}
                    class="px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs transition-colors shadow-lg shadow-emerald-600/20 flex items-center gap-2 shrink-0"
                >
                    <span>+</span>
                    <span>Nueva Materia Prima</span>
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

        <!-- VISTA TABLA (DEFAULT) -->
        {#if viewMode === 'table'}
            <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-md">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950/80 text-slate-400 uppercase font-semibold border-b border-slate-800 text-[10px] tracking-wider">
                            <tr>
                                <th class="p-3.5 w-10 text-center"></th>
                                <th class="p-4">Material / SKU</th>
                                <th class="p-4 text-center">Unidad</th>
                                <th class="p-4 text-right">Stock Actual</th>
                                <th class="p-4 text-right">Reservado</th>
                                <th class="p-4 text-right">Disponible</th>
                                <th class="p-4 text-center">Alerta Mínima</th>
                                <th class="p-4 text-right">Costo Unitario</th>
                                <th class="p-4">Proveedores</th>
                                <th class="p-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 text-slate-300">
                            {#each rawMaterials.data as mat (mat.id)}
                                {@const current = Number(mat.current_stock)}
                                {@const reserved = Number(mat.reserved_stock)}
                                {@const available = current - reserved}
                                {@const isLowStock = current <= Number(mat.min_stock_alert)}
                                {@const isExpanded = expandedRows.has(mat.id)}
                                <tr class="hover:bg-slate-800/40 transition-colors {isLowStock ? 'bg-rose-950/10' : ''} {isExpanded ? 'bg-slate-800/25' : ''}">
                                    <!-- Botón Expandir -->
                                    <td class="p-3.5 text-center">
                                        <button
                                            type="button"
                                            onclick={() => toggleRow(mat.id)}
                                            class="w-6 h-6 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center transition-transform {isExpanded ? 'rotate-90 text-emerald-400' : ''}"
                                            title={isExpanded ? 'Colapsar detalles' : 'Expandir proveedores y detalles'}
                                        >
                                            ▶
                                        </button>
                                    </td>
                                    <td class="p-4">
                                        <button
                                            type="button"
                                            class="font-bold text-white text-sm hover:text-emerald-400 text-left block transition-colors"
                                            onclick={() => toggleRow(mat.id)}
                                        >
                                            {mat.name}
                                        </button>
                                        <span class="font-mono text-[10px] text-slate-500">{mat.sku ?? 'Sin SKU'}</span>
                                    </td>
                                    <td class="p-4 text-center font-mono text-slate-400 capitalize">
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
                                    <td class="p-4 text-right font-mono text-slate-300 font-semibold">
                                        {formatCurrency(mat.unit_cost)}
                                    </td>
                                    <td class="p-4">
                                        <div class="flex flex-wrap gap-1 max-w-xs">
                                            {#each mat.suppliers ?? [] as sup}
                                                <span class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded {sup.pivot?.is_preferred ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 font-semibold' : 'bg-slate-800 text-slate-400 border border-slate-700/60'}">
                                                    {#if sup.pivot?.is_preferred}★{/if}
                                                    {sup.name}
                                                </span>
                                            {:else}
                                                <span class="text-[10px] text-slate-500 italic">Insumo local</span>
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

                                <!-- RENGLÓN EXPANDIDO (ACCORDION) -->
                                {#if isExpanded}
                                    <tr class="bg-slate-950/70 border-b border-slate-800">
                                        <td colspan="10" class="p-4 sm:p-5">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <!-- Desglose de Proveedores -->
                                                <div class="p-3.5 bg-slate-900 border border-slate-800 rounded-xl space-y-2">
                                                    <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block border-b border-slate-800 pb-1.5">
                                                        Proveedores Vinculados ({mat.suppliers?.length ?? 0})
                                                    </span>
                                                    <div class="space-y-1.5 max-h-40 overflow-y-auto">
                                                        {#each mat.suppliers ?? [] as sup}
                                                            <div class="flex items-center justify-between p-2 rounded-lg bg-slate-950/80 border border-slate-800 text-xs">
                                                                <div>
                                                                    <span class="font-bold text-white flex items-center gap-1.5">
                                                                        {#if sup.pivot?.is_preferred}
                                                                            <span class="text-amber-400 text-xs" title="Proveedor preferido">★</span>
                                                                        {/if}
                                                                        {sup.name}
                                                                    </span>
                                                                    <span class="text-[10px] text-slate-400">{sup.phone ?? 'Sin teléfono'} • {sup.email ?? 'Sin email'}</span>
                                                                </div>
                                                                <div class="text-right">
                                                                    <span class="font-mono text-emerald-400 font-bold block">
                                                                        {sup.pivot?.purchase_price ? formatCurrency(sup.pivot.purchase_price) : 'Sin precio pactado'}
                                                                    </span>
                                                                    {#if sup.lead_time_days}
                                                                        <span class="text-[10px] text-slate-500">Lead time: {sup.lead_time_days}d</span>
                                                                    {/if}
                                                                </div>
                                                            </div>
                                                        {:else}
                                                            <span class="text-slate-500 text-xs italic py-2 block">
                                                                No tiene proveedores registrados. Es un insumo de fabricación interna o compra libre.
                                                            </span>
                                                        {/each}
                                                    </div>
                                                </div>

                                                <!-- Métricas y Estado de Stock -->
                                                <div class="p-3.5 bg-slate-900 border border-slate-800 rounded-xl space-y-2.5">
                                                    <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider block border-b border-slate-800 pb-1.5">
                                                        Diagnóstico de Inventario
                                                    </span>
                                                    <div class="grid grid-cols-3 gap-2 text-center">
                                                        <div class="p-2 bg-slate-950 rounded-lg border border-slate-800">
                                                            <span class="text-[10px] text-slate-400 block">En Bodega</span>
                                                            <span class="font-mono font-bold text-white text-sm">{current.toFixed(2)}</span>
                                                        </div>
                                                        <div class="p-2 bg-slate-950 rounded-lg border border-slate-800">
                                                            <span class="text-[10px] text-amber-400 block">Comprometido</span>
                                                            <span class="font-mono font-bold text-amber-300 text-sm">{reserved.toFixed(2)}</span>
                                                        </div>
                                                        <div class="p-2 bg-slate-950 rounded-lg border border-slate-800">
                                                            <span class="text-[10px] text-emerald-400 block">Libre</span>
                                                            <span class="font-mono font-bold text-emerald-300 text-sm">{available.toFixed(2)}</span>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center justify-between text-xs pt-1">
                                                        <span class="text-slate-400">Valorización total en stock:</span>
                                                        <span class="font-mono font-bold text-emerald-400">{formatCurrency(current * Number(mat.unit_cost))}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                {/if}
                            {:else}
                                <tr>
                                    <td colspan="10" class="p-10 text-center text-slate-500 text-xs">
                                        No se encontraron materias primas registradas.
                                    </td>
                                </tr>
                            {/each}
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- VISTA EN TARJETAS (CARDS) -->
        {:else}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                {#each rawMaterials.data as mat (mat.id)}
                    {@const current = Number(mat.current_stock)}
                    {@const reserved = Number(mat.reserved_stock)}
                    {@const available = current - reserved}
                    {@const isLowStock = current <= Number(mat.min_stock_alert)}
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col justify-between shadow-md hover:border-slate-700 transition-colors {isLowStock ? 'ring-1 ring-rose-500/30' : ''}">
                        <div>
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-slate-800 text-slate-300 border border-slate-700 capitalize">
                                    {mat.unit_of_measure}
                                </span>
                                <span class="font-mono text-emerald-400 font-extrabold text-base">
                                    {formatCurrency(mat.unit_cost)}
                                </span>
                            </div>

                            <h3 class="text-base font-bold text-white">{mat.name}</h3>
                            {#if mat.sku}
                                <span class="font-mono text-[11px] text-slate-500 block mt-0.5">SKU: {mat.sku}</span>
                            {/if}

                            <!-- Métricas de Stock -->
                            <div class="mt-3.5 p-3 bg-slate-950/70 rounded-xl border border-slate-800/80 grid grid-cols-3 gap-2 text-center text-xs">
                                <div>
                                    <span class="text-[10px] text-slate-400 block">Actual</span>
                                    <span class="font-mono font-bold text-slate-200">{current.toFixed(2)}</span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-amber-400 block">Reservado</span>
                                    <span class="font-mono font-bold text-amber-300">{reserved.toFixed(2)}</span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-emerald-400 block">Disponible</span>
                                    <span class="font-mono font-bold {available <= 0 ? 'text-rose-400' : 'text-emerald-400'}">{available.toFixed(2)}</span>
                                </div>
                            </div>

                            <!-- Alerta de Stock Mínimo -->
                            <div class="mt-2.5 flex items-center justify-between text-xs">
                                <span class="text-slate-400 text-[11px]">Umbral de Alerta:</span>
                                {#if isLowStock}
                                    <span class="px-2 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30 font-mono text-[10px] font-bold animate-pulse">
                                        ⚠ Stock Bajo ({Number(mat.min_stock_alert)})
                                    </span>
                                {:else}
                                    <span class="font-mono text-slate-400 text-[11px]">
                                        {Number(mat.min_stock_alert)} {mat.unit_of_measure}
                                    </span>
                                {/if}
                            </div>

                            <!-- Proveedores -->
                            <div class="mt-3 pt-2.5 border-t border-slate-800/60">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">
                                    Proveedores ({mat.suppliers?.length ?? 0}):
                                </span>
                                <div class="flex flex-wrap gap-1">
                                    {#each mat.suppliers ?? [] as sup}
                                        <span class="text-[10px] px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700/60">
                                            {#if sup.pivot?.is_preferred}★ {/if}{sup.name}
                                        </span>
                                    {:else}
                                        <span class="text-[10px] text-slate-500 italic">Insumo local</span>
                                    {/each}
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="mt-4 pt-3 border-t border-slate-800 flex items-center justify-end gap-2">
                            <button
                                type="button"
                                onclick={() => openAdjust(mat)}
                                class="px-2.5 py-1 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 border border-emerald-500/30 text-xs font-semibold"
                            >
                                ± Stock
                            </button>
                            <button
                                type="button"
                                onclick={() => openEdit(mat)}
                                class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold"
                            >
                                Editar
                            </button>
                            <button
                                type="button"
                                onclick={() => destroy(mat)}
                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 text-slate-400 hover:text-rose-300 text-xs font-semibold"
                            >
                                Eliminar
                            </button>
                        </div>
                    </div>
                {:else}
                    <div class="col-span-full p-12 text-center bg-slate-900 border border-slate-800 rounded-2xl text-slate-500 text-xs">
                        No hay materias primas registradas. Haz clic en "Nueva Materia Prima" para comenzar.
                    </div>
                {/each}
            </div>
        {/if}

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

<!-- MODAL CREAR / EDITAR MATERIA PRIMA (Nota 4) -->
{#if showMaterialModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">
                    {editingMaterial ? `Editar Insumo: ${editingMaterial.name}` : 'Registrar Materia Prima'}
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
                        <div class="flex items-center justify-between mb-1">
                            <label for="m-unit" class="block font-semibold text-slate-300">Unidad *</label>
                            <button
                                type="button"
                                onclick={() => (quickUomModalOpen = true)}
                                class="text-[10px] text-emerald-400 hover:text-emerald-300 font-bold transition-colors"
                            >
                                + Nueva
                            </button>
                        </div>
                        <select
                            id="m-unit"
                            bind:value={materialForm.unit_of_measure}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs capitalize"
                        >
                            {#each localUnitsOfMeasure as uom}
                                {#if typeof uom === 'object' && uom !== null}
                                    <option value={uom.name}>
                                        {uom.name} {uom.abbreviation ? `(${uom.abbreviation})` : ''}
                                    </option>
                                {:else}
                                    <option value={uom}>{uom}</option>
                                {/if}
                            {/each}
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
                        <CurrencyInput bind:value={materialForm.unit_cost} id="m-cost" required />
                    </div>
                </div>

                <!-- SECCIÓN DE PROVEEDORES OPCIONALES (NOTA 4) -->
                <div class="pt-3 border-t border-slate-800 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-xs">Proveedores Asociados (Nota 4: 0, 1 o múltiples)</span>
                            <p class="text-[11px] text-slate-500">Puedes dejarlo sin proveedor si el insumo es de elaboración interna.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                onclick={() => quickSupplierModalOpen = true}
                                class="text-[11px] text-emerald-400 hover:text-emerald-300 font-bold"
                            >
                                + Nuevo Proveedor
                            </button>
                            <button
                                type="button"
                                onclick={addSupplierRow}
                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs border border-slate-700 flex items-center gap-1"
                            >
                                <span>+</span>
                                <span>Asociar Proveedor</span>
                            </button>
                        </div>
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
                                            {#each localSuppliers as s}
                                                <option value={s.id}>{s.name}</option>
                                            {/each}
                                        </select>
                                    </div>
                                    <div class="w-32">
                                        <CurrencyInput
                                            bind:value={supRow.purchase_price}
                                            placeholder="Precio compra"
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
                        {editingMaterial ? 'Actualizar Material' : 'Guardar Material'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL REGISTRO DE MOVIMIENTO / AJUSTE DE STOCK -->
{#if showMovementModal && adjustingMaterial}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-base font-bold text-white">Ajuste de Stock: {adjustingMaterial.name}</h3>
                    <span class="text-[11px] text-slate-400">Stock actual: {Number(adjustingMaterial.current_stock)} {adjustingMaterial.unit_of_measure}</span>
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
                        <option value="purchase">Compra de Insumos (Entrada)</option>
                        <option value="adjustment">Ajuste Manual de Inventario</option>
                        <option value="return">Devolución / Reintegro</option>
                        <option value="consumption">Merma / Pérdida no planeada</option>
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
                            <option value="add">+ Sumar a Stock</option>
                            <option value="subtract">- Restar de Stock</option>
                        </select>
                    </div>
                    <div>
                        <label for="mov-qty" class="block font-semibold text-slate-300 mb-1">Cantidad *</label>
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
                    <label for="mov-notes" class="block font-semibold text-slate-300 mb-1">Notas / Justificación</label>
                    <input
                        id="mov-notes"
                        type="text"
                        bind:value={movementForm.notes}
                        placeholder="Ej. Factura #402 / Corrección de inventario"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
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

<!-- MODAL FLOTANTE DE CREACIÓN RÁPIDA DE PROVEEDOR -->
<QuickCreateModal
    bind:isOpen={quickSupplierModalOpen}
    type="supplier"
    onSuccess={handleQuickSupplierSuccess}
/>

<!-- MODAL FLOTANTE DE CREACIÓN RÁPIDA DE UNIDAD DE MEDIDA -->
<QuickCreateModal
    bind:isOpen={quickUomModalOpen}
    type="units-of-measure"
    onSuccess={handleQuickUomSuccess}
/>

<!-- MODAL COMPLETO DE GESTIÓN DE UNIDADES DE MEDIDA -->
{#if showUomModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4 animate-fade-in" role="dialog" aria-modal="true">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-xl w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-hidden flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 font-bold text-sm">
                        📐
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold tracking-wider text-emerald-400 block">Catálogo del Taller</span>
                        <h3 class="text-base font-bold text-white">Unidades de Medida</h3>
                    </div>
                </div>
                <button
                    type="button"
                    onclick={() => (showUomModal = false)}
                    class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800 transition-colors"
                >
                    ✕
                </button>
            </div>

            <!-- Formulario Crear Nueva Unidad -->
            <form onsubmit={handleCreateUom} class="p-3.5 bg-slate-950/80 border border-slate-800 rounded-xl space-y-2">
                <span class="text-xs font-bold text-slate-300 block">Agregar Nueva Unidad</span>
                {#if uomError}
                    <div class="p-2 bg-rose-950/60 border border-rose-800/80 rounded-lg text-rose-300 text-xs">
                        {uomError}
                    </div>
                {/if}
                <div class="grid grid-cols-1 sm:grid-cols-5 gap-2">
                    <div class="sm:col-span-3">
                        <input
                            type="text"
                            bind:value={newUomName}
                            placeholder="Nombre (ej. Metro, Litro, Rollo)"
                            required
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 font-medium"
                        />
                    </div>
                    <div class="sm:col-span-2 flex gap-2">
                        <input
                            type="text"
                            bind:value={newUomAbbr}
                            placeholder="Abrev. (ej. m)"
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-2 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 font-mono"
                        />
                        <button
                            type="submit"
                            disabled={uomLoading || !newUomName.trim()}
                            class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-xs font-bold shrink-0 transition-colors flex items-center gap-1 shadow-md shadow-emerald-900/20"
                        >
                            {#if uomLoading}
                                <span>...</span>
                            {:else}
                                <span>+ Crear</span>
                            {/if}
                        </button>
                    </div>
                </div>
            </form>

            <!-- Listado de Unidades Existentes -->
            <div class="flex-1 overflow-y-auto space-y-1.5 pr-1 max-h-72">
                {#if localUnitsOfMeasure.length === 0}
                    <div class="p-8 text-center text-slate-500 text-xs bg-slate-950/40 rounded-xl border border-dashed border-slate-800">
                        No hay unidades de medida registradas.
                    </div>
                {:else}
                    {#each localUnitsOfMeasure as uom (uom.id || uom.name)}
                        <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-950/50 border border-slate-800/80 hover:border-slate-700/80 transition-colors text-xs">
                            {#if editingUomId === uom.id}
                                <div class="flex items-center gap-2 flex-1 mr-2">
                                    <input
                                        type="text"
                                        bind:value={editUomName}
                                        class="flex-1 bg-slate-900 border border-emerald-500 rounded-lg px-2.5 py-1.5 text-xs text-white focus:outline-none font-medium"
                                        placeholder="Nombre"
                                    />
                                    <input
                                        type="text"
                                        bind:value={editUomAbbr}
                                        class="w-20 bg-slate-900 border border-emerald-500 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none font-mono"
                                        placeholder="Abrev."
                                    />
                                    <button
                                        type="button"
                                        onclick={() => handleUpdateUom(uom.id)}
                                        disabled={uomLoading}
                                        class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-lg font-bold text-xs transition-colors"
                                    >
                                        Guardar
                                    </button>
                                    <button
                                        type="button"
                                        onclick={cancelEditUom}
                                        class="px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition-colors"
                                    >
                                        Cancelar
                                    </button>
                                </div>
                            {:else}
                                <div class="flex items-center gap-2.5">
                                    <span class="font-semibold text-slate-200 text-sm">{uom.name}</span>
                                    {#if uom.abbreviation}
                                        <span class="px-2 py-0.5 rounded bg-slate-800 border border-slate-700 text-emerald-400 text-[11px] font-mono font-medium">
                                            {uom.abbreviation}
                                        </span>
                                    {/if}
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        onclick={() => startEditUom(uom)}
                                        class="p-1.5 rounded-lg hover:bg-slate-800 text-slate-400 hover:text-white transition-colors text-xs flex items-center gap-1"
                                        title="Editar unidad"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                        <span>Editar</span>
                                    </button>
                                    <button
                                        type="button"
                                        onclick={() => handleDeleteUom(uom)}
                                        class="p-1.5 rounded-lg hover:bg-rose-950/60 text-slate-400 hover:text-rose-400 transition-colors text-xs flex items-center gap-1"
                                        title="Eliminar unidad"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            {/if}
                        </div>
                    {/each}
                {/if}
            </div>

            <!-- Footer -->
            <div class="border-t border-slate-800 pt-3 flex items-center justify-between text-[11px] text-slate-400">
                <span>{localUnitsOfMeasure.length} unidades disponibles para inventario y recetas</span>
                <button
                    type="button"
                    onclick={() => (showUomModal = false)}
                    class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold rounded-xl text-xs transition-colors"
                >
                    Cerrar
                </button>
            </div>
        </div>
    </div>
{/if}
