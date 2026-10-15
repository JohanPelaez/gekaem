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
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->restrictOnDelete()->constrained('customers');
            $table->unsignedInteger('order_number');
            $table->enum('derived_status', ['pending', 'in_progress', 'blocked', 'ready', 'delivered'])->default('pending');
            $table->unsignedInteger('total_production_minutes')->default(0);
            $table->unsignedInteger('buffer_minutes')->default(0);
            $table->dateTime('predicted_delivery_at')->nullable();
            $table->dateTime('committed_delivery_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->unsignedInteger('kanban_swimlane_position')->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'derived_status', 'kanban_swimlane_position'], 'idx_orders_kanban');
        });

        Schema::create('production_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->restrictOnDelete()->constrained('product_variants');
            $table->foreignId('workflow_id')->restrictOnDelete()->constrained('workflows');
            $table->foreignId('current_stage_id')->restrictOnDelete()->constrained('workflow_stages');
            $table->string('item_name_snapshot', 150);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->unsignedInteger('estimated_minutes');
            $table->unsignedInteger('column_position')->default(0);
            $table->boolean('materials_consumed')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'workflow_id', 'current_stage_id', 'column_position'],
                'idx_tasks_kanban_col'
            );
            $table->index(['order_id', 'current_stage_id']);
        });

        Schema::table('raw_material_movements', function (Blueprint $table): void {
            $table->foreign('production_task_id')
                ->references('id')
                ->on('production_tasks')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table): void {
            $table->dropForeign(['production_task_id']);
        });

        Schema::dropIfExists('production_tasks');
        Schema::dropIfExists('orders');
    }
};
