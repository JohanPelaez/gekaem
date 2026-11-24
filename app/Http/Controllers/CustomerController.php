<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Shipping\GenerateNextShippingGuideNumberAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers with quick search, order count and recent orders summary.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        $customers = Customer::query()
            ->when(!empty($search), function ($query) use ($search): void {
                $query->where(function ($sub) use ($search): void {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('document_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('tax_id', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('department', 'like', "%{$search}%")
                        ->orWhere('neighborhood', 'like', "%{$search}%");
                });
            })
            ->withCount('orders')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $customerIds = $customers->getCollection()->pluck('id')->all();
        $recentOrdersByCustomer = [];

        if (!empty($customerIds)) {
            $orders = Order::query()
                ->whereIn('customer_id', $customerIds)
                ->orderByDesc('id')
                ->get(['id', 'customer_id', 'order_number', 'shipping_type', 'committed_delivery_at', 'predicted_delivery_at', 'notes'])
                ->each->setAppends([]);

            foreach ($orders as $order) {
                $cid = (int) $order->customer_id;
                if (!isset($recentOrdersByCustomer[$cid])) {
                    $recentOrdersByCustomer[$cid] = [];
                }
                if (count($recentOrdersByCustomer[$cid]) < 10) {
                    $promised = $order->committed_delivery_at ?? $order->predicted_delivery_at;
                    $recentOrdersByCustomer[$cid][] = [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'shipping_type' => $order->shipping_type ?? 'contraentrega',
                        'promised_date' => $promised ? $promised->format('Y-m-d') : null,
                        'notes' => $order->notes,
                    ];
                }
            }
        }

        $customers->getCollection()->transform(function (Customer $customer) use ($recentOrdersByCustomer): Customer {
            $customer->setAttribute('recent_orders', $recentOrdersByCustomer[$customer->id] ?? []);
            return $customer;
        });

        $tenant = $this->resolveTenant();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search],
            'senderProfile' => $tenant?->senderProfile(),
        ]);
    }

    /**
     * Atomically generate the next shipping guide number for the active tenant.
     */
    public function nextShippingGuideNumber(
        Request $request,
        Customer $customer,
        GenerateNextShippingGuideNumberAction $action
    ): JsonResponse {
        $user = $request->user();
        abort_if(!$user || (!$user->isSuperAdmin() && $user->currentRole() !== 'owner'), 403);

        $tenant = $this->resolveTenant();
        abort_if(!$tenant || (int) $customer->tenant_id !== (int) $tenant->id, 403, 'El cliente no pertenece a tu taller activo.');

        $nextNumber = $action->execute($tenant);

        return response()->json([
            'success' => true,
            'guide_number' => (string) $nextNumber,
            'shipping_guide_sequence' => $nextNumber,
        ]);
    }

    /**
     * Resolve the active tenant.
     */
    protected function resolveTenant(): ?Tenant
    {
        return auth()->user()?->currentTenant()
            ?? (session('current_tenant_id') ? Tenant::find(session('current_tenant_id')) : null)
            ?? Tenant::first();
    }

    /**
     * Store a newly created customer in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCustomer($request);
        Customer::create($validated);

        return redirect()->back()->with('success', 'Cliente registrado con éxito.');
    }

    /**
     * Update the specified customer in storage.
     */
    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $this->validateCustomer($request);
        $customer->update($validated);

        return redirect()->back()->with('success', 'Cliente actualizado con éxito.');
    }

    /**
     * Validate and normalize customer input.
     *
     * @return array<string, mixed>
     */
    private function validateCustomer(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'document_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'neighborhood' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['name'] = Str::title(trim($validated['name']));
        if (!empty($validated['document_number'])) {
            $validated['document_number'] = trim($validated['document_number']);
        }
        if (!empty($validated['department'])) {
            $validated['department'] = Str::title(trim($validated['department']));
        }
        if (!empty($validated['city'])) {
            $validated['city'] = Str::title(trim($validated['city']));
        }
        if (!empty($validated['neighborhood'])) {
            $validated['neighborhood'] = Str::title(trim($validated['neighborhood']));
        }

        return $validated;
    }

    /**
     * Remove the specified customer from storage.
     */
    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->orders()->exists()) {
            return redirect()->back()->with('error', 'No se puede eliminar el cliente porque tiene pedidos asociados.');
        }

        $customer->delete();

        return redirect()->back()
            ->with('success', 'Elemento enviado a la Papelera de Reciclaje. Estará disponible para recuperación durante 8 días calendario.')
            ->with('trashed', true);
    }
}
