<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrthogonalAttribute extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
    ];

    /**
     * Get the options defined for this orthogonal attribute.
     *
     * @return HasMany<OrthogonalAttributeOption>
     */
    public function options(): HasMany
    {
        return $this->hasMany(OrthogonalAttributeOption::class);
    }
}
