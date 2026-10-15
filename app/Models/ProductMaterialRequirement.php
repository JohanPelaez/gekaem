<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMaterialRequirement extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'raw_material_id',
        'quantity_required',
        'waste_percent',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_required' => 'decimal:3',
            'waste_percent' => 'decimal:2',
        ];
    }

    /**
     * Get the product for this material requirement.
     *
     * @return BelongsTo<Product, ProductMaterialRequirement>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the specific product variant if applicable.
     *
     * @return BelongsTo<ProductVariant, ProductMaterialRequirement>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Get the required raw material.
     *
     * @return BelongsTo<RawMaterial, ProductMaterialRequirement>
     */
    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class);
    }
}
