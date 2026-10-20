<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OrderPayment extends Model
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
        'amount',
        'payment_date',
        'payment_method',
        'voucher_path',
        'notes',
        'created_by',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'voucher_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    /**
     * Get the web accessible URL for the payment voucher.
     */
    protected function voucherUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->voucher_path ? Storage::url($this->voucher_path) : null,
        );
    }

    /**
     * Get the order associated with this payment.
     *
     * @return BelongsTo<Order, OrderPayment>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user who recorded this payment.
     *
     * @return BelongsTo<User, OrderPayment>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
