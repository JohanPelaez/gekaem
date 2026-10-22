<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\GetOrdersListAction;
use App\Actions\Orders\MarkOrderDeliveredAction;
use App\Actions\Orders\PredictDeliveryDateAction;
use App\Actions\Orders\UpdateOrderAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\OrthogonalAttribute;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        protected CreateOrderAction $createOrderAction,
        protected PredictDeliveryDateAction $predictDeliveryDateAction,
        protected MarkOrderDeliveredAction $markOrderDeliveredAction
    ) {}

    /**
     * Display a listing of orders with predictive metrics and filters.
     */
    public function index(Request $request, GetOrdersListAction $getOrdersListAction): Response
    {
        $tenant = $this->resolveTenant();
        $listData = $getOrdersListAction->execute($request);

        return Inertia::render('Orders/Index', [
            ...$listData,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'products' => Product::where('is_active', true)->with(['variants', 'workflow', 'materialRequirements.rawMaterial'])->get(),
            'workflows' => Workflow::with('stages')->orderBy('name')->get(),
            'productTypes' => ProductType::orderBy('name')->get(['id', 'name']),
            'rawMaterials' => RawMaterial::orderBy('name')->get(['id', 'name', 'unit_of_measure', 'current_stock', 'reserved_stock']),
            'orthogonalAttributes' => OrthogonalAttribute::with('options')->orderBy('name')->get(),
            'attributes' => OrthogonalAttribute::with('options')->orderBy('name')->get(),
            'unitsOfMeasure' => UnitOfMeasure::orderBy('name')->get(['id', 'name', 'abbreviation']),
            'wipQueueMinutes' => (int) ProductionTask::whereHas('currentStage', fn ($q) => $q->whereIn('stage_category', ['pending', 'in_progress']))->sum('estimated_minutes'),
            'dailyProductiveMinutes' => $tenant->daily_productive_minutes ?? 480,
            'defaultBufferPercent' => $tenant->default_buffer_percent ?? 20,
        ]);
    }

    /**
     * Predict delivery date, time metrics and material shortages in real-time.
     */
    public function predict(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'buffer_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $prediction = $this->predictDeliveryDateAction->execute(
            $this->resolveTenant(),
            $validated['items'],
            isset($validated['buffer_percent']) ? (int) $validated['buffer_percent'] : null
        );

        return response()->json($prediction);
    }

    /**
     * Store a newly created order and generate its Kanban tasks.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'custom_buffer_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'committed_delivery_at' => ['nullable', 'date'],
            'shipping_type' => ['nullable', 'string', 'in:contraentrega,envio_incluido'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'initial_deposit' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'in:cash,transfer,card,other'],
            'voucher' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $order = DB::transaction(function () use ($validated, $request): Order {
            $order = $this->createOrderAction->execute($this->resolveTenant(), $validated);

            $depositAmount = isset($validated['initial_deposit']) ? (float) $validated['initial_deposit'] : 0.0;
            if ($depositAmount > 0) {
                $voucherPath = null;
                if ($request->hasFile('voucher')) {
                    $voucherPath = $request->file('voucher')->store('vouchers', 'public');
                }

                OrderPayment::create([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
                    'amount' => $depositAmount,
                    'payment_date' => now()->toDateString(),
                    'payment_method' => $validated['payment_method'] ?? 'cash',
                    'voucher_path' => $voucherPath,
                    'notes' => 'Abono inicial al registrar el pedido',
                    'created_by' => auth()->id(),
                ]);
            }

            return $order;
        });

        return redirect()->route('orders.index')->with('success', "Pedido #{$order->order_number} registrado y tareas Kanban generadas.");
    }

    /**
     * Update an existing order and intelligently sync its Kanban tasks.
     */
    public function update(Request $request, Order $order, UpdateOrderAction $updateOrderAction): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'committed_delivery_at' => ['required', 'date'],
            'shipping_type' => ['required', 'string', 'in:contraentrega,envio_incluido'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $updateOrderAction->execute($this->resolveTenant(), $order, $validated);

        return redirect()->route('orders.index')->with('success', "Pedido #{$order->order_number} actualizado con éxito.");
    }

    /**
     * Mark an order as delivered when it is ready.
     */
    public function markDelivered(Order $order): RedirectResponse
    {
        $this->markOrderDeliveredAction->execute($order);

        return redirect()->back()->with('success', "Pedido #{$order->order_number} marcado como entregado con éxito.");
    }

    /**
     * Resolve the active tenant.
     */
    protected function resolveTenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = auth()->user()?->tenants()->first()
            ?? (session('current_tenant_id') ? Tenant::find(session('current_tenant_id')) : null)
            ?? Tenant::first();

        return $tenant;
    }
}
