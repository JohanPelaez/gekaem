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
        if (Schema::hasTable('order_statuses') && !Schema::hasColumn('order_statuses', 'is_history_status')) {
            Schema::table('order_statuses', function (Blueprint $table): void {
                $table->boolean('is_history_status')->default(false)->after('position');
            });
        }

        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'shipped_at')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->timestamp('shipped_at')->nullable()->after('delivered_at');
            });
        }

        // Marcar automáticamente como estado de historial cualquier estado existente llamado "Enviado" (case-insensitive)
        if (Schema::hasTable('order_statuses') && Schema::hasColumn('order_statuses', 'is_history_status')) {
            DB::table('order_statuses')
                ->whereRaw('LOWER(TRIM(name)) = ?', ['enviado'])
                ->update(['is_history_status' => true]);

            // Establecer shipped_at en pedidos que ya se encontraban en un estado de historial
            if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'shipped_at')) {
                $historyStatusIds = DB::table('order_statuses')
                    ->where('is_history_status', true)
                    ->pluck('id');

                if ($historyStatusIds->isNotEmpty()) {
                    DB::table('orders')
                        ->whereIn('order_status_id', $historyStatusIds)
                        ->whereNull('shipped_at')
                        ->update(['shipped_at' => DB::raw('COALESCE(delivered_at, updated_at)')]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'shipped_at')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('shipped_at');
            });
        }

        if (Schema::hasTable('order_statuses') && Schema::hasColumn('order_statuses', 'is_history_status')) {
            Schema::table('order_statuses', function (Blueprint $table): void {
                $table->dropColumn('is_history_status');
            });
        }
    }
};

