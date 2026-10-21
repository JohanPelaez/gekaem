<script>
    import CurrencyInput from './CurrencyInput.svelte';
    import { csrfFetch } from '../Utils/csrfFetch.js';

    /**
     * QuickCreateModal Component (Svelte 5 Runes)
     * Secondary floating modal for context creation in dropdowns without resetting parent state.
     */
    let {
        isOpen = $bindable(false),
        type = 'customer', // 'product-type' | 'workflow' | 'supplier' | 'customer' | 'raw-material' | 'units-of-measure' | 'attribute'
        title = '',
        unitsOfMeasure = [
            'unidad', 'par', 'docena', 'metro', 'centimetro', 'rollo',
            'lamina', 'pliego', 'gramo', 'kilogramo', 'litro', 'mililitro', 'paquete'
        ],
        onSuccess,
    } = $props();

    let loading = $state(false);
    let errorMessage = $state('');

    // Estado para creación rápida inline de unidad de medida si se requiere
    let showInlineUom = $state(false);
    let inlineUomName = $state('');
    let inlineUomAbbr = $state('');
    let inlineUomLoading = $state(false);

    // Estado local para cada tipo de entidad
    let form = $state({
        name: '',
        description: '',
        phone: '',
        email: '',
        lead_time_days: 1,
        unit_of_measure: 'Unidad',
        unit_cost: 0,
        current_stock: 0,
        abbreviation: '',
        options: '',
    });

    const modalConfig = $derived.by(() => {
        switch (type) {
            case 'product-type':
                return {
                    title: title || 'Nuevo Tipo de Producto',
                    endpoint: '/quick/product-types',
                    badge: 'Categoría',
                };
            case 'workflow':
                return {
                    title: title || 'Nuevo Flujo Kanban',
                    endpoint: '/quick/workflows',
                    badge: 'Flujo de Taller',
                };
            case 'supplier':
                return {
                    title: title || 'Nuevo Proveedor',
                    endpoint: '/quick/suppliers',
                    badge: 'Abastecimiento',
                };
            case 'customer':
                return {
                    title: title || 'Nuevo Cliente',
                    endpoint: '/quick/customers',
                    badge: 'Contacto',
                };
            case 'raw-material':
                return {
                    title: title || 'Nueva Materia Prima',
                    endpoint: '/quick/raw-materials',
                    badge: 'Inventario',
                };
            case 'units-of-measure':
                return {
                    title: title || 'Nueva Unidad de Medida',
                    endpoint: '/quick/units-of-measure',
                    badge: 'Unidad',
                };
            case 'attribute':
                return {
                    title: title || 'Nuevo Atributo',
                    endpoint: '/quick/attributes',
                    badge: 'Atributo',
                };
            default:
                return {
                    title: title || 'Creación Rápida',
                    endpoint: '/quick/customers',
                    badge: 'Registro',
                };
        }
    });

    function resetForm() {
        form = {
            name: '',
            description: '',
            phone: '',
            email: '',
            lead_time_days: 1,
            unit_of_measure: typeof unitsOfMeasure[0] === 'object' ? unitsOfMeasure[0]?.name : (unitsOfMeasure[0] || 'Unidad'),
            unit_cost: 0,
            current_stock: 0,
            abbreviation: '',
            options: '',
        };
        showInlineUom = false;
        inlineUomName = '';
        inlineUomAbbr = '';
        errorMessage = '';
    }

    function closeModal() {
        isOpen = false;
        resetForm();
    }

    async function handleSubmit(e) {
        e.preventDefault();
        loading = true;
        errorMessage = '';

        try {
            const res = await csrfFetch(modalConfig.endpoint, {
                method: 'POST',
                body: JSON.stringify(form),
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                if (data.errors && typeof data.errors === 'object') {
                    const firstFieldErrors = Object.values(data.errors)[0];
                    errorMessage = Array.isArray(firstFieldErrors)
                        ? firstFieldErrors[0]
                        : (data.message || 'Los datos proporcionados no son válidos.');
                } else {
                    errorMessage = data.message || `Error del servidor (${res.status}). Por favor intenta nuevamente.`;
                }
                loading = false;
                return;
            }

            if (typeof onSuccess === 'function') {
                onSuccess(data);
            }

            closeModal();
        } catch (err) {
            errorMessage = err?.message || 'Error de red o conexión al servidor.';
        } finally {
            loading = false;
        }
    }

    async function handleQuickInlineUom() {
        if (!inlineUomName.trim()) return;
        inlineUomLoading = true;
        try {
            const res = await csrfFetch('/quick/units-of-measure', {
                method: 'POST',
                body: JSON.stringify({
                    name: inlineUomName.trim(),
                    abbreviation: inlineUomAbbr.trim() || null,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.name) {
                unitsOfMeasure.push(data);
                form.unit_of_measure = data.name;
                inlineUomName = '';
                inlineUomAbbr = '';
                showInlineUom = false;
            }
        } catch (e) {
            console.error('Error creating inline UOM:', e);
        } finally {
            inlineUomLoading = false;
        }
    }
</script>

{#if isOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm animate-fade-in" role="dialog" aria-modal="true">
        <div class="relative w-full max-w-md bg-slate-900 border border-slate-700/80 rounded-2xl shadow-2xl p-6 text-slate-100 overflow-hidden">
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-800">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 font-bold text-sm">
                        +
                    </div>
                    <div>
                        <span class="text-[10px] uppercase font-bold tracking-wider text-emerald-400 block">{modalConfig.badge}</span>
                        <h3 class="text-base font-bold text-white leading-tight">{modalConfig.title}</h3>
                    </div>
                </div>
                <button
                    type="button"
                    onclick={closeModal}
                    aria-label="Cerrar modal"
                    class="text-slate-400 hover:text-white p-1 rounded-lg hover:bg-slate-800 transition-colors"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Error banner -->
            {#if errorMessage}
                <div class="mt-4 p-3 bg-rose-950/60 border border-rose-800/80 rounded-xl text-rose-300 text-xs flex items-center gap-2">
                    <svg class="w-4 h-4 flex-shrink-0 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{errorMessage}</span>
                </div>
            {/if}

            <!-- Form -->
            <form onsubmit={handleSubmit} class="mt-4 space-y-4">
                <!-- Nombre principal (Común a todos) -->
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_name">
                        Nombre <span class="text-rose-400">*</span>
                    </label>
                    <input
                        type="text"
                        id="quick_name"
                        bind:value={form.name}
                        required
                        placeholder="Ej. Nombre identificativo"
                        class="w-full px-3.5 py-2.5 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 transition-all font-medium"
                    />
                </div>

                <!-- Campos específicos según tipo -->
                {#if type === 'workflow'}
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_desc">
                            Descripción / Nota (Opcional)
                        </label>
                        <input
                            type="text"
                            id="quick_desc"
                            bind:value={form.description}
                            placeholder="Ej. Flujo estándar de ensamble"
                            class="w-full px-3.5 py-2.5 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 transition-all"
                        />
                        <p class="text-[11px] text-slate-400 mt-1">Se crearán automáticamente las etapas <em>Pendiente</em>, <em>En Proceso</em> y <em>Terminado</em>.</p>
                    </div>
                {/if}

                {#if type === 'customer' || type === 'supplier'}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_phone">Teléfono</label>
                            <input
                                type="text"
                                id="quick_phone"
                                bind:value={form.phone}
                                placeholder="Ej. 300 123 4567"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_email">Email</label>
                            <input
                                type="email"
                                id="quick_email"
                                bind:value={form.email}
                                placeholder="contacto@ejemplo.com"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                    </div>
                {/if}

                {#if type === 'supplier'}
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_lead">
                            Tiempo de Entrega / Lead Time (Días)
                        </label>
                        <input
                            type="number"
                            id="quick_lead"
                            bind:value={form.lead_time_days}
                            min="0"
                            max="365"
                            class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                        />
                    </div>
                {/if}

                {#if type === 'units-of-measure'}
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_abbr">
                            Abreviatura (Opcional)
                        </label>
                        <input
                            type="text"
                            id="quick_abbr"
                            bind:value={form.abbreviation}
                            placeholder="Ej. m, kg, und, rollo"
                            class="w-full px-3.5 py-2.5 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                        />
                    </div>
                {/if}

                {#if type === 'attribute'}
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_options">
                            Opciones Iniciales (Separadas por coma)
                        </label>
                        <input
                            type="text"
                            id="quick_options"
                            bind:value={form.options}
                            placeholder="Ej. S, M, L, XL ó Rojo, Azul, Verde"
                            class="w-full px-3.5 py-2.5 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                        />
                        <p class="text-[11px] text-slate-400 mt-1">Escribe las opciones separadas por comas. Podrás agregar más en cualquier momento.</p>
                    </div>
                {/if}

                {#if type === 'raw-material'}
                    <div class="space-y-2">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs font-semibold text-slate-300" for="quick_uom">
                                        Unidad <span class="text-rose-400">*</span>
                                    </label>
                                    <button
                                        type="button"
                                        onclick={() => (showInlineUom = !showInlineUom)}
                                        class="text-[10px] text-emerald-400 hover:text-emerald-300 font-bold transition-colors"
                                    >
                                        {showInlineUom ? 'Cerrar' : '+ Nueva'}
                                    </button>
                                </div>
                                <select
                                    id="quick_uom"
                                    bind:value={form.unit_of_measure}
                                    required
                                    class="w-full px-3 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white focus:outline-none focus:ring-2 focus:ring-emerald-500/50 capitalize"
                                >
                                    {#each unitsOfMeasure as uom}
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
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_cost">Costo Unitario ($)</label>
                                <CurrencyInput bind:value={form.unit_cost} id="quick_cost" placeholder="0" />
                            </div>
                        </div>

                        {#if showInlineUom}
                            <div class="p-3 bg-slate-950/80 border border-emerald-500/30 rounded-xl space-y-2 animate-fade-in">
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] font-bold text-emerald-400 uppercase tracking-wider">Crear Nueva Unidad</span>
                                    <button type="button" onclick={() => (showInlineUom = false)} class="text-slate-400 hover:text-white text-xs">✕</button>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <input
                                        type="text"
                                        bind:value={inlineUomName}
                                        placeholder="Nombre (ej. Metro)"
                                        class="px-2.5 py-1.5 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                    />
                                    <input
                                        type="text"
                                        bind:value={inlineUomAbbr}
                                        placeholder="Abrev. (ej. m)"
                                        class="px-2.5 py-1.5 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                    />
                                </div>
                                <button
                                    type="button"
                                    onclick={handleQuickInlineUom}
                                    disabled={inlineUomLoading || !inlineUomName.trim()}
                                    class="w-full py-1.5 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white rounded-lg text-xs font-semibold transition-colors"
                                >
                                    {inlineUomLoading ? 'Guardando...' : 'Crear y Seleccionar Unidad'}
                                </button>
                            </div>
                        {/if}
                    </div>
                {/if}

                <!-- Actions -->
                <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-slate-800">
                    <button
                        type="button"
                        onclick={closeModal}
                        disabled={loading}
                        class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-semibold transition-colors"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        disabled={loading}
                        class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold transition-all shadow-lg shadow-emerald-900/30 flex items-center gap-1.5"
                    >
                        {#if loading}
                            <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>Guardando...</span>
                        {:else}
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>Guardar Registro</span>
                        {/if}
                    </button>
                </div>
            </form>
        </div>
    </div>
{/if}
