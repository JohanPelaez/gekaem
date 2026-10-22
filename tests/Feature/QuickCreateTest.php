<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickCreateTest extends TestCase
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

    public function test_quick_create_product_type(): void
    {
        $response = $this->postJson('/quick/product-types', [
            'name' => 'ropa deportiva',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name'])
            ->assertJson(['name' => 'Ropa Deportiva']);

        $this->assertDatabaseHas('product_types', ['name' => 'Ropa Deportiva']);
    }

    public function test_quick_create_workflow_with_default_stages(): void
    {
        $response = $this->postJson('/quick/workflows', [
            'name' => 'flujo express',
            'description' => 'Flujo con etapas rápidas',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name'])
            ->assertJson(['name' => 'Flujo Express']);

        $workflowId = $response->json('id');
        $this->assertDatabaseHas('workflows', ['id' => $workflowId, 'name' => 'Flujo Express']);
        $this->assertDatabaseHas('workflow_stages', ['workflow_id' => $workflowId, 'name' => 'Pendiente', 'stage_category' => 'pending']);
        $this->assertDatabaseHas('workflow_stages', ['workflow_id' => $workflowId, 'name' => 'En Proceso', 'stage_category' => 'in_progress']);
        $this->assertDatabaseHas('workflow_stages', ['workflow_id' => $workflowId, 'name' => 'Terminado', 'stage_category' => 'done']);
    }

    public function test_quick_create_supplier(): void
    {
        $response = $this->postJson('/quick/suppliers', [
            'name' => 'hilos y botones del norte',
            'phone' => '3001234567',
            'lead_time_days' => 2,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name'])
            ->assertJson(['name' => 'Hilos Y Botones Del Norte']);

        $this->assertDatabaseHas('suppliers', ['name' => 'Hilos Y Botones Del Norte']);
    }

    public function test_quick_create_workflow_with_custom_stages(): void
    {
        $response = $this->postJson('/quick/workflows', [
            'name' => 'flujo personalizado',
            'description' => 'Flujo con etapas a medida',
            'stages' => [
                ['name' => 'Corte', 'color' => '#f59e0b', 'stage_category' => 'pending'],
                ['name' => 'Estampado', 'color' => '#3b82f6', 'stage_category' => 'in_progress'],
                ['name' => 'Control Calidad', 'color' => '#ef4444', 'stage_category' => 'blocked'],
                ['name' => 'Empaque Final', 'color' => '#10b981', 'stage_category' => 'done'],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'stages'])
            ->assertJson(['name' => 'Flujo Personalizado']);

        $workflowId = $response->json('id');
        $this->assertDatabaseHas('workflows', ['id' => $workflowId, 'name' => 'Flujo Personalizado']);
        $this->assertDatabaseHas('workflow_stages', ['workflow_id' => $workflowId, 'name' => 'Corte', 'position' => 1, 'stage_category' => 'pending']);
        $this->assertDatabaseHas('workflow_stages', ['workflow_id' => $workflowId, 'name' => 'Estampado', 'position' => 2, 'stage_category' => 'in_progress']);
        $this->assertDatabaseHas('workflow_stages', ['workflow_id' => $workflowId, 'name' => 'Control Calidad', 'position' => 3, 'stage_category' => 'blocked']);
        $this->assertDatabaseHas('workflow_stages', ['workflow_id' => $workflowId, 'name' => 'Empaque Final', 'position' => 4, 'stage_category' => 'done']);
    }

    public function test_quick_create_customer(): void
    {
        $response = $this->postJson('/quick/customers', [
            'name' => 'tienda la 80',
            'phone' => '3119876543',
            'department' => 'antioquia',
            'city' => 'medellin',
            'neighborhood' => 'laureles',
            'address' => 'Circular 4 # 73-10',
            'notes' => 'Tocar timbre 201',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'phone', 'department', 'city', 'neighborhood', 'address', 'notes'])
            ->assertJson([
                'name' => 'Tienda La 80',
                'department' => 'Antioquia',
                'city' => 'Medellin',
                'neighborhood' => 'Laureles',
                'address' => 'Circular 4 # 73-10',
                'notes' => 'Tocar timbre 201',
            ]);

        $this->assertDatabaseHas('customers', [
            'name' => 'Tienda La 80',
            'department' => 'Antioquia',
            'city' => 'Medellin',
            'neighborhood' => 'Laureles',
        ]);
    }

    public function test_quick_create_raw_material(): void
    {
        $response = $this->postJson('/quick/raw-materials', [
            'name' => 'hilo poliester negro',
            'unit_of_measure' => 'rollo',
            'unit_cost' => 12500,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'unit_of_measure', 'unit_cost'])
            ->assertJson([
                'name' => 'Hilo Poliester Negro',
                'unit_of_measure' => 'rollo',
                'unit_cost' => 12500,
            ]);

        $this->assertDatabaseHas('raw_materials', ['name' => 'Hilo Poliester Negro', 'unit_of_measure' => 'rollo']);
    }

    public function test_quick_create_unit_of_measure(): void
    {
        $response = $this->postJson('/quick/units-of-measure', [
            'name' => 'tonelada metrica',
            'abbreviation' => 'ton',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'abbreviation'])
            ->assertJson([
                'name' => 'Tonelada Metrica',
                'abbreviation' => 'ton',
            ]);

        $this->assertDatabaseHas('units_of_measure', [
            'name' => 'Tonelada Metrica',
            'abbreviation' => 'ton',
        ]);
    }

    public function test_quick_create_attribute_with_options(): void
    {
        $response = $this->postJson('/quick/attributes', [
            'name' => 'talla calzado',
            'options' => '38, 39, 40',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'options'])
            ->assertJson(['name' => 'Talla Calzado']);

        $attributeId = $response->json('id');
        $this->assertDatabaseHas('orthogonal_attributes', ['id' => $attributeId, 'name' => 'Talla Calzado']);
        $this->assertDatabaseHas('orthogonal_attribute_options', ['orthogonal_attribute_id' => $attributeId, 'value' => '38']);
        $this->assertDatabaseHas('orthogonal_attribute_options', ['orthogonal_attribute_id' => $attributeId, 'value' => '39']);
        $this->assertDatabaseHas('orthogonal_attribute_options', ['orthogonal_attribute_id' => $attributeId, 'value' => '40']);
    }

    public function test_quick_create_attribute_option(): void
    {
        $attrResponse = $this->postJson('/quick/attributes', [
            'name' => 'color premium',
        ]);
        $attributeId = $attrResponse->json('id');

        $optionResponse = $this->postJson("/quick/attributes/{$attributeId}/options", [
            'value' => 'Negro Mate',
            'sku_suffix' => 'NGMT',
        ]);

        $optionResponse->assertStatus(201)
            ->assertJsonStructure(['id', 'orthogonal_attribute_id', 'value', 'sku_suffix'])
            ->assertJson([
                'orthogonal_attribute_id' => $attributeId,
                'value' => 'Negro Mate',
                'sku_suffix' => 'NGMT',
            ]);

        $this->assertDatabaseHas('orthogonal_attribute_options', [
            'orthogonal_attribute_id' => $attributeId,
            'value' => 'Negro Mate',
            'sku_suffix' => 'NGMT',
        ]);
    }

    public function test_unit_of_measure_update_and_delete(): void
    {
        $tenantId = session('tenant_id');
        $unit = \App\Models\UnitOfMeasure::create([
            'tenant_id' => $tenantId,
            'name' => 'Centimetro Lineal',
            'abbreviation' => 'cml',
        ]);

        $updateResponse = $this->patchJson("/units-of-measure/{$unit->id}", [
            'name' => 'Centímetro Lineal Modificado',
            'abbreviation' => 'cm-lin',
        ]);

        $updateResponse->assertStatus(200)
            ->assertJson(['name' => 'Centímetro Lineal Modificado', 'abbreviation' => 'cm-lin']);

        $this->assertDatabaseHas('units_of_measure', [
            'id' => $unit->id,
            'name' => 'Centímetro Lineal Modificado',
        ]);

        $deleteResponse = $this->deleteJson("/units-of-measure/{$unit->id}");
        $deleteResponse->assertStatus(200);

        $this->assertDatabaseMissing('units_of_measure', ['id' => $unit->id]);
    }

    public function test_quick_create_product(): void
    {
        $workflow = \App\Models\Workflow::firstOrFail();

        $response = $this->postJson('/quick/products', [
            'name' => 'taza magica personalizada',
            'workflow_id' => $workflow->id,
            'base_price' => 35000,
            'production_time_minutes' => 45,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'product' => [
                    'id',
                    'name',
                    'base_price',
                    'base_production_minutes',
                    'variants',
                ],
            ])
            ->assertJsonPath('product.name', 'Taza Magica Personalizada');

        $productId = $response->json('product.id');
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Taza Magica Personalizada',
            'base_production_minutes' => 45,
        ]);
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $productId,
        ]);
    }
}

