<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_type_id')->nullable()->nullOnDelete()->constrained('product_types');
            $table->foreignId('workflow_id')->restrictOnDelete()->constrained('workflows');
            $table->string('name');
            $table->string('barcode', 100)->nullable();
            $table->unsignedInteger('base_production_minutes');
            $table->unsignedTinyInteger('buffer_percentage')->default(20);
            $table->decimal('base_price', 15, 2);
            $table->enum('production_time_unit', ['minutes', 'hours', 'days'])->default('minutes');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'barcode']);
        });

        Schema::create('orthogonal_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('orthogonal_attribute_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('orthogonal_attribute_id')->constrained('orthogonal_attributes')->cascadeOnDelete();
            $table->string('value');
            $table->string('sku_suffix', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name_label', 150);
            $table->string('sku', 80)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->integer('production_minutes_delta')->default(0);
            $table->decimal('price_override', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_variant_options', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedBigInteger('orthogonal_attribute_option_id');
            $table->foreign('orthogonal_attribute_option_id', 'fk_pvo_option')
                ->references('id')
                ->on('orthogonal_attribute_options')
                ->cascadeOnDelete();

            $table->primary(['product_variant_id', 'orthogonal_attribute_option_id']);
        });

        Schema::create('product_material_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->cascadeOnDelete()->constrained('product_variants');
            $table->foreignId('raw_material_id')->restrictOnDelete()->constrained('raw_materials');
            $table->decimal('quantity_required', 12, 3);
            $table->decimal('waste_percent', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_material_requirements');
        Schema::dropIfExists('product_variant_options');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('orthogonal_attribute_options');
        Schema::dropIfExists('orthogonal_attributes');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_types');
    }
};
