<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'order_number',
        'derived_status',
        'total_production_minutes',
        'buffer_minutes',
        'predicted_delivery_at',
        'committed_delivery_at',
        'delivered_at',
        'kanban_swimlane_position',
        'total_amount',
        'notes',
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
            'order_number' => 'integer',
            'total_production_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'predicted_delivery_at' => 'datetime',
            'committed_delivery_at' => 'datetime',
            'delivered_at' => 'datetime',
            'kanban_swimlane_position' => 'integer',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * Get the human formatted production time.
     */
    protected function formattedTime(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn (): string => \App\Support\ProductionTimeConverter::formatHuman(
                (int) ($this->total_production_minutes + $this->buffer_minutes),
                $this->tenant?->daily_productive_minutes ?? 480
            )
        );
    }

    /**
     * Get the customer that placed this order.
     *
     * @return BelongsTo<Customer, Order>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the production tasks (items in Kanban) associated with this order ("US").
     *
     * @return HasMany<ProductionTask>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }

    /**
     * Alias to get the production tasks for this order.
     *
     * @return HasMany<ProductionTask>
     */
    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }
}
