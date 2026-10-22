<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workflow;
use App\Models\WorkflowStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowController extends Controller
{
    /**
     * Display all workflows and their dynamic Kanban stages.
     */
    public function index(): Response
    {
        $workflows = Workflow::with(['stages' => fn ($q) => $q->orderBy('position')])
            ->withCount('products')
            ->get();

        return Inertia::render('Workflows/Index', [
            'workflows' => $workflows,
        ]);
    }

    /**
     * Store a newly created workflow.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
        ]);

        if (!empty($validated['is_default'])) {
            Workflow::query()->update(['is_default' => false]);
        }

        $workflow = Workflow::create([
            'name' => Str::title(trim($validated['name'])),
            'description' => $validated['description'] ?? null,
            'is_default' => $validated['is_default'] ?? false,
        ]);

        $workflow->stages()->createMany([
            ['name' => 'Por Hacer', 'color' => '#94a3b8', 'position' => 1, 'stage_category' => 'pending'],
            ['name' => 'En Fabricación', 'color' => '#38bdf8', 'position' => 2, 'stage_category' => 'in_progress'],
            ['name' => 'Terminado', 'color' => '#10b981', 'position' => 3, 'stage_category' => 'done'],
        ]);

        return redirect()->back()->with('success', 'Flujo de trabajo creado con sus etapas iniciales.');
    }

    /**
     * Update an existing workflow.
     */
    public function update(Request $request, Workflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
        ]);

        if (!empty($validated['is_default'])) {
            Workflow::query()->update(['is_default' => false]);
        }

        $workflow->update([
            'name' => Str::title(trim($validated['name'])),
            'description' => $validated['description'] ?? null,
            'is_default' => $validated['is_default'] ?? false,
        ]);

        return redirect()->back()->with('success', 'Flujo de trabajo actualizado con éxito.');
    }

    /**
     * Add a dynamic stage to an existing workflow.
     */
    public function storeStage(Request $request, Workflow $workflow): RedirectResponse
    {
        if ($request->has('stage_type') && !$request->has('stage_category')) {
            $request->merge(['stage_category' => $request->input('stage_type')]);
        }
        if ($request->input('stage_category') === 'completed') {
            $request->merge(['stage_category' => 'done']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'integer', 'min:1'],
            'stage_category' => ['required', 'in:pending,in_progress,blocked,done'],
            'wip_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $maxPos = (int) $workflow->stages()->max('position');

        $workflow->stages()->create([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? '#64748b',
            'position' => $validated['position'] ?? ($maxPos + 1),
            'stage_category' => $validated['stage_category'],
            'wip_limit' => $validated['wip_limit'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Etapa agregada correctamente al flujo.');
    }

    /**
     * Update an existing stage.
     */
    public function updateStage(Request $request, WorkflowStage $stage): RedirectResponse
    {
        if ($request->has('stage_type') && !$request->has('stage_category')) {
            $request->merge(['stage_category' => $request->input('stage_type')]);
        }
        if ($request->input('stage_category') === 'completed') {
            $request->merge(['stage_category' => 'done']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'integer', 'min:1'],
            'stage_category' => ['required', 'in:pending,in_progress,blocked,done'],
            'wip_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $stage->update([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? $stage->color,
            'position' => $validated['position'] ?? $stage->position,
            'stage_category' => $validated['stage_category'],
            'wip_limit' => $validated['wip_limit'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Etapa actualizada con éxito.');
    }

    /**
     * Reorder stages within a workflow.
     */
    public function reorderStages(Request $request, Workflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'stage_ids' => ['required', 'array'],
            'stage_ids.*' => ['required', 'integer', 'exists:workflow_stages,id'],
        ]);

        DB::transaction(function () use ($workflow, $validated): void {
            foreach ($validated['stage_ids'] as $index => $stageId) {
                WorkflowStage::where('workflow_id', $workflow->id)
                    ->where('id', $stageId)
                    ->update(['position' => $index + 1]);
            }
        });

        return redirect()->back()->with('success', 'Orden de etapas actualizado con éxito.');
    }

    /**
     * Remove a stage if it contains no active production tasks.
     */
    public function destroyStage(WorkflowStage $stage): RedirectResponse
    {
        if ($stage->productionTasks()->exists()) {
            return redirect()->back()->with('error', 'No se puede eliminar la etapa porque contiene tareas asociadas.');
        }

        $stage->delete();

        return redirect()->back()->with('success', 'Etapa eliminada con éxito.');
    }
}
