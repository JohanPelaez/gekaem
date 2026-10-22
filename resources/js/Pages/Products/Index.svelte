<script>
    import { router, Link, page } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';
    import ViewToggle from '../../Components/ViewToggle.svelte';
    import BarcodeViewer from '../../Components/BarcodeViewer.svelte';
    import CurrencyInput from '../../Components/CurrencyInput.svelte';
    import QuickCreateModal from '../../Components/QuickCreateModal.svelte';
    import ProductFormModal from '../../Components/ProductFormModal.svelte';
    import { csrfFetch } from '../../Utils/csrfFetch.js';
    import { canViewPrices } from '../../Utils/permissions.js';
    import {
        BARCODE_FORMATS,
        generateSequentialBarcode,
        validateBarcodeForFormat,
        downloadBarcodeAsPng,
    } from '../../Utils/barcodeHelper.js';

    let {
        products,
        productTypes = [],
        workflows = [],
        rawMaterials = [],
        orthogonalAttributes = [],
        unitsOfMeasure = [],
        filters = { search: '', product_type_id: '', workflow_id: '' },
        errors = {},
    } = $props();

    let authUser = $derived(page.props.auth?.user);
    let hasFinancialAccess = $derived(canViewPrices(authUser));

    let search = $state('');
    let productTypeFilter = $state('');
    let workflowFilter = $state('');

    $effect(() => {
        search = filters.search ?? '';
        productTypeFilter = filters.product_type_id ? String(filters.product_type_id) : '';
        workflowFilter = filters.workflow_id ? String(filters.workflow_id) : '';
    });

    let viewMode = $state('table'); // 'table' | 'cards'
    let activeTab = $state('products'); // 'products' | 'types' | 'attributes'
    let expandedRows = $state(new Set());

    // Listas locales sincronizadas para permitir creación rápida en contexto
    let localProductTypes = $state([]);
    let localWorkflows = $state([]);
    let localRawMaterials = $state([]);
    let localAttributes = $state([]);

    $effect(() => {
        localProductTypes = [...productTypes];
    });
    $effect(() => {
        localWorkflows = [...workflows];
    });
    $effect(() => {
        localRawMaterials = [...rawMaterials];
    });
    $effect(() => {
        localAttributes = Array.isArray(orthogonalAttributes) ? [...orthogonalAttributes] : [];
    });

    // Listas ordenadas alfabéticamente (A-Z) mediante $derived
    let sortedRawMaterials = $derived(
        localRawMaterials.toSorted((a, b) => (a.name || '').localeCompare(b.name || '', 'es'))
    );
    let sortedUnitsOfMeasure = $derived(
        unitsOfMeasure.toSorted((a, b) => {
            const nameA = typeof a === 'object' && a !== null ? (a.name || '') : String(a || '');
            const nameB = typeof b === 'object' && b !== null ? (b.name || '') : String(b || '');
            return nameA.localeCompare(nameB, 'es');
        })
    );
    let sortedWorkflows = $derived(
        localWorkflows.toSorted((a, b) => (a.name || '').localeCompare(b.name || '', 'es'))
    );

    // Estado para agregar opciones inline a atributos
    let activeAddOptionAttrId = $state(null);
    let newOptionValue = $state('');
    let optionInlineLoading = $state(false);

    async function handleSaveOptionInline(attr) {
        if (!newOptionValue.trim()) return;
        optionInlineLoading = true;
        try {
            const res = await csrfFetch(`/quick/attributes/${attr.id}/options`, {
                method: 'POST',
                body: JSON.stringify({ value: newOptionValue.trim() }),
            });
            const created = await res.json().catch(() => ({}));
            if (res.ok && created.id) {
                localAttributes = localAttributes.map((a) => {
                    if (a.id === attr.id) {
                        return {
                            ...a,
                            options: [...(a.options || []), created],
                        };
                    }
                    return a;
                });
                newOptionValue = '';
                activeAddOptionAttrId = null;
            }
        } catch (e) {
            console.error('Error creating inline option:', e);
        } finally {
            optionInlineLoading = false;
        }
    }

    // Modales secundarios de Quick Create
    let quickModalOpen = $state(false);
    let quickModalType = $state('product-type');
    let quickTargetBOMIndex = $state(null);

    function openQuickCreate(type) {
        quickModalType = type;
        quickModalOpen = true;
    }

    function handleQuickSuccess(created) {
        if (quickModalType === 'product-type') {
            localProductTypes = [...localProductTypes, created];
        } else if (quickModalType === 'workflow') {
            localWorkflows = [...localWorkflows, created];
        } else if (quickModalType === 'raw-material') {
            localRawMaterials = [...localRawMaterials, created];
        } else if (quickModalType === 'attribute') {
            localAttributes = [...localAttributes, created];
        }
        quickModalOpen = false;
        router.reload({ only: ['products', 'productTypes', 'workflows', 'rawMaterials', 'orthogonalAttributes'] });
    }

    let showProductModal = $state(false);
    let showTypeModal = $state(false);
    let showAttributeModal = $state(false);
    let showOptionModal = $state(false);
    let selectedAttributeForOption = $state(null);

    let editingProduct = $state(null);

    let typeProcessing = $state(false);
    let attributeProcessing = $state(false);
    let optionProcessing = $state(false);

    // Formulario de Tipo de Producto en Svelte 5 Rune
    let typeForm = $state({
        name: '',
    });

    function resetTypeForm() {
        typeForm.name = '';
    }

    // Formulario de Atributo Ortogonal en Svelte 5 Rune
    let attributeForm = $state({
        name: '',
    });

    function resetAttributeForm() {
        attributeForm.name = '';
    }

    // Formulario de Opción de Atributo en Svelte 5 Rune
    let optionForm = $state({
        value: '',
        sku_suffix: '',
    });

    function resetOptionForm() {
        optionForm.value = '';
        optionForm.sku_suffix = '';
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
            '/products',
            {
                search,
                product_type_id: productTypeFilter,
                workflow_id: workflowFilter,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function clearFilters() {
        search = '';
        productTypeFilter = '';
        workflowFilter = '';
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

    function openCreateProduct() {
        editingProduct = null;
        showProductModal = true;
    }

    function openEditProduct(prod) {
        editingProduct = prod;
        showProductModal = true;
    }

    function submitType(e) {
        e.preventDefault();
        router.post('/product-types', typeForm, {
            preserveScroll: true,
            onStart: () => { typeProcessing = true; },
            onFinish: () => { typeProcessing = false; },
            onSuccess: () => {
                showTypeModal = false;
                resetTypeForm();
            },
        });
    }

    function submitAttribute(e) {
        e.preventDefault();
        router.post('/orthogonal-attributes', attributeForm, {
            preserveScroll: true,
            onStart: () => { attributeProcessing = true; },
            onFinish: () => { attributeProcessing = false; },
            onSuccess: () => {
                showAttributeModal = false;
                resetAttributeForm();
            },
        });
    }

    function openAddOption(attr) {
        selectedAttributeForOption = attr;
        resetOptionForm();
        showOptionModal = true;
    }

    function submitOption(e) {
        e.preventDefault();
        if (!selectedAttributeForOption) return;
        router.post(`/orthogonal-attributes/${selectedAttributeForOption.id}/options`, optionForm, {
            preserveScroll: true,
            onStart: () => { optionProcessing = true; },
            onFinish: () => { optionProcessing = false; },
            onSuccess: () => {
                showOptionModal = false;
                resetOptionForm();
            },
        });
    }

    function destroyProduct(prod) {
        if (confirm(`¿Estás seguro de eliminar el producto "${prod.name}"?`)) {
            router.delete(`/products/${prod.id}`, {
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

<AppLayout title="Catálogo de Productos">
    <div class="space-y-6">
        <!-- HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Módulo 2
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Catálogo de Productos</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Productos maestros, recetas de materiales (BOM) y variantes por atributos (talla, color, etc.).
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <ViewToggle bind:mode={viewMode} storageKey="gekaem_products_view" />
                <button
                    type="button"
                    onclick={() => showTypeModal = true}
                    class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs border border-slate-700 transition-colors"
                >
                    + Tipo
                </button>
                <button
                    type="button"
                    onclick={() => showAttributeModal = true}
                    class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs border border-slate-700 transition-colors"
                >
                    + Nuevo Atributo
                </button>
                <button
                    type="button"
                    onclick={openCreateProduct}
                    class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs shadow-lg shadow-emerald-600/20 flex items-center gap-1.5 transition-all"
                >
                    <span>+</span>
                    <span>Nuevo Producto</span>
                </button>
            </div>
        </div>

        <!-- PESTAÑAS DE VISTA -->
        <div class="flex items-center gap-2 border-b border-slate-800 pb-2 text-xs font-semibold">
            <button
                type="button"
                onclick={() => activeTab = 'products'}
                class="px-4 py-2 rounded-xl transition-all {activeTab === 'products' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-400 hover:text-white'}"
            >
                Productos y Variantes ({products.total ?? 0})
            </button>
            <button
                type="button"
                onclick={() => activeTab = 'attributes'}
                class="px-4 py-2 rounded-xl transition-all {activeTab === 'attributes' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-400 hover:text-white'}"
            >
                Atributos y Opciones ({localAttributes.length})
            </button>
            <button
                type="button"
                onclick={() => activeTab = 'types'}
                class="px-4 py-2 rounded-xl transition-all {activeTab === 'types' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-400 hover:text-white'}"
            >
                Tipos de Producto ({productTypes.length})
            </button>
        </div>

        <!-- PESTAÑA 1: CATÁLOGO DE PRODUCTOS -->
        {#if activeTab === 'products'}
            <!-- BUSCADOR Y FILTROS COMBINADOS -->
            <div class="bg-slate-900 border border-slate-800 p-3.5 rounded-2xl flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
                <div class="flex-1 bg-slate-950 border border-slate-800 px-3 py-2 rounded-xl flex items-center gap-2">
                    <span class="text-slate-500 text-xs">🔍</span>
                    <label for="search-products" class="sr-only">Buscar producto</label>
                    <input
                        id="search-products"
                        type="text"
                        bind:value={search}
                        oninput={handleSearchInput}
                        placeholder="Buscar producto por nombre o código de barras..."
                        class="w-full bg-transparent text-white placeholder-slate-500 text-xs focus:outline-none"
                    />
                    {#if search}
                        <button type="button" onclick={() => { search = ''; applyFilters(); }} class="text-slate-500 text-xs hover:text-white">✕</button>
                    {/if}
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <label for="filter-product-type" class="sr-only">Filtrar por tipo de producto</label>
                    <select
                        id="filter-product-type"
                        bind:value={productTypeFilter}
                        onchange={applyFilters}
                        class="bg-slate-950 border border-slate-800 text-slate-300 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-emerald-500"
                    >
                        <option value="">Todos los Tipos</option>
                        {#each localProductTypes as pt}
                            <option value={String(pt.id)}>{pt.name}</option>
                        {/each}
                    </select>

                    <label for="filter-workflow" class="sr-only">Filtrar por flujo</label>
                    <select
                        id="filter-workflow"
                        bind:value={workflowFilter}
                        onchange={applyFilters}
                        class="bg-slate-950 border border-slate-800 text-slate-300 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-emerald-500"
                    >
                        <option value="">Todos los Flujos</option>
                        {#each localWorkflows as wf}
                            <option value={String(wf.id)}>{wf.name}</option>
                        {/each}
                    </select>

                    {#if search || productTypeFilter || workflowFilter}
                        <button
                            type="button"
                            onclick={clearFilters}
                            class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white text-xs transition-colors font-medium"
                            title="Limpiar filtros"
                        >
                            Limpiar
                        </button>
                    {/if}
                </div>
            </div>

            <!-- VISTA TABLA EXPANDIBLE (DEFAULT) -->
            {#if viewMode === 'table'}
                <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-lg">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-950/90 text-slate-400 uppercase font-bold text-[10px] tracking-wider border-b border-slate-800">
                                <tr>
                                    <th class="p-3.5 w-10 text-center"></th>
                                    <th class="p-3.5">Código / Barcode</th>
                                    <th class="p-3.5">Producto</th>
                                    <th class="p-3.5">Tipo</th>
                                    <th class="p-3.5">Flujo Kanban</th>
                                    <th class="p-3.5">Tiempo Fabr.</th>
                                    {#if hasFinancialAccess}
                                        <th class="p-3.5 text-right">Precio Base</th>
                                    {/if}
                                    <th class="p-3.5 text-center">Variantes</th>
                                    <th class="p-3.5 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60 text-slate-300">
                                {#each products.data as prod (prod.id)}
                                    {@const isExpanded = expandedRows.has(prod.id)}
                                    <tr class="hover:bg-slate-800/40 transition-colors {isExpanded ? 'bg-slate-800/30' : ''}">
                                        <!-- Botón de expandir -->
                                        <td class="p-3.5 text-center">
                                            <button
                                                type="button"
                                                onclick={() => toggleRow(prod.id)}
                                                class="w-6 h-6 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center transition-transform {isExpanded ? 'rotate-90 text-emerald-400' : ''}"
                                                title={isExpanded ? 'Colapsar detalles' : 'Expandir BOM y variantes'}
                                            >
                                                ▶
                                            </button>
                                        </td>
                                        <!-- Barcode / SKU -->
                                        <td class="p-3.5">
                                            {#if prod.barcode}
                                                <div class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-slate-950 border border-slate-800 font-mono text-[11px] text-sky-400 font-bold">
                                                    <span>{prod.barcode}</span>
                                                    <button
                                                        type="button"
                                                        onclick={() => downloadBarcodeAsPng(prod.barcode, prod.barcode_format || 'CODE128', prod.name)}
                                                        class="p-0.5 text-slate-500 hover:text-emerald-400 transition-colors"
                                                        title="Descargar código de barras como imagen PNG"
                                                    >
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                                        </svg>
                                                    </button>
                                                </div>
                                                <div class="text-[9px] text-slate-500 font-mono mt-0.5">
                                                    {prod.barcode_format || 'CODE128'}
                                                </div>
                                            {:else}
                                                <span class="text-slate-600 font-mono text-[10px]">Sin código</span>
                                            {/if}
                                        </td>
                                        <!-- Producto -->
                                        <td class="p-3.5">
                                            <button
                                                type="button"
                                                class="text-left font-bold text-white text-sm hover:text-emerald-400 block transition-colors"
                                                onclick={() => toggleRow(prod.id)}
                                            >
                                                {prod.name}
                                            </button>
                                            <span class="text-[10px] text-slate-500 font-medium">Holgura: +{prod.buffer_percentage}%</span>
                                        </td>
                                        <!-- Tipo -->
                                        <td class="p-3.5">
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-slate-800 text-slate-300 border border-slate-700/80">
                                                {prod.product_type?.name ?? 'Sin tipo'}
                                            </span>
                                        </td>
                                        <!-- Flujo -->
                                        <td class="p-3.5 font-medium text-slate-200">
                                            {prod.workflow?.name ?? 'No asignado'}
                                        </td>
                                        <!-- Tiempo -->
                                        <td class="p-3.5 font-mono text-amber-300 font-semibold">
                                            ⏱ {prod.formatted_time}
                                        </td>
                                        <!-- Precio Base -->
                                        {#if hasFinancialAccess}
                                            <td class="p-3.5 text-right font-mono font-bold text-emerald-400 text-sm">
                                                {formatCurrency(prod.base_price)}
                                            </td>
                                        {/if}
                                        <!-- Variantes -->
                                        <td class="p-3.5 text-center">
                                            <span class="px-2 py-0.5 rounded-full font-mono font-bold text-[11px] {prod.variants?.length > 1 ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-400'}">
                                                {prod.variants?.length ?? 0}
                                            </span>
                                        </td>
                                        <!-- Acciones -->
                                        <td class="p-3.5 text-right">
                                            <div class="flex items-center justify-end gap-1.5">
                                                <button
                                                    type="button"
                                                    onclick={() => openEditProduct(prod)}
                                                    class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition-colors"
                                                >
                                                    Editar
                                                </button>
                                                <button
                                                    type="button"
                                                    onclick={() => destroyProduct(prod)}
                                                    class="px-2 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 text-slate-500 hover:text-rose-300 text-xs font-semibold transition-colors"
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- RENGLÓN EXPANDIDO (ACCORDION) -->
                                    {#if isExpanded}
                                        <tr class="bg-slate-950/60 border-b border-slate-800/90">
                                            <td colspan="9" class="p-4 sm:p-6 space-y-4">
                                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                                                    <!-- Columna 1: Código de Barras SVG -->
                                                    <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl flex flex-col items-center justify-center space-y-2">
                                                        <div class="flex items-center justify-between w-full px-1 border-b border-slate-800 pb-1.5">
                                                            <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Código de Barras</span>
                                                            <span class="text-[10px] font-mono text-sky-400 font-bold bg-sky-950/40 px-1.5 py-0.5 rounded border border-sky-800/40">
                                                                {prod.barcode_format || 'CODE128'}
                                                            </span>
                                                        </div>
                                                        {#if prod.barcode}
                                                            <BarcodeViewer
                                                                value={prod.barcode}
                                                                format={prod.barcode_format || 'CODE128'}
                                                                productName={prod.name}
                                                                showDownload={true}
                                                                height={42}
                                                                width={1.8}
                                                            />
                                                        {:else}
                                                            <div class="text-center py-4 text-slate-500 text-xs italic">
                                                                No se ha asignado código de barras.
                                                            </div>
                                                        {/if}
                                                    </div>

                                                    <!-- Columna 2: Receta BOM -->
                                                    <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl space-y-2">
                                                        <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                                            <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                                                Receta BOM ({prod.material_requirements?.length ?? 0} Insumos)
                                                            </span>
                                                        </div>
                                                        <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                                            {#each prod.material_requirements ?? [] as req}
                                                                <div class="flex items-center justify-between p-2 rounded-lg bg-slate-950/80 border border-slate-800/80 text-xs">
                                                                    <div class="font-medium text-slate-200">
                                                                        {req.raw_material?.name}
                                                                    </div>
                                                                    <div class="text-right font-mono text-slate-400 text-[11px]">
                                                                        <span class="text-slate-200 font-bold">{Number(req.quantity_required)}</span> {req.raw_material?.unit_of_measure}
                                                                        {#if Number(req.waste_percent) > 0}
                                                                            <span class="text-amber-400 text-[10px]">(+{Number(req.waste_percent)}%)</span>
                                                                        {/if}
                                                                    </div>
                                                                </div>
                                                            {:else}
                                                                <div class="text-slate-500 text-xs italic py-2">
                                                                    Sin insumos configurados.
                                                                </div>
                                                            {/each}
                                                        </div>
                                                    </div>

                                                    <!-- Columna 3: Variantes Ortogonales -->
                                                    <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl space-y-2">
                                                        <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                                            <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                                                Variantes Activas ({prod.variants?.length ?? 0})
                                                            </span>
                                                        </div>
                                                        <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                                            {#each prod.variants ?? [] as variant}
                                                                <div class="flex items-center justify-between p-2 rounded-lg bg-slate-950/80 border border-slate-800/80 text-xs">
                                                                    <div>
                                                                        <span class="font-bold text-slate-200 block">{variant.name_label}</span>
                                                                        {#if variant.sku}
                                                                            <span class="font-mono text-[10px] text-slate-500">SKU: {variant.sku}</span>
                                                                        {/if}
                                                                    </div>
                                                                    <div class="text-right">
                                                                        {#if hasFinancialAccess}
                                                                            {#if variant.price_override}
                                                                                <span class="font-mono font-bold text-emerald-400 block">{formatCurrency(variant.price_override)}</span>
                                                                            {:else}
                                                                                <span class="font-mono text-slate-400 block">{formatCurrency(prod.base_price)}</span>
                                                                            {/if}
                                                                        {/if}
                                                                        {#if variant.production_minutes_delta}
                                                                            <span class="font-mono text-[10px] text-amber-300">
                                                                                {variant.production_minutes_delta > 0 ? '+' : ''}{variant.production_minutes_delta}m
                                                                            </span>
                                                                        {/if}
                                                                    </div>
                                                                </div>
                                                            {:else}
                                                                <div class="text-slate-500 text-xs italic py-2">
                                                                    Variante única estándar.
                                                                </div>
                                                            {/each}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    {/if}
                                {:else}
                                    <tr>
                                        <td colspan="9" class="p-10 text-center text-slate-500 text-xs">
                                            No hay productos registrados en el catálogo.
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
                    {#each products.data as prod (prod.id)}
                        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col justify-between shadow-md group hover:border-slate-700 transition-colors">
                            <div>
                                <!-- Cabecera del Producto -->
                                <div class="flex items-start justify-between gap-2 mb-2">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-slate-800 text-slate-300 border border-slate-700">
                                        {prod.product_type?.name ?? 'Sin tipo'}
                                    </span>
                                    {#if hasFinancialAccess}
                                        <span class="font-mono text-emerald-400 font-extrabold text-base">
                                            {formatCurrency(prod.base_price)}
                                        </span>
                                    {/if}
                                </div>

                                <h3 class="text-base font-bold text-white group-hover:text-emerald-300 transition-colors">{prod.name}</h3>

                                {#if prod.barcode}
                                    <div class="mt-2 flex flex-col items-center">
                                        <div class="flex items-center justify-between w-full mb-1">
                                            <span class="text-[9px] font-mono text-slate-400 font-semibold uppercase">{prod.barcode_format || 'CODE128'}</span>
                                            <button
                                                type="button"
                                                onclick={() => downloadBarcodeAsPng(prod.barcode, prod.barcode_format || 'CODE128', prod.name)}
                                                class="text-[10px] text-slate-400 hover:text-emerald-400 flex items-center gap-1 font-semibold transition-colors"
                                                title="Descargar imagen PNG"
                                            >
                                                <svg class="w-3 h-3 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                                </svg>
                                                <span>PNG</span>
                                            </button>
                                        </div>
                                        <BarcodeViewer
                                            value={prod.barcode}
                                            format={prod.barcode_format || 'CODE128'}
                                            productName={prod.name}
                                            showDownload={false}
                                            height={32}
                                            width={1.4}
                                        />
                                    </div>
                                {/if}

                                <!-- Métricas de Producción -->
                                <div class="mt-3.5 p-3 bg-slate-950/70 rounded-xl border border-slate-800/80 space-y-1.5 text-xs">
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-400">Flujo Kanban:</span>
                                        <span class="font-semibold text-slate-200">{prod.workflow?.name ?? 'No asignado'}</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-400">Tiempo de Fabricación:</span>
                                        <span class="font-mono text-amber-300 font-bold">⏱ {prod.formatted_time}</span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-slate-400">Tiempo de Holgura:</span>
                                        <span class="font-mono text-slate-300 font-semibold">+{prod.buffer_percentage}%</span>
                                    </div>
                                </div>

                                <!-- Variantes Generadas -->
                                <div class="mt-3">
                                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">
                                        Variantes ({prod.variants?.length ?? 0}):
                                    </span>
                                    <div class="flex flex-wrap gap-1 max-h-24 overflow-y-auto">
                                        {#each prod.variants ?? [] as variant}
                                            <span class="text-[10px] px-2 py-0.5 rounded bg-slate-800 text-slate-300 border border-slate-700 font-mono">
                                                {variant.name_label}
                                                {#if hasFinancialAccess && variant.price_override}
                                                    <strong class="text-emerald-400 font-sans">({formatCurrency(variant.price_override)})</strong>
                                                {/if}
                                            </span>
                                        {/each}
                                    </div>
                                </div>

                                <!-- Receta BOM -->
                                <div class="mt-3 pt-2.5 border-t border-slate-800/60">
                                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">
                                        Receta BOM ({prod.material_requirements?.length ?? 0} insumos):
                                    </span>
                                    <div class="space-y-1">
                                        {#each prod.material_requirements ?? [] as req}
                                            <div class="flex items-center justify-between text-[11px] text-slate-300">
                                                <span class="truncate max-w-[170px]">• {req.raw_material?.name}</span>
                                                <span class="font-mono text-slate-400">
                                                    {Number(req.quantity_required)} {req.raw_material?.unit_of_measure} (+{Number(req.waste_percent)}%)
                                                </span>
                                            </div>
                                        {:else}
                                            <span class="text-[10px] text-slate-500 italic">Sin materiales requeridos</span>
                                        {/each}
                                    </div>
                                </div>
                            </div>

                            <!-- Acciones -->
                            <div class="mt-4 pt-3 border-t border-slate-800 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    onclick={() => openEditProduct(prod)}
                                    class="px-3 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold"
                                >
                                    Editar
                                </button>
                                <button
                                    type="button"
                                    onclick={() => destroyProduct(prod)}
                                    class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-rose-950 text-slate-400 hover:text-rose-300 text-xs font-semibold"
                                >
                                    Eliminar
                                </button>
                            </div>
                        </div>
                    {:else}
                        <div class="col-span-full p-12 text-center bg-slate-900 border border-slate-800 rounded-2xl text-slate-500 text-xs">
                            No hay productos registrados. Haz clic en "Nuevo Producto" para comenzar.
                        </div>
                    {/each}
                </div>
            {/if}

            <!-- PAGINACIÓN -->
            {#if products.links && products.links.length > 3}
                <div class="p-4 bg-slate-900 border border-slate-800 rounded-xl flex items-center justify-between text-xs text-slate-400">
                    <div>Mostrando {products.from ?? 0} a {products.to ?? 0} de {products.total} productos</div>
                    <div class="flex items-center gap-1">
                        {#each products.links as link}
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

        <!-- PESTAÑA 2: ATRIBUTOS Y OPCIONES -->
        {:else if activeTab === 'attributes'}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {#each localAttributes as attr (attr.id)}
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 shadow-sm space-y-3">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-2.5">
                            <h3 class="font-bold text-white text-base">{attr.name}</h3>
                            <button
                                type="button"
                                onclick={() => openAddOption(attr)}
                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs border border-slate-700"
                            >
                                + Opción
                            </button>
                        </div>
                        <div class="space-y-1.5">
                            {#each attr.options ?? [] as opt}
                                <div class="flex items-center justify-between p-2 rounded-lg bg-slate-950/70 border border-slate-800 text-xs">
                                    <span class="font-medium text-slate-200">{opt.value}</span>
                                    <span class="font-mono text-slate-500 text-[10px]">SKU: {opt.sku_suffix ?? 'N/A'}</span>
                                </div>
                            {:else}
                                <span class="text-xs text-slate-500 italic">Sin opciones creadas aún.</span>
                            {/each}
                        </div>
                    </div>
                {/each}
            </div>

        <!-- PESTAÑA 3: TIPOS DE PRODUCTO -->
        {:else if activeTab === 'types'}
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                {#each localProductTypes as pt (pt.id)}
                    <div class="p-4 bg-slate-900 border border-slate-800 rounded-xl flex items-center justify-between">
                        <span class="font-bold text-white text-sm">{pt.name}</span>
                        <span class="text-[10px] uppercase font-mono px-2 py-0.5 rounded bg-slate-800 text-slate-400">ID #{pt.id}</span>
                    </div>
                {/each}
            </div>
        {/if}
    </div>
</AppLayout>

<!-- MODAL COMPLETO DE PRODUCTO REUTILIZABLE (Nota 7, 8, 9) -->
<ProductFormModal
    bind:isOpen={showProductModal}
    product={editingProduct}
    asyncMode={false}
    {productTypes}
    {workflows}
    {rawMaterials}
    {orthogonalAttributes}
    {unitsOfMeasure}
/>

<!-- MODAL CREAR TIPO DE PRODUCTO -->
{#if showTypeModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-sm w-full p-6 shadow-2xl space-y-4">
            <h3 class="text-base font-bold text-white">Nuevo Tipo de Producto</h3>
            <form onsubmit={submitType} class="space-y-4 text-xs">
                <div>
                    <label for="pt-name" class="block font-semibold text-slate-300 mb-1">Nombre del Tipo *</label>
                    <input
                        id="pt-name"
                        type="text"
                        bind:value={typeForm.name}
                        placeholder="Ej. Dotaciones Industriales"
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                    {/if}
                </div>
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" onclick={() => showTypeModal = false} class="px-3 py-1.5 rounded-xl bg-slate-800 text-slate-300">Cancelar</button>
                    <button type="submit" disabled={typeProcessing} class="px-4 py-1.5 rounded-xl bg-emerald-600 text-white font-semibold disabled:opacity-50">Guardar</button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL CREAR ATRIBUTO -->
{#if showAttributeModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-sm w-full p-6 shadow-2xl space-y-4">
            <h3 class="text-base font-bold text-white">Nuevo Atributo</h3>
            <form onsubmit={submitAttribute} class="space-y-4 text-xs">
                <div>
                    <label for="oa-name" class="block font-semibold text-slate-300 mb-1">Nombre del Atributo *</label>
                    <input
                        id="oa-name"
                        type="text"
                        bind:value={attributeForm.name}
                        placeholder="Ej. Talla, Color, Material..."
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.name}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                    {/if}
                </div>
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" onclick={() => showAttributeModal = false} class="px-3 py-1.5 rounded-xl bg-slate-800 text-slate-300">Cancelar</button>
                    <button type="submit" disabled={attributeProcessing} class="px-4 py-1.5 rounded-xl bg-emerald-600 text-white font-semibold disabled:opacity-50">Guardar Atributo</button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL CREAR OPCIÓN EN ATRIBUTO -->
{#if showOptionModal && selectedAttributeForOption}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-sm w-full p-6 shadow-2xl space-y-4">
            <h3 class="text-base font-bold text-white">Nueva Opción para "{selectedAttributeForOption.name}"</h3>
            <form onsubmit={submitOption} class="space-y-4 text-xs">
                <div>
                    <label for="opt-val" class="block font-semibold text-slate-300 mb-1">Valor de la Opción *</label>
                    <input
                        id="opt-val"
                        type="text"
                        bind:value={optionForm.value}
                        placeholder="Ej. Azul Rey, XL, Algodón..."
                        required
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                    />
                    {#if errors.value}
                        <p class="text-[10px] text-rose-400 mt-1">{errors.value}</p>
                    {/if}
                </div>
                <div>
                    <label for="opt-sku" class="block font-semibold text-slate-300 mb-1">Sufijo para SKU (Opcional)</label>
                    <input
                        id="opt-sku"
                        type="text"
                        bind:value={optionForm.sku_suffix}
                        placeholder="Ej. AZUL, XL"
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono uppercase"
                    />
                </div>
                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                    <button type="button" onclick={() => showOptionModal = false} class="px-3 py-1.5 rounded-xl bg-slate-800 text-slate-300">Cancelar</button>
                    <button type="submit" disabled={optionProcessing} class="px-4 py-1.5 rounded-xl bg-emerald-600 text-white font-semibold disabled:opacity-50">Guardar Opción</button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL FLOTANTE DE CREACIÓN RÁPIDA (QUICK CREATE) -->
<QuickCreateModal
    bind:isOpen={quickModalOpen}
    type={quickModalType}
    unitsOfMeasure={sortedUnitsOfMeasure}
    onSuccess={handleQuickSuccess}
/>
