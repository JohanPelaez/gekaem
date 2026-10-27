<script>
    import { untrack } from 'svelte';
    import { router } from '@inertiajs/svelte';
    import CurrencyInput from './CurrencyInput.svelte';
    import QuickCreateModal from './QuickCreateModal.svelte';
    import OrderDateSelector from './OrderDateSelector.svelte';
    import ProductFormModal from './ProductFormModal.svelte';

    /**
     * Componente reutilizable para creación y edición de pedidos (Sprint 6 & 7).
     * Usa exclusivamente Runes de Svelte 5.
     */
    let {
        isOpen = $bindable(false),
        isEditing = false,
        editingOrder = null,
        initialDate = '',
        customers = [],
        products = [],
        workflows = [],
        productTypes = [],
        rawMaterials = [],
        orthogonalAttributes = [],
        attributes = [],
        unitsOfMeasure = [],
        allOrdersByDate = {},
        ordersCountByDate = {},
        allScheduledOrders = [],
        hasFinancialAccess = true,
        errors = {},
        onSuccess = () => {},
        onClose = () => {},
        onCustomerCreated = () => {},
        onProductCreated = () => {},
    } = $props();

    // Sincronización local de clientes y productos para creaciones rápidas en contexto
    let localCustomers = $state([]);
    $effect(() => {
        const incoming = customers;
        untrack(() => {
            localCustomers = [...(incoming || [])];
        });
    });

    let localProducts = $state([]);
    $effect(() => {
        const incoming = products;
        untrack(() => {
            localProducts = [...(incoming || [])];
        });
    });

    // Modales secundarios
    let quickCustomerModalOpen = $state(false);
    let productModalOpen = $state(false);
    let processing = $state(false);

    // Estado del formulario
    let orderForm = $state({
        customer_id: '',
        committed_delivery_at: '',
        shipping_type: 'contraentrega',
        notes: '',
        items: [],
        has_initial_deposit: false,
        initial_deposit: '',
        payment_method: 'transfer',
        voucher: null,
    });

    let prevIsOpen = false;
    let prevEditingOrder = null;

    // Inicializar o resetear formulario exclusivamente cuando cambia el estado de apertura o el pedido a editar
    $effect(() => {
        const currentlyOpen = isOpen;
        const currentOrder = editingOrder;

        if (currentlyOpen && (!prevIsOpen || currentOrder !== prevEditingOrder)) {
            prevIsOpen = currentlyOpen;
            prevEditingOrder = currentOrder;

            untrack(() => {
                if (isEditing && currentOrder) {
                    orderForm.customer_id = currentOrder.customer_id || currentOrder.customer?.id || '';
                    orderForm.shipping_type = currentOrder.shipping_type || 'contraentrega';
                    orderForm.notes = currentOrder.notes || '';

                    let dt = '';
                    if (currentOrder.committed_delivery_at) {
                        dt = String(currentOrder.committed_delivery_at).replace(' ', 'T').slice(0, 16);
                    } else if (currentOrder.predicted_delivery_at) {
                        dt = String(currentOrder.predicted_delivery_at).replace(' ', 'T').slice(0, 16);
                    }
                    orderForm.committed_delivery_at = dt;

                    const tasks = currentOrder.production_tasks || currentOrder.productionTasks || currentOrder.tasks || [];
                    if (tasks.length > 0) {
                        orderForm.items = tasks.map((task) => {
                            const variant = task.product_variant || task.productVariant || {};
                            const foundProduct = localProducts.find((p) =>
                                p.id == variant.product_id || (p.variants || []).some((v) => v.id == task.product_variant_id)
                            );
                            const baseUnitPrice = task.unit_price !== null && task.unit_price !== undefined && Number(task.unit_price) > 0
                                ? Number(task.unit_price)
                                : Number(variant.price_override ?? foundProduct?.base_price ?? 0);
                            return {
                                id: task.id,
                                product_id: variant.product_id || foundProduct?.id || '',
                                product_variant_id: task.product_variant_id,
                                quantity: Number(task.quantity) || 1,
                                unit_price: baseUnitPrice,
                                discount_percentage: Number(task.discount_percentage) || 0,
                            };
                        });
                    } else if (localProducts.length > 0) {
                        orderForm.items = [];
                        addItem();
                    }

                    orderForm.has_initial_deposit = false;
                    orderForm.initial_deposit = '';
                    orderForm.payment_method = 'transfer';
                    orderForm.voucher = null;
                } else {
                    orderForm.customer_id = localCustomers[0]?.id ?? '';
                    orderForm.committed_delivery_at = initialDate ? `${initialDate}T17:00` : '';
                    orderForm.shipping_type = 'contraentrega';
                    orderForm.notes = '';
                    orderForm.items = [];
                    orderForm.has_initial_deposit = false;
                    orderForm.initial_deposit = '';
                    orderForm.payment_method = 'transfer';
                    orderForm.voucher = null;

                    if (localProducts.length > 0) {
                        addItem();
                    }
                }
            });
        } else if (!currentlyOpen) {
            prevIsOpen = false;
            prevEditingOrder = null;
        }
    });

    function handleQuickCustomerSuccess(created) {
        if (!created) return;
        localCustomers = [...localCustomers, created];
        orderForm.customer_id = created.id;
        onCustomerCreated(created);
    }

    function addItem() {
        if (localProducts.length === 0) return;
        const firstProd = localProducts[0];
        const firstVariant = firstProd.variants?.[0] ?? null;

        orderForm.items = [
            ...orderForm.items,
            {
                id: null,
                product_id: firstProd.id,
                product_variant_id: firstVariant?.id ?? '',
                quantity: 1,
                unit_price: Number(firstVariant?.price_override ?? firstProd.base_price ?? 0),
                discount_percentage: 0,
            },
        ];
    }

    function removeItem(index) {
        orderForm.items = orderForm.items.filter((_, i) => i !== index);
    }

    function onProductChange(item, productId) {
        item.product_id = Number(productId);
        const prod = localProducts.find((p) => p.id === Number(productId));
        const firstVariant = prod?.variants?.[0] ?? null;
        item.product_variant_id = firstVariant?.id ?? '';
        item.unit_price = Number(firstVariant?.price_override ?? prod?.base_price ?? 0);
    }

    function onVariantChange(item, variantId) {
        item.product_variant_id = Number(variantId);
        const prod = localProducts.find((p) => p.id === Number(item.product_id));
        const variant = prod?.variants?.find((v) => v.id === Number(variantId));
        if (variant) {
            item.unit_price = Number(variant.price_override ?? prod?.base_price ?? 0);
        }
    }

    function openCreateProductModal() {
        productModalOpen = true;
    }

    function handleProductCreated(newProduct) {
        if (!newProduct) return;
        if (!localProducts.some((p) => p.id === newProduct.id)) {
            localProducts = [...localProducts, newProduct];
        }
        const defaultVariant = newProduct.variants?.[0] ?? null;
        const unitPrice = defaultVariant?.price_override !== null && defaultVariant?.price_override !== undefined && defaultVariant?.price_override !== ''
            ? Number(defaultVariant.price_override)
            : Number(newProduct.base_price || 0);

        const lastItemIdx = orderForm.items.length - 1;
        if (lastItemIdx >= 0 && (!orderForm.items[lastItemIdx].product_id || orderForm.items[lastItemIdx].product_id === '')) {
            orderForm.items[lastItemIdx].product_id = newProduct.id;
            orderForm.items[lastItemIdx].product_variant_id = defaultVariant?.id ?? '';
            orderForm.items[lastItemIdx].unit_price = unitPrice;
            orderForm.items[lastItemIdx].discount_percentage = 0;
        } else {
            orderForm.items = [
                ...orderForm.items,
                {
                    id: null,
                    product_id: newProduct.id,
                    product_variant_id: defaultVariant?.id ?? '',
                    quantity: 1,
                    unit_price: unitPrice,
                    discount_percentage: 0,
                },
            ];
        }
        onProductCreated(newProduct);
    }

    function onInitialVoucherChange(e) {
        const file = e.target.files?.[0] ?? null;
        orderForm.voucher = file;
    }

    function calculateItemSubtotal(item) {
        const qty = Number(item.quantity) || 0;
        const price = Number(item.unit_price) || 0;
        const disc = Math.min(100, Math.max(0, Number(item.discount_percentage) || 0));
        return Math.round(qty * price * (1 - disc / 100));
    }

    let totalAmount = $derived.by(() => {
        let sum = 0;
        for (const item of orderForm.items) {
            if (!item.product_variant_id || !item.quantity) continue;
            sum += calculateItemSubtotal(item);
        }
        return sum;
    });

    let initialPendingBalance = $derived.by(() => {
        if (!orderForm.has_initial_deposit || !orderForm.initial_deposit) {
            return totalAmount;
        }
        const deposit = Number(orderForm.initial_deposit) || 0;
        return Math.max(0, totalAmount - deposit);
    });

    function formatCurrency(val) {
        return new Intl.NumberFormat('es-CO', {
            style: 'currency',
            currency: 'COP',
            maximumFractionDigits: 0,
        }).format(val || 0);
    }

    function getDerivedStatusBadge(status) {
        switch (status) {
            case 'pending':
                return { text: 'Pendiente', color: 'bg-amber-500/10 text-amber-400 border-amber-500/20' };
            case 'in_progress':
                return { text: 'En Fabricación', color: 'bg-sky-500/10 text-sky-400 border-sky-500/20' };
            case 'blocked':
                return { text: 'Bloqueado', color: 'bg-rose-500/10 text-rose-400 border-rose-500/20' };
            case 'ready':
                return { text: 'Listo', color: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' };
            case 'delivered':
                return { text: 'Entregado', color: 'bg-purple-500/10 text-purple-400 border-purple-500/20' };
            default:
                return { text: status, color: 'bg-slate-800 text-slate-300 border-slate-700' };
        }
    }

    function closeModal() {
        isOpen = false;
        onClose();
    }

    function handleSubmit(e) {
        e.preventDefault();

        if (!orderForm.committed_delivery_at) {
            alert('Por favor selecciona un día en el calendario para comprometer la entrega.');
            return;
        }

        if (orderForm.items.length === 0) {
            alert('Agrega al menos un producto al pedido.');
            return;
        }

        if (isEditing && editingOrder) {
            router.put(`/orders/${editingOrder.id}`, {
                customer_id: Number(orderForm.customer_id),
                committed_delivery_at: orderForm.committed_delivery_at,
                shipping_type: orderForm.shipping_type,
                notes: orderForm.notes ?? '',
                items: orderForm.items.map((item) => ({
                    id: item.id ? Number(item.id) : null,
                    product_variant_id: Number(item.product_variant_id),
                    quantity: Number(item.quantity),
                    unit_price: Number(item.unit_price || 0),
                    discount_percentage: Number(item.discount_percentage || 0),
                })),
            }, {
                preserveScroll: true,
                onStart: () => { processing = true; },
                onFinish: () => { processing = false; },
                onSuccess: () => {
                    closeModal();
                    onSuccess();
                },
            });
            return;
        }

        const formData = new FormData();
        formData.append('customer_id', String(orderForm.customer_id));
        formData.append('committed_delivery_at', orderForm.committed_delivery_at);
        formData.append('shipping_type', orderForm.shipping_type);
        formData.append('notes', orderForm.notes ?? '');
        formData.append('has_initial_deposit', orderForm.has_initial_deposit ? '1' : '0');

        if (orderForm.has_initial_deposit && orderForm.initial_deposit) {
            formData.append('initial_deposit', String(orderForm.initial_deposit));
            formData.append('payment_method', orderForm.payment_method);
            if (orderForm.voucher) {
                formData.append('voucher', orderForm.voucher);
            }
        }

        orderForm.items.forEach((item, index) => {
            formData.append(`items[${index}][product_id]`, String(item.product_id));
            formData.append(`items[${index}][product_variant_id]`, String(item.product_variant_id));
            formData.append(`items[${index}][quantity]`, String(item.quantity));
            formData.append(`items[${index}][unit_price]`, String(item.unit_price || 0));
            formData.append(`items[${index}][discount_percentage]`, String(item.discount_percentage || 0));
        });

        router.post('/orders', formData, {
            preserveScroll: true,
            onStart: () => { processing = true; },
            onFinish: () => { processing = false; },
            onSuccess: () => {
                closeModal();
                onSuccess();
            },
        });
    }
</script>

{#if isOpen}
    <div class="fixed inset-0 z-50 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white dark:bg-[#151D2C] border border-slate-200 dark:border-[#26334D] rounded-3xl max-w-4xl w-full p-6 shadow-2xl space-y-4 max-h-[92vh] overflow-y-auto text-slate-900 dark:text-slate-100">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-[#26334D] pb-3">
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-white">
                        {isEditing ? `Editar Pedido #${editingOrder?.order_number || editingOrder?.id}` : 'Registrar Nuevo Pedido'}
                    </h3>
                    <p class="text-[11px] text-[#64748B] dark:text-slate-400">
                        {isEditing ? 'Modifica los productos, entrega y detalles del pedido conservando las etapas en Kanban.' : 'Genera las tarjetas de producción en el Tablero Kanban y programa la entrega.'}
                    </p>
                </div>
                <button type="button" onclick={closeModal} class="text-slate-400 hover:text-slate-600 dark:hover:text-white p-1 rounded-lg">✕</button>
            </div>

            <form onsubmit={handleSubmit} class="space-y-4 text-xs">
                <!-- CLIENTE Y MODALIDAD DE ENVÍO -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label for="ord-modal-customer" class="font-semibold text-slate-700 dark:text-slate-300">Cliente *</label>
                            <button
                                type="button"
                                onclick={() => quickCustomerModalOpen = true}
                                class="text-[10px] text-[#4F46E5] dark:text-[#06B6D4] hover:underline font-bold"
                            >
                                + Nuevo Cliente
                            </button>
                        </div>
                        <select
                            id="ord-modal-customer"
                            bind:value={orderForm.customer_id}
                            required
                            class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-[#4F46E5] text-xs"
                        >
                            <option value="">-- Selecciona un Cliente --</option>
                            {#each localCustomers as c}
                                <option value={c.id}>
                                    {c.name} {c.document_number ? `[Doc: ${c.document_number}]` : (c.tax_id ? `[NIT: ${c.tax_id}]` : '')} {c.city ? `(${c.city}${c.department ? `, ${c.department}` : ''})` : (c.phone ? `(${c.phone})` : '')}
                                </option>
                            {/each}
                        </select>
                        {#if errors?.customer_id}
                            <p class="text-[10px] text-rose-500 mt-1">{errors.customer_id}</p>
                        {/if}
                    </div>

                    <fieldset>
                        <legend class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Modalidad de Envío *</legend>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-center gap-2 p-2 rounded-xl border cursor-pointer transition-colors {orderForm.shipping_type === 'contraentrega' ? 'bg-[#4F46E5]/10 border-[#4F46E5] text-slate-900 dark:text-white' : 'bg-slate-50 dark:bg-[#0B0F17] border-slate-200 dark:border-[#26334D] text-[#64748B] dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-700'}">
                                <input
                                    type="radio"
                                    name="order_modal_shipping_type"
                                    value="contraentrega"
                                    bind:group={orderForm.shipping_type}
                                    class="text-[#4F46E5] focus:ring-0"
                                />
                                <span class="font-medium text-[11px] leading-tight">🚚 Contraentrega (Paga cliente)</span>
                            </label>
                            <label class="flex items-center gap-2 p-2 rounded-xl border cursor-pointer transition-colors {orderForm.shipping_type === 'envio_incluido' ? 'bg-sky-50 dark:bg-sky-950/40 border-sky-500 text-slate-900 dark:text-white' : 'bg-slate-50 dark:bg-[#0B0F17] border-slate-200 dark:border-[#26334D] text-[#64748B] dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-700'}">
                                <input
                                    type="radio"
                                    name="order_modal_shipping_type"
                                    value="envio_incluido"
                                    bind:group={orderForm.shipping_type}
                                    class="text-sky-500 focus:ring-0"
                                />
                                <span class="font-medium text-[11px] leading-tight">📦 Envío Incluido (Paga empresa)</span>
                            </label>
                        </div>
                    </fieldset>
                </div>

                <!-- SELECTOR VISUAL DE CALENDARIO -->
                <div>
                    <span class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Fecha y Hora de Entrega Comprometida *</span>
                    <OrderDateSelector
                        bind:value={orderForm.committed_delivery_at}
                        {allOrdersByDate}
                        {ordersCountByDate}
                        {allScheduledOrders}
                        {getDerivedStatusBadge}
                    />
                </div>

                <!-- RENGLONES DE PRODUCTOS -->
                <div class="space-y-3 pt-2 border-t border-slate-200 dark:border-[#26334D]">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-slate-900 dark:text-white text-xs">Productos del Pedido</span>
                            <p class="text-[11px] text-[#64748B] dark:text-slate-400">Agrega las variantes y cantidades solicitadas.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                onclick={openCreateProductModal}
                                class="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-[#4F46E5] dark:text-[#06B6D4] font-semibold text-xs border border-slate-200 dark:border-slate-700 flex items-center gap-1 transition-colors"
                                title="Crear un producto nuevo con receta BOM y variantes"
                            >
                                <span>+</span>
                                <span>Nuevo Producto</span>
                            </button>
                            <button
                                type="button"
                                onclick={addItem}
                                disabled={localProducts.length === 0}
                                class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-emerald-600 dark:text-emerald-400 font-semibold text-xs border border-slate-200 dark:border-slate-700 flex items-center gap-1.5 disabled:opacity-50"
                            >
                                <span>+</span>
                                <span>Agregar Renglón</span>
                            </button>
                        </div>
                    </div>

                    {#if orderForm.items.length === 0}
                        <div class="p-6 text-center border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-xl text-slate-400 text-xs">
                            Agrega al menos un producto al pedido.
                        </div>
                    {:else}
                        <div class="space-y-2">
                            {#each orderForm.items as item, idx}
                                {@const selectedProd = localProducts.find((p) => p.id == item.product_id)}
                                <div class="p-3 bg-slate-50 dark:bg-[#0B0F17] rounded-xl border border-slate-200 dark:border-[#26334D] grid grid-cols-12 gap-2.5 items-end">
                                    {#if hasFinancialAccess}
                                        <!-- PRODUCTO (col-span-12 sm:col-span-3) -->
                                        <div class="col-span-12 sm:col-span-3 min-w-0">
                                            <label for="modal-prod-sel-{idx}" class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block mb-1 truncate">Producto</label>
                                            <select
                                                id="modal-prod-sel-{idx}"
                                                bind:value={item.product_id}
                                                onchange={(e) => onProductChange(item, e.target.value)}
                                                class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 text-slate-900 dark:text-white text-xs truncate focus:outline-none focus:border-[#4F46E5]"
                                            >
                                                {#each localProducts as p}
                                                    <option value={p.id}>{p.name}</option>
                                                {/each}
                                            </select>
                                        </div>

                                        <!-- VARIANTE (col-span-6 sm:col-span-2) -->
                                        <div class="col-span-6 sm:col-span-2 min-w-0">
                                            <label for="modal-var-sel-{idx}" class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block mb-1 truncate">Variante</label>
                                            <select
                                                id="modal-var-sel-{idx}"
                                                bind:value={item.product_variant_id}
                                                onchange={(e) => onVariantChange(item, e.target.value)}
                                                class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 text-slate-900 dark:text-white text-xs font-mono truncate focus:outline-none focus:border-[#4F46E5]"
                                            >
                                                {#each selectedProd?.variants ?? [] as v}
                                                    <option value={v.id}>
                                                        {v.name_label}
                                                    </option>
                                                {/each}
                                            </select>
                                        </div>

                                        <!-- CANTIDAD (col-span-6 sm:col-span-1) -->
                                        <div class="col-span-6 sm:col-span-1 min-w-0">
                                            <label for="modal-qty-sel-{idx}" class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block mb-1 truncate">Cant.</label>
                                            <input
                                                id="modal-qty-sel-{idx}"
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                bind:value={item.quantity}
                                                required
                                                class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 text-slate-900 dark:text-white text-xs font-mono font-bold text-center focus:outline-none focus:border-[#4F46E5]"
                                            />
                                        </div>

                                        <!-- PRECIO UNIT. (col-span-4 sm:col-span-2) -->
                                        <div class="col-span-4 sm:col-span-2 min-w-0">
                                            <label for="modal-price-sel-{idx}" class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block mb-1 truncate">Precio Unit.</label>
                                            <input
                                                id="modal-price-sel-{idx}"
                                                type="number"
                                                min="0"
                                                step="1"
                                                bind:value={item.unit_price}
                                                placeholder="0"
                                                class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 text-slate-900 dark:text-slate-200 text-xs font-mono font-medium focus:outline-none focus:border-[#4F46E5]"
                                            />
                                        </div>

                                        <!-- DESC. (%) (col-span-4 sm:col-span-2) -->
                                        <div class="col-span-4 sm:col-span-2 min-w-0">
                                            <label for="modal-disc-sel-{idx}" class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block mb-1 truncate">Desc. (%)</label>
                                            <input
                                                id="modal-disc-sel-{idx}"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                bind:value={item.discount_percentage}
                                                placeholder="0"
                                                class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 text-amber-600 dark:text-amber-300 text-xs font-mono font-medium focus:outline-none focus:border-[#4F46E5]"
                                            />
                                        </div>

                                        <!-- SUBTOTAL + BORRAR (col-span-4 sm:col-span-2) -->
                                        <div class="col-span-4 sm:col-span-2 min-w-0 flex items-center gap-1.5">
                                            <div class="flex-1 min-w-0">
                                                <span class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block mb-1 truncate">Subtotal</span>
                                                <div class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 text-emerald-600 dark:text-emerald-400 text-xs font-mono font-bold truncate">
                                                    {formatCurrency(calculateItemSubtotal(item))}
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onclick={() => removeItem(idx)}
                                                class="p-1.5 text-slate-400 hover:text-rose-500 dark:hover:text-rose-400 shrink-0 self-end mb-0.5 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-950/20 transition-colors"
                                                title="Quitar producto"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    {:else}
                                        <!-- Sin acceso financiero -->
                                        <div class="col-span-12 sm:col-span-6 min-w-0">
                                            <label for="modal-prod-sel-{idx}" class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block mb-1 truncate">Producto</label>
                                            <select
                                                id="modal-prod-sel-{idx}"
                                                bind:value={item.product_id}
                                                onchange={(e) => onProductChange(item, e.target.value)}
                                                class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 text-slate-900 dark:text-white text-xs truncate focus:outline-none focus:border-[#4F46E5]"
                                            >
                                                {#each localProducts as p}
                                                    <option value={p.id}>{p.name}</option>
                                                {/each}
                                            </select>
                                        </div>

                                        <div class="col-span-6 sm:col-span-4 min-w-0">
                                            <label for="modal-var-sel-{idx}" class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block mb-1 truncate">Variante</label>
                                            <select
                                                id="modal-var-sel-{idx}"
                                                bind:value={item.product_variant_id}
                                                onchange={(e) => onVariantChange(item, e.target.value)}
                                                class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 text-slate-900 dark:text-white text-xs font-mono truncate focus:outline-none focus:border-[#4F46E5]"
                                            >
                                                {#each selectedProd?.variants ?? [] as v}
                                                    <option value={v.id}>{v.name_label}</option>
                                                {/each}
                                            </select>
                                        </div>

                                        <div class="col-span-4 sm:col-span-1 min-w-0">
                                            <label for="modal-qty-sel-{idx}" class="text-[10px] text-[#64748B] dark:text-slate-400 uppercase font-bold block mb-1 truncate">Cant.</label>
                                            <input
                                                id="modal-qty-sel-{idx}"
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                bind:value={item.quantity}
                                                required
                                                class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-2 py-1.5 text-slate-900 dark:text-white text-xs font-mono font-bold text-center focus:outline-none focus:border-[#4F46E5]"
                                            />
                                        </div>

                                        <div class="col-span-2 sm:col-span-1 min-w-0 flex items-center justify-end pb-1">
                                            <button
                                                type="button"
                                                onclick={() => removeItem(idx)}
                                                class="p-2 text-slate-400 hover:text-rose-500 dark:hover:text-rose-400 shrink-0 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-950/20 transition-colors"
                                                title="Quitar producto"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    {/if}
                                </div>
                            {/each}
                        </div>
                    {/if}
                </div>

                <!-- NOTAS -->
                <div>
                    <label for="ord-modal-notes" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Notas / Instrucciones Especiales</label>
                    <input
                        id="ord-modal-notes"
                        type="text"
                        bind:value={orderForm.notes}
                        placeholder="Ej. Entregar en portería, medidas especiales..."
                        class="w-full bg-white dark:bg-[#0B0F17] border border-slate-200 dark:border-[#26334D] rounded-xl px-3 py-2 text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-[#4F46E5] text-xs"
                    />
                </div>

                <!-- SECCIÓN DE ABONO INICIAL (SOLO AL CREAR Y CON PERMISOS FINANCIEROS) -->
                {#if hasFinancialAccess && !isEditing}
                    <div class="pt-3 border-t border-slate-200 dark:border-[#26334D] space-y-3">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                bind:checked={orderForm.has_initial_deposit}
                                class="rounded bg-white dark:bg-slate-950 border-slate-200 dark:border-slate-800 text-[#4F46E5] focus:ring-0"
                            />
                            <span class="font-bold text-slate-900 dark:text-white text-xs">Registrar Abono Inicial en este Pedido</span>
                        </label>

                        {#if orderForm.has_initial_deposit}
                            <div class="p-4 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-emerald-500/30 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label for="ord-modal-deposit" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Monto del Abono ($) *</label>
                                        <CurrencyInput bind:value={orderForm.initial_deposit} id="ord-modal-deposit" required placeholder="0" />
                                    </div>

                                    <div>
                                        <label for="ord-modal-method" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">Método de Pago *</label>
                                        <select
                                            id="ord-modal-method"
                                            bind:value={orderForm.payment_method}
                                            class="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-slate-900 dark:text-white text-xs focus:outline-none focus:border-[#4F46E5]"
                                        >
                                            <option value="transfer">Transferencia / Nequi / Daviplata</option>
                                            <option value="cash">Efectivo</option>
                                            <option value="card">Tarjeta</option>
                                            <option value="other">Otro medio</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label for="ord-modal-voucher" class="block font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                        Comprobante o Foto del Pago (Opcional, máx 5MB)
                                    </label>
                                    <input
                                        id="ord-modal-voucher"
                                        type="file"
                                        accept="image/*,.pdf"
                                        onchange={onInitialVoucherChange}
                                        class="w-full text-slate-500 dark:text-slate-400 text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-slate-100 dark:file:bg-slate-800 file:text-slate-700 dark:file:text-slate-200 hover:file:bg-slate-200 dark:hover:file:bg-slate-700"
                                    />
                                </div>
                            </div>
                        {/if}
                    </div>
                {/if}

                <!-- TOTAL, DESGLOSE DE SALDO EN VIVO Y BOTONES -->
                <div class="pt-3 border-t border-slate-200 dark:border-[#26334D] space-y-3">
                    {#if hasFinancialAccess}
                        {#if !isEditing && orderForm.has_initial_deposit && Number(orderForm.initial_deposit) > 0}
                            <div class="p-3.5 bg-slate-50 dark:bg-[#0B0F17] rounded-2xl border border-slate-200 dark:border-[#26334D] space-y-2 text-xs">
                                <div class="flex justify-between items-center text-[#64748B] dark:text-slate-400">
                                    <span>Subtotal / Total del Pedido:</span>
                                    <span class="font-mono font-bold text-slate-900 dark:text-white text-sm">{formatCurrency(totalAmount)}</span>
                                </div>
                                <div class="flex justify-between items-center text-emerald-600 dark:text-emerald-400">
                                    <span>Abono Inicial (-):</span>
                                    <span class="font-mono font-bold">- {formatCurrency(orderForm.initial_deposit)}</span>
                                </div>
                                <div class="flex justify-between items-center pt-2 border-t border-slate-200 dark:border-[#26334D] font-bold">
                                    <span class="text-slate-700 dark:text-slate-300">Saldo Faltante a Cobrar:</span>
                                    <span class="font-mono text-sm {initialPendingBalance > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'}">
                                        {initialPendingBalance > 0 ? formatCurrency(initialPendingBalance) : '✓ Total Cubierto ($0)'}
                                    </span>
                                </div>
                            </div>
                        {/if}
                    {/if}

                    <div class="flex items-center justify-between">
                        {#if hasFinancialAccess}
                            <div class="text-xs">
                                <span class="text-[#64748B] dark:text-slate-400">Total del Pedido: </span>
                                <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400 text-base">{formatCurrency(totalAmount)}</span>
                            </div>
                        {:else}
                            <div></div>
                        {/if}

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                onclick={closeModal}
                                class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-semibold"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                disabled={processing}
                                class="px-5 py-2 rounded-xl bg-[#4F46E5] hover:bg-[#4338CA] text-white font-medium shadow-sm disabled:opacity-50"
                            >
                                {processing ? (isEditing ? 'Guardando...' : 'Creando...') : (isEditing ? 'Guardar Cambios' : 'Crear Pedido')}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
{/if}

<!-- MODAL FLOTANTE DE CREACIÓN RÁPIDA DE CLIENTE -->
<QuickCreateModal
    bind:isOpen={quickCustomerModalOpen}
    type="customer"
    onSuccess={handleQuickCustomerSuccess}
/>

<!-- MODAL COMPLETO DE PRODUCTO EN CONTEXTO ASYNC (Nota 7, 8, 9) -->
<ProductFormModal
    bind:isOpen={productModalOpen}
    asyncMode={true}
    {productTypes}
    {workflows}
    {rawMaterials}
    {orthogonalAttributes}
    {attributes}
    {unitsOfMeasure}
    {errors}
    onSuccess={handleProductCreated}
/>
