<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RawMaterial extends Model
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
        'sku',
        'unit_of_measure',
        'current_stock',
        'reserved_stock',
        'min_stock_alert',
        'unit_cost',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:3',
            'reserved_stock' => 'decimal:3',
            'min_stock_alert' => 'decimal:3',
            'unit_cost' => 'decimal:2',
        ];
    }

    /**
     * Get the suppliers that supply this raw material.
     *
     * @return BelongsToMany<Supplier>
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'raw_material_supplier')
            ->withPivot(['id', 'purchase_price', 'is_preferred'])
            ->withTimestamps();
    }

    /**
     * Get the supplier pivot records for this raw material.
     *
     * @return HasMany<RawMaterialSupplier>
     */
    public function rawMaterialSuppliers(): HasMany
    {
        return $this->hasMany(RawMaterialSupplier::class);
    }

    /**
     * Get the stock movements for this raw material.
     *
     * @return HasMany<RawMaterialMovement>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(RawMaterialMovement::class);
    }

    /**
     * Get the product material requirements referencing this raw material.
     *
     * @return HasMany<ProductMaterialRequirement>
     */
    public function productMaterialRequirements(): HasMany
    {
        return $this->hasMany(ProductMaterialRequirement::class);
    }
}
