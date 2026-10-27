<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'document_number',
        'phone',
        'email',
        'tax_id',
        'department',
        'city',
        'neighborhood',
        'address',
        'notes',
    ];

    /**
     * Get the orders associated with the customer.
     *
     * @return HasMany<Order>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
