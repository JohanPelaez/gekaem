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
        // 1. Tabla customers: agregar document_number después de name
        Schema::table('customers', function (Blueprint $table): void {
            if (!Schema::hasColumn('customers', 'document_number')) {
                $table->string('document_number', 50)->nullable()->after('name');
            }
        });

        // 2. Tabla suppliers: agregar payment_methods y notes
        Schema::table('suppliers', function (Blueprint $table): void {
            if (!Schema::hasColumn('suppliers', 'payment_methods')) {
                $table->text('payment_methods')->nullable()->after('email');
            }
            if (!Schema::hasColumn('suppliers', 'notes')) {
                $table->text('notes')->nullable()->after('lead_time_days');
            }
        });

        // 3. Tabla production_tasks: verificar unit_price y agregar discount_percentage
        Schema::table('production_tasks', function (Blueprint $table): void {
            if (!Schema::hasColumn('production_tasks', 'unit_price')) {
                $table->decimal('unit_price', 12, 2)->nullable()->default(0)->after('quantity');
            }
            if (!Schema::hasColumn('production_tasks', 'discount_percentage')) {
                $table->decimal('discount_percentage', 5, 2)->default(0)->after('unit_price');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('production_tasks', 'discount_percentage')) {
                $table->dropColumn('discount_percentage');
            }
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            if (Schema::hasColumn('suppliers', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('suppliers', 'payment_methods')) {
                $table->dropColumn('payment_methods');
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'document_number')) {
                $table->dropColumn('document_number');
            }
        });
    }
};
