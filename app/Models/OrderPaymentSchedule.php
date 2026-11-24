<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPaymentSchedule extends Model
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
        'scheduled_date',
        'amount',
        'notes',
        'status',
        'payment_id',
        'paid_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
            'amount' => 'float',
            'payment_id' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Get the order associated with this scheduled payment.
     *
     * @return BelongsTo<Order, OrderPaymentSchedule>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the actual payment created when this schedule was marked as paid.
     *
     * @return BelongsTo<OrderPayment, OrderPaymentSchedule>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'payment_id');
    }
}

