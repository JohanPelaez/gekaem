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
        'billing_cycle',
        'paid_seats',
        'extra_ceo_seats',
        'extra_worker_seats',
        'extra_workflows',
        'current_period_ends_at',
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
            'paid_seats' => 'integer',
            'extra_ceo_seats' => 'integer',
            'extra_worker_seats' => 'integer',
            'extra_workflows' => 'integer',
            'current_period_ends_at' => 'datetime',
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
     * Get the next cut / renewal or trial expiration date.
     */
    public function nextCutDate(): ?\Carbon\Carbon
    {
        if ($this->subscription_status === 'active' && $this->current_period_ends_at !== null) {
            return $this->current_period_ends_at;
        }

        return $this->trial_ends_at ?? $this->created_at?->copy()->addDays(15);
    }

    /**
     * Get the signed number of days remaining until the next cut date.
     * Negative values indicate expired cut.
     */
    public function daysUntilNextCut(): int
    {
        $cut = $this->nextCutDate();
        if ($cut === null) {
            return 0;
        }

        return (int) ceil(now()->floatDiffInDays($cut, false));
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
     * Normalize plan type: 'standard' or null maps to 'basic'.
     */
    public function normalizedPlanType(): string
    {
        return $this->plan_type === 'pro' ? 'pro' : 'basic';
    }

    /**
     * Determine whether the tenant is billed annually.
     */
    public function isAnnual(): bool
    {
        return ($this->billing_cycle ?? 'monthly') === 'annual';
    }

    /**
     * Retrieve the plan configuration array from config/plans.php.
     *
     * @return array<string, mixed>
     */
    public function planConfig(): array
    {
        $key = $this->normalizedPlanType();

        /** @var array<string, mixed> $plan */
        $plan = config("plans.plans.{$key}", config('plans.plans.basic', []));

        return $plan;
    }

    /**
     * Count active CEO/Admin users in the tenant (excluding super_admin).
     */
    public function usedCeoSeats(): int
    {
        return $this->users()
            ->where('users.is_super_admin', false)
            ->whereIn('tenant_user.role', ['owner', 'admin'])
            ->count();
    }

    /**
     * Count active Worker/Operario users in the tenant (excluding super_admin).
     */
    public function usedWorkerSeats(): int
    {
        return $this->users()
            ->where('users.is_super_admin', false)
            ->where('tenant_user.role', 'worker')
            ->count();
    }

    /**
     * Count active (non-trashed) workflows in the tenant.
     */
    public function usedWorkflowsCount(): int
    {
        return $this->workflows()->count();
    }

    /**
     * Maximum allowed CEO/Admin seats (base plan + extra purchased).
     */
    public function maxCeoSeats(): int
    {
        $base = (int) ($this->planConfig()['limits']['ceo'] ?? 1);

        return $base + (int) ($this->extra_ceo_seats ?? 0);
    }

    /**
     * Maximum allowed Worker/Operario seats (base plan + extra purchased).
     */
    public function maxWorkerSeats(): int
    {
        $base = (int) ($this->planConfig()['limits']['worker'] ?? 0);

        return $base + (int) ($this->extra_worker_seats ?? 0);
    }

    /**
     * Maximum allowed active workflows (base plan + extra purchased).
     */
    public function maxWorkflows(): int
    {
        $base = (int) ($this->planConfig()['limits']['workflows'] ?? 2);

        return $base + (int) ($this->extra_workflows ?? 0);
    }

    /**
     * Whether the tenant has available quota to add another CEO/Admin user.
     */
    public function canAddCeo(): bool
    {
        return $this->usedCeoSeats() < $this->maxCeoSeats();
    }

    /**
     * Whether the tenant has available quota to add another Worker user.
     */
    public function canAddWorker(): bool
    {
        return $this->usedWorkerSeats() < $this->maxWorkerSeats();
    }

    /**
     * Whether the tenant has available quota to create or restore another Workflow.
     */
    public function canAddWorkflow(): bool
    {
        return $this->usedWorkflowsCount() < $this->maxWorkflows();
    }

    /**
     * Calculate total recurring subscription amount in COP for the given plan and billing cycle.
     */
    public function calculateSubscriptionTotal(?string $plan = null, ?string $cycle = null): int
    {
        $planKey = ($plan ?? $this->normalizedPlanType()) === 'pro' ? 'pro' : 'basic';
        $cycleKey = ($cycle ?? ($this->billing_cycle ?: 'monthly')) === 'annual' ? 'annual' : 'monthly';
        $priceField = $cycleKey === 'annual' ? 'annual_price' : 'monthly_price';

        $planBase = (int) config("plans.plans.{$planKey}.{$priceField}", 30000);
        $ceoPrice = (int) config("plans.addons.ceo.{$priceField}", 20000);
        $workerPrice = (int) config("plans.addons.worker.{$priceField}", 15000);
        $workflowPrice = (int) config("plans.addons.workflow.{$priceField}", 15000);

        return $planBase
            + ((int) ($this->extra_ceo_seats ?? 0) * $ceoPrice)
            + ((int) ($this->extra_worker_seats ?? 0) * $workerPrice)
            + ((int) ($this->extra_workflows ?? 0) * $workflowPrice);
    }

    /**
     * Build a structured quota and plan summary ready for the frontend.
     *
     * @return array<string, mixed>
     */
    public function getQuotaSummary(): array
    {
        $planType = $this->normalizedPlanType();
        $plan = $this->planConfig();
        $cycle = $this->isAnnual() ? 'annual' : 'monthly';

        $usedCeo = $this->usedCeoSeats();
        $baseCeo = (int) ($plan['limits']['ceo'] ?? 1);
        $extraCeo = (int) ($this->extra_ceo_seats ?? 0);
        $maxCeo = $baseCeo + $extraCeo;

        $usedWorker = $this->usedWorkerSeats();
        $baseWorker = (int) ($plan['limits']['worker'] ?? 0);
        $extraWorker = (int) ($this->extra_worker_seats ?? 0);
        $maxWorker = $baseWorker + $extraWorker;

        $usedWorkflow = $this->usedWorkflowsCount();
        $baseWorkflow = (int) ($plan['limits']['workflows'] ?? 2);
        $extraWorkflow = (int) ($this->extra_workflows ?? 0);
        $maxWorkflow = $baseWorkflow + $extraWorkflow;

        return [
            'plan_type' => $planType,
            'plan_name' => (string) ($plan['name'] ?? 'Plan Básico'),
            'billing_cycle' => $cycle,
            'subscription_status' => (string) ($this->subscription_status ?? 'trialing'),
            'has_payment_method' => $this->hasValidPaymentMethod(),
            'card_brand' => $this->card_brand,
            'card_last_four' => $this->card_last_four,
            'subscription_total' => $this->calculateSubscriptionTotal($planType, $cycle),
            'quotas' => [
                'ceo' => [
                    'used' => $usedCeo,
                    'base' => $baseCeo,
                    'extra' => $extraCeo,
                    'max' => $maxCeo,
                    'can_add' => $usedCeo < $maxCeo,
                ],
                'worker' => [
                    'used' => $usedWorker,
                    'base' => $baseWorker,
                    'extra' => $extraWorker,
                    'max' => $maxWorker,
                    'can_add' => $usedWorker < $maxWorker,
                ],
                'workflow' => [
                    'used' => $usedWorkflow,
                    'base' => $baseWorkflow,
                    'extra' => $extraWorkflow,
                    'max' => $maxWorkflow,
                    'can_add' => $usedWorkflow < $maxWorkflow,
                ],
            ],
            'addons' => config('plans.addons', []),
        ];
    }

    /**
     * Total number of active workshop members (Owner + Workers, excluding super_admin).
     */
    public function activeUsersCount(): int
    {
        return $this->users()
            ->where('users.is_super_admin', false)
            ->count();
    }

    /**
     * Number of users included base in the active plan without extra seat fees.
     */
    public function includedBaseSeats(): int
    {
        $limits = $this->planConfig()['limits'] ?? [];

        return (int) ($limits['ceo'] ?? 1) + (int) ($limits['worker'] ?? 0);
    }

    /**
     * Available covered seats before requiring an extra seat fee.
     */
    public function availableSeats(): int
    {
        $covered = $this->maxCeoSeats() + $this->maxWorkerSeats();

        return max(0, $covered - $this->activeUsersCount());
    }

    /**
     * Calculate recurring total in COP based on active billing cycle and extras.
     * Kept for backwards compatibility with existing Bold/SuperAdmin callers.
     */
    public function calculateMonthlyTotal(?int $forUserCount = null, ?string $forPlan = null): int
    {
        return $this->calculateSubscriptionTotal($forPlan, $this->billing_cycle ?: 'monthly');
    }

    /**
     * Determine whether adding the next team member requires an immediate extra seat charge.
     */
    public function requiresImmediateExtraSeatCharge(): bool
    {
        return !$this->canAddWorker() && !$this->canAddCeo();
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
     * Get the material requests belonging to the tenant.
     *
     * @return HasMany<MaterialRequest>
     */
    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class);
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
            if (empty($tenant->plan_type) || $tenant->plan_type === 'standard') {
                $tenant->plan_type = 'basic';
            }
            if (empty($tenant->billing_cycle)) {
                $tenant->billing_cycle = 'monthly';
            }
            if ($tenant->extra_ceo_seats === null) {
                $tenant->extra_ceo_seats = 0;
            }
            if ($tenant->extra_worker_seats === null) {
                $tenant->extra_worker_seats = 0;
            }
            if ($tenant->extra_workflows === null) {
                $tenant->extra_workflows = 0;
            }
            if ($tenant->paid_seats === null) {
                $tenant->paid_seats = ($tenant->plan_type === 'pro') ? 4 : 1;
            }
            if ($tenant->current_period_ends_at === null) {
                $tenant->current_period_ends_at = $tenant->trial_ends_at ?? now()->addMonth();
            }
        });

        static::created(function (Tenant $tenant): void {
            static::ensureDefaultData($tenant->id);
        });
    }
}
