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
        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('abbreviation', 20)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        // Sembrar unidades por defecto para todos los tenants existentes
        $defaultUnits = [
            ['name' => 'Unidad', 'abbreviation' => 'und'],
            ['name' => 'Par', 'abbreviation' => 'par'],
            ['name' => 'Docena', 'abbreviation' => 'doc'],
            ['name' => 'Metro', 'abbreviation' => 'm'],
            ['name' => 'Centímetro', 'abbreviation' => 'cm'],
            ['name' => 'Rollo', 'abbreviation' => 'rollo'],
            ['name' => 'Lámina', 'abbreviation' => 'lám'],
            ['name' => 'Pliego', 'abbreviation' => 'pliego'],
            ['name' => 'Gramo', 'abbreviation' => 'g'],
            ['name' => 'Kilogramo', 'abbreviation' => 'kg'],
            ['name' => 'Litro', 'abbreviation' => 'l'],
            ['name' => 'Mililitro', 'abbreviation' => 'ml'],
            ['name' => 'Paquete', 'abbreviation' => 'paq'],
        ];

        $tenantIds = DB::table('tenants')->pluck('id');
        $now = now();

        $rows = [];
        foreach ($tenantIds as $tenantId) {
            foreach ($defaultUnits as $unit) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'name' => $unit['name'],
                    'abbreviation' => $unit['abbreviation'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (!empty($rows)) {
            DB::table('units_of_measure')->insert($rows);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }
};
