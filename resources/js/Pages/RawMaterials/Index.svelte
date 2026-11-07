<script>
    import { untrack } from 'svelte';
    import { router, Link, page } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';
    import CurrencyInput from '../../Components/CurrencyInput.svelte';
    import QuickCreateModal from '../../Components/QuickCreateModal.svelte';
    import { csrfFetch } from '../../Utils/csrfFetch.js';
    import { canViewPrices } from '../../Utils/permissions.js';

    let {
        rawMaterials,
        allSuppliers = [],
        unitsOfMeasure = [],
        pendingRequests = [],
        allMaterialsList = [],
        filters = { search: '', unit_of_measure: '', supplier_id: '', low_stock: false },
        errors = {},
    } = $props();

    let authUser = $derived(page.props.auth?.user);
    let hasFinancialAccess = $derived(canViewPrices(authUser));
    let isOwnerOrAdmin = $derived(Boolean(authUser?.is_super_admin || authUser?.role === 'owner' || authUser?.role === 'admin'));
    let isWorker = $derived(authUser?.role === 'worker');

    // Estado del modal de Solicitud de Materia Prima al CEO (Sprint 9)
    let showRequestModal = $state(false);
    let requestSubmitting = $state(false);
    let requestForm = $state({
        type: 'existing', // 'existing' | 'new'
        raw_material_id: '',
        new_material_name: '',
        requested_quantity: '',
        unit: '',
        notes: '',
    });

    function openRequestModal() {
        const firstMat = allMaterialsList[0] || null;
        requestForm = {
            type: 'existing',
            raw_material_id: firstMat?.id ? String(firstMat.id) : '',
            new_material_name: '',
            requested_quantity: '',
            unit: firstMat?.unit_of_measure || '',
            notes: '',
        };
        showRequestModal = true;
    }

    function onSelectExistingMaterial(e) {
        const matId = e.target.value;
        requestForm.raw_material_id = matId;
        const found = allMaterialsList.find(m => String(m.id) === String(matId));
        if (found) {
            requestForm.unit = found.unit_of_measure || '';
        }
    }

    function submitMaterialRequest(e) {
        e.preventDefault();
        requestSubmitting = true;
        router.post('/material-requests', requestForm, {
            preserveScroll: true,
            onFinish: () => { requestSubmitting = false; },
            onSuccess: () => {
                showRequestModal = false;
            },
        });
    }

    function handleApproveRequest(id) {
        router.patch(`/material-requests/${id}`, { status: 'approved' }, {
            preserveScroll: true,
        });
    }

    function handleRejectRequest(id) {
        if (confirm('¿Deseas descartar esta solicitud de materia prima?')) {
            router.patch(`/material-requests/${id}`, { status: 'rejected' }, {
                preserveScroll: true,
            });
        }
    }

    let search = $state('');
    let unitFilter = $state('');
    let supplierFilter = $state('');
    let lowStockFilter = $state(false);

    $effect(() => {
        const incoming = filters;
        untrack(() => {
            search = incoming.search ?? '';
            unitFilter = incoming.unit_of_measure ?? '';
            supplierFilter = incoming.supplier_id ? String(incoming.supplier_id) : '';
            lowStockFilter = Boolean(incoming.low_stock);
        });
    });

    let viewMode = $state('table'); // 'table' | 'cards'
    let expandedRows = $state(new Set());
    let openActionMenuMatId = $state(null);
    let menuCoords = $state({ top: 0, right: 0, openUpwards: false, rectTop: 0 });

    function toggleActionMenu(e, matId) {
        e.stopPropagation();
        if (openActionMenuMatId === matId) {
            openActionMenuMatId = null;
            return;
        }
        const rect = e.currentTarget.getBoundingClientRect();
        const openUpwards = window.innerHeight - rect.bottom < 220;
        menuCoords = {
            top: rect.bottom + 6,
            right: Math.max(12, window.innerWidth - rect.right),
            openUpwards,
            rectTop: rect.top,
        };
        openActionMenuMatId = matId;
    }

    // Proveedores locales sincronizados para permitir quick create
    let localSuppliers = $state([]);
    $effect(() => {
        const incoming = allSuppliers;
        untrack(() => {
            localSuppliers = [...(incoming || [])];
        });
    });

    // Unidades de medida locales sincronizadas
    let localUnitsOfMeasure = $state([]);
    $effect(() => {
        const incoming = unitsOfMeasure;
        untrack(() => {
            localUnitsOfMeasure = Array.isArray(incoming) ? [...incoming] : [];
        });
    });

    // Unidades de medida ordenadas alfabéticamente (A-Z)
    let sortedUnitsOfMeasure = $derived(
        localUnitsOfMeasure.toSorted((a, b) => {
            const nameA = typeof a === 'object' && a !== null ? (a.name || '') : String(a || '');
            const nameB = typeof b === 'object' && b !== null ? (b.name || '') : String(b || '');
            return nameA.localeCompare(nameB, 'es');
        })
    );

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
        if (!sortedUnitsOfMeasure || sortedUnitsOfMeasure.length === 0) return 'Unidad';
        const first = sortedUnitsOfMeasure[0];
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

    let currentStockAdjust = $derived(Number(adjustingMaterial?.current_stock ?? 0));
    let adjustQty = $derived(Number(movementForm.quantity || 0));
    let isAddingStock = $derived(movementForm.direction === 'add');
    let resultingStock = $derived(
        isAddingStock
            ? currentStockAdjust + adjustQty
            : Math.max(0, currentStockAdjust - adjustQty)
    );

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
            applyFilters();
        }, 300);
    }

    function applyFilters() {
        router.get(
            '/raw-materials',
            {
                search,
                unit_of_measure: unitFilter,
                supplier_id: supplierFilter,
                low_stock: lowStockFilter ? '1' : '',
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function clearFilters() {
        search = '';
        unitFilter = '';
        supplierFilter = '';
        lowStockFilter = false;
        applyFilters();
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

<svelte:window onclick={() => openActionMenuMatId = null} onscroll={() => openActionMenuMatId = null} />

<AppLayout title="Materia Prima e Inventario">
    <div class="space-y-4">
        <!-- ENCABEZADO Y ACCIONES -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Materia Prima e Inventario</h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    Control de stock, insumos primordiales para recetas BOM y vinculación con proveedores.
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0 self-start sm:self-auto flex-wrap">
                <ViewToggle bind:mode={viewMode} storageKey="gekaem_raw_materials_view" />

                <!-- Botón Solicitar Materia Prima al CEO -->
                <button
                    type="button"
                    onclick={openRequestModal}
                    class="px-3.5 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-semibold text-xs transition shadow-sm flex items-center gap-1.5 shrink-0"
                    title="Enviar solicitud de materia prima o insumo al CEO del taller"
                >
                    <span>📦</span>
                    <span>Solicitar Materia Prima al CEO</span>
                </button>

                {#if isOwnerOrAdmin}
                    <button
                        type="button"
                        onclick={() => (showUomModal = true)}
                        class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold text-xs transition-colors flex items-center gap-1.5 shrink-0"
                    >
                        <svg class="w-4 h-4 text-[#4F46E5] dark:text-[#06B6D4]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
                        </svg>
                        <span>Unidades de Medida</span>
                    </button>
                    <button
                        type="button"
                        onclick={openCreate}
                        class="px-3.5 py-2 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-semibold text-xs transition shadow-sm flex items-center gap-2 shrink-0"
                    >
                        <span>+</span>
                        <span>Nueva Materia Prima</span>
                    </button>
                {/if}
            </div>
        </div>

        <!-- BANDEJA DE SOLICITUDES DE MATERIA PRIMA (VISIBLE PARA DUEÑO Y ADMIN) -->
        {#if isOwnerOrAdmin && pendingRequests && pendingRequests.length > 0}
            <div class="bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent border border-amber-500/30 rounded-2xl p-4 sm:p-5 shadow-xs space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="text-xl">🔔</span>
                        <div>
                            <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                <span>Solicitudes de Materia Prima del Equipo</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-500 text-white">
                                    {pendingRequests.length} {pendingRequests.length === 1 ? 'pendiente' : 'pendientes'}
                                </span>
                            </h2>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                Insumos solicitados por el equipo de operarios para la producción en el taller.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 pt-1">
                    {#each pendingRequests as req (req.id)}
                        <div class="p-3.5 bg-white dark:bg-[#151D2C] border border-amber-300/60 dark:border-amber-500/25 rounded-xl shadow-xs space-y-2.5">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400 dark:text-slate-500 block">
                                        Solicitado por: <strong class="text-slate-700 dark:text-slate-300">{req.user?.name ?? 'Trabajador'}</strong>
                                    </span>
                                    <h3 class="font-bold text-xs text-slate-900 dark:text-white truncate mt-0.5">
                                        {req.raw_material?.name ?? req.new_material_name ?? 'Insumo'}
                                    </h3>
                                </div>
                                <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase shrink-0 {req.raw_material_id ? 'bg-indigo-50 dark:bg-indigo-500/15 text-[#4F46E5] dark:text-indigo-300 border border-indigo-200 dark:border-indigo-500/30' : 'bg-emerald-50 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30'}">
                                    {req.raw_material_id ? 'Existente' : 'Nuevo Insumo'}
                                </span>
                            </div>

                            <div class="flex items-baseline gap-2 bg-slate-50 dark:bg-[#0B0F17] px-2.5 py-1.5 rounded-lg border border-slate-200/60 dark:border-[#26334D]">
                                <span class="text-[11px] text-slate-500">Cantidad requerida:</span>
                                <span class="text-xs font-mono font-extrabold text-amber-600 dark:text-amber-400">
                                    {req.requested_quantity} {req.unit || req.raw_material?.unit_of_measure || ''}
                                </span>
                            </div>

                            {#if req.notes}
                                <p class="text-[11px] text-slate-600 dark:text-slate-300 italic bg-amber-50/50 dark:bg-amber-950/20 p-2 rounded-lg border border-amber-200/40 dark:border-amber-900/30">
                                    "{req.notes}"
                                </p>
                            {/if}

                            <div class="flex items-center justify-end gap-2 pt-1 border-t border-slate-100 dark:border-[#26334D]">
                                <button
                                    type="button"
                                    onclick={() => handleRejectRequest(req.id)}
                                    class="px-2.5 py-1 rounded-lg text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 text-xs font-semibold border border-rose-200 dark:border-rose-900/40 transition-colors"
                                >
                                    Descartar
                                </button>
                                <button
                                    type="button"
                                    onclick={() => handleApproveRequest(req.id)}
                                    class="px-3 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-xs transition-colors flex items-center gap-1"
                                >
                                    <span>✓</span>
                                    <span>Aprobar / Atender</span>
                                </button>
                            </div>
                        </div>
                    {/each}
                </div>
            </div>
        {/if}

        <!-- BUSCADOR Y FILTROS COMBINADOS -->
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] p-3.5 rounded-2xl flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 shadow-sm">
            <div class="flex-1 bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] px-3 py-2 rounded-xl flex items-center gap-2">
                <span class="text-slate-400 text-xs">🔍</span>
                <label for="search-materials" class="sr-only">Buscar materia prima</label>
                <input
                    id="search-materials"
                    type="text"
                    bind:value={search}
                    oninput={handleSearchInput}
                    placeholder="Buscar materia prima por nombre o SKU..."
                    class="w-full bg-transparent text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:outline-none"
                />
                {#if search}
                    <button type="button" onclick={() => { search = ''; applyFilters(); }} class="text-slate-400 hover:text-slate-700 dark:hover:text-white text-xs">✕</button>
                {/if}
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
                <!-- Filtro Unidad de Medida -->
                <label for="filter-uom" class="sr-only">Filtrar por unidad de medida</label>
                <select
                    id="filter-uom"
                    bind:value={unitFilter}
                    onchange={applyFilters}
                    class="bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-[#4F46E5]"
                >
                    <option value="">Todas las Unidades</option>
                    {#each sortedUnitsOfMeasure as uom}
                        <option value={uom.abbreviation || uom.name}>{uom.name} ({uom.abbreviation || uom.name})</option>
                    {/each}
                </select>

                <!-- Filtro Proveedor -->
                <select
                    bind:value={supplierFilter}
                    onchange={applyFilters}
                    class="bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-[#4F46E5]"
                >
                    <option value="">Todos los Proveedores</option>
                    {#each localSuppliers as sup}
                        <option value={String(sup.id)}>{sup.name}</option>
                    {/each}
                </select>

                <!-- Toggle Rápido Stock Bajo / Crítico -->
                <button
                    type="button"
                    onclick={() => { lowStockFilter = !lowStockFilter; applyFilters(); }}
                    class="px-3 py-2 rounded-xl border text-xs font-semibold flex items-center gap-1.5 transition-colors {lowStockFilter ? 'bg-rose-50 text-rose-600 border-rose-300 dark:bg-rose-500/20 dark:text-rose-300 dark:border-rose-500/40' : 'bg-slate-50 dark:bg-[#0B0F17] text-slate-600 dark:text-slate-400 border-slate-200 dark:border-[#26334D] hover:text-slate-900 dark:hover:text-slate-200'}"
                    title="Filtrar solo insumos con stock por debajo o igual al umbral mínimo"
                >
                    <span>⚠</span>
                    <span>Solo Stock Bajo</span>
                </button>

                {#if search || unitFilter || supplierFilter || lowStockFilter}
                    <button
                        type="button"
                        onclick={clearFilters}
                        class="px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-300 border border-slate-200 dark:border-[#26334D] text-xs transition-colors font-medium"
                        title="Limpiar filtros"
                    >
                        Limpiar
                    </button>
                {/if}
            </div>
        </div>

        <!-- VISTA TABLA (DEFAULT) -->
        {#if viewMode === 'table'}
            <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl overflow-hidden shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50/80 dark:bg-[#0B0F17]/60 text-[#64748B] dark:text-slate-400 uppercase font-semibold border-b border-slate-200 dark:border-[#26334D] text-[10px] tracking-wider whitespace-nowrap">
                            <tr>
                                <th class="py-2.5 px-3 w-8 text-center"></th>
                                <th class="py-2.5 px-3">Material / SKU</th>
                                <th class="py-2.5 px-3 text-center">Unidad</th>
                                <th class="py-2.5 px-3 text-right">Stock Actual</th>
                                <th class="py-2.5 px-3 text-right">Reservado</th>
                                <th class="py-2.5 px-3 text-right">Disponible</th>
                                <th class="py-2.5 px-3 text-center">Alerta Mínima</th>
                                {#if hasFinancialAccess}
                                    <th class="py-2.5 px-3 text-right">Costo Unitario</th>
                                {/if}
                                <th class="py-2.5 px-3">Proveedores</th>
                                {#if isOwnerOrAdmin}
                                    <th class="py-2.5 px-3 text-right">Acciones</th>
                                {/if}
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-[#26334D]/60 text-slate-700 dark:text-slate-300">
                            {#each rawMaterials.data as mat (mat.id)}
                                {@const current = Number(mat.current_stock)}
                                {@const reserved = Number(mat.reserved_stock)}
                                {@const available = current - reserved}
                                {@const isLowStock = current <= Number(mat.min_stock_alert)}
                                {@const isExpanded = expandedRows.has(mat.id)}
                                <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors {isLowStock ? 'bg-rose-50/50 dark:bg-rose-950/10' : ''} {isExpanded ? 'bg-slate-50/90 dark:bg-slate-800/25' : ''}">
                                    <!-- Botón Expandir -->
                                    <td class="py-2.5 px-3 text-center whitespace-nowrap">
                                        <button
                                            type="button"
                                            onclick={() => toggleRow(mat.id)}
                                            class="w-5 h-5 rounded-md bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 flex items-center justify-center transition-transform text-[10px] {isExpanded ? 'rotate-90 text-[#4F46E5] dark:text-[#06B6D4]' : ''}"
                                            title={isExpanded ? 'Colapsar detalles' : 'Expandir proveedores y detalles'}
                                        >
                                            ▶
                                        </button>
                                    </td>
                                    <td class="py-2.5 px-3 whitespace-nowrap">
                                        <div class="inline-flex items-center gap-2">
                                            <button
                                                type="button"
                                                class="font-bold text-slate-900 dark:text-white text-xs hover:text-[#4F46E5] dark:hover:text-[#06B6D4] text-left transition-colors"
                                                onclick={() => toggleRow(mat.id)}
                                            >
                                                {mat.name}
                                            </button>
                                            <span class="font-mono text-[10px] text-slate-400 dark:text-slate-500">({mat.sku ?? 'Sin SKU'})</span>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3 text-center font-mono text-slate-500 dark:text-slate-400 capitalize whitespace-nowrap">
                                        {mat.unit_of_measure}
                                    </td>
                                    <td class="py-2.5 px-3 text-right font-mono font-bold text-slate-900 dark:text-slate-100 whitespace-nowrap">
                                        {current.toFixed(2)}
                                    </td>
                                    <td class="py-2.5 px-3 text-right font-mono font-semibold text-amber-600 dark:text-amber-400 whitespace-nowrap">
                                        {reserved.toFixed(2)}
                                    </td>
                                    <td class="py-2.5 px-3 text-right font-mono font-bold whitespace-nowrap {available <= 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'}">
                                        {available.toFixed(2)}
                                    </td>
                                    <td class="py-2.5 px-3 text-center whitespace-nowrap">
                                        {#if isLowStock}
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-mono font-bold text-[10px] bg-rose-100 dark:bg-rose-500/20 text-rose-700 dark:text-rose-300 border border-rose-300 dark:border-rose-500/30 animate-pulse">
                                                ⚠ Min: {Number(mat.min_stock_alert)}
                                            </span>
                                        {:else}
                                            <span class="font-mono text-slate-500 dark:text-slate-400 text-[11px]">
                                                Min: {Number(mat.min_stock_alert)}
                                            </span>
                                        {/if}
                                    </td>
                                    {#if hasFinancialAccess}
                                        <td class="py-2.5 px-3 text-right font-mono text-slate-900 dark:text-slate-200 font-semibold whitespace-nowrap">
                                            {formatCurrency(mat.unit_cost)}
                                        </td>
                                    {/if}
                                    <td class="py-2.5 px-3 whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1 max-w-[200px] overflow-hidden">
                                            {#each (mat.suppliers ?? []).slice(0, 2) as sup}
                                                <span class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 rounded-full {sup.pivot?.is_preferred ? 'bg-emerald-50 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30 font-semibold' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700/60'}">
                                                    {#if sup.pivot?.is_preferred}★{/if}
                                                    {sup.name}
                                                </span>
                                            {/each}
                                            {#if (mat.suppliers?.length ?? 0) > 2}
                                                <span class="text-[10px] text-slate-400 font-mono">+{mat.suppliers.length - 2}</span>
                                            {/if}
                                            {#if !mat.suppliers?.length}
                                                <span class="text-[10px] text-slate-400 italic">Insumo local</span>
                                            {/if}
                                        </div>
                                    </td>
                                    {#if isOwnerOrAdmin}
                                        <td class="py-2.5 px-3 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center justify-end">
                                                <button
                                                    type="button"
                                                    onclick={(e) => toggleActionMenu(e, mat.id)}
                                                    class="p-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] text-xs font-bold transition-colors"
                                                    title="Más opciones"
                                                >
                                                    •••
                                                </button>
                                            </div>

                                            <!-- Menú Contextual Desplegable (Fixed) -->
                                            {#if openActionMenuMatId === mat.id}
                                                <div
                                                    role="menu"
                                                    tabindex="-1"
                                                    class="fixed z-[9999] w-48 rounded-xl bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] shadow-xl py-1 text-left divide-y divide-slate-100 dark:divide-[#26334D]"
                                                    style="right: {menuCoords.right}px; {menuCoords.openUpwards ? `bottom: ${window.innerHeight - menuCoords.rectTop + 6}px;` : `top: ${menuCoords.top}px;`}"
                                                    onclick={(e) => e.stopPropagation()}
                                                    onkeydown={(e) => { if (e.key === 'Escape') openActionMenuMatId = null; }}
                                                >
                                                    <div class="py-0.5">
                                                        <button
                                                            type="button"
                                                            onclick={() => { openActionMenuMatId = null; openEdit(mat); }}
                                                            class="w-full flex items-center gap-2 px-3 py-1.5 text-xs text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors"
                                                        >
                                                            <span>✏️</span>
                                                            <span>Editar Insumo</span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onclick={() => { openActionMenuMatId = null; openAdjust(mat); }}
                                                            class="w-full flex items-center gap-2 px-3 py-1.5 text-xs text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 transition-colors"
                                                        >
                                                            <span>📦</span>
                                                            <span>Ajustar Stock / Compra</span>
                                                        </button>
                                                    </div>
                                                    <div class="py-0.5">
                                                        <button
                                                            type="button"
                                                            onclick={() => { openActionMenuMatId = null; destroy(mat); }}
                                                            class="w-full flex items-center gap-2 px-3 py-1.5 text-xs text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-colors"
                                                        >
                                                            <span>🗑️</span>
                                                            <span>Eliminar Insumo</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            {/if}
                                        </td>
                                    {/if}
                                </tr>


                                <!-- RENGLÓN EXPANDIDO (ACCORDION) -->
                                {#if isExpanded}
                                    <tr class="bg-slate-50/70 dark:bg-[#0B0F17]/70 border-b border-slate-200 dark:border-[#26334D]">
                                        <td colspan="10" class="p-4 sm:p-5">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <!-- Desglose de Proveedores -->
                                                <div class="p-3.5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl space-y-2">
                                                    <span class="text-[10px] uppercase font-bold text-slate-500 dark:text-slate-400 tracking-wider block border-b border-slate-100 dark:border-[#26334D] pb-1.5">
                                                        Proveedores Vinculados ({mat.suppliers?.length ?? 0})
                                                    </span>
                                                    <div class="space-y-1.5 max-h-40 overflow-y-auto">
                                                        {#each mat.suppliers ?? [] as sup}
                                                            <div class="flex items-center justify-between p-2 rounded-lg bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-xs">
                                                                <div>
                                                                    <span class="font-bold text-slate-900 dark:text-white flex items-center gap-1.5">
                                                                        {#if sup.pivot?.is_preferred}
                                                                            <span class="text-amber-500 dark:text-amber-400 text-xs" title="Proveedor preferido">★</span>
                                                                        {/if}
                                                                        {sup.name}
                                                                    </span>
                                                                    <span class="text-[10px] text-slate-500 dark:text-slate-400">{sup.phone ?? 'Sin teléfono'} • {sup.email ?? 'Sin email'}</span>
                                                                </div>
                                                                <div class="text-right">
                                                                    {#if hasFinancialAccess}
                                                                        <span class="font-mono text-emerald-600 dark:text-emerald-400 font-bold block">
                                                                            {sup.pivot?.purchase_price ? formatCurrency(sup.pivot.purchase_price) : 'Sin precio pactado'}
                                                                        </span>
                                                                    {/if}
                                                                    {#if sup.lead_time_days}
                                                                        <span class="text-[10px] text-slate-400 dark:text-slate-500">Lead time: {sup.lead_time_days}d</span>
                                                                    {/if}
                                                                </div>
                                                            </div>
                                                        {:else}
                                                            <span class="text-slate-400 dark:text-slate-500 text-xs italic py-2 block">
                                                                No tiene proveedores registrados. Es un insumo de fabricación interna o compra libre.
                                                            </span>
                                                        {/each}
                                                    </div>
                                                </div>

                                                <!-- Métricas y Estado de Stock -->
                                                <div class="p-3.5 bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl space-y-2.5">
                                                    <span class="text-[10px] uppercase font-bold text-slate-500 dark:text-slate-400 tracking-wider block border-b border-slate-100 dark:border-[#26334D] pb-1.5">
                                                        Diagnóstico de Inventario
                                                    </span>
                                                    <div class="grid grid-cols-3 gap-2 text-center">
                                                        <div class="p-2 bg-slate-50 dark:bg-[#0B0F17] rounded-lg border border-slate-200 dark:border-[#26334D]">
                                                            <span class="text-[10px] text-slate-500 dark:text-slate-400 block">En Bodega</span>
                                                            <span class="font-mono font-bold text-slate-900 dark:text-white text-sm">{current.toFixed(2)}</span>
                                                        </div>
                                                        <div class="p-2 bg-slate-50 dark:bg-[#0B0F17] rounded-lg border border-slate-200 dark:border-[#26334D]">
                                                            <span class="text-[10px] text-amber-600 dark:text-amber-400 block">Comprometido</span>
                                                            <span class="font-mono font-bold text-amber-600 dark:text-amber-300 text-sm">{reserved.toFixed(2)}</span>
                                                        </div>
                                                        <div class="p-2 bg-slate-50 dark:bg-[#0B0F17] rounded-lg border border-slate-200 dark:border-[#26334D]">
                                                            <span class="text-[10px] text-emerald-600 dark:text-emerald-400 block">Libre</span>
                                                            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-300 text-sm">{available.toFixed(2)}</span>
                                                        </div>
                                                    </div>
                                                    {#if hasFinancialAccess}
                                                        <div class="flex items-center justify-between text-xs pt-1">
                                                            <span class="text-slate-500 dark:text-slate-400">Valorización total en stock:</span>
                                                            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400">{formatCurrency(current * Number(mat.unit_cost))}</span>
                                                        </div>
                                                    {/if}
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                {/if}
                            {:else}
                                <tr>
                                    <td colspan="10" class="p-10 text-center text-slate-400 dark:text-slate-500 text-xs">
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
                    <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-2xl p-5 flex flex-col justify-between shadow-sm hover:border-[#4F46E5] dark:hover:border-[#06B6D4] transition {isLowStock ? 'ring-1 ring-rose-400 dark:ring-rose-500/30' : ''}">
                        <div>
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 capitalize">
                                    {mat.unit_of_measure}
                                </span>
                                {#if hasFinancialAccess}
                                    <span class="font-mono text-emerald-600 dark:text-emerald-400 font-extrabold text-base">
                                        {formatCurrency(mat.unit_cost)}
                                    </span>
                                {/if}
                            </div>

                            <h3 class="text-base font-bold text-slate-900 dark:text-white">{mat.name}</h3>
                            {#if mat.sku}
                                <span class="font-mono text-[11px] text-slate-400 dark:text-slate-500 block mt-0.5">SKU: {mat.sku}</span>
                            {/if}

                            <!-- Métricas de Stock -->
                            <div class="mt-3.5 p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D] grid grid-cols-3 gap-2 text-center text-xs">
                                <div>
                                    <span class="text-[10px] text-slate-500 dark:text-slate-400 block">Actual</span>
                                    <span class="font-mono font-bold text-slate-900 dark:text-slate-200">{current.toFixed(2)}</span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-amber-600 dark:text-amber-400 block">Reservado</span>
                                    <span class="font-mono font-bold text-amber-600 dark:text-amber-300">{reserved.toFixed(2)}</span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-emerald-600 dark:text-emerald-400 block">Disponible</span>
                                    <span class="font-mono font-bold {available <= 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'}">{available.toFixed(2)}</span>
                                </div>
                            </div>

                            <!-- Alerta de Stock Mínimo -->
                            <div class="mt-2.5 flex items-center justify-between text-xs">
                                <span class="text-slate-500 dark:text-slate-400 text-[11px]">Umbral de Alerta:</span>
                                {#if isLowStock}
                                    <span class="px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-500/20 text-rose-700 dark:text-rose-300 border border-rose-300 dark:border-rose-500/30 font-mono text-[10px] font-bold animate-pulse">
                                        ⚠ Stock Bajo ({Number(mat.min_stock_alert)})
                                    </span>
                                {:else}
                                    <span class="font-mono text-slate-500 dark:text-slate-400 text-[11px]">
                                        {Number(mat.min_stock_alert)} {mat.unit_of_measure}
                                    </span>
                                {/if}
                            </div>

                            <!-- Proveedores -->
                            <div class="mt-3 pt-2.5 border-t border-slate-100 dark:border-[#26334D]">
                                <span class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider block mb-1">
                                    Proveedores ({mat.suppliers?.length ?? 0}):
                                </span>
                                <div class="flex flex-wrap gap-1">
                                    {#each mat.suppliers ?? [] as sup}
                                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700/60">
                                            {#if sup.pivot?.is_preferred}★ {/if}{sup.name}
                                        </span>
                                    {:else}
                                        <span class="text-[10px] text-slate-400 italic">Insumo local</span>
                                    {/each}
                                </div>
                            </div>
                        </div>

                        <!-- Acciones (Solo Dueño o Admin) -->
                        {#if isOwnerOrAdmin}
                            <div class="mt-4 pt-3 border-t border-slate-100 dark:border-[#26334D] flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    onclick={() => openAdjust(mat)}
                                    class="px-2.5 py-1 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 dark:bg-emerald-600/20 dark:hover:bg-emerald-600/30 dark:text-emerald-300 dark:border-emerald-500/30 text-xs font-semibold transition-colors"
                                >
                                    ± Stock
                                </button>
                                <button
                                    type="button"
                                    onclick={() => openEdit(mat)}
                                    class="px-3 py-1 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] text-xs font-semibold transition-colors"
                                >
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    onclick={() => destroy(mat)}
                                    class="px-2.5 py-1 rounded-xl bg-slate-100 hover:bg-rose-50 text-slate-500 hover:text-rose-600 dark:bg-slate-800 dark:hover:bg-rose-950 dark:text-slate-400 dark:hover:text-rose-300 border border-slate-200 dark:border-[#26334D] text-xs font-semibold transition-colors"
                                >
                                    Eliminar
                                </button>
                            </div>
                        {/if}
                    </div>
                {:else}
                    <div class="col-span-full p-12 text-center bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-2xl text-slate-400 dark:text-slate-500 text-xs shadow-sm">
                        No hay materias primas registradas. Haz clic en "Nueva Materia Prima" para comenzar.
                    </div>
                {/each}
            </div>
        {/if}

        <!-- PAGINACIÓN -->
        {#if rawMaterials.links && rawMaterials.links.length > 3}
            <div class="p-4 border-t border-slate-200 dark:border-[#26334D] flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                <div>
                    Mostrando {rawMaterials.from ?? 0} a {rawMaterials.to ?? 0} de {rawMaterials.total} insumos
                </div>
                <div class="flex items-center gap-1">
                    {#each rawMaterials.links as link}
                        {#if link.url}
                            <Link
                                href={link.url}
                                class="px-3 py-1.5 rounded-xl border text-xs font-medium transition {link.active ? 'bg-[#4F46E5] text-white border-[#4F46E5] font-bold' : 'bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-300 border-slate-200 dark:border-[#26334D]'}"
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
    <div class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-2xl max-w-2xl w-full p-6 shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <h3 class="text-base font-bold text-slate-900 dark:text-white">
                    {editingMaterial ? `Editar Insumo: ${editingMaterial.name}` : 'Registrar Materia Prima'}
                </h3>
                <button type="button" onclick={() => showMaterialModal = false} class="text-slate-400 hover:text-slate-700 dark:hover:text-white">✕</button>
            </div>

            <form onsubmit={submitMaterial} class="space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="m-name" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Nombre del Material *</label>
                        <input
                            id="m-name"
                            type="text"
                            bind:value={materialForm.name}
                            placeholder="Ej. Tela Drill Algodón"
                            required
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] text-xs"
                        />
                        {#if errors.name}
                            <p class="text-[10px] text-rose-500 mt-1">{errors.name}</p>
                        {/if}
                    </div>
                    <div>
                        <label for="m-sku" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Código SKU / Referencia</label>
                        <input
                            id="m-sku"
                            type="text"
                            bind:value={materialForm.sku}
                            placeholder="Ej. MAT-DRL-01"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] text-xs font-mono"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label for="m-unit" class="block font-semibold text-slate-700 dark:text-slate-300">Unidad de Medida *</label>
                            <button
                                type="button"
                                onclick={() => (quickUomModalOpen = true)}
                                class="text-[10px] text-[#4F46E5] dark:text-[#06B6D4] hover:underline font-bold transition-colors"
                            >
                                + Nueva
                            </button>
                        </div>
                        <select
                            id="m-unit"
                            bind:value={materialForm.unit_of_measure}
                            required
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] text-xs capitalize"
                        >
                            {#each sortedUnitsOfMeasure as uom}
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
                            <label for="m-stock" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Stock Actual *</label>
                            <input
                                id="m-stock"
                                type="number"
                                step="0.001"
                                min="0"
                                bind:value={materialForm.current_stock}
                                required
                                class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] text-xs font-mono"
                            />
                        </div>
                    {/if}

                    <div>
                        <label for="m-alert" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Stock Mínimo (Alerta) *</label>
                        <input
                            id="m-alert"
                            type="number"
                            step="0.001"
                            min="0"
                            bind:value={materialForm.min_stock_alert}
                            required
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] text-xs font-mono"
                        />
                    </div>

                    {#if hasFinancialAccess}
                        <div>
                            <label for="m-cost" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Costo por Unidad ($) *</label>
                            <CurrencyInput bind:value={materialForm.unit_cost} id="m-cost" required />
                        </div>
                    {/if}
                </div>

                <!-- SECCIÓN DE PROVEEDORES OPCIONALES (NOTA 4) -->
                <div class="pt-3 border-t border-slate-200 dark:border-[#26334D] space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-slate-900 dark:text-white text-xs">Proveedores Asociados (Nota 4: 0, 1 o múltiples)</span>
                            <p class="text-[11px] text-slate-500">Puedes dejarlo sin proveedor si el insumo es de elaboración interna.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                onclick={() => quickSupplierModalOpen = true}
                                class="text-[11px] text-[#4F46E5] dark:text-[#06B6D4] hover:underline font-bold"
                            >
                                + Nuevo Proveedor
                            </button>
                            <button
                                type="button"
                                onclick={addSupplierRow}
                                class="px-2.5 py-1 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 font-semibold text-xs border border-slate-200 dark:border-[#26334D] flex items-center gap-1"
                            >
                                <span>+</span>
                                <span>Asociar Proveedor</span>
                            </button>
                        </div>
                    </div>

                    {#if materialForm.suppliers.length === 0}
                        <div class="p-3 text-center bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-dashed border-slate-200 dark:border-[#26334D] text-slate-400 text-[11px]">
                            Ningún proveedor vinculado. El material operará como insumo local.
                        </div>
                    {:else}
                        <div class="space-y-2">
                            <!-- Cabecera de columnas para proveedores -->
                            <div class="hidden sm:flex items-center gap-2 px-2.5 py-1 text-[11px] font-semibold text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-[#26334D]/60">
                                <span class="flex-1">Proveedor *</span>
                                {#if hasFinancialAccess}
                                    <span class="w-32">Precio de Compra ($)</span>
                                {/if}
                                <span class="w-20 text-center">Preferido</span>
                                <span class="w-8"></span>
                            </div>

                            {#each materialForm.suppliers as supRow, idx}
                                <div class="p-2.5 bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D] flex flex-col sm:flex-row sm:items-center gap-2">
                                    <div class="flex-1">
                                        <label for="sup-sel-{idx}" class="sm:hidden block font-semibold text-slate-500 dark:text-slate-400 text-[10px] mb-1">Proveedor *</label>
                                        <select
                                            id="sup-sel-{idx}"
                                            bind:value={supRow.supplier_id}
                                            class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-lg px-2.5 py-1.5 text-slate-900 dark:text-white text-xs"
                                        >
                                            {#each localSuppliers as s}
                                                <option value={s.id}>{s.name}</option>
                                            {/each}
                                        </select>
                                    </div>
                                    {#if hasFinancialAccess}
                                        <div class="w-full sm:w-32">
                                            <label for="sup-price-{idx}" class="sm:hidden block font-semibold text-slate-500 dark:text-slate-400 text-[10px] mb-1">Precio de Compra ($)</label>
                                            <CurrencyInput
                                                id="sup-price-{idx}"
                                                bind:value={supRow.purchase_price}
                                                placeholder="Precio compra"
                                            />
                                        </div>
                                    {/if}
                                    <label class="flex items-center gap-1.5 text-[11px] text-slate-700 dark:text-slate-300 whitespace-nowrap cursor-pointer self-start sm:self-center py-1">
                                        <input
                                            type="checkbox"
                                            bind:checked={supRow.is_preferred}
                                            class="rounded bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-700 text-[#4F46E5] focus:ring-[#4F46E5]"
                                        />
                                        <span>Preferido</span>
                                    </label>
                                    <button
                                        type="button"
                                        onclick={() => removeSupplierRow(idx)}
                                        class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 self-end sm:self-center"
                                        title="Quitar proveedor"
                                    >
                                        ✕
                                    </button>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-[#26334D]">
                    <button
                        type="button"
                        onclick={() => showMaterialModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={materialProcessing}
                        class="px-4 py-2 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-semibold shadow-sm disabled:opacity-50"
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
    <div class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">Ajuste de Stock: {adjustingMaterial.name}</h3>
                    <span class="text-[11px] text-slate-500 dark:text-slate-400">Registra entradas o salidas de inventario</span>
                </div>
                <button type="button" onclick={() => showMovementModal = false} class="text-slate-400 hover:text-slate-700 dark:hover:text-white">✕</button>
            </div>

            <!-- TARJETA CON 3 INDICADORES EN TIEMPO REAL -->
            <div class="grid grid-cols-3 gap-2 p-2.5 bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D] text-center">
                <div class="p-2 rounded-lg bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D]/60 shadow-xs">
                    <span class="block text-[10px] uppercase font-bold text-slate-500 dark:text-slate-400">Stock Actual</span>
                    <span class="text-xs sm:text-sm font-mono font-bold text-slate-800 dark:text-slate-200">
                        {currentStockAdjust}
                    </span>
                    <span class="block text-[9px] text-slate-400 truncate">{adjustingMaterial.unit_of_measure}</span>
                </div>
                <div class="p-2 rounded-lg bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D]/60 shadow-xs">
                    <span class="block text-[10px] uppercase font-bold text-slate-500 dark:text-slate-400">Ajuste</span>
                    <span class="text-xs sm:text-sm font-mono font-bold {isAddingStock ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}">
                        {isAddingStock ? '+' : '-'}{adjustQty}
                    </span>
                    <span class="block text-[9px] text-slate-400 truncate">{adjustingMaterial.unit_of_measure}</span>
                </div>
                <div class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-700/60 shadow-xs">
                    <span class="block text-[10px] uppercase font-bold text-emerald-700 dark:text-emerald-300">Nuevo Stock</span>
                    <span class="text-xs sm:text-sm font-mono font-bold text-emerald-600 dark:text-emerald-400">
                        {resultingStock}
                    </span>
                    <span class="block text-[9px] text-emerald-600/80 dark:text-emerald-400/80 truncate">{adjustingMaterial.unit_of_measure}</span>
                </div>
            </div>

            <form onsubmit={submitMovement} class="space-y-4 text-xs">
                <div>
                    <label for="mov-type" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Tipo de Movimiento *</label>
                    <select
                        id="mov-type"
                        bind:value={movementForm.type}
                        required
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] text-xs"
                    >
                        <option value="purchase">Compra de Insumos (Entrada)</option>
                        <option value="adjustment">Ajuste Manual de Inventario</option>
                        <option value="return">Devolución / Reintegro</option>
                        <option value="consumption">Merma / Pérdida no planeada</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="mov-dir" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Operación *</label>
                        <select
                            id="mov-dir"
                            bind:value={movementForm.direction}
                            required
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] text-xs"
                        >
                            <option value="add">+ Sumar a Stock</option>
                            <option value="subtract">- Restar de Stock</option>
                        </select>
                    </div>
                    <div>
                        <label for="mov-qty" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Cantidad *</label>
                        <input
                            id="mov-qty"
                            type="number"
                            step="0.001"
                            min="0.001"
                            bind:value={movementForm.quantity}
                            required
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] text-xs font-mono font-bold"
                        />
                    </div>
                </div>

                <div>
                    <label for="mov-notes" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Notas / Justificación</label>
                    <input
                        id="mov-notes"
                        type="text"
                        bind:value={movementForm.notes}
                        placeholder="Ej. Factura #402 / Corrección de inventario"
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] text-xs"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-200 dark:border-[#26334D]">
                    <button
                        type="button"
                        onclick={() => showMovementModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={movementProcessing}
                        class="px-4 py-2 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-semibold shadow-sm disabled:opacity-50"
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
    <div class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4 animate-fade-in" role="dialog" aria-modal="true">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-2xl max-w-xl w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-hidden flex flex-col">
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-[#4F46E5]/10 border border-[#4F46E5]/20 flex items-center justify-center text-[#4F46E5] dark:text-[#06B6D4] font-bold text-sm">
                        📐
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold tracking-wider text-[#4F46E5] dark:text-[#06B6D4] block">Catálogo del Taller</span>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">Unidades de Medida</h3>
                    </div>
                </div>
                <button
                    type="button"
                    onclick={() => (showUomModal = false)}
                    class="text-slate-400 hover:text-slate-700 dark:hover:text-white p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                >
                    ✕
                </button>
            </div>

            <!-- Formulario Crear Nueva Unidad -->
            <form onsubmit={handleCreateUom} class="p-3.5 bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl space-y-2">
                <span class="text-xs font-bold text-slate-900 dark:text-slate-200 block">Agregar Nueva Unidad</span>
                {#if uomError}
                    <div class="p-2 bg-rose-50 dark:bg-rose-950/60 border border-rose-200 dark:border-rose-800/80 rounded-lg text-rose-600 dark:text-rose-300 text-xs">
                        {uomError}
                    </div>
                {/if}
                <div class="grid grid-cols-1 sm:grid-cols-5 gap-2 items-end">
                    <div class="sm:col-span-3">
                        <label for="new-uom-name" class="block text-[11px] font-semibold text-slate-700 dark:text-slate-300 mb-1">Nombre (ej. Metro) *</label>
                        <input
                            id="new-uom-name"
                            type="text"
                            bind:value={newUomName}
                            placeholder="Ej. Metro, Litro, Rollo"
                            required
                            class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] font-medium"
                        />
                    </div>
                    <div class="sm:col-span-2 flex gap-2 items-end">
                        <div class="flex-1">
                            <label for="new-uom-abbr" class="block text-[11px] font-semibold text-slate-700 dark:text-slate-300 mb-1">Abreviatura (ej. m)</label>
                            <input
                                id="new-uom-abbr"
                                type="text"
                                bind:value={newUomAbbr}
                                placeholder="Ej. m, kg"
                                class="w-full bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-xl px-2.5 py-2 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] font-mono"
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={uomLoading || !newUomName.trim()}
                            class="px-3.5 py-2 bg-[#4F46E5] hover:bg-[#4338CA] disabled:opacity-50 text-white rounded-xl text-xs font-bold shrink-0 transition flex items-center gap-1 shadow-sm mb-[1px]"
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
                {#if sortedUnitsOfMeasure.length === 0}
                    <div class="p-8 text-center text-slate-400 dark:text-slate-500 text-xs bg-slate-50 dark:bg-[#0B0F17]/40 rounded-xl border border-dashed border-slate-200 dark:border-[#26334D]">
                        No hay unidades de medida registradas.
                    </div>
                {:else}
                    {#each sortedUnitsOfMeasure as uom (uom.id || uom.name)}
                        <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] hover:border-slate-300 dark:hover:border-slate-700 transition-colors text-xs">
                            {#if editingUomId === uom.id}
                                <div class="flex flex-col sm:flex-row items-stretch sm:items-end gap-2 flex-1 mr-2 p-2 bg-white dark:bg-[#151D2C] rounded-xl border border-[#4F46E5]">
                                    <div class="flex-1">
                                        <label for="edit-uom-name-{uom.id}" class="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 mb-0.5">Nombre (ej. Metro) *</label>
                                        <input
                                            id="edit-uom-name-{uom.id}"
                                            type="text"
                                            bind:value={editUomName}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-lg px-2.5 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] font-medium"
                                            placeholder="Nombre"
                                        />
                                    </div>
                                    <div class="w-full sm:w-24">
                                        <label for="edit-uom-abbr-{uom.id}" class="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 mb-0.5">Abreviatura (ej. m)</label>
                                        <input
                                            id="edit-uom-abbr-{uom.id}"
                                            type="text"
                                            bind:value={editUomAbbr}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-lg px-2.5 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] font-mono"
                                            placeholder="Abrev."
                                        />
                                    </div>
                                    <div class="flex items-center gap-1.5 self-end">
                                        <button
                                            type="button"
                                            onclick={() => handleUpdateUom(uom.id)}
                                            disabled={uomLoading}
                                            class="px-3 py-1.5 bg-[#4F46E5] hover:bg-[#4338CA] text-white rounded-lg font-bold text-xs transition-colors"
                                        >
                                            Guardar
                                        </button>
                                        <button
                                            type="button"
                                            onclick={cancelEditUom}
                                            class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] rounded-lg text-xs transition-colors"
                                        >
                                            Cancelar
                                        </button>
                                    </div>
                                </div>
                            {:else}
                                <div class="flex items-center gap-2.5">
                                    <span class="font-semibold text-slate-900 dark:text-slate-200 text-sm">{uom.name}</span>
                                    {#if uom.abbreviation}
                                        <span class="px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-[#4F46E5] dark:text-[#06B6D4] text-[11px] font-mono font-medium">
                                            {uom.abbreviation}
                                        </span>
                                    {/if}
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        onclick={() => startEditUom(uom)}
                                        class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors text-xs flex items-center gap-1"
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
                                        class="p-1.5 rounded-lg hover:bg-rose-50 text-slate-400 hover:text-rose-600 dark:hover:bg-rose-950/60 dark:hover:text-rose-400 transition-colors text-xs flex items-center gap-1"
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
            <div class="border-t border-slate-200 dark:border-[#26334D] pt-3 flex items-center justify-between text-[11px] text-slate-500 dark:text-slate-400">
                <span>{localUnitsOfMeasure.length} unidades disponibles para inventario y recetas</span>
                <button
                    type="button"
                    onclick={() => (showUomModal = false)}
                    class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold rounded-xl text-xs transition-colors"
                >
                    Cerrar
                </button>
            </div>
        </div>
    </div>
{/if}

<!-- MODAL SOLICITAR MATERIA PRIMA AL CEO (SPRINT 9) -->
{#if showRequestModal}
    <div class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4 animate-fade-in" role="dialog" aria-modal="true">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
            <!-- Encabezado -->
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-amber-500/15 border border-amber-500/25 flex items-center justify-center text-amber-600 dark:text-amber-400 font-bold text-base shadow-xs">
                        📦
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold tracking-wider text-amber-600 dark:text-amber-400 block">Abastecimiento Operativo</span>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">Solicitar Materia Prima al CEO</h3>
                    </div>
                </div>
                <button
                    type="button"
                    onclick={() => (showRequestModal = false)}
                    class="text-slate-400 hover:text-slate-700 dark:hover:text-white p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                >
                    ✕
                </button>
            </div>

            <form onsubmit={submitMaterialRequest} class="space-y-4 text-xs">
                <!-- Selector de tipo de insumo -->
                <div>
                    <span class="block font-semibold text-slate-700 dark:text-slate-300 mb-2">Tipo de Solicitud:</span>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            onclick={() => (requestForm.type = 'existing')}
                            class="p-2.5 rounded-xl border text-xs font-semibold flex items-center justify-center gap-2 transition-all {requestForm.type === 'existing' ? 'bg-[#4F46E5] text-white border-[#4F46E5] shadow-xs' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100'}"
                        >
                            <span>📦</span>
                            <span>Insumo Existente en Bodega</span>
                        </button>
                        <button
                            type="button"
                            onclick={() => (requestForm.type = 'new')}
                            class="p-2.5 rounded-xl border text-xs font-semibold flex items-center justify-center gap-2 transition-all {requestForm.type === 'new' ? 'bg-[#4F46E5] text-white border-[#4F46E5] shadow-xs' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100'}"
                        >
                            <span>✨</span>
                            <span>Nueva Materia Prima</span>
                        </button>
                    </div>
                </div>

                {#if requestForm.type === 'existing'}
                    <!-- Selector de Insumo Existente -->
                    <div>
                        <label for="req-mat-select" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Materia Prima en Catálogo *</label>
                        <select
                            id="req-mat-select"
                            value={requestForm.raw_material_id}
                            onchange={onSelectExistingMaterial}
                            required
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white text-xs focus:ring-1 focus:ring-[#4F46E5]"
                        >
                            {#if allMaterialsList.length === 0}
                                <option value="">No hay materias primas registradas en catálogo</option>
                            {:else}
                                {#each allMaterialsList as mat}
                                    <option value={String(mat.id)}>
                                        {mat.name} (Stock: {Number(mat.current_stock).toFixed(2)} {mat.unit_of_measure})
                                    </option>
                                {/each}
                            {/if}
                        </select>
                        {#if errors.raw_material_id}
                            <p class="text-[10px] text-rose-500 mt-1">{errors.raw_material_id}</p>
                        {/if}
                    </div>
                {:else}
                    <!-- Insumo Nuevo -->
                    <div>
                        <label for="req-new-name" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Nombre de la Nueva Materia Prima *</label>
                        <input
                            id="req-new-name"
                            type="text"
                            bind:value={requestForm.new_material_name}
                            placeholder="Ej. Tela Lino Beige 250gr"
                            required
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:ring-1 focus:ring-[#4F46E5]"
                        />
                        {#if errors.new_material_name}
                            <p class="text-[10px] text-rose-500 mt-1">{errors.new_material_name}</p>
                        {/if}
                    </div>

                    <div>
                        <label for="req-new-unit" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Unidad de Medida (opcional)</label>
                        <input
                            id="req-new-unit"
                            type="text"
                            bind:value={requestForm.unit}
                            placeholder="Ej. metros, rollos, kg, unidades"
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:ring-1 focus:ring-[#4F46E5]"
                        />
                    </div>
                {/if}

                <!-- Cantidad Requerida -->
                <div>
                    <label for="req-qty" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                        Cantidad Requerida * {requestForm.unit ? `(${requestForm.unit})` : ''}
                    </label>
                    <input
                        id="req-qty"
                        type="number"
                        step="any"
                        min="0.01"
                        bind:value={requestForm.requested_quantity}
                        placeholder="Ej. 15.5"
                        required
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white font-mono text-sm focus:ring-1 focus:ring-[#4F46E5]"
                    />
                    {#if errors.requested_quantity}
                        <p class="text-[10px] text-rose-500 mt-1">{errors.requested_quantity}</p>
                    {/if}
                </div>

                <!-- Notas / Urgencia para el CEO -->
                <div>
                    <label for="req-notes" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Notas / Motivo / Urgencia para el CEO</label>
                    <textarea
                        id="req-notes"
                        rows="3"
                        bind:value={requestForm.notes}
                        placeholder="Ej. Se necesita para completar los 3 pedidos del viernes. Nos quedamos sin stock para el lote."
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 text-xs focus:ring-1 focus:ring-[#4F46E5]"
                    ></textarea>
                </div>

                <!-- Botones de Acción -->
                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-200 dark:border-[#26334D]">
                    <button
                        type="button"
                        onclick={() => (showRequestModal = false)}
                        class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold text-xs"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={requestSubmitting}
                        class="px-5 py-2 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-semibold text-xs shadow-sm transition disabled:opacity-50 flex items-center gap-1.5"
                    >
                        {#if requestSubmitting}
                            <span>Enviando...</span>
                        {:else}
                            <span>📤</span>
                            <span>Enviar Solicitud al CEO</span>
                        {/if}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
