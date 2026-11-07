<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MaterialRequest;
use App\Models\RawMaterial;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MaterialRequestController extends Controller
{
    /**
     * Store a newly created material request from a team worker or owner.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $tenant = $user?->currentTenant();

        if ($tenant === null) {
            return redirect()->back()->with('error', 'No tienes un taller activo asociado.');
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:existing,new'],
            'raw_material_id' => ['required_if:type,existing', 'nullable', 'integer', 'exists:raw_materials,id'],
            'new_material_name' => ['required_if:type,new', 'nullable', 'string', 'max:150'],
            'requested_quantity' => ['required', 'numeric', 'min:0.01'],
            'unit' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $rawMaterialId = null;
        $unit = $validated['unit'] ?? null;
        $newMaterialName = null;

        if ($validated['type'] === 'existing') {
            $rawMaterialId = (int) $validated['raw_material_id'];
            $material = RawMaterial::find($rawMaterialId);
            if ($material && empty($unit)) {
                $unit = $material->unit_of_measure;
            }
        } else {
            $newMaterialName = trim((string) $validated['new_material_name']);
        }

        MaterialRequest::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'raw_material_id' => $rawMaterialId,
            'new_material_name' => $newMaterialName,
            'requested_quantity' => $validated['requested_quantity'],
            'unit' => $unit,
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        return redirect()->back()->with('success', 'Solicitud de materia prima enviada al CEO con éxito.');
    }

    /**
     * Update the status of a material request (approve or reject).
     * Restricted to Owner or Admin.
     */
    public function update(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $user = $request->user();
        abort_if(
            $user?->currentRole() === 'worker',
            403,
            'Solo el Dueño o Administrador puede aprobar o descartar solicitudes de materia prima.'
        );

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
        ]);

        $materialRequest->update([
            'status' => $validated['status'],
        ]);

        $statusLabel = $validated['status'] === 'approved' ? 'aprobada' : 'descartada';

        return redirect()->back()->with('success', "Solicitud de materia prima marcada como {$statusLabel}.");
    }
}

