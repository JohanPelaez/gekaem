<script>
    import JsBarcode from 'jsbarcode';
    import { downloadBarcodeAsPng, validateBarcodeForFormat } from '../Utils/barcodeHelper.js';

    /**
     * BarcodeViewer Component (Svelte 5 Runes)
     * Renders barcode using JsBarcode on an SVG element with optional PNG download.
     */
    let {
        value = '',
        format = 'CODE128',
        width = 1.6,
        height = 36,
        displayValue = true,
        fontSize = 11,
        textColor = '#94a3b8',
        lineColor = '#38bdf8',
        productName = '',
        showDownload = false,
        class: customClass = '',
    } = $props();

    let svgElement = $state(null);
    let error = $state(null);

    $effect(() => {
        if (!svgElement) return;

        if (!value || typeof value !== 'string' || value.trim() === '') {
            error = null;
            return;
        }

        const trimmed = value.trim();
        const validation = validateBarcodeForFormat(trimmed, format || 'CODE128');

        if (!validation.valid) {
            error = validation.message;
            return;
        }

        try {
            error = null;
            JsBarcode(svgElement, trimmed, {
                format: format || 'CODE128',
                width: Number(width) || 1.6,
                height: Number(height) || 36,
                displayValue: Boolean(displayValue),
                fontOptions: 'bold',
                font: 'monospace',
                textAlign: 'center',
                textPosition: 'bottom',
                textMargin: 3,
                fontSize: Number(fontSize) || 11,
                background: 'transparent',
                lineColor: lineColor || '#38bdf8',
                textColor: textColor || '#94a3b8',
                margin: 4,
            });
        } catch (err) {
            error = err?.message || 'Código incompatible con el formato seleccionado';
        }
    });

    function handleDownload() {
        if (!error && value) {
            downloadBarcodeAsPng(value, format || 'CODE128', productName);
        }
    }
</script>

<div class="inline-flex flex-col items-center justify-center p-2 rounded-xl bg-slate-950/80 border border-slate-800/80 shadow-inner group relative {customClass}">
    {#if error}
        <div class="text-[11px] text-amber-400 font-mono py-1 px-2.5 bg-amber-950/40 border border-amber-800/50 rounded-lg flex items-center gap-1.5 text-center">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>{error}</span>
        </div>
    {:else}
        <svg bind:this={svgElement} class="max-w-full overflow-visible"></svg>
        {#if showDownload && value}
            <button
                type="button"
                onclick={handleDownload}
                class="mt-1 px-2.5 py-1 rounded-lg bg-slate-900 hover:bg-slate-800 border border-slate-700/80 text-[10px] text-slate-300 hover:text-emerald-400 transition-colors flex items-center gap-1 font-semibold"
                title="Descargar código de barras como imagen PNG"
            >
                <svg class="w-3 h-3 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                <span>Descargar PNG</span>
            </button>
        {/if}
    {/if}
</div>
