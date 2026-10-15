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
        Schema::create('raw_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('unit_of_measure', 20);
            $table->decimal('current_stock', 12, 3)->default(0);
            $table->decimal('reserved_stock', 12, 3)->default(0);
            $table->decimal('min_stock_alert', 12, 3)->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('raw_material_supplier', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('raw_material_id')->constrained('raw_materials')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();

            $table->unique(['raw_material_id', 'supplier_id']);
        });

        Schema::create('raw_material_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('raw_material_id')->constrained('raw_materials')->cascadeOnDelete();
            $table->unsignedBigInteger('production_task_id')->nullable()->index();
            $table->enum('type', ['purchase', 'consumption', 'adjustment', 'return']);
            $table->decimal('quantity', 12, 3);
            $table->decimal('stock_after', 12, 3);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('raw_material_movements');
        Schema::dropIfExists('raw_material_supplier');
        Schema::dropIfExists('raw_materials');
    }
};
