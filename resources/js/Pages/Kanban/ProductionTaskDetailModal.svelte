<script>
    import { untrack } from 'svelte';
    import { fade, scale } from 'svelte/transition';
    import JsBarcode from 'jsbarcode';
    import { downloadBarcodeAsPng, validateBarcodeForFormat } from '../../Utils/barcodeHelper.js';

    let {
        isOpen = false,
        task = null,
        order = null,
        stages = [],
        rawMaterials = [],
        canManageCatalog = false,
        errors = {},
        onStageChange = () => {},
        onEditProduct = () => {},
        onClose = () => {}
    } = $props();

    // Referencias y estado para el código de barras dinámico
    let barcodeSvg = $state(null);
    let barcodeError = $state(null);

    // Etapa actual de la tarea
    let currentStage = $derived.by(() => {
        if (!task) return null;
        return stages.find(s => s.id === task.current_stage_id) || task.current_stage || task.currentStage;
    });

    // Producto base y variante
    let variant = $derived(task?.product_variant || task?.productVariant);
    let product = $derived(variant?.product);
    let productType = $derived(product?.product_type || product?.productType);

    // Código de barras, formato y nombre para descarga
    let barcodeValue = $derived(variant?.barcode || product?.barcode || '');
    let barcodeFormat = $derived(product?.barcode_format || 'CODE128');
    let barcodeProductName = $derived.by(() => {
        if (variant?.name_label && variant?.name_label !== product?.name) {
            return `${product?.name ?? ''} (${variant.name_label})`;
        }
        return product?.name ?? task?.item_name_snapshot ?? 'Producto';
    });

    // Renderizar código de barras con JsBarcode sobre el SVG
    $effect(() => {
        if (!isOpen || !task) return;

        const code = barcodeValue ? String(barcodeValue).trim() : '';
        const svg = barcodeSvg;
        const fmt = barcodeFormat;

        untrack(() => {
            if (!code || !svg) {
                barcodeError = null;
                return;
            }

            const validation = validateBarcodeForFormat(code, fmt);
            if (!validation.valid) {
                barcodeError = validation.message;
                return;
            }

            try {
                barcodeError = null;
                JsBarcode(svg, code, {
                    format: fmt,
                    width: 1.8,
                    height: 48,
                    displayValue: true,
                    font: 'monospace',
                    fontOptions: 'bold',
                    textAlign: 'center',
                    textPosition: 'bottom',
                    textMargin: 4,
                    fontSize: 12,
                    background: '#ffffff',
                    lineColor: '#000000',
                    textColor: '#0f172a',
                    margin: 8,
                });
            } catch (err) {
                barcodeError = err?.message || 'Código incompatible con el formato seleccionado';
            }
        });
    });

    function handleDownloadBarcode() {
        if (!barcodeValue) return;
        downloadBarcodeAsPng(barcodeValue, barcodeFormat, barcodeProductName);
    }

    // Atributos y opciones de la variante
    let attributeOptions = $derived.by(() => {
        return variant?.attribute_options || variant?.attributeOptions || [];
    });

    // Formateo de tiempo estimado
    let timeFormatted = $derived.by(() => {
        if (!task) return '0 min';
        const totalMin = Number(task.estimated_minutes || 0);
        if (totalMin < 60) return `${totalMin} min`;
        const hours = Math.floor(totalMin / 60);
        const mins = totalMin % 60;
        return mins > 0 ? `${hours}h ${mins}min` : `${hours}h`;
    });

    let unitTimeFormatted = $derived.by(() => {
        if (!task || !task.quantity) return '0 min';
        const qty = Number(task.quantity) || 1;
        const unitMin = Math.round(Number(task.estimated_minutes || 0) / qty);
        if (unitMin < 60) return `${unitMin} min`;
        const hours = Math.floor(unitMin / 60);
        const mins = unitMin % 60;
        return mins > 0 ? `${hours}h ${mins}min` : `${hours}h`;
    });

    // Desglose de materias primas (Receta BOM)
    let requirements = $derived.by(() => {
        if (!task) return [];

        const prodReqs = product?.materials ?? product?.material_requirements ?? product?.materialRequirements ?? [];
        const varReqs = variant?.material_requirements ?? variant?.materialRequirements ?? [];
        const all = [...prodReqs, ...varReqs];

        // Filtrar requerimientos aplicables a esta variante (base o específicos)
        const filtered = all.filter(r => !r.product_variant_id || r.product_variant_id === variant?.id);

        return filtered.map(req => {
            const mat = req.raw_material ?? req.rawMaterial ?? rawMaterials.find(m => m.id === req.raw_material_id);
            const baseQty = Number(req.quantity_required || 0);
            const wastePct = Number(req.waste_percent || 0);
            const unitQtyWithWaste = wastePct > 0 ? baseQty * (1 + (wastePct / 100)) : baseQty;
            const totalRequired = unitQtyWithWaste * Number(task.quantity || 1);
            const currentStock = Number(mat?.current_stock || 0);
            const hasStock = currentStock >= totalRequired;

            return {
                id: req.id,
                materialName: mat?.name ?? 'Materia prima',
                unitOfMeasure: mat?.unit_of_measure ?? 'unid',
                baseQty,
                wastePct,
                unitQtyWithWaste,
                totalRequired,
                currentStock,
                hasStock,
                minStockAlert: Number(mat?.min_stock_alert || 0)
            };
        });
    });

    function handleStageSelect(e) {
        const newStageId = Number(e.target.value);
        if (newStageId && task) {
            onStageChange(task.id, newStageId);
        }
    }
</script>

{#if isOpen && task}
    <div
        class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-slate-950/80 backdrop-blur-sm overflow-y-auto"
        transition:fade={{ duration: 150 }}
        role="dialog"
        aria-modal="true"
        aria-labelledby="task-detail-modal-title"
    >
        <div
            class="relative w-full max-w-3xl bg-slate-900 border border-slate-800 rounded-3xl shadow-2xl overflow-hidden my-auto"
            transition:scale={{ duration: 150, start: 0.96 }}
        >
            <!-- CABECERA PRINCIPAL -->
            <div class="px-6 py-5 border-b border-slate-800 flex items-start justify-between gap-4 bg-gradient-to-r from-slate-900 via-slate-900 to-sky-950/30">
                <div class="space-y-1.5 flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-mono font-bold bg-sky-500/10 text-sky-400 border border-sky-500/30">
                            Tarea #{task.id}
                        </span>
                        {#if order}
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-mono font-bold bg-slate-800 text-slate-300 border border-slate-700">
                                Pedido #{order.order_number}
                            </span>
                        {/if}
                        {#if productType}
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-500/10 text-indigo-300 border border-indigo-500/20">
                                {productType.name}
                            </span>
                        {/if}
                    </div>

                    <h2 id="task-detail-modal-title" class="text-xl font-bold text-white tracking-tight truncate" title={task.item_name_snapshot}>
                        {product?.name ?? task.item_name_snapshot}
                    </h2>

                    {#if variant?.name_label && variant?.name_label !== product?.name}
                        <p class="text-xs text-sky-400 font-medium">
                            Variante: <span class="font-bold text-slate-200">{variant.name_label}</span>
                        </p>
                    {/if}
                </div>

                <!-- Botón de Cerrar -->
                <button
                    type="button"
                    onclick={onClose}
                    class="p-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition-colors shrink-0"
                    aria-label="Cerrar modal"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- CUERPO DEL MODAL -->
            <div class="p-6 space-y-6 max-h-[calc(85vh-130px)] overflow-y-auto">

                <!-- SELECTOR DE ETAPA Y SKU -->
                <div class="grid grid-cols-1 sm:grid-cols-12 gap-4 p-4 rounded-2xl bg-slate-950/70 border border-slate-800/80 items-center">
                    <div class="sm:col-span-8 space-y-1">
                        <label for="modal-task-stage-select" class="block text-xs font-semibold text-slate-300">
                            Etapa actual en el flujo de producción:
                        </label>
                        <div class="flex items-center gap-2">
                            <span
                                class="w-3.5 h-3.5 rounded-full shrink-0 shadow-sm"
                                style="background-color: {currentStage?.color || '#64748b'};"
                            ></span>
                            <select
                                id="modal-task-stage-select"
                                value={task.current_stage_id}
                                onchange={handleStageSelect}
                                class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-xs font-bold text-white focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500"
                            >
                                {#each stages as st}
                                    <option value={st.id}>{st.name}</option>
                                {/each}
                            </select>
                        </div>
                    </div>

                    <div class="sm:col-span-4 flex flex-wrap sm:justify-end gap-2 text-xs">
                        {#if variant?.sku || product?.sku}
                            <div class="px-3 py-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300">
                                <span class="text-[10px] text-slate-500 block uppercase font-bold">SKU</span>
                                <span class="font-mono font-bold text-sky-400">{variant?.sku || product?.sku}</span>
                            </div>
                        {/if}
                    </div>
                </div>

                <!-- BLOQUE VISUAL DEL CÓDIGO DE BARRAS Y DESCARGA (SPRINT 7) -->
                {#if barcodeValue}
                    <div class="p-4 rounded-2xl bg-slate-950/80 border border-slate-800 space-y-3">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-1.5">
                                    <span>🏷️</span>
                                    <span>Código de Barras</span>
                                </span>
                                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-sky-950 text-sky-400 border border-sky-800/80">
                                    Formato: {barcodeFormat}
                                </span>
                                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-slate-900 text-slate-300 border border-slate-800">
                                    {barcodeValue}
                                </span>
                            </div>

                            <button
                                type="button"
                                onclick={handleDownloadBarcode}
                                class="px-3.5 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold transition-all shadow-md shadow-sky-600/20 flex items-center gap-1.5 shrink-0"
                                title="Descargar código de barras en formato PNG de alta resolución"
                            >
                                <span>📥</span>
                                <span>Descargar Código de Barras (PNG)</span>
                            </button>
                        </div>

                        <!-- Renderizado del código de barras con JsBarcode -->
                        <div class="flex flex-col items-center justify-center p-3 rounded-xl bg-white border border-slate-200 shadow-sm overflow-x-auto">
                            {#if barcodeError}
                                <div class="text-xs text-amber-700 font-mono py-1.5 px-3 bg-amber-50 border border-amber-300 rounded-lg flex items-center gap-1.5 text-center">
                                    <span>⚠️</span>
                                    <span>{barcodeError}</span>
                                </div>
                            {:else}
                                <svg bind:this={barcodeSvg} class="max-w-full overflow-visible"></svg>
                            {/if}
                        </div>
                    </div>
                {/if}

                <!-- ATRIBUTOS ORTOGONALES Y CONFIGURACIÓN EXACTA -->
                {#if attributeOptions.length > 0}
                    <div class="space-y-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">
                            Atributos Ortogonales a Fabricar
                        </span>
                        <div class="flex flex-wrap gap-2.5">
                            {#each attributeOptions as opt}
                                <div class="px-3 py-1.5 rounded-xl bg-gradient-to-r from-sky-950/40 to-slate-900 border border-sky-500/30 flex items-center gap-2">
                                    <span class="text-xs text-sky-400 font-medium">
                                        {opt.attribute?.name ?? 'Atributo'}:
                                    </span>
                                    <span class="text-xs font-bold text-white">
                                        {opt.value}
                                    </span>
                                </div>
                            {/each}
                        </div>
                    </div>
                {/if}

                <!-- MÉTRICAS DE PRODUCCIÓN -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="p-3.5 rounded-2xl bg-slate-950/60 border border-slate-800 space-y-1">
                        <span class="text-[10px] uppercase font-bold tracking-wider text-slate-500 block">
                            Cantidad a Fabricar
                        </span>
                        <span class="text-lg font-bold text-white font-mono">
                            {task.quantity} <span class="text-xs font-normal text-slate-400">unidades</span>
                        </span>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-950/60 border border-slate-800 space-y-1">
                        <span class="text-[10px] uppercase font-bold tracking-wider text-slate-500 block">
                            Tiempo Total Estimado
                        </span>
                        <span class="text-lg font-bold text-amber-400 font-mono">
                            ⏱ {timeFormatted}
                        </span>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-950/60 border border-slate-800 space-y-1">
                        <span class="text-[10px] uppercase font-bold tracking-wider text-slate-500 block">
                            Tiempo Unitario
                        </span>
                        <span class="text-lg font-bold text-slate-300 font-mono">
                            {unitTimeFormatted} <span class="text-xs font-normal text-slate-500">/ unid</span>
                        </span>
                    </div>

                    <div class="p-3.5 rounded-2xl bg-slate-950/60 border border-slate-800 space-y-1">
                        <span class="text-[10px] uppercase font-bold tracking-wider text-slate-500 block">
                            Consumo de Inventario
                        </span>
                        {#if task.materials_consumed}
                            <span class="text-xs font-bold text-emerald-400 flex items-center gap-1 mt-1">
                                <span>✓</span>
                                <span>Descontado BOM</span>
                            </span>
                        {:else}
                            <span class="text-xs font-bold text-amber-400 flex items-center gap-1 mt-1">
                                <span>⏳</span>
                                <span>Pendiente</span>
                            </span>
                        {/if}
                    </div>
                </div>

                <!-- INFORMACIÓN DE REFERENCIA DEL PEDIDO Y NOTAS -->
                {#if order}
                    <div class="p-4 rounded-2xl bg-slate-950/50 border border-slate-800 space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs font-bold text-slate-300 flex items-center gap-1.5">
                                <span>📋</span>
                                <span>Referencia del Pedido #{order.order_number}</span>
                            </span>
                            {#if order.customer}
                                <span class="text-xs text-slate-400">
                                    Cliente: <span class="font-bold text-white">{order.customer.name}</span>
                                </span>
                            {/if}
                        </div>

                        {#if order.notes}
                            <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800/80 text-xs text-slate-300">
                                <span class="font-bold text-slate-400 block mb-0.5">Notas e instrucciones especiales:</span>
                                <p class="whitespace-pre-line text-slate-200">{order.notes}</p>
                            </div>
                        {/if}
                    </div>
                {/if}

                <!-- SECCIÓN PRINCIPAL: ¿QUÉ LLEVA ESTE PRODUCTO? (RECETA BOM) -->
                <div class="space-y-3 pt-2 border-t border-slate-800">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                                <span>🧵</span>
                                <span>¿Qué lleva este producto? (Materia Prima / Receta BOM)</span>
                            </h3>
                            <p class="text-xs text-slate-400">
                                Desglose de insumos y consumos calculados para fabricar {task.quantity} unidad(es).
                            </p>
                        </div>

                        {#if canManageCatalog && product}
                            <button
                                type="button"
                                onclick={() => onEditProduct(product)}
                                class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-sky-400 text-xs font-semibold border border-slate-700 transition-colors flex items-center gap-1"
                                title="Editar receta de materiales de este producto"
                            >
                                <span>⚙️</span>
                                <span>Editar Receta</span>
                            </button>
                        {/if}
                    </div>

                    {#if requirements.length === 0}
                        <div class="p-5 rounded-2xl border-2 border-dashed border-slate-800 bg-slate-950/40 text-center space-y-3">
                            <p class="text-xs text-slate-400">
                                Este producto no tiene materias primas asociadas en su receta.
                            </p>
                            {#if canManageCatalog && product}
                                <button
                                    type="button"
                                    onclick={() => onEditProduct(product)}
                                    class="px-4 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold transition-all shadow-md inline-flex items-center gap-1.5"
                                >
                                    <span>+</span>
                                    <span>Configurar Receta de Materia Prima</span>
                                </button>
                            {/if}
                        </div>
                    {:else}
                        <div class="rounded-2xl border border-slate-800 overflow-hidden bg-slate-950/40">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-slate-950 border-b border-slate-800 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                        <tr>
                                            <th class="p-3">Materia Prima</th>
                                            <th class="p-3">Consumo x 1 Unidad</th>
                                            <th class="p-3">Total Requerido</th>
                                            <th class="p-3">Estado en Inventario</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-850">
                                        {#each requirements as req (req.id)}
                                            <tr class="hover:bg-slate-900/50 transition-colors">
                                                <td class="p-3 font-semibold text-white">
                                                    {req.materialName}
                                                </td>
                                                <td class="p-3 text-slate-300 font-mono">
                                                    {req.baseQty} {req.unitOfMeasure}
                                                    {#if req.wastePct > 0}
                                                        <span class="text-[10px] text-slate-400 block font-sans">
                                                            (+{req.wastePct}% merma)
                                                        </span>
                                                    {/if}
                                                </td>
                                                <td class="p-3 font-mono font-bold text-sky-400 text-sm">
                                                    {req.totalRequired.toFixed(3)} {req.unitOfMeasure}
                                                </td>
                                                <td class="p-3">
                                                    {#if req.hasStock}
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                                                ✓ Stock OK
                                                            </span>
                                                            <span class="text-[10px] text-slate-400 font-mono">
                                                                (Disp: {req.currentStock})
                                                            </span>
                                                        </div>
                                                    {:else}
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                                                ⚠️ Stock Bajo
                                                            </span>
                                                            <span class="text-[10px] text-amber-300/80 font-mono">
                                                                (Disp: {req.currentStock})
                                                            </span>
                                                        </div>
                                                    {/if}
                                                </td>
                                            </tr>
                                        {/each}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    {/if}
                </div>

            </div>

            <!-- PIE DEL MODAL -->
            <div class="px-6 py-4 border-t border-slate-800 bg-slate-950/80 flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">
                    Cambia la etapa desde el selector superior o arrastrando la tarjeta en el tablero.
                </span>

                <button
                    type="button"
                    onclick={onClose}
                    class="px-5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold transition-colors"
                >
                    Cerrar Ficha
                </button>
            </div>
        </div>
    </div>
{/if}
