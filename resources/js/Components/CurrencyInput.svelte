<script>
    /**
     * CurrencyInput Component (Svelte 5 Runes)
     * Displays real-time thousand separators (e.g. "$ 150.000") while binding the raw numeric value.
     */
    let {
        value = $bindable(0),
        id = '',
        name = '',
        placeholder = '0',
        required = false,
        disabled = false,
        min = 0,
        class: customClass = '',
    } = $props();

    function formatNumber(val) {
        if (val === null || val === undefined || val === '') return '';
        const num = Number(val);
        if (isNaN(num)) return '';
        // Separador de miles usando formato estándar (ej. 150.000 en COP/es-CO)
        return new Intl.NumberFormat('es-CO', {
            maximumFractionDigits: 2,
        }).format(num);
    }

    let displayValue = $state(formatNumber(value));

    // Sincronizar displayValue si value cambia externamente (ej. al editar)
    $effect(() => {
        const expected = formatNumber(value);
        // Si el valor numérico difiere significativamente del valor en pantalla
        const rawFromDisplay = parseRaw(displayValue);
        if (Number(value || 0) !== rawFromDisplay) {
            displayValue = expected;
        }
    });

    function parseRaw(str) {
        if (!str) return 0;
        // Eliminar caracteres no numéricos excepto coma o punto decimal si aplica
        // En Colombia / es-CO: el punto es separador de miles.
        // Si hay comas, convertirlas a punto decimal.
        const cleanStr = String(str).replace(/[^\d]/g, '');
        return cleanStr ? Number(cleanStr) : 0;
    }

    function handleInput(e) {
        const inputVal = e.target.value;
        const raw = parseRaw(inputVal);
        value = raw;
        displayValue = raw > 0 ? formatNumber(raw) : (inputVal === '' ? '' : '0');
    }
</script>

<div class="relative rounded-xl shadow-inner">
    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400 font-bold text-sm">
        $
    </div>
    <input
        type="text"
        inputmode="numeric"
        {id}
        {name}
        {placeholder}
        {required}
        {disabled}
        value={displayValue}
        oninput={handleInput}
        class="w-full pl-8 pr-4 py-2.5 bg-slate-950/80 border border-slate-700/80 rounded-xl text-slate-100 placeholder-slate-500 font-semibold focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 transition-all {customClass}"
    />
</div>
