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
        Schema::create('order_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('system_key')->nullable()->default('custom');
            $table->string('color', 20)->default('#64748b');
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'position'], 'idx_order_statuses_pos');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('order_status_id')
                ->nullable()
                ->after('derived_status')
                ->constrained('order_statuses')
                ->nullOnDelete();
        });

        // Iterar sobre todos los tenants existentes e insertar los 4 estados iniciales por defecto
        $tenants = DB::table('tenants')->get();
        $now = now();

        $defaultStatuses = [
            ['name' => 'Pendiente', 'system_key' => 'pending', 'color' => '#64748b', 'position' => 1],
            ['name' => 'En Producción', 'system_key' => 'in_progress', 'color' => '#3b82f6', 'position' => 2],
            ['name' => 'Bloqueado', 'system_key' => 'blocked', 'color' => '#ef4444', 'position' => 3],
            ['name' => 'Listo para Entrega', 'system_key' => 'ready', 'color' => '#10b981', 'position' => 4],
        ];

        foreach ($tenants as $tenant) {
            $tenantStatusMap = [];

            foreach ($defaultStatuses as $def) {
                $existing = DB::table('order_statuses')
                    ->where('tenant_id', $tenant->id)
                    ->where('system_key', $def['system_key'])
                    ->first();

                if ($existing) {
                    $tenantStatusMap[$def['system_key']] = $existing->id;
                } else {
                    $id = DB::table('order_statuses')->insertGetId([
                        'tenant_id' => $tenant->id,
                        'name' => $def['name'],
                        'system_key' => $def['system_key'],
                        'color' => $def['color'],
                        'position' => $def['position'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $tenantStatusMap[$def['system_key']] = $id;
                }
            }

            // Vincular pedidos existentes asignándoles el order_status_id correspondiente
            $orders = DB::table('orders')->where('tenant_id', $tenant->id)->get();
            $defaultPendingId = $tenantStatusMap['pending'] ?? null;

            foreach ($orders as $order) {
                $derived = (string) ($order->derived_status ?? 'pending');
                $targetStatusKey = match ($derived) {
                    'in_progress' => 'in_progress',
                    'blocked' => 'blocked',
                    'ready', 'delivered' => 'ready',
                    default => 'pending',
                };

                $assignedId = $tenantStatusMap[$targetStatusKey] ?? $defaultPendingId;
                if ($assignedId) {
                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['order_status_id' => $assignedId]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['order_status_id']);
            $table->dropColumn('order_status_id');
        });

        Schema::dropIfExists('order_statuses');
    }
};
