<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitOfMeasure extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'units_of_measure';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'abbreviation',
    ];

    /**
     * Unidades de medida por defecto.
     *
     * @var list<array{name: string, abbreviation: string}>
     */
    public const DEFAULT_UNITS = [
        ['name' => 'Unidad', 'abbreviation' => 'und'],
        ['name' => 'Par', 'abbreviation' => 'par'],
        ['name' => 'Docena', 'abbreviation' => 'doc'],
        ['name' => 'Metro', 'abbreviation' => 'm'],
        ['name' => 'Centímetro', 'abbreviation' => 'cm'],
        ['name' => 'Rollo', 'abbreviation' => 'rollo'],
        ['name' => 'Lámina', 'abbreviation' => 'lám'],
        ['name' => 'Pliego', 'abbreviation' => 'pliego'],
        ['name' => 'Gramo', 'abbreviation' => 'g'],
        ['name' => 'Kilogramo', 'abbreviation' => 'kg'],
        ['name' => 'Litro', 'abbreviation' => 'l'],
        ['name' => 'Mililitro', 'abbreviation' => 'ml'],
        ['name' => 'Paquete', 'abbreviation' => 'paq'],
    ];

    /**
     * Sembrar unidades por defecto para un tenant específico.
     */
    public static function seedDefaultsForTenant(int $tenantId): void
    {
        $existingCount = static::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->count();
        if ($existingCount > 0) {
            return;
        }

        $basicUnits = [
            ['name' => 'Unidad', 'abbreviation' => 'und'],
            ['name' => 'Metro', 'abbreviation' => 'm'],
            ['name' => 'Centímetro', 'abbreviation' => 'cm'],
            ['name' => 'Kilogramo', 'abbreviation' => 'kg'],
            ['name' => 'Gramo', 'abbreviation' => 'g'],
            ['name' => 'Litro', 'abbreviation' => 'L'],
            ['name' => 'Par', 'abbreviation' => 'par'],
            ['name' => 'Docena', 'abbreviation' => 'doc'],
            ['name' => 'Rollo', 'abbreviation' => 'rollo'],
            ['name' => 'Mililitro', 'abbreviation' => 'ml'],
            ['name' => 'Paquete', 'abbreviation' => 'paq'],
        ];

        $now = now();
        $rows = array_map(fn (array $unit) => [
            'tenant_id' => $tenantId,
            'name' => $unit['name'],
            'abbreviation' => $unit['abbreviation'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $basicUnits);

        static::insert($rows);
    }

    /**
     * Get the tenant that owns the unit of measure.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
