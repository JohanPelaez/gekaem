<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('order_statuses') || !Schema::hasTable('orders')) {
            return;
        }

        if (!Schema::hasColumn('order_statuses', 'is_history_status') || !Schema::hasColumn('orders', 'shipped_at')) {
            return;
        }

        // 1. Marcar is_history_status = true en todos los estados existentes llamados "Enviado" o "Entregado"
        DB::table('order_statuses')
            ->whereRaw('LOWER(TRIM(name)) IN (?, ?)', ['enviado', 'entregado'])
            ->update(['is_history_status' => true]);

        // 2. Rescatar pedidos en limbo: delivered o con shipped_at, o en un estado de historial sin derived_status/shipped_at sincronizado
        $tenants = DB::table('tenants')->pluck('id');
        $now = now();

        foreach ($tenants as $tenantId) {
            $historyStatus = DB::table('order_statuses')
                ->where('tenant_id', $tenantId)
                ->where('is_history_status', true)
                ->orderBy('position')
                ->first();

            // Verificar si el tenant tiene pedidos entregados o con shipped_at
            $limboOrdersCount = DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->where(function ($q): void {
                    $q->where('derived_status', 'delivered')
                        ->orWhereNotNull('shipped_at');
                })
                ->count();

            if ($limboOrdersCount === 0 && !$historyStatus) {
                continue;
            }

            // Si hay pedidos en limbo pero no existe columna de historial, buscar por coincidencia parcial o crear "Enviado"
            if (!$historyStatus) {
                $historyStatus = DB::table('order_statuses')
                    ->where('tenant_id', $tenantId)
                    ->where(function ($q): void {
                        $q->whereRaw('LOWER(TRIM(name)) LIKE ?', ['%enviado%'])
                            ->orWhereRaw('LOWER(TRIM(name)) LIKE ?', ['%entregado%']);
                    })
                    ->orderBy('position')
                    ->first();

                if ($historyStatus) {
                    DB::table('order_statuses')
                        ->where('id', $historyStatus->id)
                        ->update(['is_history_status' => true, 'updated_at' => $now]);
                } else {
                    $maxPos = (int) (DB::table('order_statuses')
                        ->where('tenant_id', $tenantId)
                        ->max('position') ?? 0);

                    $historyStatusId = DB::table('order_statuses')->insertGetId([
                        'tenant_id' => $tenantId,
                        'name' => 'Enviado',
                        'system_key' => 'custom',
                        'color' => '#10b981',
                        'position' => $maxPos + 1,
                        'is_history_status' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $historyStatus = (object) ['id' => $historyStatusId];
                }
            }

            $historyStatusIds = DB::table('order_statuses')
                ->where('tenant_id', $tenantId)
                ->where('is_history_status', true)
                ->pluck('id')
                ->all();

            // Sincronizar todos los pedidos delivered o shipped_at != null hacia el estado de historial del tenant
            DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($historyStatusIds): void {
                    $q->where('derived_status', 'delivered')
                        ->orWhereNotNull('shipped_at');
                    if (!empty($historyStatusIds)) {
                        $q->orWhereIn('order_status_id', $historyStatusIds);
                    }
                })
                ->update([
                    'order_status_id' => $historyStatus->id,
                    'derived_status' => 'delivered',
                    'shipped_at' => DB::raw('COALESCE(shipped_at, delivered_at, updated_at)'),
                    'delivered_at' => DB::raw('COALESCE(delivered_at, shipped_at, updated_at)'),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Migración incremental de sincronización de datos; no destructiva.
    }
};

