<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Catalog\GenerateOrthogonalVariantsAction;
use App\Actions\Kanban\MoveProductionTaskAction;
use App\Actions\Kanban\SyncOrderDerivedStatusAction;
use App\Actions\Orders\CreateOrderAction;
use App\Actions\Orders\PredictDeliveryDateAction;
use App\Models\Customer;
use App\Models\OrthogonalAttribute;
use App\Models\OrthogonalAttributeOption;
use App\Models\Product;
use App\Models\ProductMaterialRequirement;
use App\Models\ProductType;
use App\Models\RawMaterial;
use App\Models\RawMaterialSupplier;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Support\ProductionTimeConverter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with realistic domain data.
     */
    public function run(): void
    {
        // 1. Usuario Demo y Tenant Principal
        $user = User::firstOrCreate(
            ['email' => 'admin@gekaem.test'],
            [
                'name' => 'Admin Gekaem',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'taller-creativo-gekaem'],
            [
                'name' => 'Taller Creativo Gekaem',
                'daily_productive_minutes' => 480,
                'default_buffer_percent' => 20,
                'settings' => [
                    'currency' => 'COP',
                    'workshop_hours' => '08:00 - 17:00',
                    'timezone' => 'America/Bogota',
                ],
            ]
        );

        // Asociar usuario al Tenant con rol 'owner'
        if (!$user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        }

        // Establecer tenant en sesión para consistencia con BelongsToTenant
        session(['tenant_id' => $tenant->id]);

        // 2. Proveedores (Suppliers)
        $supplier1 = Supplier::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Telas & Textiles del Valle',
            'contact_name' => 'Hernán Darío Correa',
            'phone' => '+57 300 123 4567',
            'email' => 'ventas@telasdelvalle.com',
            'lead_time_days' => 3,
        ]);

        $supplier2 = Supplier::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Insumos y Herrajes Pro',
            'contact_name' => 'Carolina Restrepo',
            'phone' => '+57 311 987 6543',
            'email' => 'contacto@herrajespro.com',
            'lead_time_days' => 2,
        ]);

        // 3. Materias Primas e Inventario (RawMaterials)
        $materialTela = RawMaterial::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Tela Drill Algodón',
            'sku' => 'MAT-DRL-01',
            'unit_of_measure' => 'metros',
            'current_stock' => 150.000,
            'reserved_stock' => 0.000,
            'min_stock_alert' => 20.000,
            'unit_cost' => 18.50,
        ]);

        $materialHilo = RawMaterial::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Hilo de Poliéster Alta Resistencia',
            'sku' => 'MAT-HIL-02',
            'unit_of_measure' => 'conos',
            'current_stock' => 45.000,
            'reserved_stock' => 0.000,
            'min_stock_alert' => 5.000,
            'unit_cost' => 6.20,
        ]);

        $materialCremallera = RawMaterial::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cremallera Reforzada 30cm',
            'sku' => 'MAT-CRM-03',
            'unit_of_measure' => 'unidades',
            'current_stock' => 200.000,
            'reserved_stock' => 0.000,
            'min_stock_alert' => 30.000,
            'unit_cost' => 1.50,
        ]);

        // Materia prima sin proveedor asignado (Cumpliendo Nota 4)
        $materialEtiqueta = RawMaterial::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Etiqueta Textil Artesanal',
            'sku' => 'MAT-ETQ-04',
            'unit_of_measure' => 'unidades',
            'current_stock' => 500.000,
            'reserved_stock' => 0.000,
            'min_stock_alert' => 50.000,
            'unit_cost' => 0.35,
        ]);

        // Enlaces con proveedores (raw_material_supplier)
        RawMaterialSupplier::create([
            'raw_material_id' => $materialTela->id,
            'supplier_id' => $supplier1->id,
            'purchase_price' => 18.50,
            'is_preferred' => true,
        ]);

        RawMaterialSupplier::create([
            'raw_material_id' => $materialHilo->id,
            'supplier_id' => $supplier2->id,
            'purchase_price' => 6.20,
            'is_preferred' => true,
        ]);

        RawMaterialSupplier::create([
            'raw_material_id' => $materialHilo->id,
            'supplier_id' => $supplier1->id,
            'purchase_price' => 6.50,
            'is_preferred' => false,
        ]);

        RawMaterialSupplier::create([
            'raw_material_id' => $materialCremallera->id,
            'supplier_id' => $supplier2->id,
            'purchase_price' => 1.50,
            'is_preferred' => true,
        ]);

        // 4. Flujos Kanban (Workflows) y Etapas (WorkflowStages)
        $workflowConfeccion = Workflow::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Flujo de Confección Textil',
            'description' => 'Proceso completo de corte, confección, ensamblaje y control de calidad.',
            'is_default' => true,
        ]);

        $stageEspera = WorkflowStage::create([
            'workflow_id' => $workflowConfeccion->id,
            'name' => 'En Espera',
            'color' => '#94a3b8',
            'position' => 1,
            'stage_category' => 'pending',
            'wip_limit' => null,
        ]);

        $stageCorte = WorkflowStage::create([
            'workflow_id' => $workflowConfeccion->id,
            'name' => 'Corte y Trazado',
            'color' => '#38bdf8',
            'position' => 2,
            'stage_category' => 'in_progress',
            'wip_limit' => 5,
        ]);

        $stageCostura = WorkflowStage::create([
            'workflow_id' => $workflowConfeccion->id,
            'name' => 'Costura y Ensamble',
            'color' => '#f59e0b',
            'position' => 3,
            'stage_category' => 'in_progress',
            'wip_limit' => 4,
        ]);

        $stageCalidad = WorkflowStage::create([
            'workflow_id' => $workflowConfeccion->id,
            'name' => 'Control de Calidad',
            'color' => '#a855f7',
            'position' => 4,
            'stage_category' => 'in_progress',
            'wip_limit' => 3,
        ]);

        $stageBloqueado = WorkflowStage::create([
            'workflow_id' => $workflowConfeccion->id,
            'name' => 'Detenido / Bloqueado',
            'color' => '#ef4444',
            'position' => 5,
            'stage_category' => 'blocked',
            'wip_limit' => null,
        ]);

        $stageTerminado = WorkflowStage::create([
            'workflow_id' => $workflowConfeccion->id,
            'name' => 'Terminado',
            'color' => '#10b981',
            'position' => 6,
            'stage_category' => 'done',
            'wip_limit' => null,
        ]);

        // Segundo flujo: Estampado Rápido
        $workflowEstampado = Workflow::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Flujo de Estampado Rápido',
            'description' => 'Flujo ágil para productos con personalización directa y secado.',
            'is_default' => false,
        ]);

        $stageDiseno = WorkflowStage::create([
            'workflow_id' => $workflowEstampado->id,
            'name' => 'Cola de Diseño',
            'color' => '#94a3b8',
            'position' => 1,
            'stage_category' => 'pending',
            'wip_limit' => null,
        ]);

        $stageImpresion = WorkflowStage::create([
            'workflow_id' => $workflowEstampado->id,
            'name' => 'Impresión y Planchado',
            'color' => '#3b82f6',
            'position' => 2,
            'stage_category' => 'in_progress',
            'wip_limit' => 3,
        ]);

        $stageEmpacado = WorkflowStage::create([
            'workflow_id' => $workflowEstampado->id,
            'name' => 'Empacado Final',
            'color' => '#10b981',
            'position' => 3,
            'stage_category' => 'done',
            'wip_limit' => null,
        ]);

        // 5. Tipos de Producto
        $typePrendas = ProductType::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Prendas de Vestir',
        ]);

        $typeAccesorios = ProductType::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Accesorios Personalizados',
        ]);

        // 6. Atributos Ortogonales y Opciones
        $attrColor = OrthogonalAttribute::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Color',
        ]);

        $optColorNegro = OrthogonalAttributeOption::create([
            'orthogonal_attribute_id' => $attrColor->id,
            'value' => 'Negro',
            'sku_suffix' => 'NEG',
        ]);

        $optColorBlanco = OrthogonalAttributeOption::create([
            'orthogonal_attribute_id' => $attrColor->id,
            'value' => 'Blanco',
            'sku_suffix' => 'BLA',
        ]);

        $optColorAzul = OrthogonalAttributeOption::create([
            'orthogonal_attribute_id' => $attrColor->id,
            'value' => 'Azul Marino',
            'sku_suffix' => 'AZUL',
        ]);

        $optColorVerde = OrthogonalAttributeOption::create([
            'orthogonal_attribute_id' => $attrColor->id,
            'value' => 'Verde Oliva',
            'sku_suffix' => 'VERD',
        ]);

        $attrTalla = OrthogonalAttribute::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Talla',
        ]);

        $optTallaM = OrthogonalAttributeOption::create([
            'orthogonal_attribute_id' => $attrTalla->id,
            'value' => 'Talla M',
            'sku_suffix' => 'M',
        ]);

        $optTallaL = OrthogonalAttributeOption::create([
            'orthogonal_attribute_id' => $attrTalla->id,
            'value' => 'Talla L',
            'sku_suffix' => 'L',
        ]);

        // 7. Productos con Unidades de Tiempo Distintas
        $generateVariantsAction = new GenerateOrthogonalVariantsAction();

        // Producto 1: Rápido (45 minutos)
        $productGorra = Product::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'product_type_id' => $typeAccesorios->id,
            'workflow_id' => $workflowEstampado->id,
            'name' => 'Gorra Bordada Personalizada',
            'barcode' => '7701234567891',
            'production_time_unit' => 'minutes',
            'base_production_minutes' => 45,
            'buffer_percentage' => 15,
            'base_price' => 25.00,
            'is_active' => true,
        ]);

        // Generar variantes de Gorra (Color: Negro, Blanco)
        $variantsGorra = $generateVariantsAction->execute($productGorra, [
            [$optColorNegro->id, $optColorBlanco->id],
        ]);

        // Receta BOM para Gorra
        ProductMaterialRequirement::create([
            'product_id' => $productGorra->id,
            'product_variant_id' => null,
            'raw_material_id' => $materialHilo->id,
            'quantity_required' => 0.050,
            'waste_percent' => 5.00,
        ]);

        ProductMaterialRequirement::create([
            'product_id' => $productGorra->id,
            'product_variant_id' => null,
            'raw_material_id' => $materialEtiqueta->id,
            'quantity_required' => 1.000,
            'waste_percent' => 0.00,
        ]);

        // Producto 2: Complejo (2 días laborales = 960 minutos productivos)
        $minutesChaqueta = ProductionTimeConverter::toMinutes(2.0, 'days', $tenant->daily_productive_minutes);

        $productChaqueta = Product::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'product_type_id' => $typePrendas->id,
            'workflow_id' => $workflowConfeccion->id,
            'name' => 'Chaqueta Cortavientos Premium',
            'barcode' => '7701234567892',
            'production_time_unit' => 'days',
            'base_production_minutes' => $minutesChaqueta,
            'buffer_percentage' => 20,
            'base_price' => 120.00,
            'is_active' => true,
        ]);

        // Generar variantes ortogonales de Chaqueta: Talla (M, L) x Color (Azul, Verde) = 4 variantes
        $variantsChaqueta = $generateVariantsAction->execute($productChaqueta, [
            [$optTallaM->id, $optTallaL->id],
            [$optColorAzul->id, $optColorVerde->id],
        ]);

        // Receta BOM para Chaqueta
        ProductMaterialRequirement::create([
            'product_id' => $productChaqueta->id,
            'product_variant_id' => null,
            'raw_material_id' => $materialTela->id,
            'quantity_required' => 2.200,
            'waste_percent' => 5.00,
        ]);

        ProductMaterialRequirement::create([
            'product_id' => $productChaqueta->id,
            'product_variant_id' => null,
            'raw_material_id' => $materialCremallera->id,
            'quantity_required' => 1.000,
            'waste_percent' => 0.00,
        ]);

        ProductMaterialRequirement::create([
            'product_id' => $productChaqueta->id,
            'product_variant_id' => null,
            'raw_material_id' => $materialHilo->id,
            'quantity_required' => 0.150,
            'waste_percent' => 5.00,
        ]);

        ProductMaterialRequirement::create([
            'product_id' => $productChaqueta->id,
            'product_variant_id' => null,
            'raw_material_id' => $materialEtiqueta->id,
            'quantity_required' => 1.000,
            'waste_percent' => 0.00,
        ]);

        // 8. Clientes (Customers)
        $customer1 = Customer::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Carlos Mendoza - Boutique Urbana',
            'phone' => '+57 310 555 1234',
            'email' => 'carlos@boutiqueurbana.co',
            'tax_id' => '900123456-1',
            'address' => 'Calle 10 # 43E-20, Medellín',
            'notes' => 'Cliente preferencial con pedidos recurrentes.',
        ]);

        $customer2 = Customer::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'Empresas Antioquia S.A.S.',
            'phone' => '+57 320 444 9876',
            'email' => 'compras@empresasantioquia.com',
            'tax_id' => '890987654-3',
            'address' => 'Carrera 48 # 26-85, Medellín',
            'notes' => 'Dotaciones y uniformes corporativos.',
        ]);

        $customer3 = Customer::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'name' => 'María Fernanda Gómez',
            'phone' => '+57 301 777 4321',
            'email' => 'mafe.gomez@gmail.com',
            'tax_id' => '1037654321',
            'address' => 'Circular 4 # 73-12, Laureles',
            'notes' => 'Pedidos individuales con personalización especial.',
        ]);

        // 9. Crear Pedidos usando CreateOrderAction y mover tareas con MoveProductionTaskAction
        $predictAction = new PredictDeliveryDateAction();
        $createOrderAction = new CreateOrderAction($predictAction);
        $syncStatusAction = new SyncOrderDerivedStatusAction();
        $moveTaskAction = new MoveProductionTaskAction($syncStatusAction);

        $variantChaquetaM_Azul = $variantsChaqueta->first();
        $variantChaquetaL_Verde = $variantsChaqueta->last();
        $variantGorraNegro = $variantsGorra->first();
        $variantGorraBlanco = $variantsGorra->last();

        // Pedido 1: 'pending' (Todas las tareas en estado inicial 'En Espera' y 'Cola de Diseño')
        $orderPending = $createOrderAction->execute($tenant, [
            'customer_id' => $customer1->id,
            'notes' => 'Pedido de colección inicial. Requiere entrega programada.',
            'items' => [
                ['product_variant_id' => $variantChaquetaM_Azul->id, 'quantity' => 2],
                ['product_variant_id' => $variantGorraNegro->id, 'quantity' => 5],
            ],
        ]);

        // Pedido 2: 'in_progress' (Se mueve la tarea a 'Corte y Trazado' -> consume materiales)
        $orderInProgress = $createOrderAction->execute($tenant, [
            'customer_id' => $customer2->id,
            'notes' => 'Dotación para personal de campo. En proceso de corte urgente.',
            'items' => [
                ['product_variant_id' => $variantChaquetaL_Verde->id, 'quantity' => 3],
            ],
        ]);

        $taskInProgress = $orderInProgress->tasks->first();
        if ($taskInProgress !== null) {
            $moveTaskAction->execute($taskInProgress, $stageCorte->id, 1);
        }

        // Pedido 3: 'ready' (Se mueve la tarea hasta el estado final 'Empacado Final')
        $orderReady = $createOrderAction->execute($tenant, [
            'customer_id' => $customer3->id,
            'notes' => 'Gorras para evento promocional. Listo para despacho.',
            'items' => [
                ['product_variant_id' => $variantGorraBlanco->id, 'quantity' => 2],
            ],
        ]);

        $taskReady = $orderReady->tasks->first();
        if ($taskReady !== null) {
            // Mover a impresión primero para consumir insumos
            $moveTaskAction->execute($taskReady, $stageImpresion->id, 1);
            // Mover a terminado para que la orden pase a 'ready'
            $moveTaskAction->execute($taskReady, $stageEmpacado->id, 1);
        }
    }
}
