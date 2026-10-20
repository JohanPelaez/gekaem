<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
        'current_tenant_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'current_tenant_id' => 'integer',
        ];
    }

    /**
     * Get the tenants associated with the user.
     *
     * @return BelongsToMany<Tenant>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the current tenant relationship.
     *
     * @return BelongsTo<Tenant, User>
     */
    public function currentTenantModel(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /**
     * Get the current active tenant for the user.
     */
    public function currentTenant(): ?Tenant
    {
        // 1. Si es SuperAdmin, puede acceder al current_tenant_id o al primer tenant existente
        if ($this->isSuperAdmin()) {
            if ($this->current_tenant_id) {
                $tenant = Tenant::find($this->current_tenant_id);
                if ($tenant !== null) {
                    return $tenant;
                }
            }

            $firstTenant = Tenant::first();
            if ($firstTenant !== null) {
                $this->updateQuietly(['current_tenant_id' => $firstTenant->id]);
                return $firstTenant;
            }

            return null;
        }

        // 2. Si no es SuperAdmin, verificar si el current_tenant_id le pertenece
        if ($this->current_tenant_id) {
            $tenant = $this->tenants()->where('tenants.id', $this->current_tenant_id)->first();
            if ($tenant !== null) {
                return $tenant;
            }
        }

        // 3. Fallback al primer tenant asignado
        $firstAssigned = $this->tenants()->first();
        if ($firstAssigned !== null) {
            $this->updateQuietly(['current_tenant_id' => $firstAssigned->id]);
            return $firstAssigned;
        }

        return null;
    }

    /**
     * Check if user is a platform Super Administrator.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Check if user is an owner of the given or active tenant.
     */
    public function isOwnerOf(?Tenant $tenant = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $targetTenantId = $tenant?->id ?? $this->current_tenant_id ?? $this->tenants()->first()?->id;
        if (!$targetTenantId) {
            return false;
        }

        return $this->tenants()
            ->where('tenants.id', $targetTenantId)
            ->whereIn('tenant_user.role', ['owner', 'dueño', 'dueno'])
            ->exists();
    }

    /**
     * Check if user is a worker in the given or active tenant.
     */
    public function isWorkerOf(?Tenant $tenant = null): bool
    {
        $targetTenantId = $tenant?->id ?? $this->current_tenant_id ?? $this->tenants()->first()?->id;
        if (!$targetTenantId) {
            return false;
        }

        return $this->tenants()
            ->where('tenants.id', $targetTenantId)
            ->whereIn('tenant_user.role', ['worker', 'operator', 'operador', 'trabajador'])
            ->exists();
    }

    /**
     * Get the active role of the user ('admin' | 'owner' | 'worker').
     */
    public function currentRole(): string
    {
        if ($this->isSuperAdmin()) {
            return 'admin';
        }

        $activeTenant = $this->currentTenant();
        if ($activeTenant === null) {
            return 'worker';
        }

        $pivot = $this->tenants()->where('tenants.id', $activeTenant->id)->first()?->pivot;
        $role = strtolower((string) ($pivot?->role ?? 'worker'));

        return in_array($role, ['owner', 'dueño', 'dueno'], true) ? 'owner' : 'worker';
    }
}
