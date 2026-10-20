<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    /**
     * Boot the BelongsToTenant trait for a model.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = static::resolveCurrentTenantId();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
            }
        });

        static::creating(function ($model): void {
            if ($model->tenant_id === null) {
                $tenantId = static::resolveCurrentTenantId();

                if ($tenantId !== null) {
                    $model->tenant_id = $tenantId;
                }
            }
        });
    }

    /**
     * Resolve the current active tenant ID from session or authenticated user.
     */
    public static function resolveCurrentTenantId(): ?int
    {
        if (auth()->check()) {
            $user = auth()->user();
            if ($user?->current_tenant_id) {
                return (int) $user->current_tenant_id;
            }

            if (method_exists($user, 'currentTenant')) {
                $tenant = $user->currentTenant();
                if ($tenant !== null) {
                    return (int) $tenant->id;
                }
            }
        }

        if (session()?->has('tenant_id')) {
            $sessionTenantId = session('tenant_id');
            if ($sessionTenantId !== null && is_numeric($sessionTenantId)) {
                return (int) $sessionTenantId;
            }
        }

        return null;
    }

    /**
     * Get the tenant that owns this record.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
