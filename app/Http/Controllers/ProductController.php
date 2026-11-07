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
use App\Models\UnitOfMeasure;
use App\Models\Workflow;
use App\Support\ProductionTimeConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Display a listing of products, types and orthogonal axes.
     */
    public function index(Request $request): Response
    {
        $tenant = auth()->user()?->currentTenant();
        if ($tenant) {
            Tenant::ensureDefaultData($tenant->id);
        }

        $search = $request->string('search')->trim()->value();
        $typeId = $request->input('product_type_id');
        $wfId = $request->input('workflow_id');

        $products = Product::query()
            ->when(!empty($search), function ($query) use ($search): void {
                $query->where(fn ($sub) => $sub->where('name', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%"));
            })
            ->when(!empty($typeId), fn ($q) => $q->where('product_type_id', (int) $typeId))
            ->when(!empty($wfId), fn ($q) => $q->where('workflow_id', (int) $wfId))
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
            'productTypes' => ProductType::orderBy('name', 'asc')->get(['id', 'name']),
            'workflows' => Workflow::orderBy('name', 'asc')->get(['id', 'name']),
            'rawMaterials' => RawMaterial::orderBy('name', 'asc')->get(['id', 'name', 'unit_of_measure', 'current_stock', 'reserved_stock']),
            'orthogonalAttributes' => OrthogonalAttribute::with('options')->orderBy('name', 'asc')->get(),
            'unitsOfMeasure' => UnitOfMeasure::orderBy('name', 'asc')->get(['id', 'name', 'abbreviation']),
            'filters' => [
                'search' => $search,
                'product_type_id' => $typeId ? (int) $typeId : '',
                'workflow_id' => $wfId ? (int) $wfId : '',
            ],
        ]);
    }

    /**
     * Store a newly created product with BOM requirements and orthogonal variants.
     */
    public function store(Request $request, GenerateOrthogonalVariantsAction $variantsAction): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'product_type_id' => ['nullable', 'integer', 'exists:product_types,id'],
            'workflow_id' => ['required', 'integer', 'exists:workflows,id'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'barcode_format' => ['nullable', 'string', 'in:CODE128,CODE39,EAN13,UPC,EAN8,ITF14'],
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

        $product = DB::transaction(function () use ($validated, $variantsAction): Product {
            $tenant = Tenant::find(session('tenant_id')) ?? auth()->user()?->currentTenant();
            $dailyMinutes = $tenant?->daily_productive_minutes ?? 480;

            $baseMinutes = ProductionTimeConverter::toMinutes(
                (float) $validated['time_value'],
                $validated['production_time_unit'],
                $dailyMinutes
            );

            /** @var Product $product */
            $product = Product::create([
                'name' => Str::title(trim($validated['name'])),
                'product_type_id' => $validated['product_type_id'] ?? null,
                'workflow_id' => $validated['workflow_id'],
                'barcode' => $validated['barcode'] ?? null,
                'barcode_format' => $validated['barcode_format'] ?? 'CODE128',
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

            return $product;
        });

        if ($request->wantsJson() || $request->is('quick/*')) {
            return response()->json([
                'product' => $product->load([
                    'variants.attributeOptions.attribute',
                    'productType',
                    'workflow',
                    'materials',
                    'materialRequirements.rawMaterial',
                ]),
            ], 201);
        }

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
            'barcode_format' => ['nullable', 'string', 'in:CODE128,CODE39,EAN13,UPC,EAN8,ITF14'],
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
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name_label' => ['nullable', 'string', 'max:150'],
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
                'name' => Str::title(trim($validated['name'])),
                'product_type_id' => $validated['product_type_id'] ?? null,
                'workflow_id' => $validated['workflow_id'],
                'barcode' => $validated['barcode'] ?? null,
                'barcode_format' => $validated['barcode_format'] ?? 'CODE128',
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

            // Gestionar variantes: actualizar existentes, crear nuevas y remover no vinculadas
            $keptVariantIds = [];
            foreach ($validated['variants'] ?? [] as $varData) {
                if (!empty($varData['id'])) {
                    $variant = ProductVariant::where('id', $varData['id'])
                        ->where('product_id', $product->id)
                        ->first();
                    if ($variant) {
                        $variant->update([
                            'name_label' => !empty($varData['name_label']) ? trim($varData['name_label']) : $variant->name_label,
                            'sku' => !empty($varData['sku']) ? trim($varData['sku']) : null,
                            'barcode' => $varData['barcode'] ?? null,
                            'production_minutes_delta' => $varData['production_minutes_delta'] ?? 0,
                            'price_override' => isset($varData['price_override']) && $varData['price_override'] !== '' ? $varData['price_override'] : null,
                        ]);
                        $keptVariantIds[] = $variant->id;
                    }
                } else {
                    $newVariant = ProductVariant::create([
                        'product_id' => $product->id,
                        'name_label' => !empty($varData['name_label']) ? trim($varData['name_label']) : 'Variante',
                        'sku' => !empty($varData['sku']) ? trim($varData['sku']) : null,
                        'barcode' => $varData['barcode'] ?? null,
                        'production_minutes_delta' => $varData['production_minutes_delta'] ?? 0,
                        'price_override' => isset($varData['price_override']) && $varData['price_override'] !== '' ? $varData['price_override'] : null,
                        'is_active' => true,
                    ]);
                    $keptVariantIds[] = $newVariant->id;
                }
            }

            // Eliminar únicamente las variantes removidas que no estén vinculadas a production_tasks
            if (!empty($keptVariantIds)) {
                $removableVariants = ProductVariant::where('product_id', $product->id)
                    ->whereNotIn('id', $keptVariantIds)
                    ->get();

                foreach ($removableVariants as $remVariant) {
                    if (!ProductionTask::where('product_variant_id', $remVariant->id)->exists()) {
                        $remVariant->delete();
                    }
                }
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

        ProductType::create([
            'name' => Str::title(trim($validated['name'])),
        ]);

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

        OrthogonalAttribute::create([
            'name' => Str::title(trim($validated['name'])),
        ]);

        return redirect()->back()->with('success', 'Eje de atributo ortogonal creado.');
    }

    /**
     * Store an option in an orthogonal attribute.
     */
    public function storeOption(Request $request, OrthogonalAttribute $orthogonalAttribute): RedirectResponse
    {
        $rawValues = explode(',', (string) $request->input('value', ''));
        $values = array_values(array_filter(array_map('trim', $rawValues)));

        if (count($values) > 1) {
            foreach ($values as $val) {
                $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', $val);
                $suffix = strtoupper(substr($cleaned !== '' ? $cleaned : 'OPT', 0, 4));
                $orthogonalAttribute->options()->create([
                    'value' => $val,
                    'sku_suffix' => $suffix,
                ]);
            }
            return redirect()->back()->with('success', 'Opciones agregadas al atributo.');
        }

        $validated = $request->validate([
            'value' => ['required', 'string', 'max:100'],
            'sku_suffix' => ['nullable', 'string', 'max:20'],
        ]);

        $val = trim($validated['value']);
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', $val);
        $defaultSuffix = strtoupper(substr($cleaned !== '' ? $cleaned : 'OPT', 0, 4));

        $orthogonalAttribute->options()->create([
            'value' => $val,
            'sku_suffix' => !empty($validated['sku_suffix']) ? trim($validated['sku_suffix']) : $defaultSuffix,
        ]);

        return redirect()->back()->with('success', 'Opción agregada al atributo.');
    }

    /**
     * Quick create a product and its default variant, or process full creation if detailed payload is provided.
     */
    public function quickStore(Request $request, GenerateOrthogonalVariantsAction $variantsAction): JsonResponse
    {
        if ($request->has('time_value') || $request->has('materials') || $request->has('grouped_options')) {
            /** @var JsonResponse $response */
            $response = $this->store($request, $variantsAction);
            return $response;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'product_type_id' => ['nullable', 'integer', 'exists:product_types,id'],
            'workflow_id' => ['nullable', 'integer', 'exists:workflows,id'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'production_time_minutes' => ['nullable', 'numeric', 'min:1'],
        ]);

        $product = DB::transaction(function () use ($validated): Product {
            $workflowId = !empty($validated['workflow_id'])
                ? (int) $validated['workflow_id']
                : Workflow::first()?->id;

            if (!$workflowId) {
                abort(422, 'No hay flujos de trabajo configurados en el taller.');
            }

            $baseMinutes = (int) ($validated['production_time_minutes'] ?? 30);

            /** @var Product $product */
            $product = Product::create([
                'name' => Str::title(trim($validated['name'])),
                'product_type_id' => $validated['product_type_id'] ?? null,
                'workflow_id' => $workflowId,
                'base_production_minutes' => $baseMinutes,
                'production_time_unit' => 'minutes',
                'buffer_percentage' => 20,
                'base_price' => $validated['base_price'],
                'is_active' => true,
            ]);

            ProductVariant::create([
                'product_id' => $product->id,
                'name_label' => 'Estándar',
                'sku' => "PRD-{$product->id}-STD",
                'production_minutes_delta' => 0,
                'price_override' => null,
                'is_active' => true,
            ]);

            return $product;
        });

        return response()->json([
            'product' => $product->load(['variants.attributeOptions.attribute', 'productType', 'workflow', 'materialRequirements.rawMaterial', 'materials']),
        ], 201);
    }
}
