<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            if (!Schema::hasColumn('tenants', 'billing_cycle')) {
                $table->string('billing_cycle', 20)->default('monthly')->after('plan_type');
            }
            if (!Schema::hasColumn('tenants', 'extra_ceo_seats')) {
                $table->unsignedInteger('extra_ceo_seats')->default(0)->after('paid_seats');
            }
            if (!Schema::hasColumn('tenants', 'extra_worker_seats')) {
                $table->unsignedInteger('extra_worker_seats')->default(0)->after('extra_ceo_seats');
            }
            if (!Schema::hasColumn('tenants', 'extra_workflows')) {
                $table->unsignedInteger('extra_workflows')->default(0)->after('extra_worker_seats');
            }
        });

        // Normalizar cualquier tenant existente con plan_type = 'standard' o NULL hacia 'basic'
        if (Schema::hasColumn('tenants', 'plan_type')) {
            DB::table('tenants')
                ->where('plan_type', 'standard')
                ->orWhereNull('plan_type')
                ->update(['plan_type' => 'basic']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['billing_cycle', 'extra_ceo_seats', 'extra_worker_seats', 'extra_workflows'],
                fn (string $col): bool => Schema::hasColumn('tenants', $col)
            ));

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
