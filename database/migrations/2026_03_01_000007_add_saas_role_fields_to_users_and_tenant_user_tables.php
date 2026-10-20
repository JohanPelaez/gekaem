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
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'is_super_admin')) {
                $table->boolean('is_super_admin')->default(false)->after('password');
            }
            if (!Schema::hasColumn('users', 'current_tenant_id')) {
                $table->foreignId('current_tenant_id')
                    ->nullable()
                    ->after('is_super_admin')
                    ->constrained('tenants')
                    ->nullOnDelete();
            }
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            // Modificar role a string para soportar 'owner', 'worker', 'operator' limpiamente
            $table->string('role', 50)->default('worker')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'current_tenant_id')) {
                $table->dropForeign(['current_tenant_id']);
                $table->dropColumn('current_tenant_id');
            }
            if (Schema::hasColumn('users', 'is_super_admin')) {
                $table->dropColumn('is_super_admin');
            }
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->enum('role', ['owner', 'operator'])->default('operator')->change();
        });
    }
};
