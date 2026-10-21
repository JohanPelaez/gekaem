<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderPaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuickCreateController;
use App\Http\Controllers\RawMaterialController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TenantSettingsController;
use App\Http\Controllers\UnitOfMeasureController;
use App\Http\Controllers\WorkflowController;
use Illuminate\Support\Facades\Route;

// Rutas de Autenticación
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/demo-login', [AuthController::class, 'demoLogin'])->name('demo-login');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Redirección principal hacia el Tablero Kanban
Route::get('/', fn () => redirect()->route('kanban.index'));

// Rutas protegidas de la aplicación
Route::middleware(['auth'])->group(function (): void {
    // Módulo 5: Tablero Kanban (US = Pedidos, Tareas = Productos)
    Route::prefix('kanban')->name('kanban.')->group(function (): void {
        Route::get('/', [KanbanController::class, 'index'])->name('index');
        Route::patch('/tasks/{productionTask}/move', [KanbanController::class, 'moveTask'])->name('tasks.move');
        Route::patch('/orders/{order}/macro-status', [KanbanController::class, 'macroStatus'])->name('orders.macro-status');
    });

    // Módulo 1: Pedidos, Calculadora Predictiva de Entrega y Cierre Kanban
    Route::prefix('orders')->name('orders.')->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::post('/', [OrderController::class, 'store'])->name('store');
        Route::post('/predict', [OrderController::class, 'predict'])->name('predict');
        Route::patch('/{order}/deliver', [OrderController::class, 'markDelivered'])->name('deliver');
        Route::post('/{order}/payments', [OrderPaymentController::class, 'store'])->name('payments.store');
    });

    Route::delete('/payments/{payment}', [OrderPaymentController::class, 'destroy'])->name('payments.destroy');

    // Módulo 2: Productos, Recetas BOM y Variantes Ortogonales (Notas 7, 8, 9)
    Route::prefix('products')->name('products.')->group(function (): void {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::patch('/{product}', [ProductController::class, 'update'])->name('update');
        Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
    });

    // Rutas auxiliares para Tipos de Producto y Ejes Ortogonales
    Route::post('/product-types', [ProductController::class, 'storeType'])->name('product-types.store');
    Route::post('/orthogonal-attributes', [ProductController::class, 'storeAttribute'])->name('orthogonal-attributes.store');
    Route::post('/orthogonal-attributes/{orthogonalAttribute}/options', [ProductController::class, 'storeOption'])->name('orthogonal-attributes.options.store');

    // Módulo 3: Clientes (Nota 5: Todo pedido requiere cliente)
    Route::prefix('customers')->name('customers.')->group(function (): void {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::patch('/{customer}', [CustomerController::class, 'update'])->name('update');
        Route::delete('/{customer}', [CustomerController::class, 'destroy'])->name('destroy');
    });

    // Módulo 4: Proveedores y Lead Time
    Route::prefix('suppliers')->name('suppliers.')->group(function (): void {
        Route::get('/', [SupplierController::class, 'index'])->name('index');
        Route::post('/', [SupplierController::class, 'store'])->name('store');
        Route::patch('/{supplier}', [SupplierController::class, 'update'])->name('update');
        Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->name('destroy');
    });

    // Módulo 6: Materia Prima e Inventario (Nota 4: 0, 1 o múltiples proveedores)
    Route::prefix('raw-materials')->name('raw-materials.')->group(function (): void {
        Route::get('/', [RawMaterialController::class, 'index'])->name('index');
        Route::post('/', [RawMaterialController::class, 'store'])->name('store');
        Route::patch('/{rawMaterial}', [RawMaterialController::class, 'update'])->name('update');
        Route::delete('/{rawMaterial}', [RawMaterialController::class, 'destroy'])->name('destroy');
        Route::post('/{rawMaterial}/movements', [RawMaterialController::class, 'adjustStock'])->name('movements.store');
    });

    // Módulo 7: Tipos de Flujos y Estados Dinámicos
    Route::prefix('workflows')->name('workflows.')->group(function (): void {
        Route::get('/', [WorkflowController::class, 'index'])->name('index');
        Route::post('/', [WorkflowController::class, 'store'])->name('store');
        Route::patch('/{workflow}', [WorkflowController::class, 'update'])->name('update');
        Route::post('/{workflow}/stages', [WorkflowController::class, 'storeStage'])->name('stages.store');
        Route::post('/{workflow}/stages/reorder', [WorkflowController::class, 'reorderStages'])->name('stages.reorder');
        Route::patch('/stages/{stage}', [WorkflowController::class, 'updateStage'])->name('stages.update');
        Route::delete('/stages/{stage}', [WorkflowController::class, 'destroyStage'])->name('stages.destroy');
    });

    // Unidades de Medida
    Route::prefix('units-of-measure')->name('units-of-measure.')->group(function (): void {
        Route::get('/', [UnitOfMeasureController::class, 'index'])->name('index');
        Route::post('/', [UnitOfMeasureController::class, 'store'])->name('store');
        Route::patch('/{unitOfMeasure}', [UnitOfMeasureController::class, 'update'])->name('update');
        Route::delete('/{unitOfMeasure}', [UnitOfMeasureController::class, 'destroy'])->name('destroy');
    });

    // Rutas de Creación Rápida en Contexto (Quick Create)
    Route::prefix('quick')->name('quick.')->group(function (): void {
        Route::post('/product-types', [QuickCreateController::class, 'productType'])->name('product-types');
        Route::post('/workflows', [QuickCreateController::class, 'workflow'])->name('workflows');
        Route::post('/suppliers', [QuickCreateController::class, 'supplier'])->name('suppliers');
        Route::post('/customers', [QuickCreateController::class, 'customer'])->name('customers');
        Route::post('/raw-materials', [QuickCreateController::class, 'rawMaterial'])->name('raw-materials');
        Route::post('/units-of-measure', [QuickCreateController::class, 'unitOfMeasure'])->name('units-of-measure');
        Route::post('/attributes', [QuickCreateController::class, 'attribute'])->name('attributes');
        Route::post('/attributes/{orthogonalAttribute}/options', [QuickCreateController::class, 'attributeOption'])->name('attributes.options');
    });

    // Perfil de Usuario y Ajustes
    Route::get('/settings', [ProfileController::class, 'edit'])->name('settings.index');
    Route::get('/profile', fn () => redirect()->route('settings.index', ['tab' => 'profile']))->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'updateProfile'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    // Configuración del Taller y Equipo (Solo Dueño o Admin)
    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::patch('/tenant', [TenantSettingsController::class, 'update'])->name('tenant.update');
        Route::patch('/', [TenantSettingsController::class, 'update'])->name('update');
        Route::post('/members', [TenantSettingsController::class, 'addMember'])->name('members.store');
        Route::delete('/members/{user}', [TenantSettingsController::class, 'removeMember'])->name('members.destroy');
    });

    // Panel SuperAdmin SaaS (Solo SuperAdmin)
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', [AdminManagementController::class, 'index'])->name('index');
        Route::post('/users', [AdminManagementController::class, 'storeUser'])->name('users.store');
        Route::post('/switch-tenant/{tenant}', [AdminManagementController::class, 'switchTenant'])->name('switch-tenant');
    });
});