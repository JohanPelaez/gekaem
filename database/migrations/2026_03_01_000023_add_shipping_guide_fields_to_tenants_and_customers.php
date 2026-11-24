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
        Schema::table('tenants', function (Blueprint $table): void {
            if (!Schema::hasColumn('tenants', 'shipping_header')) {
                $table->string('shipping_header', 150)->nullable()->after('name');
            }
            if (!Schema::hasColumn('tenants', 'tax_id')) {
                $table->string('tax_id', 60)->nullable()->after('shipping_header');
            }
            if (!Schema::hasColumn('tenants', 'phone')) {
                $table->string('phone', 50)->nullable()->after('tax_id');
            }
            if (!Schema::hasColumn('tenants', 'email')) {
                $table->string('email', 150)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('tenants', 'address')) {
                $table->string('address', 255)->nullable()->after('email');
            }
            if (!Schema::hasColumn('tenants', 'city')) {
                $table->string('city', 100)->nullable()->after('address');
            }
            if (!Schema::hasColumn('tenants', 'department')) {
                $table->string('department', 100)->nullable()->after('city');
            }
            if (!Schema::hasColumn('tenants', 'shipping_guide_sequence')) {
                $table->unsignedBigInteger('shipping_guide_sequence')->default(1000000000)->after('department');
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (!Schema::hasColumn('customers', 'document_number')) {
                $table->string('document_number', 50)->nullable();
            }
            if (!Schema::hasColumn('customers', 'phone')) {
                $table->string('phone', 50)->nullable();
            }
            if (!Schema::hasColumn('customers', 'email')) {
                $table->string('email', 100)->nullable();
            }
            if (!Schema::hasColumn('customers', 'address')) {
                $table->string('address', 255)->nullable();
            }
            if (!Schema::hasColumn('customers', 'neighborhood')) {
                $table->string('neighborhood', 100)->nullable();
            }
            if (!Schema::hasColumn('customers', 'city')) {
                $table->string('city', 100)->nullable();
            }
            if (!Schema::hasColumn('customers', 'department')) {
                $table->string('department', 100)->nullable();
            }
            if (!Schema::hasColumn('customers', 'notes')) {
                $table->text('notes')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'shipping_header',
                'tax_id',
                'phone',
                'email',
                'address',
                'city',
                'department',
                'shipping_guide_sequence',
            ], fn (string $col): bool => Schema::hasColumn('tenants', $col)));

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
