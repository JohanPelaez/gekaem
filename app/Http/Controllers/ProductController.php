<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Catalog\GenerateOrthogonalVariantsAction;
use App\Models\OrthogonalAttribute;
use App\Models\OrthogonalAttributeOption;
use App\Models\Product;
use App\Models\ProductMaterialRequirement;
use App\Models\ProductType;
use App\Models\ProductVariant;
use App\Models\ProductionTask;
use App\Models\RawMaterial;
use App\Models\Tenant;
use App\Models\Workflow;
use App\Support\ProductionTimeConverter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Display a listing of products, types and orthogonal axes.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        $products = Product::query()
            ->when(!empty($search), function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            })
            ->with([
                'productType',
                'workflow',
                'materialRequirements.rawMaterial',
                'variants.attributeOptions.attribute',
            ])
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'productTypes' => ProductType::orderBy('name')->get(['id', 'name']),
            'workflows' => Workflow::orderBy('name')->get(['id', 'name']),
            'rawMaterials' => RawMaterial::orderBy('name')->get(['id', 'name', 'unit_of_measure', 'current_stock', 'reserved_stock']),
            'orthogonalAttributes' => OrthogonalAttribute::with('options')->orderBy('name')->get(),
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Store a newly created product with BOM requirements and orthogonal variants.
     */
    public function store(Request $request, GenerateOrthogonalVariantsAction $variantsAction): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'product_type_id' => ['nullable', 'integer', 'exists:product_types,id'],
            'workflow_id' => ['required', 'integer', 'exists:workflows,id'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'time_value' => ['required', 'numeric', 'min:0.1'],
            'production_time_unit' => ['required', 'in:minutes,hours,days'],
            'buffer_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'materials' => ['nullable', 'array'],
            'materials.*.raw_material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'materials.*.quantity_required' => ['required', 'numeric', 'min:0.001'],
            'materials.*.waste_percent' => ['nullable', 'numeric', 'min:0'],
            'grouped_options' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($validated, $variantsAction): void {
            $tenant = Tenant::find(session('tenant_id')) ?? auth()->user()?->currentTenant();
            $dailyMinutes = $tenant?->daily_productive_minutes ?? 480;

            $baseMinutes = ProductionTimeConverter::toMinutes(
                (float) $validated['time_value'],
                $validated['production_time_unit'],
                $dailyMinutes
            );

            /** @var Product $product */
            $product = Product::create([
                'name' => $validated['name'],
                'product_type_id' => $validated['product_type_id'] ?? null,
                'workflow_id' => $validated['workflow_id'],
                'barcode' => $validated['barcode'] ?? null,
                'base_production_minutes' => $baseMinutes,
                'production_time_unit' => $validated['production_time_unit'],
                'buffer_percentage' => $validated['buffer_percentage'],
                'base_price' => $validated['base_price'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            // Guardar requerimientos de materiales (BOM)
            foreach ($validated['materials'] ?? [] as $mat) {
                ProductMaterialRequirement::create([
                    'product_id' => $product->id,
                    'raw_material_id' => $mat['raw_material_id'],
                    'quantity_required' => $mat['quantity_required'],
                    'waste_percent' => $mat['waste_percent'] ?? 0,
                ]);
            }

            // Generar variantes ortogonales o variante estándar única
            $groupedOptions = array_filter($validated['grouped_options'] ?? []);
            if (!empty($groupedOptions)) {
                $variantsAction->execute($product, $groupedOptions);
            } else {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'name_label' => 'Estándar',
                    'sku' => "PRD-{$product->id}-STD",
                    'production_minutes_delta' => 0,
                    'is_active' => true,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Producto, receta BOM y variantes guardados con éxito.');
    }

    /**
     * Update an existing product.
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'product_type_id' => ['nullable', 'integer', 'exists:product_types,id'],
            'workflow_id' => ['required', 'integer', 'exists:workflows,id'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'time_value' => ['required', 'numeric', 'min:0.1'],
            'production_time_unit' => ['required', 'in:minutes,hours,days'],
            'buffer_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'materials' => ['nullable', 'array'],
            'materials.*.raw_material_id' => ['required', 'integer', 'exists:raw_materials,id'],
            'materials.*.quantity_required' => ['required', 'numeric', 'min:0.001'],
            'materials.*.waste_percent' => ['nullable', 'numeric', 'min:0'],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['required', 'integer', 'exists:product_variants,id'],
            'variants.*.sku' => ['nullable', 'string', 'max:80'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.production_minutes_delta' => ['nullable', 'integer'],
            'variants.*.price_override' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($product, $validated): void {
            $dailyMinutes = $product->tenant?->daily_productive_minutes ?? 480;

            $baseMinutes = ProductionTimeConverter::toMinutes(
                (float) $validated['time_value'],
                $validated['production_time_unit'],
                $dailyMinutes
            );

            $product->update([
                'name' => $validated['name'],
                'product_type_id' => $validated['product_type_id'] ?? null,
                'workflow_id' => $validated['workflow_id'],
                'barcode' => $validated['barcode'] ?? null,
                'base_production_minutes' => $baseMinutes,
                'production_time_unit' => $validated['production_time_unit'],
                'buffer_percentage' => $validated['buffer_percentage'],
                'base_price' => $validated['base_price'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            // Sincronizar BOM
            $product->materialRequirements()->delete();
            foreach ($validated['materials'] ?? [] as $mat) {
                ProductMaterialRequirement::create([
                    'product_id' => $product->id,
                    'raw_material_id' => $mat['raw_material_id'],
                    'quantity_required' => $mat['quantity_required'],
                    'waste_percent' => $mat['waste_percent'] ?? 0,
                ]);
            }

            // Actualizar deltas y precios de variantes existentes
            foreach ($validated['variants'] ?? [] as $varData) {
                ProductVariant::where('id', $varData['id'])
                    ->where('product_id', $product->id)
                    ->update([
                        'sku' => $varData['sku'] ?? null,
                        'barcode' => $varData['barcode'] ?? null,
                        'production_minutes_delta' => $varData['production_minutes_delta'] ?? 0,
                        'price_override' => $varData['price_override'] ?? null,
                    ]);
            }
        });

        return redirect()->back()->with('success', 'Producto actualizado correctamente.');
    }

    /**
     * Remove the specified product.
     */
    public function destroy(Product $product): RedirectResponse
    {
        $variantIds = $product->variants()->pluck('id');

        if (ProductionTask::whereIn('product_variant_id', $variantIds)->exists()) {
            return redirect()->back()->with('error', 'No se puede eliminar el producto porque tiene tareas registradas en pedidos.');
        }

        $product->delete();

        return redirect()->back()->with('success', 'Producto eliminado con éxito.');
    }

    /**
     * Store a product type.
     */
    public function storeType(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        ProductType::create($validated);

        return redirect()->back()->with('success', 'Tipo de producto creado.');
    }

    /**
     * Store an orthogonal attribute.
     */
    public function storeAttribute(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        OrthogonalAttribute::create($validated);

        return redirect()->back()->with('success', 'Eje de atributo ortogonal creado.');
    }

    /**
     * Store an option in an orthogonal attribute.
     */
    public function storeOption(Request $request, OrthogonalAttribute $orthogonalAttribute): RedirectResponse
    {
        $validated = $request->validate([
            'value' => ['required', 'string', 'max:100'],
            'sku_suffix' => ['nullable', 'string', 'max:20'],
        ]);

        $orthogonalAttribute->options()->create($validated);

        return redirect()->back()->with('success', 'Opción agregada al atributo.');
    }
}
