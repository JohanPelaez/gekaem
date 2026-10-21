<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\OrthogonalAttribute;
use App\Models\OrthogonalAttributeOption;
use App\Models\ProductType;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuickCreateController extends Controller
{
    /**
     * Quick create a Product Type.
     */
    public function productType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
        ]);

        $type = ProductType::create([
            'name' => Str::title(trim($validated['name'])),
        ]);

        return response()->json([
            'id' => $type->id,
            'name' => $type->name,
        ], 201);
    }

    /**
     * Quick create a Workflow with basic default stages.
     */
    public function workflow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $workflow = DB::transaction(function () use ($validated): Workflow {
            $wf = Workflow::create([
                'name' => Str::title(trim($validated['name'])),
                'description' => $validated['description'] ?? null,
                'is_default' => false,
            ]);

            WorkflowStage::create([
                'workflow_id' => $wf->id,
                'name' => 'Pendiente',
                'color' => '#64748b',
                'position' => 1,
                'stage_category' => 'pending',
            ]);

            WorkflowStage::create([
                'workflow_id' => $wf->id,
                'name' => 'En Proceso',
                'color' => '#3b82f6',
                'position' => 2,
                'stage_category' => 'in_progress',
            ]);

            WorkflowStage::create([
                'workflow_id' => $wf->id,
                'name' => 'Terminado',
                'color' => '#10b981',
                'position' => 3,
                'stage_category' => 'done',
            ]);

            return $wf;
        });

        return response()->json([
            'id' => $workflow->id,
            'name' => $workflow->name,
        ], 201);
    }

    /**
     * Quick create a Supplier.
     */
    public function supplier(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $supplier = Supplier::create([
            'name' => Str::title(trim($validated['name'])),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'lead_time_days' => $validated['lead_time_days'] ?? 1,
        ]);

        return response()->json([
            'id' => $supplier->id,
            'name' => $supplier->name,
        ], 201);
    }

    /**
     * Quick create a Customer.
     */
    public function customer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100'],
        ]);

        $customer = Customer::create([
            'name' => Str::title(trim($validated['name'])),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
        ]);

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
        ], 201);
    }

    /**
     * Quick create a Raw Material.
     */
    public function rawMaterial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'unit_of_measure' => ['required', 'string', 'max:20'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'current_stock' => ['nullable', 'numeric', 'min:0'],
            'min_stock_alert' => ['nullable', 'numeric', 'min:0'],
        ]);

        $material = RawMaterial::create([
            'name' => Str::title(trim($validated['name'])),
            'unit_of_measure' => $validated['unit_of_measure'],
            'unit_cost' => $validated['unit_cost'] ?? 0,
            'current_stock' => $validated['current_stock'] ?? 0,
            'reserved_stock' => 0,
            'min_stock_alert' => $validated['min_stock_alert'] ?? 0,
        ]);

        return response()->json([
            'id' => $material->id,
            'name' => $material->name,
            'unit_of_measure' => $material->unit_of_measure,
            'unit_cost' => (float) $material->unit_cost,
        ], 201);
    }

    /**
     * Quick create a Unit of Measure.
     */
    public function unitOfMeasure(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abbreviation' => ['nullable', 'string', 'max:20'],
        ]);

        $unit = UnitOfMeasure::create([
            'name' => Str::title(trim($validated['name'])),
            'abbreviation' => !empty($validated['abbreviation']) ? trim($validated['abbreviation']) : null,
        ]);

        return response()->json([
            'id' => $unit->id,
            'name' => $unit->name,
            'abbreviation' => $unit->abbreviation,
        ], 201);
    }

    /**
     * Quick create an Orthogonal Attribute and optional initial options.
     */
    public function attribute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'options' => ['nullable'],
        ]);

        $attribute = DB::transaction(function () use ($validated): OrthogonalAttribute {
            $attr = OrthogonalAttribute::create([
                'name' => Str::title(trim($validated['name'])),
            ]);

            $rawOptions = $validated['options'] ?? null;
            $optionValues = [];

            if (is_string($rawOptions)) {
                $optionValues = array_map('trim', explode(',', $rawOptions));
            } elseif (is_array($rawOptions)) {
                $optionValues = array_map(fn ($val) => trim((string) $val), $rawOptions);
            }

            foreach ($optionValues as $val) {
                if ($val !== '') {
                    $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', $val);
                    $suffix = strtoupper(substr($cleaned !== '' ? $cleaned : 'OPT', 0, 4));
                    $attr->options()->create([
                        'value' => $val,
                        'sku_suffix' => $suffix,
                    ]);
                }
            }

            return $attr->load('options');
        });

        return response()->json([
            'id' => $attribute->id,
            'name' => $attribute->name,
            'options' => $attribute->options,
        ], 201);
    }

    /**
     * Quick create an option for an Orthogonal Attribute.
     */
    public function attributeOption(Request $request, OrthogonalAttribute $orthogonalAttribute): JsonResponse
    {
        $validated = $request->validate([
            'value' => ['required', 'string', 'max:100'],
            'sku_suffix' => ['nullable', 'string', 'max:20'],
        ]);

        $val = trim($validated['value']);
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', $val);
        $defaultSuffix = strtoupper(substr($cleaned !== '' ? $cleaned : 'OPT', 0, 4));
        $suffix = !empty($validated['sku_suffix']) ? trim($validated['sku_suffix']) : $defaultSuffix;

        $option = $orthogonalAttribute->options()->create([
            'value' => $val,
            'sku_suffix' => $suffix,
        ]);

        return response()->json([
            'id' => $option->id,
            'orthogonal_attribute_id' => $option->orthogonal_attribute_id,
            'value' => $option->value,
            'sku_suffix' => $option->sku_suffix,
        ], 201);
    }
}
