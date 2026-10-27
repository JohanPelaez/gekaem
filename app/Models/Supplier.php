<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
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
        'contact_name',
        'phone',
        'email',
        'payment_methods',
        'lead_time_days',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
        ];
    }

    /**
     * Get the raw materials supplied by this supplier.
     *
     * @return BelongsToMany<RawMaterial>
     */
    public function rawMaterials(): BelongsToMany
    {
        return $this->belongsToMany(RawMaterial::class, 'raw_material_supplier')
            ->withPivot(['id', 'purchase_price', 'is_preferred'])
            ->withTimestamps();
    }

    /**
     * Get the raw material supplier pivot records.
     *
     * @return HasMany<RawMaterialSupplier>
     */
    public function rawMaterialSuppliers(): HasMany
    {
        return $this->hasMany(RawMaterialSupplier::class);
    }
}
