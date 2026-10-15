<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
     * Get the current active tenant for the user.
     */
    public function currentTenant(): ?Tenant
    {
        if (session()?->has('tenant_id')) {
            $sessionTenantId = session('tenant_id');
            if ($sessionTenantId !== null && is_numeric($sessionTenantId)) {
                $tenant = $this->tenants()->where('tenants.id', (int) $sessionTenantId)->first();
                if ($tenant !== null) {
                    return $tenant;
                }
            }
        }

        return $this->tenants()->first();
    }
}

