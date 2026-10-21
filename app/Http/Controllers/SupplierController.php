<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    /**
     * Display a listing of suppliers with search and raw material count.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        $suppliers = Supplier::query()
            ->when(!empty($search), function ($query) use ($search): void {
                $query->where(function ($sub) use ($search): void {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->withCount('rawMaterials')
            ->with(['rawMaterials' => fn ($q) => $q->select('raw_materials.id', 'raw_materials.name', 'raw_materials.unit_of_measure')])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Store a newly created supplier in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $validated['name'] = Str::title(trim($validated['name']));
        Supplier::create($validated);

        return redirect()->back()->with('success', 'Proveedor registrado con éxito.');
    }

    /**
     * Update the specified supplier in storage.
     */
    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $validated['name'] = Str::title(trim($validated['name']));
        $supplier->update($validated);

        return redirect()->back()->with('success', 'Proveedor actualizado con éxito.');
    }

    /**
     * Remove the specified supplier from storage.
     */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()->back()->with('success', 'Proveedor eliminado con éxito.');
    }
}
