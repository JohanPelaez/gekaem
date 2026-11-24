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
        if (!Schema::hasTable('order_payment_schedules')) {
            Schema::create('order_payment_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->date('scheduled_date');
                $table->decimal('amount', 12, 2);
                $table->string('notes')->nullable();
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('payment_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'order_id', 'status', 'scheduled_date'], 'idx_ops_tenant_order_status_date');
                $table->index(['tenant_id', 'status', 'scheduled_date'], 'idx_ops_tenant_status_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_payment_schedules');
    }
};

