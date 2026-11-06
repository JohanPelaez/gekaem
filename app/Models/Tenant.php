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
        'trial_ends_at',
        'subscription_status',
        'plan_type',
        'bold_customer_id',
        'payment_method_token',
        'card_last_four',
        'card_brand',
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
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Determine whether the tenant has a valid payment method on file.
     */
    public function hasValidPaymentMethod(): bool
    {
        return !empty($this->payment_method_token);
    }

    /**
     * Determine whether the 15-day free trial has expired.
     */
    public function isTrialExpired(): bool
    {
        $trialEnds = $this->trial_ends_at ?? $this->created_at?->copy()->addDays(15);
        if ($trialEnds === null) {
            return false;
        }

        return now()->greaterThan($trialEnds);
    }

    /**
     * Determine whether the free trial is currently active.
     */
    public function isTrialActive(): bool
    {
        return !$this->isTrialExpired();
    }

    /**
     * Number of remaining days of free trial.
     */
    public function trialDaysRemaining(): int
    {
        $trialEnds = $this->trial_ends_at ?? $this->created_at?->copy()->addDays(15);
        if ($trialEnds === null) {
            return 15;
        }

        if (now()->greaterThan($trialEnds)) {
            return 0;
        }

        return (int) ceil(now()->floatDiffInDays($trialEnds, false));
    }

    /**
     * Determine whether the tenant can access operational workshop routes.
     * If trial expired, the workshop must have a valid payment method or active subscription.
     */
    public function canAccessApp(): bool
    {
        if (!$this->isTrialExpired()) {
            return true;
        }

        return $this->hasValidPaymentMethod() || $this->subscription_status === 'active';
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
     * Get the order statuses belonging to the tenant.
     *
     * @return HasMany<OrderStatus>
     */
    public function orderStatuses(): HasMany
    {
        return $this->hasMany(OrderStatus::class);
    }

    /**
     * Ensure default base data (OrderStatuses, Workflows & Stages, UnitsOfMeasure) exists for a tenant.
     */
    public static function ensureDefaultData(int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }

        // 1. Estados de Pedido (OrderStatus): Si tiene 0 registros, crear los 4 por defecto
        $statusCount = OrderStatus::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->count();

        if ($statusCount === 0) {
            OrderStatus::seedDefaultStatusesForTenant($tenantId);
        }

        // 2. Flujo Kanban Base (Workflow) y Etapas (WorkflowStage): Si tiene 0 registros, crear flujo inicial
        $workflowCount = Workflow::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->count();

        if ($workflowCount === 0) {
            $workflow = Workflow::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenantId,
                'name' => 'Flujo General de Producción',
                'description' => 'Flujo inicial por defecto para el taller.',
                'is_default' => true,
            ]);

            $workflow->stages()->createMany([
                ['name' => 'Pendiente', 'color' => '#64748b', 'position' => 1, 'stage_category' => 'pending'],
                ['name' => 'En Proceso', 'color' => '#3b82f6', 'position' => 2, 'stage_category' => 'in_progress'],
                ['name' => 'Terminado', 'color' => '#10b981', 'position' => 3, 'stage_category' => 'done'],
            ]);
        }

        // 3. Unidades de Medida Base (UnitOfMeasure): Si tiene 0 registros, crear las unidades básicas
        $unitCount = UnitOfMeasure::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->count();

        if ($unitCount === 0) {
            UnitOfMeasure::seedDefaultsForTenant($tenantId);
        }
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            if ($tenant->trial_ends_at === null) {
                $tenant->trial_ends_at = now()->addDays(15);
            }
            if (empty($tenant->subscription_status)) {
                $tenant->subscription_status = 'trialing';
            }
            if (empty($tenant->plan_type)) {
                $tenant->plan_type = 'standard';
            }
        });

        static::created(function (Tenant $tenant): void {
            static::ensureDefaultData($tenant->id);
        });
    }
}
