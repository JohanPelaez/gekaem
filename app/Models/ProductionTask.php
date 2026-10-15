<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionTask extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'order_id',
        'product_variant_id',
        'workflow_id',
        'current_stage_id',
        'item_name_snapshot',
        'quantity',
        'unit_price',
        'estimated_minutes',
        'column_position',
        'materials_consumed',
        'started_at',
        'completed_at',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'formatted_time',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'estimated_minutes' => 'integer',
            'column_position' => 'integer',
            'materials_consumed' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the human formatted estimated production time.
     */
    protected function formattedTime(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn (): string => \App\Support\ProductionTimeConverter::formatHuman(
                (int) $this->estimated_minutes,
                $this->tenant?->daily_productive_minutes ?? 480
            )
        );
    }

    /**
     * Get the order ("US") that owns this task.
     *
     * @return BelongsTo<Order, ProductionTask>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product variant for this task.
     *
     * @return BelongsTo<ProductVariant, ProductionTask>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * Get the workflow governing this task.
     *
     * @return BelongsTo<Workflow, ProductionTask>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the current Kanban workflow stage of this task.
     *
     * @return BelongsTo<WorkflowStage, ProductionTask>
     */
    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
    }

    /**
     * Get raw material movements recorded for this task.
     *
     * @return HasMany<RawMaterialMovement>
     */
    public function materialMovements(): HasMany
    {
        return $this->hasMany(RawMaterialMovement::class);
    }
}
