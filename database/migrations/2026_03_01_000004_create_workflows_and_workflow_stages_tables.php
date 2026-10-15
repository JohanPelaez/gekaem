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
        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'is_default']);
        });

        Schema::create('workflow_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->default('#64748b');
            $table->unsignedSmallInteger('position');
            $table->enum('stage_category', ['pending', 'in_progress', 'blocked', 'done']);
            $table->unsignedSmallInteger('wip_limit')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_stages');
        Schema::dropIfExists('workflows');
    }
};
