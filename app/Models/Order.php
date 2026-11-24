<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
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
        'order_status_id',
        'shipping_type',
        'total_production_minutes',
        'buffer_minutes',
        'predicted_delivery_at',
        'committed_delivery_at',
        'delivered_at',
        'shipped_at',
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
        'paid_amount',
        'pending_balance',
        'payment_status',
        'delivery_date_key',
        'promised_date',
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
            'order_status_id' => 'integer',
            'total_production_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'predicted_delivery_at' => 'datetime',
            'committed_delivery_at' => 'datetime',
            'delivered_at' => 'datetime',
            'shipped_at' => 'datetime',
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
     * Get the total amount paid in advances for this order.
     */
    protected function paidAmount(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (): float {
                return (float) ($this->relationLoaded('payments')
                    ? $this->payments->sum('amount')
                    : $this->payments()->sum('amount'));
            }
        );
    }

    /**
     * Get the pending balance to be collected for this order.
     */
    protected function pendingBalance(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (): float {
                $total = (float) $this->total_amount;
                $paid = (float) $this->paid_amount;
                return max(0.0, round($total - $paid, 2));
            }
        );
    }

    /**
     * Get the payment status ('unpaid', 'partial', 'paid').
     */
    protected function paymentStatus(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: function (): string {
                $paid = (float) $this->paid_amount;
                $total = (float) $this->total_amount;

                if ($paid <= 0) {
                    return 'unpaid';
                }
                if ($paid >= $total) {
                    return 'paid';
                }
                return 'partial';
            }
        );
    }

    /**
     * Get the pure YYYY-MM-DD date key without UTC timezone offset.
     */
    public function getDeliveryDateKeyAttribute(): ?string
    {
        $raw = $this->attributes['committed_delivery_at']
            ?? $this->getRawOriginal('committed_delivery_at')
            ?? $this->attributes['predicted_delivery_at']
            ?? $this->getRawOriginal('predicted_delivery_at')
            ?? null;

        if (!$raw) {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }

        return substr(trim((string) $raw), 0, 10);
    }

    /**
     * Get promised date normalized to YYYY-MM-DD.
     */
    public function getPromisedDateAttribute(): ?string
    {
        return $this->getDeliveryDateKeyAttribute();
    }

    /**
     * Resolve the effective total amount of the order considering item discounts.
     */
    public function resolveEffectiveTotal(): float
    {
        $tasks = $this->relationLoaded('productionTasks')
            ? $this->productionTasks
            : $this->productionTasks()->get(['quantity', 'unit_price', 'discount_percentage']);

        if ($tasks->isNotEmpty()) {
            $computed = $tasks->reduce(function (float $carry, ProductionTask $task): float {
                $qty = (float) $task->quantity;
                $price = (float) $task->unit_price;
                $disc = max(0.0, min(100.0, (float) ($task->discount_percentage ?? 0)));

                return $carry + ($qty * $price * (1.0 - ($disc / 100.0)));
            }, 0.0);

            if ($computed > 0) {
                return round($computed, 2);
            }
        }

        return round((float) $this->total_amount, 2);
    }

    /**
     * Get the payments/advances recorded for this order.
     *
     * @return HasMany<OrderPayment>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('payment_date', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Get the scheduled payments for this order sorted by scheduled date ascending.
     *
     * @return HasMany<OrderPaymentSchedule>
     */
    public function paymentSchedules(): HasMany
    {
        return $this->hasMany(OrderPaymentSchedule::class)
            ->orderBy('scheduled_date', 'asc')
            ->orderBy('id', 'asc');
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
        return $this->hasMany(ProductionTask::class)->orderBy('id', 'asc');
    }

    /**
     * Alias to get the production tasks for this order.
     *
     * @return HasMany<ProductionTask>
     */
    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class)->orderBy('id', 'asc');
    }

    /**
     * Get the order status assigned to this order.
     *
     * @return BelongsTo<OrderStatus, Order>
     */
    public function orderStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class);
    }

    /**
     * Scope orders that are active on the Kanban board (exact complement of inHistory).
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeActiveOnBoard(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $q): void {
                $q->whereNull('order_status_id')
                    ->orWhereHas('orderStatus', fn (Builder $statusQuery) => $statusQuery->where('is_history_status', false));
            })
            ->where('derived_status', '!=', 'delivered')
            ->whereNull('shipped_at');
    }

    /**
     * Scope orders that belong to the Order History (shipped, delivered, or in a history status).
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeInHistory(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereHas('orderStatus', fn (Builder $statusQuery) => $statusQuery->where('is_history_status', true))
                ->orWhere('derived_status', 'delivered')
                ->orWhereNotNull('shipped_at');
        });
    }

    /**
     * Resolve (or automatically create) the history OrderStatus for a given tenant.
     */
    public static function resolveHistoryStatusForTenant(int $tenantId): OrderStatus
    {
        $historyStatus = OrderStatus::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_history_status', true)
            ->orderBy('position')
            ->first();

        if ($historyStatus !== null) {
            return $historyStatus;
        }

        $matchingByName = OrderStatus::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where(function (Builder $q): void {
                $q->whereRaw('LOWER(TRIM(name)) LIKE ?', ['%enviado%'])
                    ->orWhereRaw('LOWER(TRIM(name)) LIKE ?', ['%entregado%']);
            })
            ->orderBy('position')
            ->first();

        if ($matchingByName !== null) {
            $matchingByName->is_history_status = true;
            $matchingByName->save();

            return $matchingByName;
        }

        $maxPosition = (int) (OrderStatus::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->max('position') ?? 0);

        return OrderStatus::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantId,
            'name' => 'Enviado',
            'system_key' => 'custom',
            'color' => '#10b981',
            'position' => $maxPosition + 1,
            'is_history_status' => true,
        ]);
    }
}
