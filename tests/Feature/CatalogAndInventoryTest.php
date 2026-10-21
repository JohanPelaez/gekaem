<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OrthogonalAttribute;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAndInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::first();
        $this->actingAs($user);
        session(['tenant_id' => Tenant::first()->id]);
    }

    public function test_customers_crud(): void
    {
        // 1. Index
        $response = $this->get('/customers');
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Customers/Index')->has('customers'));

        // 2. Store
        $createResponse = $this->post('/customers', [
            'name' => 'Nuevo Cliente de Prueba',
            'phone' => '+57 300 999 8877',
            'email' => 'cliente.nuevo@test.com',
            'tax_id' => '123456789-0',
            'address' => 'Calle Falsa 123',
            'notes' => 'Cliente VIP',
        ]);
        $createResponse->assertRedirect();
        $this->assertDatabaseHas('customers', ['name' => 'Nuevo Cliente De Prueba']);

        // 3. Update
        $customer = Customer::where('name', 'Nuevo Cliente De Prueba')->firstOrFail();
        $updateResponse = $this->patch("/customers/{$customer->id}", [
            'name' => 'Cliente Modificado',
            'phone' => '+57 300 111 2233',
        ]);
        $updateResponse->assertRedirect();
        $this->assertDatabaseHas('customers', ['name' => 'Cliente Modificado']);

        // 4. Destroy
        $deleteResponse = $this->delete("/customers/{$customer->id}");
        $deleteResponse->assertRedirect();
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_suppliers_crud(): void
    {
        // 1. Index
        $response = $this->get('/suppliers');
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Suppliers/Index')->has('suppliers'));

        // 2. Store
        $createResponse = $this->post('/suppliers', [
            'name' => 'Insumos del Caribe',
            'contact_name' => 'Mateo Gómez',
            'phone' => '+57 315 555 6677',
            'email' => 'mateo@insumoscaribe.com',
            'lead_time_days' => 4,
        ]);
        $createResponse->assertRedirect();
        $this->assertDatabaseHas('suppliers', ['name' => 'Insumos Del Caribe', 'lead_time_days' => 4]);

        // 3. Update
        $supplier = Supplier::where('name', 'Insumos Del Caribe')->firstOrFail();
        $updateResponse = $this->patch("/suppliers/{$supplier->id}", [
            'name' => 'Insumos del Caribe S.A.S.',
            'lead_time_days' => 5,
        ]);
        $updateResponse->assertRedirect();
        $this->assertDatabaseHas('suppliers', ['name' => 'Insumos Del Caribe S.a.s.', 'lead_time_days' => 5]);

        // 4. Destroy
        $deleteResponse = $this->delete("/suppliers/{$supplier->id}");
        $deleteResponse->assertRedirect();
        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_raw_materials_crud_and_movements(): void
    {
        $supplier = Supplier::firstOrFail();

        // 1. Store with supplier pivot (Nota 4)
        $response = $this->post('/raw-materials', [
            'name' => 'Botones Metálicos 15mm',
            'sku' => 'MAT-BOT-01',
            'unit_of_measure' => 'unidades',
            'current_stock' => 500,
            'min_stock_alert' => 50,
            'unit_cost' => 0.25,
            'suppliers' => [
                [
                    'supplier_id' => $supplier->id,
                    'purchase_price' => 0.20,
                    'is_preferred' => true,
                ],
            ],
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('raw_materials', ['name' => 'Botones Metálicos 15Mm']);

        $material = RawMaterial::where('name', 'Botones Metálicos 15Mm')->firstOrFail();
        $this->assertCount(1, $material->suppliers);
        $this->assertSame($supplier->id, $material->suppliers->first()->id);

        // 2. Adjust Stock (Purchase movement)
        $adjustResponse = $this->post("/raw-materials/{$material->id}/movements", [
            'type' => 'purchase',
            'quantity' => 200,
            'direction' => 'add',
            'notes' => 'Factura compra #102',
        ]);
        $adjustResponse->assertRedirect();
        $material->refresh();
        $this->assertEquals(700.0, (float) $material->current_stock);
        $this->assertDatabaseHas('raw_material_movements', [
            'raw_material_id' => $material->id,
            'type' => 'purchase',
            'quantity' => 200,
            'stock_after' => 700,
        ]);

        // 3. Update
        $updateResponse = $this->patch("/raw-materials/{$material->id}", [
            'name' => 'Botones Metálicos Reforzados 15mm',
            'unit_of_measure' => 'unidades',
            'min_stock_alert' => 100,
            'unit_cost' => 0.30,
        ]);
        $updateResponse->assertRedirect();
        $this->assertDatabaseHas('raw_materials', ['name' => 'Botones Metálicos Reforzados 15Mm']);

        // 4. Destroy
        $this->delete("/raw-materials/{$material->id}")->assertRedirect();
        $this->assertDatabaseMissing('raw_materials', ['id' => $material->id]);
    }

    public function test_products_with_bom_and_orthogonal_variants(): void
    {
        $workflow = Workflow::firstOrFail();
        $rawMaterial = RawMaterial::firstOrFail();
        $colorAttr = OrthogonalAttribute::where('name', 'Color')->firstOrFail();
        $options = $colorAttr->options()->take(2)->get();

        // 1. Create product with BOM & orthogonal variants
        $response = $this->post('/products', [
            'name' => 'Polo Clásica Piqué',
            'workflow_id' => $workflow->id,
            'time_value' => 1.5,
            'production_time_unit' => 'hours', // 1.5 hours = 90 min
            'buffer_percentage' => 15,
            'base_price' => 35.00,
            'is_active' => true,
            'materials' => [
                [
                    'raw_material_id' => $rawMaterial->id,
                    'quantity_required' => 1.2,
                    'waste_percent' => 5,
                ],
            ],
            'grouped_options' => [
                $colorAttr->id => $options->pluck('id')->all(),
            ],
        ]);

        $response->assertRedirect();
        $product = Product::where('name', 'Polo Clásica Piqué')->firstOrFail();

        // Verify base production minutes normalized: 1.5h * 60 = 90 min
        $this->assertSame(90, (int) $product->base_production_minutes);
        $this->assertSame('hours', $product->production_time_unit);

        // Verify BOM requirements (Nota 8)
        $this->assertCount(1, $product->materialRequirements);
        $this->assertEquals(1.2, (float) $product->materialRequirements->first()->quantity_required);

        // Verify orthogonal variants created (Nota 9)
        $this->assertCount(2, $product->variants);
    }
}
