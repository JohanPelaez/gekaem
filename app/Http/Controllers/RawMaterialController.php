<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RawMaterial;
use App\Models\RawMaterialMovement;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RawMaterialController extends Controller
{
    /**
     * Display a listing of raw materials with suppliers and movements.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();
        $unit = $request->string('unit_of_measure')->trim()->value();
        $supplierId = $request->input('supplier_id');
        $lowStock = $request->boolean('low_stock');

        $rawMaterials = RawMaterial::query()
            ->when(!empty($search), function ($query) use ($search): void {
                $query->where(fn ($sub) => $sub->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
            })
            ->when(!empty($unit), fn ($q) => $q->where('unit_of_measure', $unit))
            ->when(!empty($supplierId), fn ($q) => $q->whereHas('suppliers', fn ($s) => $s->where('suppliers.id', (int) $supplierId)))
            ->when($lowStock, fn ($q) => $q->whereColumn('current_stock', '<=', 'min_stock_alert'))
            ->with(['suppliers' => fn ($q) => $q->withPivot('purchase_price', 'is_preferred')])
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $allSuppliers = Supplier::orderBy('name')->get(['id', 'name']);

        return Inertia::render('RawMaterials/Index', [
            'rawMaterials' => $rawMaterials,
            'allSuppliers' => $allSuppliers,
            'unitsOfMeasure' => UnitOfMeasure::orderBy('name')->get(['id', 'name', 'abbreviation']),
            'filters' => [
                'search' => $search,
                'unit_of_measure' => $unit,
                'supplier_id' => $supplierId ? (int) $supplierId : '',
                'low_stock' => $lowStock,
            ],
        ]);
    }

    /**
     * Store a newly created raw material with optional suppliers (Nota 4).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:50'],
            'unit_of_measure' => ['required', 'string', 'max:20'],
            'current_stock' => ['required', 'numeric', 'min:0'],
            'min_stock_alert' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'suppliers' => ['nullable', 'array'],
            'suppliers.*.supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'suppliers.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'suppliers.*.is_preferred' => ['boolean'],
        ]);

        DB::transaction(function () use ($validated): void {
            /** @var RawMaterial $material */
            $material = RawMaterial::create([
                'name' => Str::title(trim($validated['name'])),
                'sku' => $validated['sku'] ?? null,
                'unit_of_measure' => $validated['unit_of_measure'],
                'current_stock' => $validated['current_stock'],
                'reserved_stock' => 0,
                'min_stock_alert' => $validated['min_stock_alert'],
                'unit_cost' => $validated['unit_cost'],
            ]);

            // Si hay stock inicial mayor a 0, registrar movimiento de ajuste
            if ((float) $validated['current_stock'] > 0) {
                RawMaterialMovement::create([
                    'tenant_id' => $material->tenant_id,
                    'raw_material_id' => $material->id,
                    'type' => 'adjustment',
                    'quantity' => $validated['current_stock'],
                    'stock_after' => $validated['current_stock'],
                    'notes' => 'Registro de inventario inicial',
                ]);
            }

            // Nota 4: Asociar 0, 1 o múltiples proveedores mediante tabla pivote
            if (!empty($validated['suppliers'])) {
                $syncData = [];
                foreach ($validated['suppliers'] as $sup) {
                    $syncData[$sup['supplier_id']] = [
                        'purchase_price' => $sup['purchase_price'] ?? null,
                        'is_preferred' => $sup['is_preferred'] ?? false,
                    ];
                }
                $material->suppliers()->sync($syncData);
            }
        });

        return redirect()->back()->with('success', 'Materia prima registrada con éxito.');
    }

    /**
     * Update the specified raw material and its supplier links.
     */
    public function update(Request $request, RawMaterial $rawMaterial): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:50'],
            'unit_of_measure' => ['required', 'string', 'max:20'],
            'min_stock_alert' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'suppliers' => ['nullable', 'array'],
            'suppliers.*.supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'suppliers.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'suppliers.*.is_preferred' => ['boolean'],
        ]);

        DB::transaction(function () use ($rawMaterial, $validated): void {
            $rawMaterial->update([
                'name' => Str::title(trim($validated['name'])),
                'sku' => $validated['sku'] ?? null,
                'unit_of_measure' => $validated['unit_of_measure'],
                'min_stock_alert' => $validated['min_stock_alert'],
                'unit_cost' => $validated['unit_cost'],
            ]);

            // Sincronizar proveedores opcionales (Nota 4)
            $syncData = [];
            foreach ($validated['suppliers'] ?? [] as $sup) {
                $syncData[$sup['supplier_id']] = [
                    'purchase_price' => $sup['purchase_price'] ?? null,
                    'is_preferred' => $sup['is_preferred'] ?? false,
                ];
            }
            $rawMaterial->suppliers()->sync($syncData);
        });

        return redirect()->back()->with('success', 'Materia prima actualizada con éxito.');
    }

    /**
     * Adjust stock through manual purchases, adjustments, or returns.
     */
    public function adjustStock(Request $request, RawMaterial $rawMaterial): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:purchase,adjustment,return'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'direction' => ['required', 'in:add,subtract'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($rawMaterial, $validated): void {
            $qty = (float) $validated['quantity'];
            $isSubtract = $validated['direction'] === 'subtract';

            $newStock = $isSubtract
                ? max(0.0, (float) $rawMaterial->current_stock - $qty)
                : (float) $rawMaterial->current_stock + $qty;

            $rawMaterial->current_stock = $newStock;
            $rawMaterial->save();

            RawMaterialMovement::create([
                'tenant_id' => $rawMaterial->tenant_id,
                'raw_material_id' => $rawMaterial->id,
                'type' => $validated['type'],
                'quantity' => $qty,
                'stock_after' => $newStock,
                'notes' => $validated['notes'] ?? 'Ajuste manual de stock',
            ]);
        });

        return redirect()->back()->with('success', 'Movimiento de inventario registrado correctamente.');
    }

    /**
     * Remove the specified raw material from storage.
     */
    public function destroy(RawMaterial $rawMaterial): RedirectResponse
    {
        if ($rawMaterial->productMaterialRequirements()->exists()) {
            return redirect()->back()->with('error', 'No se puede eliminar la materia prima porque está incluida en recetas BOM de productos.');
        }

        $rawMaterial->delete();

        return redirect()->back()->with('success', 'Materia prima eliminada con éxito.');
    }
}
