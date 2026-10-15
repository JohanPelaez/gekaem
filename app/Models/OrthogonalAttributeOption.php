<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrthogonalAttributeOption extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'orthogonal_attribute_id',
        'value',
        'sku_suffix',
    ];

    /**
     * Get the parent orthogonal attribute.
     *
     * @return BelongsTo<OrthogonalAttribute, OrthogonalAttributeOption>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(OrthogonalAttribute::class, 'orthogonal_attribute_id');
    }

    /**
     * Get the product variants associated with this option.
     *
     * @return BelongsToMany<ProductVariant>
     */
    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'product_variant_options',
            'orthogonal_attribute_option_id',
            'product_variant_id'
        );
    }
}
