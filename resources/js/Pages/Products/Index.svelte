<script>
    import { router, Link } from '@inertiajs/svelte';
    import AppLayout from '../../Layouts/AppLayout.svelte';

    let {
        products,
        productTypes = [],
        workflows = [],
        rawMaterials = [],
        orthogonalAttributes = [],
        filters = { search: '' },
        errors = {},
    } = $props();

    let search = $state('');
    $effect(() => {
        search = filters.search ?? '';
    });
    let activeTab = $state('products'); // 'products' | 'types' | 'attributes'

    let showProductModal = $state(false);
    let showTypeModal = $state(false);
    let showAttributeModal = $state(false);
    let showOptionModal = $state(false);
    let selectedAttributeForOption = $state(null);

    let editingProduct = $state(null);

    let productProcessing = $state(false);
    let typeProcessing = $state(false);
    let attributeProcessing = $state(false);
    let optionProcessing = $state(false);

    // Formulario principal de Producto en Svelte 5 Rune
    let productForm = $state({
        name: '',
        product_type_id: '',
        workflow_id: '',
        barcode: '',
        time_value: 45,
        production_time_unit: 'minutes',
        buffer_percentage: 20,
        base_price: 0,
        is_active: true,
        materials: [],
        grouped_options: {},
        variants: [],
    });

    function resetProductForm() {
        productForm.name = '';
        productForm.product_type_id = productTypes[0]?.id ?? '';
        productForm.workflow_id = workflows[0]?.id ?? '';
        productForm.barcode = '';
        productForm.time_value = 45;
        productForm.production_time_unit = 'minutes';
        productForm.buffer_percentage = 20;
        productForm.base_price = 0;
        productForm.is_active = true;
        productForm.materials = [];
        productForm.grouped_options = {};
        productForm.variants = [];
    }

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
            router.get(
                '/products',
                { search },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
    }

    function openCreateProduct() {
        editingProduct = null;
        resetProductForm();
        productForm.workflow_id = workflows[0]?.id ?? '';
        productForm.product_type_id = productTypes[0]?.id ?? '';
        productForm.production_time_unit = 'minutes';
        productForm.time_value = 45;
        productForm.buffer_percentage = 20;
        productForm.is_active = true;
        productForm.materials = [];
        productForm.grouped_options = {};
        productForm.variants = [];
        showProductModal = true;
    }

    function openEditProduct(prod) {
        editingProduct = prod;
        productForm.name = prod.name ?? '';
        productForm.product_type_id = prod.product_type_id ?? '';
        productForm.workflow_id = prod.workflow_id ?? '';
        productForm.barcode = prod.barcode ?? '';
        productForm.production_time_unit = prod.production_time_unit ?? 'minutes';

        // Reconvertir minutos base para el input de formulario
        const unit = prod.production_time_unit ?? 'minutes';
        const mins = prod.base_production_minutes ?? 0;
        if (unit === 'days') {
            productForm.time_value = Number((mins / 480).toFixed(2));
        } else if (unit === 'hours') {
            productForm.time_value = Number((mins / 60).toFixed(2));
        } else {
            productForm.time_value = mins;
        }

        productForm.buffer_percentage = prod.buffer_percentage ?? 20;
        productForm.base_price = Number(prod.base_price ?? 0);
        productForm.is_active = Boolean(prod.is_active);

        // Cargar receta BOM existente
        productForm.materials = (prod.material_requirements ?? []).map((m) => ({
            raw_material_id: m.raw_material_id,
            quantity_required: Number(m.quantity_required),
            waste_percent: Number(m.waste_percent ?? 0),
        }));

        // Cargar variantes para ajustar deltas / precios
        productForm.variants = (prod.variants ?? []).map((v) => ({
            id: v.id,
            name_label: v.name_label,
            sku: v.sku ?? '',
            barcode: v.barcode ?? '',
            production_minutes_delta: v.production_minutes_delta ?? 0,
            price_override: v.price_override ?? '',
        }));

        showProductModal = true;
    }

    function addMaterialRow() {
        if (rawMaterials.length === 0) return;
        productForm.materials = [
            ...productForm.materials,
            {
                raw_material_id: rawMaterials[0].id,
                quantity_required: 1,
                waste_percent: 0,
            },
        ];
    }

    function removeMaterialRow(index) {
        productForm.materials = productForm.materials.filter((_, i) => i !== index);
    }

    function toggleOptionSelection(attrId, optionId) {
        if (!productForm.grouped_options[attrId]) {
            productForm.grouped_options[attrId] = [];
        }

        const idx = productForm.grouped_options[attrId].indexOf(optionId);
        if (idx === -1) {
            productForm.grouped_options[attrId] = [
                ...productForm.grouped_options[attrId],
                optionId,
            ];
        } else {
            productForm.grouped_options[attrId] = productForm.grouped_options[
                attrId
            ].filter((id) => id !== optionId);
        }
    }

    function submitProduct(e) {
        e.preventDefault();
        if (editingProduct) {
            router.patch(`/products/${editingProduct.id}`, productForm, {
                preserveScroll: true,
                onStart: () => { productProcessing = true; },
                onFinish: () => { productProcessing = false; },
                onSuccess: () => {
                    showProductModal = false;
                    resetProductForm();
                },
            });
        } else {
            router.post('/products', productForm, {
                preserveScroll: true,
                onStart: () => { productProcessing = true; },
                onFinish: () => { productProcessing = false; },
                onSuccess: () => {
                    showProductModal = false;
                    resetProductForm();
                },
            });
        }
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
        if (confirm(`¿Eliminar el producto "${prod.name}" y sus variantes?`)) {
            router.delete(`/products/${prod.id}`, { preserveScroll: true });
        }
    }
</script>

<AppLayout title="Productos y Variantes">
    <div class="space-y-6">
        <!-- ENCABEZADO -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-900 border border-slate-800 p-6 rounded-2xl">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold tracking-wider uppercase bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                        Módulo 2
                    </span>
                    <h1 class="text-2xl font-bold tracking-tight text-white">Catálogo de Productos y Recetas BOM</h1>
                </div>
                <p class="text-xs text-slate-400 mt-1">
                    Gestión de productos con tiempos de producción en minutos/horas/días, recetas de materiales (BOM) y variantes ortogonales.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    onclick={() => showTypeModal = true}
                    class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs border border-slate-700"
                >
                    + Tipo de Producto
                </button>
                <button
                    type="button"
                    onclick={() => showAttributeModal = true}
                    class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs border border-slate-700"
                >
                    + Eje Ortogonal
                </button>
                <button
                    type="button"
                    onclick={openCreateProduct}
                    class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs shadow-lg shadow-emerald-600/20 flex items-center gap-1.5"
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
                Ejes y Opciones Ortogonales ({orthogonalAttributes.length})
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
            <!-- BUSCADOR -->
            <div class="bg-slate-900 border border-slate-800 p-4 rounded-xl flex items-center gap-3">
                <span class="text-slate-400 text-sm">🔍</span>
                <input
                    type="text"
                    bind:value={search}
                    oninput={handleSearchInput}
                    placeholder="Buscar producto por nombre o código de barras..."
                    class="w-full bg-transparent text-white placeholder-slate-500 text-xs focus:outline-none"
                />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                {#each products.data as prod (prod.id)}
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5 flex flex-col justify-between shadow-md group hover:border-slate-700 transition-colors">
                        <div>
                            <!-- Cabecera del Producto -->
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-slate-800 text-slate-300 border border-slate-700">
                                    {prod.product_type?.name ?? 'Sin tipo'}
                                </span>
                                <span class="font-mono text-emerald-400 font-extrabold text-base">
                                    ${Number(prod.base_price).toFixed(2)}
                                </span>
                            </div>

                            <h3 class="text-base font-bold text-white group-hover:text-emerald-300 transition-colors">{prod.name}</h3>
                            {#if prod.barcode}
                                <span class="font-mono text-[11px] text-slate-500 block mt-0.5">Barcode: {prod.barcode}</span>
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
                                    <span class="font-mono text-slate-300 font-semibold">{prod.buffer_percentage}%</span>
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
                                            {#if variant.price_override}
                                                <strong class="text-emerald-400 font-sans">(${Number(variant.price_override).toFixed(2)})</strong>
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

            <!-- PESTAÑA 2: EJES ORTOGONALES -->
        {:else if activeTab === 'attributes'}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {#each orthogonalAttributes as attr (attr.id)}
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
                {#each productTypes as pt (pt.id)}
                    <div class="p-4 bg-slate-900 border border-slate-800 rounded-xl flex items-center justify-between">
                        <span class="font-bold text-white text-sm">{pt.name}</span>
                        <span class="text-[10px] uppercase font-mono px-2 py-0.5 rounded bg-slate-800 text-slate-400">ID #{pt.id}</span>
                    </div>
                {/each}
            </div>
        {/if}
    </div>
</AppLayout>

<!-- MODAL COMPLETO DE PRODUCTO (Nota 7, 8, 9) -->
{#if showProductModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-3xl w-full p-6 shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="text-base font-bold text-white">
                    {editingProduct ? `Editar Producto: ${editingProduct.name}` : 'Crear Producto con Receta BOM y Variantes'}
                </h3>
                <button type="button" onclick={() => showProductModal = false} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={submitProduct} class="space-y-4 text-xs">
                <!-- DATOS BASE (Nota 7) -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="sm:col-span-2">
                        <label for="p-name" class="block font-semibold text-slate-300 mb-1">Nombre del Producto *</label>
                        <input
                            id="p-name"
                            type="text"
                            bind:value={productForm.name}
                            placeholder="Ej. Chaqueta Cortavientos Premium"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                        {#if errors.name}
                            <p class="text-[10px] text-rose-400 mt-1">{errors.name}</p>
                        {/if}
                    </div>
                    <div>
                        <label for="p-type" class="block font-semibold text-slate-300 mb-1">Tipo de Producto</label>
                        <select
                            id="p-type"
                            bind:value={productForm.product_type_id}
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        >
                            <option value="">(Ninguno)</option>
                            {#each productTypes as pt}
                                <option value={pt.id}>{pt.name}</option>
                            {/each}
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label for="p-workflow" class="block font-semibold text-slate-300 mb-1">Flujo Kanban Asignado (Nota 7) *</label>
                        <select
                            id="p-workflow"
                            bind:value={productForm.workflow_id}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        >
                            {#each workflows as wf}
                                <option value={wf.id}>{wf.name}</option>
                            {/each}
                        </select>
                    </div>
                    <div>
                        <label for="p-barcode" class="block font-semibold text-slate-300 mb-1">Código de Barras</label>
                        <input
                            id="p-barcode"
                            type="text"
                            bind:value={productForm.barcode}
                            placeholder="770..."
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono"
                        />
                    </div>
                    <div>
                        <label for="p-price" class="block font-semibold text-slate-300 mb-1">Precio Base ($) *</label>
                        <input
                            id="p-price"
                            type="number"
                            step="0.01"
                            min="0"
                            bind:value={productForm.base_price}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs font-mono font-bold"
                        />
                    </div>
                </div>

                <!-- TIEMPO DE PRODUCCIÓN EN MINUTOS / HORAS / DÍAS Y HOLGURA -->
                <div class="p-3 bg-slate-950/70 rounded-xl border border-slate-800 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label for="p-time-val" class="block font-semibold text-slate-300 mb-1">Tiempo de Fabricación *</label>
                        <input
                            id="p-time-val"
                            type="number"
                            step="0.1"
                            min="0.1"
                            bind:value={productForm.time_value}
                            required
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white text-xs font-mono font-bold"
                        />
                    </div>
                    <div>
                        <label for="p-time-unit" class="block font-semibold text-slate-300 mb-1">Unidad de Tiempo *</label>
                        <select
                            id="p-time-unit"
                            bind:value={productForm.production_time_unit}
                            required
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white text-xs"
                        >
                            <option value="minutes">Minutos (ej. 45 min)</option>
                            <option value="hours">Horas (ej. 3.5 h)</option>
                            <option value="days">Días Laborales (ej. 2 días = 960 min)</option>
                        </select>
                    </div>
                    <div>
                        <label for="p-buffer" class="block font-semibold text-slate-300 mb-1">Porcentaje de Holgura (%) *</label>
                        <input
                            id="p-buffer"
                            type="number"
                            min="0"
                            max="100"
                            bind:value={productForm.buffer_percentage}
                            required
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-amber-300 text-xs font-mono font-bold"
                        />
                    </div>
                </div>

                <!-- NOTA 8: RECETA BOM (CONSUMO DE MATERIALES) -->
                <div class="pt-3 border-t border-slate-800 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-xs">Receta de Materiales Primordiales (BOM)</span>
                            <p class="text-[11px] text-slate-500">Insumos que se consumen automáticamente al iniciar la fabricación.</p>
                        </div>
                        <button
                            type="button"
                            onclick={addMaterialRow}
                            disabled={rawMaterials.length === 0}
                            class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs border border-slate-700 flex items-center gap-1 disabled:opacity-50"
                        >
                            <span>+</span>
                            <span>Agregar Insumo</span>
                        </button>
                    </div>

                    {#if productForm.materials.length === 0}
                        <div class="p-3 text-center bg-slate-950/60 rounded-xl border border-dashed border-slate-800 text-slate-500 text-[11px]">
                            Sin materiales configurados en la receta BOM.
                        </div>
                    {:else}
                        <div class="space-y-2">
                            {#each productForm.materials as matRow, idx}
                                <div class="flex items-center gap-2 p-2 bg-slate-950 rounded-xl border border-slate-800">
                                    <div class="flex-1">
                                        <select
                                            bind:value={matRow.raw_material_id}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs"
                                        >
                                            {#each rawMaterials as m}
                                                <option value={m.id}>{m.name} ({m.unit_of_measure})</option>
                                            {/each}
                                        </select>
                                    </div>
                                    <div class="w-28">
                                        <input
                                            type="number"
                                            step="0.001"
                                            min="0.001"
                                            placeholder="Cantidad"
                                            bind:value={matRow.quantity_required}
                                            required
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs font-mono"
                                        />
                                    </div>
                                    <div class="w-24">
                                        <input
                                            type="number"
                                            step="0.1"
                                            min="0"
                                            placeholder="Desperdicio %"
                                            bind:value={matRow.waste_percent}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-slate-300 text-xs font-mono"
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        onclick={() => removeMaterialRow(idx)}
                                        class="p-1.5 rounded-lg text-slate-500 hover:text-rose-400"
                                        title="Quitar de receta"
                                    >
                                        ✕
                                    </button>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>

                <!-- NOTA 9: VARIANTES ORTOGONALES -->
                <div class="pt-3 border-t border-slate-800 space-y-3">
                    <div>
                        <span class="font-bold text-white text-xs">Variantes Arquitectónicamente Ortogonales (Nota 9)</span>
                        <p class="text-[11px] text-slate-500">
                            {#if editingProduct}
                                Ajusta deltas de tiempo de fabricación y precios por variante.
                            {:else}
                                Selecciona las opciones por eje para generar combinaciones cartesianas (o ninguna para crear 1 variante estándar).
                            {/if}
                        </p>
                    </div>

                    {#if !editingProduct}
                        <!-- Selector de opciones ortogonales en creación -->
                        <div class="space-y-3">
                            {#each orthogonalAttributes as attr}
                                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800">
                                    <span class="font-semibold text-slate-300 text-xs block mb-2">{attr.name}:</span>
                                    <div class="flex flex-wrap gap-2">
                                        {#each attr.options ?? [] as opt}
                                            {@const isChecked = (productForm.grouped_options[attr.id] ?? []).includes(opt.id)}
                                            <button
                                                type="button"
                                                onclick={() => toggleOptionSelection(attr.id, opt.id)}
                                                class="px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors {isChecked ? 'bg-emerald-600 text-white border-emerald-500' : 'bg-slate-900 text-slate-400 border-slate-700 hover:border-slate-500'}"
                                            >
                                                {opt.value}
                                            </button>
                                        {/each}
                                    </div>
                                </div>
                            {/each}
                        </div>
                    {:else}
                        <!-- Ajuste de deltas y precio en edición -->
                        <div class="space-y-2">
                            {#each productForm.variants as vRow}
                                <div class="p-2.5 bg-slate-950 rounded-xl border border-slate-800 flex flex-wrap items-center gap-3">
                                    <div class="w-36 font-bold text-white text-xs">{vRow.name_label}</div>
                                    <div class="w-28">
                                        <input
                                            type="text"
                                            placeholder="SKU"
                                            bind:value={vRow.sku}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-white text-xs font-mono"
                                        />
                                    </div>
                                    <div class="w-28">
                                        <input
                                            type="number"
                                            placeholder="+/- min delta"
                                            bind:value={vRow.production_minutes_delta}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-amber-300 text-xs font-mono"
                                            title="Delta de minutos respecto al producto base"
                                        />
                                    </div>
                                    <div class="w-28">
                                        <input
                                            type="number"
                                            step="0.01"
                                            placeholder="Precio override"
                                            bind:value={vRow.price_override}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2 py-1 text-emerald-400 text-xs font-mono font-bold"
                                            title="Sobrescribir precio base"
                                        />
                                    </div>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={() => showProductModal = false}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={productProcessing}
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                    >
                        {editingProduct ? 'Actualizar Producto' : 'Guardar Producto'}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}

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

<!-- MODAL CREAR ATRIBUTO ORTOGONAL -->
{#if showAttributeModal}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-sm w-full p-6 shadow-2xl space-y-4">
            <h3 class="text-base font-bold text-white">Nuevo Eje Ortogonal</h3>
            <form onsubmit={submitAttribute} class="space-y-4 text-xs">
                <div>
                    <label for="oa-name" class="block font-semibold text-slate-300 mb-1">Nombre del Eje (Atributo) *</label>
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
                    <button type="submit" disabled={attributeProcessing} class="px-4 py-1.5 rounded-xl bg-emerald-600 text-white font-semibold disabled:opacity-50">Guardar Eje</button>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL CREAR OPCIÓN EN ATRIBUTO ORTOGONAL -->
{#if showOptionModal && selectedAttributeForOption}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl max-w-sm w-full p-6 shadow-2xl space-y-4">
            <h3 class="text-base font-bold text-white">Nueva Opción en "{selectedAttributeForOption.name}"</h3>
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
