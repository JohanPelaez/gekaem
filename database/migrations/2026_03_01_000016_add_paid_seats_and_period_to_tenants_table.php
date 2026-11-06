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
            if (!Schema::hasColumn('tenants', 'paid_seats')) {
                $table->unsignedSmallInteger('paid_seats')->default(1)->after('plan_type');
            }
            if (!Schema::hasColumn('tenants', 'current_period_ends_at')) {
                $table->timestamp('current_period_ends_at')->nullable()->after('trial_ends_at');
            }
        });

        // Inicializar paid_seats según plan_type para tenants existentes
        $tenants = DB::table('tenants')->get();
        foreach ($tenants as $tenant) {
            $isPro = ($tenant->plan_type ?? 'standard') === 'pro';
            $userCount = DB::table('tenant_user')->where('tenant_id', $tenant->id)->count();
            $baseSeats = $isPro ? 5 : 1;
            $seats = max($baseSeats, $userCount);

            DB::table('tenants')->where('id', $tenant->id)->update([
                'paid_seats' => $seats,
                'current_period_ends_at' => $tenant->trial_ends_at ?? now()->addMonth(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            if (Schema::hasColumn('tenants', 'current_period_ends_at')) {
                $table->dropColumn('current_period_ends_at');
            }
            if (Schema::hasColumn('tenants', 'paid_seats')) {
                $table->dropColumn('paid_seats');
            }
        });
    }
};

