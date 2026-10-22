<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderStatus extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'order_statuses';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'system_key',
        'color',
        'position',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Get all orders associated with this status.
     *
     * @return HasMany<Order>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Seed initial default order statuses for a specific tenant.
     */
    public static function seedDefaultStatusesForTenant(int $tenantId): void
    {
        $defaults = [
            ['name' => 'Pendiente', 'system_key' => 'pending', 'color' => '#64748b', 'position' => 1],
            ['name' => 'En Producción', 'system_key' => 'in_progress', 'color' => '#3b82f6', 'position' => 2],
            ['name' => 'Bloqueado', 'system_key' => 'blocked', 'color' => '#ef4444', 'position' => 3],
            ['name' => 'Listo para Entrega', 'system_key' => 'ready', 'color' => '#10b981', 'position' => 4],
        ];

        foreach ($defaults as $data) {
            static::withoutGlobalScope('tenant')->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'system_key' => $data['system_key'],
                ],
                [
                    'name' => $data['name'],
                    'color' => $data['color'],
                    'position' => $data['position'],
                ]
            );
        }
    }
}
