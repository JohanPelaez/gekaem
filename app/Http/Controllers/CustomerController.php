<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers with quick search and order count.
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

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => ['search' => $search],
        ]);
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

        return redirect()->back()->with('success', 'Cliente eliminado con éxito.');
    }
}
