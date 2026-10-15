<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\MarkOrderDeliveredAction;
use App\Actions\Orders\PredictDeliveryDateAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionTask;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function index(Request $request): Response
    {
        $tenant = $this->resolveTenant();
        $status = $request->string('status')->trim()->value();
        $search = $request->string('search')->trim()->value();

        $orders = Order::query()
            ->when(!empty($status), fn ($q) => $q->where('derived_status', $status))
            ->when(!empty($search), function ($q) use ($search): void {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            })
            ->with(['customer', 'productionTasks.productVariant.product', 'productionTasks.currentStage'])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->whereHas('currentStage', fn ($s) => $s->where('stage_category', 'done')),
            ])
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'phone']),
            'products' => Product::where('is_active', true)->with(['variants', 'workflow', 'materialRequirements.rawMaterial'])->get(),
            'wipQueueMinutes' => (int) ProductionTask::whereHas('currentStage', fn ($q) => $q->whereIn('stage_category', ['pending', 'in_progress']))->sum('estimated_minutes'),
            'dailyProductiveMinutes' => $tenant->daily_productive_minutes ?? 480,
            'defaultBufferPercent' => $tenant->default_buffer_percent ?? 20,
            'filters' => ['search' => $search, 'status' => $status],
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
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->createOrderAction->execute($this->resolveTenant(), $validated);

        return redirect()->route('orders.index')->with('success', "Pedido #{$order->order_number} registrado y tareas Kanban generadas.");
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
