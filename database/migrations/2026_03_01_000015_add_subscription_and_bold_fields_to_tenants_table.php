<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
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
            $table->dateTime('trial_ends_at')->nullable()->after('default_buffer_percent');
            $table->string('subscription_status', 30)->default('trialing')->after('trial_ends_at');
            $table->string('plan_type', 30)->default('standard')->after('subscription_status');
            $table->string('bold_customer_id', 150)->nullable()->after('plan_type');
            $table->string('payment_method_token', 255)->nullable()->after('bold_customer_id');
            $table->string('card_last_four', 4)->nullable()->after('payment_method_token');
            $table->string('card_brand', 50)->nullable()->after('card_last_four');

            $table->index('subscription_status');
        });

        // Inicializar datos para tenants existentes calculando trial_ends_at = created_at + 15 días
        $existingTenants = DB::table('tenants')->get();
        foreach ($existingTenants as $tenant) {
            $createdAt = $tenant->created_at ? Carbon::parse($tenant->created_at) : now();
            $settings = [];
            if (!empty($tenant->settings)) {
                $decoded = json_decode((string) $tenant->settings, true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }

            $plan = $settings['plan'] ?? 'standard';
            $trialEnds = isset($settings['trial_ends_at'])
                ? Carbon::parse($settings['trial_ends_at'])
                : $createdAt->copy()->addDays(15);

            DB::table('tenants')->where('id', $tenant->id)->update([
                'trial_ends_at' => $trialEnds,
                'subscription_status' => 'trialing',
                'plan_type' => in_array($plan, ['standard', 'pro'], true) ? $plan : 'standard',
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex(['subscription_status']);
            $table->dropColumn([
                'trial_ends_at',
                'subscription_status',
                'plan_type',
                'bold_customer_id',
                'payment_method_token',
                'card_last_four',
                'card_brand',
            ]);
        });
    }
};

