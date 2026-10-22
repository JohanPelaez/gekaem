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

    // Unidades de medida ordenadas alfabéticamente (A-Z)
    let sortedUnitsOfMeasure = $derived(
        unitsOfMeasure.toSorted((a, b) => {
            const nameA = typeof a === 'object' && a !== null ? (a.name || '') : String(a || '');
            const nameB = typeof b === 'object' && b !== null ? (b.name || '') : String(b || '');
            return nameA.localeCompare(nameB, 'es');
        })
    );

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
        tax_id: '',
        department: '',
        city: '',
        neighborhood: '',
        address: '',
        notes: '',
        lead_time_days: 1,
        unit_of_measure: 'Unidad',
        unit_cost: 0,
        current_stock: 0,
        abbreviation: '',
        options: '',
        stages: [
            { name: 'Pendiente', color: '#64748b', stage_category: 'pending' },
            { name: 'En Proceso', color: '#3b82f6', stage_category: 'in_progress' },
            { name: 'Terminado', color: '#10b981', stage_category: 'done' },
        ],
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
        const defaultUom = sortedUnitsOfMeasure[0];
        form = {
            name: '',
            description: '',
            phone: '',
            email: '',
            tax_id: '',
            department: '',
            city: '',
            neighborhood: '',
            address: '',
            notes: '',
            lead_time_days: 1,
            unit_of_measure: typeof defaultUom === 'object' ? defaultUom?.name : (defaultUom || 'Unidad'),
            unit_cost: 0,
            current_stock: 0,
            abbreviation: '',
            options: '',
            stages: [
                { name: 'Pendiente', color: '#64748b', stage_category: 'pending' },
                { name: 'En Proceso', color: '#3b82f6', stage_category: 'in_progress' },
                { name: 'Terminado', color: '#10b981', stage_category: 'done' },
            ],
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

    function addWorkflowStage() {
        form.stages = [
            ...form.stages,
            { name: '', color: '#38bdf8', stage_category: 'in_progress' },
        ];
    }

    function removeWorkflowStage(idx) {
        if (form.stages.length <= 1) return;
        form.stages = form.stages.filter((_, i) => i !== idx);
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
        <div class="relative w-full {type === 'workflow' || type === 'customer' ? 'max-w-xl' : 'max-w-md'} bg-slate-900 border border-slate-700/80 rounded-2xl shadow-2xl p-6 text-slate-100 max-h-[90vh] flex flex-col overflow-hidden">
            <!-- Header -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-800 shrink-0">
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
                <div class="mt-4 p-3 bg-rose-950/60 border border-rose-800/80 rounded-xl text-rose-300 text-xs flex items-center gap-2 shrink-0">
                    <svg class="w-4 h-4 flex-shrink-0 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{errorMessage}</span>
                </div>
            {/if}

            <!-- Form -->
            <form onsubmit={handleSubmit} class="mt-4 space-y-4 overflow-y-auto pr-1 flex-1">
                <!-- Nombre principal (Común a todos) -->
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_name">
                        {type === 'customer' ? 'Nombre o Razón Social' : 'Nombre'} <span class="text-rose-400">*</span>
                    </label>
                    <input
                        type="text"
                        id="quick_name"
                        bind:value={form.name}
                        required
                        placeholder={type === 'customer' ? 'Ej. Boutique Urbana / Carlos Mendoza' : 'Ej. Nombre identificativo'}
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
                    </div>

                    <!-- Columnas / Etapas Dinámicas en el Quick Create de Flujo -->
                    <div class="space-y-2 pt-2 border-t border-slate-800">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="block text-xs font-bold text-slate-200">
                                    Columnas / Etapas del Flujo ({form.stages.length})
                                </span>
                                <p class="text-[11px] text-slate-400">Personaliza las etapas que tendrá este flujo en el tablero Kanban.</p>
                            </div>
                            <button
                                type="button"
                                onclick={addWorkflowStage}
                                class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-emerald-400 font-semibold text-xs rounded-lg border border-slate-700 transition-colors flex items-center gap-1"
                            >
                                <span>+</span>
                                <span>Agregar Columna</span>
                            </button>
                        </div>

                        <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
                            <!-- Cabecera de columnas para etapas del flujo -->
                            <div class="hidden sm:flex items-center gap-2 px-2.5 py-1 text-[10px] uppercase font-bold text-slate-400 tracking-wider">
                                <span class="w-5 text-center">#</span>
                                <span class="flex-1">Nombre de la Columna *</span>
                                <span class="w-14 text-center">Color</span>
                                <span class="w-32">Tipo de Etapa</span>
                                <span class="w-6"></span>
                            </div>

                            {#each form.stages as stage, idx}
                                <div
                                    class="p-2.5 bg-slate-950 rounded-xl border border-slate-800 flex flex-col sm:flex-row sm:items-center gap-2"
                                    style="border-left: 3px solid {stage.color || '#64748b'};"
                                >
                                    <div class="flex items-center gap-2 sm:hidden text-[11px] font-bold text-slate-400 mb-1">
                                        <span>Columna #{idx + 1}</span>
                                    </div>
                                    <span class="w-5 text-center font-mono text-[11px] font-bold text-slate-500 shrink-0 hidden sm:inline-block">#{idx + 1}</span>
                                    <div class="flex-1 min-w-0">
                                        <label for="stage_name_{idx}" class="block sm:hidden text-[10px] font-semibold text-slate-400 mb-0.5">Nombre de la Columna *</label>
                                        <input
                                            id="stage_name_{idx}"
                                            type="text"
                                            bind:value={stage.name}
                                            placeholder="Nombre de la Columna"
                                            required
                                            class="w-full px-2.5 py-1.5 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                        />
                                    </div>
                                    <div class="flex items-center justify-between sm:justify-start gap-2 shrink-0">
                                        <div class="flex items-center gap-1.5">
                                            <label for="stage_color_{idx}" class="sm:hidden text-[10px] font-semibold text-slate-400">Color:</label>
                                            <input
                                                id="stage_color_{idx}"
                                                type="color"
                                                bind:value={stage.color}
                                                class="w-7 h-7 rounded cursor-pointer bg-transparent border-0"
                                                title="Color Identificador"
                                            />
                                        </div>
                                        <div>
                                            <label for="stage_cat_{idx}" class="block sm:hidden text-[10px] font-semibold text-slate-400 mb-0.5">Tipo de Etapa</label>
                                            <select
                                                id="stage_cat_{idx}"
                                                bind:value={stage.stage_category}
                                                class="w-32 px-2 py-1.5 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                            >
                                                <option value="pending">Pendiente</option>
                                                <option value="in_progress">En Proceso</option>
                                                <option value="blocked">Bloqueado</option>
                                                <option value="done">Terminado</option>
                                            </select>
                                        </div>
                                        <button
                                            type="button"
                                            onclick={() => removeWorkflowStage(idx)}
                                            disabled={form.stages.length <= 1}
                                            class="p-1 text-slate-500 hover:text-rose-400 disabled:opacity-20 transition-colors"
                                            title="Eliminar columna"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </div>
                            {/each}
                        </div>
                    </div>
                {/if}

                <!-- Campos de Cliente Completo (Puntos 3 y 17) -->
                {#if type === 'customer'}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_phone">Teléfono / WhatsApp</label>
                            <input
                                type="text"
                                id="quick_phone"
                                bind:value={form.phone}
                                placeholder="Ej. +57 300 123 4567"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_email">Correo Electrónico</label>
                            <input
                                type="email"
                                id="quick_email"
                                bind:value={form.email}
                                placeholder="contacto@ejemplo.com"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_dept">Departamento</label>
                            <input
                                type="text"
                                id="quick_dept"
                                bind:value={form.department}
                                placeholder="Ej. Antioquia"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_city">Ciudad / Municipio</label>
                            <input
                                type="text"
                                id="quick_city"
                                bind:value={form.city}
                                placeholder="Ej. Medellín"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_neigh">Barrio</label>
                            <input
                                type="text"
                                id="quick_neigh"
                                bind:value={form.neighborhood}
                                placeholder="Ej. El Poblado"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_address">Dirección de Entrega</label>
                            <input
                                type="text"
                                id="quick_address"
                                bind:value={form.address}
                                placeholder="Ej. Calle 10 # 40-50"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_notes">Notas Internas</label>
                        <textarea
                            id="quick_notes"
                            bind:value={form.notes}
                            rows="2"
                            placeholder="Detalles de entrega, preferencias del cliente..."
                            class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                        ></textarea>
                    </div>
                {/if}

                {#if type === 'supplier'}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_phone_sup">Teléfono</label>
                            <input
                                type="text"
                                id="quick_phone_sup"
                                bind:value={form.phone}
                                placeholder="Ej. 300 123 4567"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1" for="quick_email_sup">Email</label>
                            <input
                                type="email"
                                id="quick_email_sup"
                                bind:value={form.email}
                                placeholder="contacto@ejemplo.com"
                                class="w-full px-3.5 py-2 bg-slate-950/90 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                            />
                        </div>
                    </div>

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
                                    <div>
                                        <label for="quick_inline_uom_name" class="block text-[10px] font-semibold text-slate-400 mb-0.5">Nombre (ej. Metro) *</label>
                                        <input
                                            id="quick_inline_uom_name"
                                            type="text"
                                            bind:value={inlineUomName}
                                            placeholder="Ej. Metro"
                                            class="w-full px-2.5 py-1.5 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                        />
                                    </div>
                                    <div>
                                        <label for="quick_inline_uom_abbr" class="block text-[10px] font-semibold text-slate-400 mb-0.5">Abreviatura (ej. m)</label>
                                        <input
                                            id="quick_inline_uom_abbr"
                                            type="text"
                                            bind:value={inlineUomAbbr}
                                            placeholder="Ej. m"
                                            class="w-full px-2.5 py-1.5 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                        />
                                    </div>
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
                <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-slate-800 shrink-0">
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
