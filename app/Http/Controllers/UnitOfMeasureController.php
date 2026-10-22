<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UnitOfMeasure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UnitOfMeasureController extends Controller
{
    /**
     * Display a listing of units of measure (JSON or Inertia).
     */
    public function index(): JsonResponse
    {
        $units = UnitOfMeasure::orderBy('name', 'asc')->get(['id', 'name', 'abbreviation']);

        return response()->json($units);
    }

    /**
     * Store a newly created unit of measure.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abbreviation' => ['nullable', 'string', 'max:20'],
        ]);

        $unit = UnitOfMeasure::create([
            'name' => Str::title(trim($validated['name'])),
            'abbreviation' => !empty($validated['abbreviation']) ? trim($validated['abbreviation']) : null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $unit->id,
                'name' => $unit->name,
                'abbreviation' => $unit->abbreviation,
            ], 201);
        }

        return redirect()->back()->with('success', 'Unidad de medida creada con éxito.');
    }

    /**
     * Update the specified unit of measure.
     */
    public function update(Request $request, UnitOfMeasure $unitOfMeasure): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abbreviation' => ['nullable', 'string', 'max:20'],
        ]);

        $unitOfMeasure->update([
            'name' => Str::title(trim($validated['name'])),
            'abbreviation' => !empty($validated['abbreviation']) ? trim($validated['abbreviation']) : null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $unitOfMeasure->id,
                'name' => $unitOfMeasure->name,
                'abbreviation' => $unitOfMeasure->abbreviation,
            ]);
        }

        return redirect()->back()->with('success', 'Unidad de medida actualizada.');
    }

    /**
     * Remove the specified unit of measure.
     */
    public function destroy(Request $request, UnitOfMeasure $unitOfMeasure): JsonResponse|RedirectResponse
    {
        $unitOfMeasure->delete();

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return redirect()->back()->with('success', 'Unidad de medida eliminada.');
    }
}
