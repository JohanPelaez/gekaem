<script>
    import { untrack } from 'svelte';
    import { router, page } from '@inertiajs/svelte';
    import BarcodeViewer from './BarcodeViewer.svelte';
    import CurrencyInput from './CurrencyInput.svelte';
    import QuickCreateModal from './QuickCreateModal.svelte';
    import { csrfFetch } from '../Utils/csrfFetch.js';
    import { canViewPrices } from '../Utils/permissions.js';
    import {
        BARCODE_FORMATS,
        generateSequentialBarcode,
        validateBarcodeForFormat,
        downloadBarcodeAsPng,
    } from '../Utils/barcodeHelper.js';

    /**
     * ProductFormModal Component (Svelte 5 Runes)
     * Reusable full-featured modal for creating and editing products with barcode generator,
     * BOM raw material recipe, orthogonal variant axes, and quick creation in context.
     */
    let {
        isOpen = $bindable(false),
        product = null,
        productTypes = [],
        workflows = [],
        rawMaterials = [],
        orthogonalAttributes = [],
        attributes = [],
        unitsOfMeasure = [],
        asyncMode = false,
        errors = {},
        onSuccess = () => {},
        onClose = () => {},
    } = $props();

    let authUser = $derived(page.props.auth?.user);
    let hasFinancialAccess = $derived(canViewPrices(authUser));
    let pageErrors = $derived(page.props.errors || {});
    let formErrors = $derived(errors && Object.keys(errors).length > 0 ? errors : pageErrors);

    // Listas locales sincronizadas para permitir creación rápida en contexto sin resetear
    let localProductTypes = $state([]);
    let localWorkflows = $state([]);
    let localRawMaterials = $state([]);
    let localAttributes = $state([]);

    $effect(() => {
        const incomingTypes = productTypes;
        untrack(() => {
            localProductTypes = [...(incomingTypes || [])];
        });
    });
    $effect(() => {
        const incomingWfs = workflows;
        untrack(() => {
            localWorkflows = [...(incomingWfs || [])];
            if (!productForm.workflow_id && localWorkflows.length > 0) {
                productForm.workflow_id = localWorkflows[0].id;
            }
        });
    });
    $effect(() => {
        const incomingMats = rawMaterials;
        untrack(() => {
            localRawMaterials = [...(incomingMats || [])];
        });
    });
    $effect(() => {
        const incomingAttrs = (Array.isArray(orthogonalAttributes) && orthogonalAttributes.length > 0)
            ? orthogonalAttributes
            : (Array.isArray(attributes) ? attributes : []);
        untrack(() => {
            localAttributes = [...incomingAttrs];
        });
    });

    // Listas ordenadas alfabéticamente (A-Z)
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

    // Formulario principal de Producto en Svelte 5 Rune
    let productForm = $state({
        name: '',
        product_type_id: '',
        workflow_id: '',
        barcode: '',
        barcode_format: 'CODE128',
        time_value: 45,
        production_time_unit: 'minutes',
        buffer_percentage: 20,
        base_price: 0,
        is_active: true,
        materials: [],
        grouped_options: {},
        variants: [],
    });

    let currentFormatObj = $derived(
        BARCODE_FORMATS.find((f) => f.value === productForm.barcode_format) || BARCODE_FORMATS[0]
    );

    let barcodeValidation = $derived(
        productForm.barcode
            ? validateBarcodeForFormat(productForm.barcode, productForm.barcode_format)
            : { valid: false, message: '' }
    );

    let prevIsOpen = false;
    let prevProduct = null;

    // Sincronización al abrir el modal para creación o edición
    $effect(() => {
        const currentlyOpen = isOpen;
        const currentProd = product;

        if (currentlyOpen && (!prevIsOpen || currentProd !== prevProduct)) {
            prevIsOpen = currentlyOpen;
            prevProduct = currentProd;

            untrack(() => {
                if (currentProd) {
                    productForm.name = currentProd.name ?? '';
                    productForm.product_type_id = currentProd.product_type_id ?? '';
                    productForm.workflow_id = currentProd.workflow_id ?? (localWorkflows[0]?.id ?? '');
                    productForm.barcode = currentProd.barcode ?? '';
                    productForm.barcode_format = currentProd.barcode_format || (currentProd.barcode && /^\d{13}$/.test(currentProd.barcode) ? 'EAN13' : 'CODE128');
                    productForm.production_time_unit = currentProd.production_time_unit ?? 'minutes';

                    const unit = currentProd.production_time_unit ?? 'minutes';
                    const mins = currentProd.base_production_minutes ?? 0;
                    if (unit === 'days') {
                        productForm.time_value = Number((mins / 480).toFixed(2));
                    } else if (unit === 'hours') {
                        productForm.time_value = Number((mins / 60).toFixed(2));
                    } else {
                        productForm.time_value = mins;
                    }

                    productForm.buffer_percentage = currentProd.buffer_percentage ?? 20;
                    productForm.base_price = Number(currentProd.base_price ?? 0);
                    productForm.is_active = Boolean(currentProd.is_active);

                    // Cargar receta BOM
                    const reqs = currentProd.material_requirements || currentProd.materialRequirements || [];
                    productForm.materials = reqs.map((m) => ({
                        raw_material_id: m.raw_material_id,
                        quantity_required: Number(m.quantity_required),
                        waste_percent: Number(m.waste_percent ?? 0),
                    }));

                    // Cargar variantes
                    productForm.variants = (currentProd.variants ?? []).map((v) => ({
                        id: v.id,
                        name_label: v.name_label,
                        sku: v.sku ?? '',
                        barcode: v.barcode ?? '',
                        production_minutes_delta: v.production_minutes_delta ?? 0,
                        price_override: v.price_override ? Number(v.price_override) : '',
                    }));
                    productForm.grouped_options = {};
                } else {
                    resetProductForm();
                }
            });
        } else if (!currentlyOpen) {
            prevIsOpen = false;
            prevProduct = null;
        }
    });

    function resetProductForm() {
        productForm.name = '';
        productForm.product_type_id = localProductTypes[0]?.id ?? '';
        productForm.workflow_id = localWorkflows[0]?.id ?? '';
        productForm.barcode = '';
        productForm.barcode_format = 'CODE128';
        productForm.time_value = 45;
        productForm.production_time_unit = 'minutes';
        productForm.buffer_percentage = 20;
        productForm.base_price = 0;
        productForm.is_active = true;
        productForm.materials = [];
        productForm.grouped_options = {};
        productForm.variants = [];
    }

    function generateConsecutiveBarcode() {
        const nextSeq = Math.floor(100000 + Math.random() * 900000);
        productForm.barcode = generateSequentialBarcode(
            productForm.barcode_format || 'CODE128',
            nextSeq,
            1
        );
    }

    // Modal secundario de Quick Create en contexto
    let quickModalOpen = $state(false);
    let quickModalType = $state('product-type');
    let quickTargetBOMIndex = $state(null);

    function openQuickCreate(type, targetIdx = null) {
        quickModalType = type;
        quickTargetBOMIndex = targetIdx;
        quickModalOpen = true;
    }

    function handleQuickSuccess(created) {
        if (quickModalType === 'product-type') {
            localProductTypes = [...localProductTypes, created];
            productForm.product_type_id = created.id;
        } else if (quickModalType === 'workflow') {
            localWorkflows = [...localWorkflows, created];
            productForm.workflow_id = created.id;
        } else if (quickModalType === 'raw-material') {
            localRawMaterials = [...localRawMaterials, created];
            if (quickTargetBOMIndex !== null && productForm.materials[quickTargetBOMIndex]) {
                productForm.materials[quickTargetBOMIndex].raw_material_id = created.id;
            } else {
                productForm.materials = [
                    ...productForm.materials,
                    {
                        raw_material_id: created.id,
                        quantity_required: 1,
                        waste_percent: 0,
                    },
                ];
            }
            quickTargetBOMIndex = null;
        } else if (quickModalType === 'attribute') {
            localAttributes = [...localAttributes, created];
            if (created.options && created.options.length > 0) {
                productForm.grouped_options[created.id] = created.options.map((o) => o.id);
            }
        }
    }

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
                if (!productForm.grouped_options[attr.id]) {
                    productForm.grouped_options[attr.id] = [];
                }
                if (!productForm.grouped_options[attr.id].includes(created.id)) {
                    productForm.grouped_options[attr.id] = [...productForm.grouped_options[attr.id], created.id];
                }
                newOptionValue = '';
                activeAddOptionAttrId = null;
            }
        } catch (e) {
            console.error('Error creating inline option:', e);
        } finally {
            optionInlineLoading = false;
        }
    }

    function addMaterialRow() {
        if (sortedRawMaterials.length === 0) {
            openQuickCreate('raw-material');
            return;
        }
        productForm.materials = [
            ...productForm.materials,
            {
                raw_material_id: sortedRawMaterials[0].id,
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

    let processing = $state(false);

    async function handleSubmit(e) {
        e.preventDefault();
        processing = true;

        const payload = {
            ...productForm,
            name: productForm.name?.trim(),
            product_type_id: productForm.product_type_id ? Number(productForm.product_type_id) : null,
            workflow_id: Number(productForm.workflow_id),
            base_price: Number(productForm.base_price || 0),
            time_value: Number(productForm.time_value || 0),
            buffer_percentage: Number(productForm.buffer_percentage || 0),
            barcode: productForm.barcode?.trim() || null,
        };

        if (asyncMode) {
            try {
                const res = await csrfFetch('/products', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                if (res.ok) {
                    const data = await res.json();
                    const createdProduct = data.product;
                    isOpen = false;
                    resetProductForm();
                    onSuccess(createdProduct);
                } else {
                    const errData = await res.json().catch(() => ({}));
                    alert(errData.message || 'Error al guardar el producto.');
                }
            } catch (err) {
                console.error('Error saving product in async mode:', err);
                alert('Error de conexión al guardar el producto.');
            } finally {
                processing = false;
            }
            return;
        }

        // Modo estándar con Inertia (Products/Index)
        if (product?.id) {
            router.patch(`/products/${product.id}`, payload, {
                preserveScroll: true,
                onStart: () => { processing = true; },
                onFinish: () => { processing = false; },
                onSuccess: () => {
                    isOpen = false;
                    resetProductForm();
                    onSuccess(payload);
                },
            });
        } else {
            router.post('/products', payload, {
                preserveScroll: true,
                onStart: () => { processing = true; },
                onFinish: () => { processing = false; },
                onSuccess: () => {
                    isOpen = false;
                    resetProductForm();
                    onSuccess(payload);
                },
            });
        }
    }

    function handleClose() {
        isOpen = false;
        onClose();
    }
</script>

{#if isOpen}
    <div class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-3xl w-full p-6 shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto animate-in fade-in zoom-in-95 duration-100">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-base font-bold text-white">
                        {product ? `Editar Producto: ${product.name}` : 'Crear Producto con Receta BOM y Variantes'}
                    </h3>
                    <p class="text-[11px] text-slate-400">
                        Configura el catálogo, tiempos, código de barras y consumo de insumos.
                    </p>
                </div>
                <button type="button" onclick={handleClose} class="text-slate-400 hover:text-white">✕</button>
            </div>

            <form onsubmit={handleSubmit} class="space-y-4 text-xs">
                <!-- DATOS BASE -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="sm:col-span-2">
                        <label for="pf-name" class="block font-semibold text-slate-300 mb-1">Nombre del Producto *</label>
                        <input
                            id="pf-name"
                            type="text"
                            bind:value={productForm.name}
                            placeholder="Ej. Chaqueta Cortavientos Premium"
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        />
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label for="pf-type" class="font-semibold text-slate-300">Tipo de Producto</label>
                            <button
                                type="button"
                                onclick={() => openQuickCreate('product-type')}
                                class="text-[10px] text-emerald-400 hover:text-emerald-300 font-bold"
                            >
                                + Nuevo
                            </button>
                        </div>
                        <select
                            id="pf-type"
                            bind:value={productForm.product_type_id}
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        >
                            <option value="">(Ninguno)</option>
                            {#each localProductTypes as pt}
                                <option value={pt.id}>{pt.name}</option>
                            {/each}
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label for="pf-workflow" class="font-semibold text-slate-300">Flujo Kanban Asignado *</label>
                            <button
                                type="button"
                                onclick={() => openQuickCreate('workflow')}
                                class="text-[10px] text-emerald-400 hover:text-emerald-300 font-bold"
                            >
                                + Nuevo
                            </button>
                        </div>
                        <select
                            id="pf-workflow"
                            bind:value={productForm.workflow_id}
                            required
                            class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-emerald-500 text-xs"
                        >
                            {#each sortedWorkflows as wf}
                                <option value={wf.id}>{wf.name}</option>
                            {/each}
                        </select>
                    </div>
                    {#if hasFinancialAccess}
                        <div>
                            <label for="pf-price" class="block font-semibold text-slate-300 mb-1">Precio Base ($) *</label>
                            <CurrencyInput bind:value={productForm.base_price} id="pf-price" required />
                        </div>
                    {/if}
                </div>

                <!-- CÓDIGO DE BARRAS INTELIGENTE CON PREVISUALIZACIÓN SVG -->
                <div class="p-3.5 bg-slate-950/80 rounded-2xl border border-slate-800 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span class="font-bold text-white text-xs">Código de Barras</span>
                            <p class="text-[11px] text-slate-400">Genera correlativos automáticos o ingresa un código existente.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <label for="pf-barcode-fmt" class="text-[11px] font-semibold text-slate-300">Formato:</label>
                            <select
                                id="pf-barcode-fmt"
                                bind:value={productForm.barcode_format}
                                class="bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1 text-white text-[11px] font-mono focus:border-sky-500 focus:outline-none"
                            >
                                {#each BARCODE_FORMATS as fmt}
                                    <option value={fmt.value}>{fmt.label}</option>
                                {/each}
                            </select>
                            <button
                                type="button"
                                onclick={generateConsecutiveBarcode}
                                class="px-3 py-1 bg-slate-800 hover:bg-slate-700 text-sky-400 border border-sky-500/30 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition-colors"
                            >
                                <span>⚡ Generar Consecutivo</span>
                            </button>
                        </div>
                    </div>

                    <!-- Indicador y sugerencia del formato actual -->
                    <div class="text-[11px] text-slate-400 flex items-center gap-1.5 px-1">
                        <span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400"></span>
                        <span class="font-medium text-slate-300">{currentFormatObj.label}:</span>
                        <span>{currentFormatObj.hint}</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-start">
                        <div class="space-y-1">
                            <label for="pf-barcode" class="block font-semibold text-slate-300 text-xs mb-1">Código / SKU Base</label>
                            <input
                                id="pf-barcode"
                                type="text"
                                bind:value={productForm.barcode}
                                placeholder="Ingresa o genera código de barras"
                                class="w-full bg-slate-900 border {productForm.barcode && !barcodeValidation.valid ? 'border-amber-500/70 focus:border-amber-500' : 'border-slate-700 focus:border-sky-500'} rounded-xl px-3 py-2 text-white text-xs font-mono font-bold focus:outline-none"
                            />
                            {#if productForm.barcode && !barcodeValidation.valid}
                                <div class="text-[11px] text-amber-400 font-mono flex items-center gap-1.5 px-1">
                                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                    <span>{barcodeValidation.message}</span>
                                </div>
                            {/if}
                        </div>
                        <div class="flex flex-col items-center justify-center gap-2">
                            {#if productForm.barcode}
                                <BarcodeViewer
                                    value={productForm.barcode}
                                    format={productForm.barcode_format}
                                    height={36}
                                    width={1.5}
                                    productName={productForm.name}
                                />
                                {#if barcodeValidation.valid}
                                    <button
                                        type="button"
                                        onclick={() => downloadBarcodeAsPng(productForm.barcode, productForm.barcode_format, productForm.name)}
                                        class="px-3 py-1 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 border border-emerald-500/30 text-emerald-300 text-xs font-semibold flex items-center gap-1.5 transition-colors"
                                    >
                                        <svg class="w-3.5 h-3.5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                        <span>Guardar como Imagen (PNG)</span>
                                    </button>
                                {/if}
                            {:else}
                                <span class="text-slate-500 text-[11px] italic py-2">Previsualización de código aparecerá aquí</span>
                            {/if}
                        </div>
                    </div>
                </div>

                <!-- TIEMPO DE PRODUCCIÓN EN MINUTOS / HORAS / DÍAS Y HOLGURA -->
                <div class="p-3 bg-slate-950/70 rounded-xl border border-slate-800 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label for="pf-time-val" class="block font-semibold text-slate-300 mb-1">Tiempo de Fabricación *</label>
                        <input
                            id="pf-time-val"
                            type="number"
                            step="0.1"
                            min="0.1"
                            bind:value={productForm.time_value}
                            required
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-white text-xs font-mono font-bold"
                        />
                    </div>
                    <div>
                        <label for="pf-time-unit" class="block font-semibold text-slate-300 mb-1">Unidad de Tiempo *</label>
                        <select
                            id="pf-time-unit"
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
                        <label for="pf-buffer" class="block font-semibold text-slate-300 mb-1">Porcentaje de Holgura (%) *</label>
                        <input
                            id="pf-buffer"
                            type="number"
                            min="0"
                            max="100"
                            bind:value={productForm.buffer_percentage}
                            required
                            class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-amber-300 text-xs font-mono font-bold"
                        />
                    </div>
                </div>

                <!-- RECETA BOM (CONSUMO DE MATERIALES) -->
                <div class="pt-3 border-t border-slate-800 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-xs">Receta de Materiales Primordiales (BOM)</span>
                            <p class="text-[11px] text-slate-500">Insumos que se consumen automáticamente al iniciar la fabricación.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                onclick={() => openQuickCreate('raw-material')}
                                class="text-[11px] text-emerald-400 hover:text-emerald-300 font-bold"
                            >
                                + Nuevo Material
                            </button>
                            <button
                                type="button"
                                onclick={addMaterialRow}
                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs border border-slate-700 flex items-center gap-1"
                            >
                                <span>+</span>
                                <span>Agregar Insumo</span>
                            </button>
                        </div>
                    </div>

                    {#if productForm.materials.length === 0}
                        <div class="p-3 text-center bg-slate-950/60 rounded-xl border border-dashed border-slate-800 text-slate-500 text-[11px]">
                            Sin materiales configurados en la receta BOM. Haz clic en "Agregar Insumo" para asociar materia prima.
                        </div>
                    {:else}
                        <div class="space-y-2">
                            <!-- Cabecera de columnas para receta BOM -->
                            <div class="hidden sm:grid sm:grid-cols-12 gap-2 px-3 py-1 text-[11px] font-semibold text-slate-400 border-b border-slate-800/60">
                                <div class="sm:col-span-6">Materia Prima *</div>
                                <div class="sm:col-span-3">Cantidad por unidad *</div>
                                <div class="sm:col-span-2">Merma (%)</div>
                                <div class="sm:col-span-1 text-center">Acción</div>
                            </div>
                            {#each productForm.materials as matRow, idx}
                                <div class="p-2.5 bg-slate-950 rounded-xl border border-slate-800 grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
                                    <div class="sm:col-span-6">
                                        <label for="pf-bom-mat-{idx}" class="sm:hidden block font-semibold text-slate-400 text-[10px] mb-1">Materia Prima *</label>
                                        <select
                                            id="pf-bom-mat-{idx}"
                                            bind:value={matRow.raw_material_id}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs"
                                        >
                                            {#each sortedRawMaterials as m}
                                                <option value={m.id}>{m.name} ({m.unit_of_measure})</option>
                                            {/each}
                                        </select>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label for="pf-bom-qty-{idx}" class="sm:hidden block font-semibold text-slate-400 text-[10px] mb-1">Cantidad por unidad *</label>
                                        <input
                                            id="pf-bom-qty-{idx}"
                                            type="number"
                                            step="0.001"
                                            min="0.001"
                                            placeholder="Cantidad"
                                            bind:value={matRow.quantity_required}
                                            required
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs font-mono"
                                        />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label for="pf-bom-waste-{idx}" class="sm:hidden block font-semibold text-slate-400 text-[10px] mb-1">Merma (%)</label>
                                        <input
                                            id="pf-bom-waste-{idx}"
                                            type="number"
                                            step="0.1"
                                            min="0"
                                            placeholder="Merma %"
                                            bind:value={matRow.waste_percent}
                                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-slate-300 text-xs font-mono"
                                        />
                                    </div>
                                    <div class="sm:col-span-1 flex justify-center">
                                        <button
                                            type="button"
                                            onclick={() => removeMaterialRow(idx)}
                                            class="p-1.5 rounded-lg text-slate-500 hover:text-rose-400"
                                            title="Quitar de receta"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>

                <!-- ATRIBUTOS Y VARIANTES -->
                <div class="pt-3 border-t border-slate-800 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-xs">Atributos del Producto (Talla, Color, Material)</span>
                            <p class="text-[11px] text-slate-500">
                                {#if product}
                                    Ajusta deltas de tiempo de fabricación y precios por variante.
                                {:else}
                                    Selecciona las opciones por atributo para generar combinaciones (o ninguna para crear 1 variante estándar).
                                {/if}
                            </p>
                        </div>
                        {#if !product}
                            <button
                                type="button"
                                onclick={() => openQuickCreate('attribute')}
                                class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs border border-slate-700 flex items-center gap-1 transition-colors"
                            >
                                <span>+</span>
                                <span>Nuevo Atributo</span>
                            </button>
                        {/if}
                    </div>

                    {#if !product}
                        <div class="space-y-3">
                            {#each localAttributes as attr}
                                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="font-semibold text-slate-300 text-xs">{attr.name}:</span>
                                        <button
                                            type="button"
                                            onclick={() => {
                                                if (activeAddOptionAttrId === attr.id) {
                                                    activeAddOptionAttrId = null;
                                                    newOptionValue = '';
                                                } else {
                                                    activeAddOptionAttrId = attr.id;
                                                    newOptionValue = '';
                                                }
                                            }}
                                            class="text-[11px] text-emerald-400 hover:text-emerald-300 font-bold transition-colors"
                                        >
                                            {activeAddOptionAttrId === attr.id ? '✕ Cancelar' : '+ Opción'}
                                        </button>
                                    </div>

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

                                    {#if activeAddOptionAttrId === attr.id}
                                        <div class="space-y-1.5 pt-2 border-t border-slate-800/80">
                                            <label for="pf-inline-opt-{attr.id}" class="block text-[11px] font-semibold text-slate-300">Nombre de la nueva opción *</label>
                                            <div class="flex items-center gap-2">
                                                <input
                                                    id="pf-inline-opt-{attr.id}"
                                                    type="text"
                                                    bind:value={newOptionValue}
                                                    placeholder="Nueva opción (ej. XL o Verde)"
                                                    class="flex-1 bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500"
                                                    onkeydown={(e) => { if (e.key === 'Enter') { e.preventDefault(); handleSaveOptionInline(attr); } }}
                                                />
                                                <button
                                                    type="button"
                                                    onclick={() => handleSaveOptionInline(attr)}
                                                    disabled={optionInlineLoading || !newOptionValue.trim()}
                                                    class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-xs font-bold transition-colors shrink-0"
                                                >
                                                    {optionInlineLoading ? '...' : 'Guardar'}
                                                </button>
                                            </div>
                                        </div>
                                    {/if}
                                </div>
                            {/each}
                        </div>
                    {:else}
                        <div class="space-y-2">
                            {#each productForm.variants as vRow, vIdx}
                                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="font-bold text-white text-xs">{vRow.name_label}</span>
                                        <span class="text-[10px] text-slate-500 font-mono">Variante #{vIdx + 1}</span>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 items-end">
                                        <div>
                                            <label for="pf-v-sku-{vIdx}" class="block font-semibold text-slate-400 text-[11px] mb-1">Código / SKU</label>
                                            <input
                                                id="pf-v-sku-{vIdx}"
                                                type="text"
                                                placeholder="Ej. SKU-VAR-01"
                                                bind:value={vRow.sku}
                                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-white text-xs font-mono"
                                            />
                                        </div>
                                        <div>
                                            <label for="pf-v-delta-{vIdx}" class="block font-semibold text-slate-400 text-[11px] mb-1">Tiempo de producción (min)</label>
                                            <input
                                                id="pf-v-delta-{vIdx}"
                                                type="number"
                                                placeholder="0"
                                                bind:value={vRow.production_minutes_delta}
                                                class="w-full bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-amber-300 text-xs font-mono font-bold"
                                                title="Delta de minutos (+/-) respecto al producto base"
                                            />
                                        </div>
                                        {#if hasFinancialAccess}
                                            <div>
                                                <label for="pf-v-price-{vIdx}" class="block font-semibold text-slate-400 text-[11px] mb-1">Precio variante ($)</label>
                                                <CurrencyInput
                                                    id="pf-v-price-{vIdx}"
                                                    bind:value={vRow.price_override}
                                                    placeholder="Precio personalizado"
                                                />
                                            </div>
                                        {/if}
                                    </div>
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={handleClose}
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={processing}
                        class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-semibold shadow-lg shadow-emerald-600/20 disabled:opacity-50"
                    >
                        {processing ? 'Guardando...' : (product ? 'Actualizar Producto' : 'Guardar Producto')}
                    </button>
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
