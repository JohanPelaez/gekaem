<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductVariantOption extends Pivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'product_variant_options';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_variant_id',
        'orthogonal_attribute_option_id',
    ];

    /**
     * Get the associated product variant.
     *
     * @return BelongsTo<ProductVariant, ProductVariantOption>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * Get the associated orthogonal attribute option.
     *
     * @return BelongsTo<OrthogonalAttributeOption, ProductVariantOption>
     */
    public function attributeOption(): BelongsTo
    {
        return $this->belongsTo(OrthogonalAttributeOption::class, 'orthogonal_attribute_option_id');
    }
}
