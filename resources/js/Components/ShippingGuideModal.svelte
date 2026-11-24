<script>
    import { untrack } from 'svelte';
    import { page } from '@inertiajs/svelte';
    import { csrfFetch } from '../Utils/csrfFetch.js';
    import {
        formatDateDDMMYYYY,
        printShippingGuide,
        downloadShippingGuidePng,
    } from '../Utils/shippingGuideExport.js';

    let {
        open = false,
        customer = null,
        order = null,
        initialOrder = null,
        senderProfile = null,
        onClose = () => {},
        onSenderProfileSaved = () => {},
    } = $props();

    let resolvedOrder = $derived(initialOrder ?? order);
    let resolvedSenderProfile = $derived(senderProfile ?? page.props.senderProfile ?? null);

    function getTodayIso() {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    let selectedOrderId = $state('');
    let guideNumber = $state('');
    let guideDate = $state(getTodayIso());
    let paymentMode = $state('contraentrega'); // 'origen' | 'contraentrega'
    let observations = $state('');
    let printFormat = $state('thermal'); // 'thermal' | 'letter'

    let senderOpen = $state(false);
    let recipientOpen = $state(false);

    let sender = $state({
        shipping_header: '',
        name: '',
        tax_id: '',
        phone: '',
        email: '',
        address: '',
        city: '',
        department: '',
    });

    let recipient = $state({
        name: '',
        document_number: '',
        phone: '',
        email: '',
        address: '',
        neighborhood: '',
        city: '',
        department: '',
    });

    let generatingNumber = $state(false);
    let savingSender = $state(false);
    let numberFeedback = $state({ type: '', message: '' });
    let senderFeedback = $state({ type: '', message: '' });

    let recentOrders = $derived.by(() => {
        const list = [];
        const ord = resolvedOrder;
        if (ord && ord.id) {
            list.push({
                id: ord.id,
                order_number: ord.order_number,
                shipping_type: ord.shipping_type ?? 'contraentrega',
                promised_date: ord.promised_date ?? null,
                notes: ord.notes ?? '',
            });
        }
        const custOrders = customer?.recent_orders ?? [];
        for (const o of custOrders) {
            if (!list.some((x) => String(x.id) === String(o.id))) {
                list.push(o);
            }
        }
        return list;
    });

    let formattedDate = $derived(formatDateDDMMYYYY(guideDate));
    let headerDisplay = $derived(
        (sender.shipping_header || sender.name || 'LOGÍSTICA & ENVÍOS EXPRESS').trim().toUpperCase(),
    );
    let senderCityDept = $derived(
        [sender.city, sender.department].map((v) => String(v || '').trim()).filter(Boolean).join(', '),
    );
    let missingSenderData = $derived(
        !String(sender.tax_id || '').trim() || !String(sender.address || '').trim(),
    );
    let missingRecipientData = $derived(
        !String(recipient.phone || '').trim() || !String(recipient.address || '').trim(),
    );

    let initializedKey = null;
    $effect(() => {
        const isOpen = open;
        const custId = customer?.id ?? null;
        const activeOrd = resolvedOrder;
        const ordId = activeOrd?.id ?? null;

        if (!isOpen) {
            initializedKey = null;
            return;
        }

        const key = `${custId}-${ordId}`;
        if (custId && key !== initializedKey) {
            initializedKey = key;
            untrack(() => {
                // Sender profile
                const sp = resolvedSenderProfile ?? {};
                sender.shipping_header = sp.shipping_header ?? '';
                sender.name = sp.name ?? '';
                sender.tax_id = sp.tax_id ?? '';
                sender.phone = sp.phone ?? '';
                sender.email = sp.email ?? '';
                sender.address = sp.address ?? '';
                sender.city = sp.city ?? '';
                sender.department = sp.department ?? '';

                // Open sender accordion automatically if key data is missing
                senderOpen = !String(sp.tax_id || '').trim() || !String(sp.address || '').trim();
                // Open recipient accordion automatically if phone or address is missing
                recipientOpen = !String(customer?.phone || '').trim() || !String(customer?.address || '').trim();

                // Recipient (from customer)
                recipient.name = customer?.name ?? '';
                recipient.document_number = customer?.document_number || customer?.tax_id || '';
                recipient.phone = customer?.phone ?? '';
                recipient.email = customer?.email ?? '';
                recipient.address = customer?.address ?? '';
                recipient.neighborhood = customer?.neighborhood ?? '';
                recipient.city = customer?.city ?? '';
                recipient.department = customer?.department ?? '';

                // Guide defaults
                const seq = Number(sp.shipping_guide_sequence || 1000000000);
                guideNumber = String(seq + 1);
                guideDate = getTodayIso();
                numberFeedback = { type: '', message: '' };
                senderFeedback = { type: '', message: '' };

                if (activeOrd && activeOrd.id) {
                    selectedOrderId = String(activeOrd.id);
                    paymentMode = activeOrd.shipping_type === 'envio_incluido' ? 'origen' : 'contraentrega';
                    observations = customer?.notes || activeOrd.notes || '';
                } else {
                    selectedOrderId = '';
                    paymentMode = 'contraentrega';
                    observations = customer?.notes ?? '';
                }
            });
        }
    });

    function handleOrderSelectChange(e) {
        const val = e.target.value;
        selectedOrderId = val;
        if (!val) return;
        const found = recentOrders.find((o) => String(o.id) === String(val));
        if (found) {
            paymentMode = found.shipping_type === 'envio_incluido' ? 'origen' : 'contraentrega';
            if (!observations.trim() && found.notes) {
                observations = found.notes;
            }
        }
    }

    async function generateNextGuideNumber() {
        if (!customer?.id || generatingNumber) return;
        generatingNumber = true;
        numberFeedback = { type: '', message: '' };
        try {
            const res = await csrfFetch(`/customers/${customer.id}/shipping-guide/next-number`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                numberFeedback = {
                    type: 'error',
                    message: data.message || 'No se pudo generar el consecutivo.',
                };
                return;
            }
            if (data.guide_number) {
                guideNumber = String(data.guide_number);
            }
            if (data.shipping_guide_sequence) {
                onSenderProfileSaved({
                    ...(senderProfile ?? {}),
                    ...sender,
                    shipping_guide_sequence: data.shipping_guide_sequence,
                });
            }
            numberFeedback = {
                type: 'success',
                message: `Consecutivo #${guideNumber} asignado.`,
            };
        } catch (err) {
            numberFeedback = {
                type: 'error',
                message: err?.message || 'Error de conexión al generar el consecutivo.',
            };
        } finally {
            generatingNumber = false;
        }
    }

    async function saveSenderAsDefault() {
        if (savingSender) return;
        savingSender = true;
        senderFeedback = { type: '', message: '' };
        try {
            const res = await csrfFetch('/settings/sender-profile', {
                method: 'PUT',
                body: JSON.stringify(sender),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                if (data.errors && typeof data.errors === 'object') {
                    const firstErr = Object.values(data.errors)[0];
                    senderFeedback = {
                        type: 'error',
                        message: Array.isArray(firstErr) ? firstErr[0] : String(firstErr),
                    };
                } else {
                    senderFeedback = {
                        type: 'error',
                        message: data.message || 'No se pudieron guardar los datos del remitente.',
                    };
                }
                return;
            }
            if (data.senderProfile) {
                onSenderProfileSaved(data.senderProfile);
            }
            senderFeedback = {
                type: 'success',
                message: data.message || 'Datos del remitente guardados.',
            };
        } catch (err) {
            senderFeedback = {
                type: 'error',
                message: err?.message || 'Error de conexión al guardar.',
            };
        } finally {
            savingSender = false;
        }
    }

    function handlePrint() {
        printShippingGuide({
            guideNumber,
            formattedDate,
            paymentMode,
            sender,
            recipient,
            observations,
            printFormat,
        });
    }

    function handleDownloadPng() {
        downloadShippingGuidePng({
            guideNumber,
            formattedDate,
            paymentMode,
            sender,
            recipient,
            observations,
        });
    }

    function handleKeydown(e) {
        if (open && e.key === 'Escape') {
            onClose();
        }
    }
</script>

<svelte:window onkeydown={handleKeydown} />

{#if open && customer}
    <div
        class="fixed inset-0 z-[70] bg-slate-900/70 dark:bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-3 sm:p-5 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="shipping-guide-modal-title"
    >
        <div
            class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-slate-100 rounded-3xl max-w-5xl w-full shadow-2xl flex flex-col max-h-[94vh] overflow-hidden"
        >
            <!-- HEADER DEL MODAL -->
            <div class="flex items-center justify-between gap-3 px-5 sm:px-6 py-4 border-b border-slate-200 dark:border-[#26334D] shrink-0">
                <div class="min-w-0">
                    <h2 id="shipping-guide-modal-title" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <span>🏷️</span>
                        <span class="truncate">Guía de Envío — {customer.name}</span>
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        Configura la modalidad de pago, verifica remitente/destinatario e imprime o descarga en PNG de alto contraste.
                    </p>
                </div>
                <button
                    type="button"
                    onclick={onClose}
                    class="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-300 flex items-center justify-center text-sm font-bold shrink-0 transition"
                    aria-label="Cerrar modal"
                >
                    ✕
                </button>
            </div>

            <!-- CUERPO 2 COLUMNAS -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 p-5 sm:p-6 overflow-y-auto flex-1">
                <!-- COLUMNA IZQUIERDA (5 cols): CONFIGURACIÓN RÁPIDA -->
                <div class="lg:col-span-5 space-y-4 text-xs">
                    <!-- Selector opcional de pedido -->
                    {#if recentOrders.length > 0}
                        <div>
                            <label for="sg-order-select" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                Vincular a Pedido del Cliente (Opcional)
                            </label>
                            <select
                                id="sg-order-select"
                                value={selectedOrderId}
                                onchange={handleOrderSelectChange}
                                class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-white rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-[#4F46E5]"
                            >
                                <option value="">Sin vincular a pedido</option>
                                {#each recentOrders as ord (ord.id)}
                                    <option value={String(ord.id)}>
                                        Pedido #{ord.order_number} · {ord.shipping_type === 'envio_incluido' ? 'Envío Incluido' : 'Contraentrega'}{ord.promised_date ? ` (${ord.promised_date})` : ''}
                                    </option>
                                {/each}
                            </select>
                        </div>
                    {/if}

                    <!-- GUÍA N.º + FECHA -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="sg-guide-number" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                GUÍA N.º
                            </label>
                            <div class="flex items-center gap-1.5">
                                <input
                                    id="sg-guide-number"
                                    type="text"
                                    bind:value={guideNumber}
                                    placeholder="1000000001"
                                    class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-white rounded-xl px-3 py-2 text-xs font-mono font-bold focus:outline-none focus:border-[#4F46E5]"
                                />
                                <button
                                    type="button"
                                    onclick={generateNextGuideNumber}
                                    disabled={generatingNumber}
                                    title="Incrementar y asignar el siguiente consecutivo oficial del taller"
                                    class="px-2.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-[#26334D] font-semibold text-[11px] whitespace-nowrap shrink-0 transition disabled:opacity-50"
                                >
                                    {generatingNumber ? '...' : '↻ Consecutivo'}
                                </button>
                            </div>
                            {#if numberFeedback.message}
                                <p class="text-[11px] mt-1 font-medium {numberFeedback.type === 'success' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-500'}">
                                    {numberFeedback.message}
                                </p>
                            {/if}
                        </div>

                        <div>
                            <label for="sg-guide-date" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                FECHA
                            </label>
                            <input
                                id="sg-guide-date"
                                type="date"
                                bind:value={guideDate}
                                class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-white rounded-xl px-3 py-2 text-xs font-mono focus:outline-none focus:border-[#4F46E5]"
                            />
                        </div>
                    </div>

                    <!-- MODALIDAD DE PAGO -->
                    <div>
                        <span class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                            MODALIDAD DE PAGO
                        </span>
                        <div class="grid grid-cols-1 gap-2">
                            <button
                                type="button"
                                onclick={() => (paymentMode = 'origen')}
                                class="w-full text-left px-3.5 py-2.5 rounded-xl border transition-all flex items-center gap-2.5 {paymentMode === 'origen'
                                    ? 'bg-[#4F46E5]/10 border-[#4F46E5] text-[#4F46E5] dark:text-indigo-300 font-bold'
                                    : 'bg-slate-50 dark:bg-[#0B0F17] border-slate-200 dark:border-[#26334D] text-slate-700 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-600'}"
                            >
                                <span class="font-mono font-black text-xs">{paymentMode === 'origen' ? '[ X ]' : '[   ]'}</span>
                                <span>PAGADO EN ORIGEN (Punto de Envío)</span>
                            </button>
                            <button
                                type="button"
                                onclick={() => (paymentMode = 'contraentrega')}
                                class="w-full text-left px-3.5 py-2.5 rounded-xl border transition-all flex items-center gap-2.5 {paymentMode === 'contraentrega'
                                    ? 'bg-[#4F46E5]/10 border-[#4F46E5] text-[#4F46E5] dark:text-indigo-300 font-bold'
                                    : 'bg-slate-50 dark:bg-[#0B0F17] border-slate-200 dark:border-[#26334D] text-slate-700 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-600'}"
                            >
                                <span class="font-mono font-black text-xs">{paymentMode === 'contraentrega' ? '[ X ]' : '[   ]'}</span>
                                <span>CONTRA ENTREGA (Cobro en Destino)</span>
                            </button>
                        </div>
                    </div>

                    <!-- OBSERVACIONES -->
                    <div>
                        <label for="sg-observations" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                            OBSERVACIONES
                        </label>
                        <textarea
                            id="sg-observations"
                            bind:value={observations}
                            rows="2"
                            placeholder="Ej. Dejar en portería si no se encuentra."
                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] text-slate-900 dark:text-white placeholder-slate-400 rounded-xl px-3 py-2 text-xs focus:outline-none focus:border-[#4F46E5]"
                        ></textarea>
                    </div>

                    <!-- ACORDEÓN 1: EDITAR DATOS DEL REMITENTE (ORIGEN) -->
                    <div class="rounded-2xl border border-slate-200 dark:border-[#26334D] overflow-hidden bg-slate-50/70 dark:bg-[#0B0F17]/60">
                        <button
                            type="button"
                            onclick={() => (senderOpen = !senderOpen)}
                            class="w-full px-3.5 py-2.5 flex items-center justify-between gap-2 text-left font-semibold text-slate-800 dark:text-slate-100 hover:bg-slate-100/70 dark:hover:bg-slate-800/40 transition"
                        >
                            <span class="flex items-center gap-1.5">
                                <span>🏢</span>
                                <span>Editar Datos del Remitente (Origen)</span>
                            </span>
                            <span class="text-slate-400 text-[11px]">{senderOpen ? '▲' : '▼'}</span>
                        </button>

                        {#if missingSenderData}
                            <div class="px-3.5 py-2 bg-amber-50 dark:bg-amber-950/40 border-t border-amber-200 dark:border-amber-800/50 text-amber-800 dark:text-amber-200 text-[11px] font-medium">
                                ⚠️ Completa los datos de tu taller para que aparezcan en la guía.
                            </div>
                        {/if}

                        {#if senderOpen}
                            <div class="p-3.5 space-y-2.5 border-t border-slate-200 dark:border-[#26334D] bg-white dark:bg-[#151D2C]">
                                <div>
                                    <label for="sg-s-header" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Encabezado</label>
                                    <input
                                        id="sg-s-header"
                                        type="text"
                                        bind:value={sender.shipping_header}
                                        placeholder="LOGÍSTICA & ENVÍOS EXPRESS"
                                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                    />
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <div>
                                        <label for="sg-s-name" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Nombre / Taller</label>
                                        <input
                                            id="sg-s-name"
                                            type="text"
                                            bind:value={sender.name}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                    <div>
                                        <label for="sg-s-tax" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">NIT / Cédula</label>
                                        <input
                                            id="sg-s-tax"
                                            type="text"
                                            bind:value={sender.tax_id}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs font-mono text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <div>
                                        <label for="sg-s-phone" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Celular</label>
                                        <input
                                            id="sg-s-phone"
                                            type="text"
                                            bind:value={sender.phone}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                    <div>
                                        <label for="sg-s-email" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Email</label>
                                        <input
                                            id="sg-s-email"
                                            type="email"
                                            bind:value={sender.email}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label for="sg-s-addr" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Dirección</label>
                                    <input
                                        id="sg-s-addr"
                                        type="text"
                                        bind:value={sender.address}
                                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                    />
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <div>
                                        <label for="sg-s-city" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Ciudad</label>
                                        <input
                                            id="sg-s-city"
                                            type="text"
                                            bind:value={sender.city}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                    <div>
                                        <label for="sg-s-dept" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Departamento</label>
                                        <input
                                            id="sg-s-dept"
                                            type="text"
                                            bind:value={sender.department}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                </div>

                                {#if senderFeedback.message}
                                    <p class="text-[11px] font-semibold {senderFeedback.type === 'success' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-500'}">
                                        {senderFeedback.type === 'success' ? '✓ ' : '⚠ '}{senderFeedback.message}
                                    </p>
                                {/if}

                                <div class="pt-1">
                                    <button
                                        type="button"
                                        onclick={saveSenderAsDefault}
                                        disabled={savingSender}
                                        class="w-full px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-100 border border-slate-200 dark:border-[#26334D] font-semibold text-[11px] transition disabled:opacity-50"
                                    >
                                        {savingSender ? 'Guardando...' : '💾 Guardar como datos predeterminados del taller'}
                                    </button>
                                </div>
                            </div>
                        {/if}
                    </div>

                    <!-- ACORDEÓN 2: AJUSTAR DATOS DEL DESTINATARIO -->
                    <div class="rounded-2xl border border-slate-200 dark:border-[#26334D] overflow-hidden bg-slate-50/70 dark:bg-[#0B0F17]/60">
                        <button
                            type="button"
                            onclick={() => (recipientOpen = !recipientOpen)}
                            class="w-full px-3.5 py-2.5 flex items-center justify-between gap-2 text-left font-semibold text-slate-800 dark:text-slate-100 hover:bg-slate-100/70 dark:hover:bg-slate-800/40 transition"
                        >
                            <span class="flex items-center gap-1.5">
                                <span>👤</span>
                                <span>Ajustar Datos del Destinatario (solo para esta guía)</span>
                            </span>
                            <span class="text-slate-400 text-[11px]">{recipientOpen ? '▲' : '▼'}</span>
                        </button>

                        {#if missingRecipientData}
                            <div class="px-3.5 py-2 bg-amber-50 dark:bg-amber-950/40 border-t border-amber-200 dark:border-amber-800/50 text-amber-800 dark:text-amber-200 text-[11px] font-medium">
                                ⚠️ Faltan datos del destinatario (celular o dirección). Puedes completarlos aquí antes de imprimir o descargar.
                            </div>
                        {/if}

                        {#if recipientOpen}
                            <div class="p-3.5 space-y-2.5 border-t border-slate-200 dark:border-[#26334D] bg-white dark:bg-[#151D2C]">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <div>
                                        <label for="sg-r-name" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Nombre</label>
                                        <input
                                            id="sg-r-name"
                                            type="text"
                                            bind:value={recipient.name}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                    <div>
                                        <label for="sg-r-doc" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Cédula / NIT</label>
                                        <input
                                            id="sg-r-doc"
                                            type="text"
                                            bind:value={recipient.document_number}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs font-mono text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <div>
                                        <label for="sg-r-phone" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Celular</label>
                                        <input
                                            id="sg-r-phone"
                                            type="text"
                                            bind:value={recipient.phone}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                    <div>
                                        <label for="sg-r-email" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Email</label>
                                        <input
                                            id="sg-r-email"
                                            type="email"
                                            bind:value={recipient.email}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label for="sg-r-addr" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Dirección</label>
                                    <input
                                        id="sg-r-addr"
                                        type="text"
                                        bind:value={recipient.address}
                                        class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                    />
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                                    <div>
                                        <label for="sg-r-neigh" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Barrio</label>
                                        <input
                                            id="sg-r-neigh"
                                            type="text"
                                            bind:value={recipient.neighborhood}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                    <div>
                                        <label for="sg-r-city" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Ciudad</label>
                                        <input
                                            id="sg-r-city"
                                            type="text"
                                            bind:value={recipient.city}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                    <div>
                                        <label for="sg-r-dept" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Departamento</label>
                                        <input
                                            id="sg-r-dept"
                                            type="text"
                                            bind:value={recipient.department}
                                            class="w-full bg-slate-50 dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-1.5 text-xs text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5]"
                                        />
                                    </div>
                                </div>
                            </div>
                        {/if}
                    </div>

                    <!-- FORMATO DE IMPRESIÓN -->
                    <div>
                        <span class="block font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                            Formato de Impresión
                        </span>
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                onclick={() => (printFormat = 'thermal')}
                                class="px-3 py-2 rounded-xl border text-[11px] font-semibold transition-all {printFormat === 'thermal'
                                    ? 'bg-[#4F46E5]/10 border-[#4F46E5] text-[#4F46E5] dark:text-indigo-300'
                                    : 'bg-slate-50 dark:bg-[#0B0F17] border-slate-200 dark:border-[#26334D] text-slate-600 dark:text-slate-400'}"
                            >
                                🏷️ Térmica (10 x 15 cm)
                            </button>
                            <button
                                type="button"
                                onclick={() => (printFormat = 'letter')}
                                class="px-3 py-2 rounded-xl border text-[11px] font-semibold transition-all {printFormat === 'letter'
                                    ? 'bg-[#4F46E5]/10 border-[#4F46E5] text-[#4F46E5] dark:text-indigo-300'
                                    : 'bg-slate-50 dark:bg-[#0B0F17] border-slate-200 dark:border-[#26334D] text-slate-600 dark:text-slate-400'}"
                            >
                                📄 Hoja Carta / A4
                            </button>
                        </div>
                    </div>

                    <!-- BOTONES DE ACCIÓN FINALES -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 pt-2">
                        <button
                            type="button"
                            onclick={handlePrint}
                            class="w-full px-4 py-2.5 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-bold text-xs shadow-sm transition flex items-center justify-center gap-1.5"
                        >
                            <span>🖨️</span>
                            <span>Imprimir / Guardar PDF</span>
                        </button>
                        <button
                            type="button"
                            onclick={handleDownloadPng}
                            class="w-full px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-100 border border-slate-200 dark:border-[#26334D] font-bold text-xs transition flex items-center justify-center gap-1.5"
                        >
                            <span>📥</span>
                            <span>Descargar PNG</span>
                        </button>
                    </div>
                </div>

                <!-- COLUMNA DERECHA (7 cols): VISTA PREVIA B&N DE ALTO CONTRASTE SIEMPRE -->
                <div class="lg:col-span-7 flex flex-col items-center">
                    <div class="w-full flex items-center justify-between mb-2">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Vista Previa de Impresión (Blanco y Negro · Alto Contraste)
                        </span>
                        <span class="text-[11px] font-mono text-slate-400">
                            {printFormat === 'thermal' ? '100 × 150 mm' : 'Carta / A4'}
                        </span>
                    </div>

                    <div class="w-full bg-slate-200/80 dark:bg-slate-900/90 p-3 sm:p-5 rounded-2xl border border-slate-300 dark:border-[#26334D] flex justify-center">
                        <!-- HOJA DE LA GUÍA: SIEMPRE FONDO #FFFFFF Y TINTA #000000 -->
                        <div
                            class="w-full max-w-[460px] bg-white text-black p-4 sm:p-5 shadow-lg border border-black/20 space-y-3 select-text"
                            style="background-color: #FFFFFF; color: #000000; font-family: Arial, Helvetica, sans-serif;"
                        >
                            <!-- 1. CABECERA SUPERIOR -->
                            <div class="flex items-stretch justify-between gap-3 border-b-2 border-black pb-2.5">
                                <div
                                    class="bg-black text-white font-extrabold uppercase tracking-wide px-3 py-2 text-xs sm:text-sm flex items-center leading-tight max-w-[58%] break-words"
                                    style="background-color: #000000; color: #FFFFFF;"
                                >
                                    {headerDisplay}
                                </div>
                                <div class="text-right flex flex-col justify-center shrink-0">
                                    <div class="text-xs sm:text-sm font-black tracking-tight">
                                        GUÍA N.º: <span class="font-mono">{guideNumber || '----------'}</span>
                                    </div>
                                    <div class="text-[11px] sm:text-xs font-bold mt-0.5">
                                        FECHA: <span class="font-mono">{formattedDate}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. MODALIDAD DE PAGO -->
                            <div class="border-2 border-black p-2.5 space-y-1">
                                <div class="text-[11px] font-black uppercase tracking-wide border-b border-black pb-1 mb-1.5">
                                    MODALIDAD DE PAGO
                                </div>
                                <div class="text-xs font-bold flex items-center gap-1.5">
                                    <span class="font-mono font-black">[{paymentMode === 'origen' ? ' X ' : '   '}]</span>
                                    <span>PAGADO EN ORIGEN (Punto de Envío)</span>
                                </div>
                                <div class="text-xs font-bold flex items-center gap-1.5">
                                    <span class="font-mono font-black">[{paymentMode === 'contraentrega' ? ' X ' : '   '}]</span>
                                    <span>CONTRA ENTREGA (Cobro en Destino)</span>
                                </div>
                            </div>

                            <!-- 3. DATOS DEL REMITENTE (ORIGEN) -->
                            <div class="border-2 border-black p-2.5 space-y-0.5 text-xs">
                                <div class="text-[11px] font-black uppercase tracking-wide border-b border-black pb-1 mb-1.5">
                                    DATOS DEL REMITENTE (ORIGEN)
                                </div>
                                <div><span class="font-extrabold">Nombre:</span> {sender.name || '—'}</div>
                                <div><span class="font-extrabold">NIT / Cédula:</span> {sender.tax_id || '—'}</div>
                                <div><span class="font-extrabold">Celular:</span> {sender.phone || '—'}</div>
                                <div><span class="font-extrabold">Email:</span> {sender.email || '—'}</div>
                                <div><span class="font-extrabold">Dirección:</span> {sender.address || '—'}</div>
                                <div><span class="font-extrabold">Ciudad / Depto:</span> {senderCityDept || '—'}</div>
                            </div>

                            <!-- 4. DATOS DEL DESTINATARIO (ENTREGA) -->
                            <div class="border-[3px] border-black">
                                <div
                                    class="bg-black text-white text-center font-extrabold uppercase py-1.5 px-2 text-xs sm:text-sm tracking-wide"
                                    style="background-color: #000000; color: #FFFFFF;"
                                >
                                    DATOS DEL DESTINATARIO (ENTREGA)
                                </div>
                                <div class="p-3 space-y-1.5">
                                    <div class="border-b-2 border-black pb-1.5">
                                        <span class="block text-[10px] font-black uppercase tracking-wider">NOMBRE:</span>
                                        <div class="text-xl sm:text-2xl font-black uppercase leading-tight break-words">
                                            {recipient.name || '—'}
                                        </div>
                                    </div>
                                    <div class="text-xs sm:text-sm leading-snug"><span class="font-black uppercase">CÉDULA:</span> <span class="font-bold">{recipient.document_number || '—'}</span></div>
                                    <div class="text-xs sm:text-sm leading-snug"><span class="font-black uppercase">CELULAR:</span> <span class="font-bold">{recipient.phone || '—'}</span></div>
                                    <div class="text-xs sm:text-sm leading-snug break-words"><span class="font-black uppercase">EMAIL:</span> <span class="font-bold">{recipient.email || '—'}</span></div>
                                    <div class="text-xs sm:text-sm leading-snug break-words"><span class="font-black uppercase">DIRECCIÓN:</span> <span class="font-bold">{recipient.address || '—'}</span></div>
                                    <div class="text-xs sm:text-sm leading-snug"><span class="font-black uppercase">BARRIO:</span> <span class="font-bold">{recipient.neighborhood || '—'}</span></div>
                                    <div class="text-xs sm:text-sm leading-snug"><span class="font-black uppercase">CIUDAD:</span> <span class="font-bold">{recipient.city || '—'}</span></div>
                                    <div class="text-xs sm:text-sm leading-snug"><span class="font-black uppercase">DEPARTAMENTO:</span> <span class="font-bold">{recipient.department || '—'}</span></div>
                                </div>
                            </div>

                            <!-- 5. OBSERVACIONES -->
                            <div class="border-2 border-black p-2.5 text-xs min-h-[46px]">
                                <span class="font-black uppercase">OBSERVACIONES:</span>
                                {#if observations && observations.trim()}
                                    <span class="ml-1 font-medium break-words">{observations.trim()}</span>
                                {:else}
                                    <div class="border-b border-black h-4 mt-1"></div>
                                {/if}
                            </div>

                            <!-- 6. PIE DE GUÍA -->
                            <div class="text-center font-mono text-xl sm:text-2xl font-black tracking-widest pt-1">
                                *{guideNumber || '0000000000'}*
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
{/if}
