<?php

declare(strict_types=1);

use App\Http\Controllers\KanbanController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Redirección principal hacia el Tablero Kanban
Route::get('/', fn () => redirect()->route('kanban.index'));

// Módulo 5: Tablero Kanban (US = Pedidos, Tareas = Productos)
Route::prefix('kanban')->name('kanban.')->group(function (): void {
    Route::get('/', [KanbanController::class, 'index'])->name('index');
    Route::patch('/tasks/{productionTask}/move', [KanbanController::class, 'moveTask'])->name('tasks.move');
});

// Módulo 7: Tipos de Flujos y Estados Dinámicos
Route::prefix('workflows')->name('workflows.')->group(function (): void {
    Route::get('/', [WorkflowController::class, 'index'])->name('index');
    Route::post('/', [WorkflowController::class, 'store'])->name('store');
    Route::post('/{workflow}/stages', [WorkflowController::class, 'storeStage'])->name('stages.store');
    Route::patch('/stages/{stage}', [WorkflowController::class, 'updateStage'])->name('stages.update');
    Route::delete('/stages/{stage}', [WorkflowController::class, 'destroyStage'])->name('stages.destroy');
});

// Rutas placeholder para los demás módulos del MVP (Fase 4)
Route::get('/orders', fn () => Inertia::render('Placeholder', [
    'moduleName' => 'Pedidos y Predicción de Entrega',
    'moduleCode' => 'Módulo 1',
    'description' => 'Registro de pedidos con tiempos calculados, tiempos de holgura y fechas predichas de entrega.',
]))->name('orders.index');

Route::get('/products', fn () => Inertia::render('Placeholder', [
    'moduleName' => 'Productos, Variantes Ortogonales y Recetas BOM',
    'moduleCode' => 'Módulo 2',
    'description' => 'Administración de catálogo maestro, combinaciones cartesianas y consumo de materiales.',
]))->name('products.index');

Route::get('/raw-materials', fn () => Inertia::render('Placeholder', [
    'moduleName' => 'Materia Prima e Inventario',
    'moduleCode' => 'Módulo 6',
    'description' => 'Control de stock físico, stock reservado, alertas mínimas y movimientos Kardex.',
]))->name('raw-materials.index');

Route::get('/customers', fn () => Inertia::render('Placeholder', [
    'moduleName' => 'Gestión de Clientes',
    'moduleCode' => 'Módulo 3',
    'description' => 'Administración de clientes vinculados obligatoriamente a los pedidos.',
]))->name('customers.index');

Route::get('/suppliers', fn () => Inertia::render('Placeholder', [
    'moduleName' => 'Gestión de Proveedores',
    'moduleCode' => 'Módulo 4',
    'description' => 'Registro de proveedores de materia prima y tiempos de espera de reposición (lead time).',
]))->name('suppliers.index');