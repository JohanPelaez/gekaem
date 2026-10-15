<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'name_label',
        'sku',
        'barcode',
        'production_minutes_delta',
        'price_override',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'production_minutes_delta' => 'integer',
            'price_override' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the parent product.
     *
     * @return BelongsTo<Product, ProductVariant>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the orthogonal attribute options that form this variant.
     *
     * @return BelongsToMany<OrthogonalAttributeOption>
     */
    public function attributeOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            OrthogonalAttributeOption::class,
            'product_variant_options',
            'product_variant_id',
            'orthogonal_attribute_option_id'
        );
    }

    /**
     * Get variant-specific material requirements.
     *
     * @return HasMany<ProductMaterialRequirement>
     */
    public function materialRequirements(): HasMany
    {
        return $this->hasMany(ProductMaterialRequirement::class);
    }

    /**
     * Get production tasks generated for this variant.
     *
     * @return HasMany<ProductionTask>
     */
    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }
}
