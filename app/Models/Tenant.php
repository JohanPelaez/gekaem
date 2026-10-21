<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'daily_productive_minutes',
        'default_buffer_percent',
        'settings',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_productive_minutes' => 'integer',
            'default_buffer_percent' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * Get the users associated with the tenant.
     *
     * @return BelongsToMany<User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the customers belonging to the tenant.
     *
     * @return HasMany<Customer>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Get the suppliers belonging to the tenant.
     *
     * @return HasMany<Supplier>
     */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /**
     * Get the raw materials belonging to the tenant.
     *
     * @return HasMany<RawMaterial>
     */
    public function rawMaterials(): HasMany
    {
        return $this->hasMany(RawMaterial::class);
    }

    /**
     * Get the raw material movements belonging to the tenant.
     *
     * @return HasMany<RawMaterialMovement>
     */
    public function rawMaterialMovements(): HasMany
    {
        return $this->hasMany(RawMaterialMovement::class);
    }

    /**
     * Get the workflows belonging to the tenant.
     *
     * @return HasMany<Workflow>
     */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /**
     * Get the product types belonging to the tenant.
     *
     * @return HasMany<ProductType>
     */
    public function productTypes(): HasMany
    {
        return $this->hasMany(ProductType::class);
    }

    /**
     * Get the products belonging to the tenant.
     *
     * @return HasMany<Product>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get the orthogonal attributes belonging to the tenant.
     *
     * @return HasMany<OrthogonalAttribute>
     */
    public function orthogonalAttributes(): HasMany
    {
        return $this->hasMany(OrthogonalAttribute::class);
    }

    /**
     * Get the orders belonging to the tenant.
     *
     * @return HasMany<Order>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the production tasks belonging to the tenant.
     *
     * @return HasMany<ProductionTask>
     */
    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }

    /**
     * Get the units of measure belonging to the tenant.
     *
     * @return HasMany<UnitOfMeasure>
     */
    public function unitsOfMeasure(): HasMany
    {
        return $this->hasMany(UnitOfMeasure::class);
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::created(function (Tenant $tenant): void {
            UnitOfMeasure::seedDefaultsForTenant($tenant->id);
        });
    }
}
