<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'product_type_id',
        'workflow_id',
        'name',
        'barcode',
        'barcode_format',
        'base_production_minutes',
        'production_time_unit',
        'buffer_percentage',
        'base_price',
        'is_active',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'formatted_time',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_production_minutes' => 'integer',
            'buffer_percentage' => 'integer',
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the human formatted production time.
     */
    protected function formattedTime(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn (): string => \App\Support\ProductionTimeConverter::formatHuman(
                (int) $this->base_production_minutes,
                $this->tenant?->daily_productive_minutes ?? 480
            )
        );
    }

    /**
     * Get the product type for this product.
     *
     * @return BelongsTo<ProductType, Product>
     */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    /**
     * Get the workflow linked to this product.
     *
     * @return BelongsTo<Workflow, Product>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the variants defined for this product.
     *
     * @return HasMany<ProductVariant>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Get the bill of materials (BOM) requirements for this product.
     *
     * @return HasMany<ProductMaterialRequirement>
     */
    public function materialRequirements(): HasMany
    {
        return $this->hasMany(ProductMaterialRequirement::class);
    }
}
