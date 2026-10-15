<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Models\OrthogonalAttributeOption;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GenerateOrthogonalVariantsAction
{
    /**
     * Generate Cartesian product of orthogonal options, create/update product_variants, and sync options.
     *
     * @param  array<array-key, list<int|OrthogonalAttributeOption>>  $groupedOptions
     * @return Collection<int, ProductVariant>
     */
    public function execute(Product $product, array $groupedOptions): Collection
    {
        return DB::transaction(function () use ($product, $groupedOptions): Collection {
            if (empty($groupedOptions)) {
                throw new InvalidArgumentException('Se requiere al menos un grupo de opciones ortogonales.');
            }

            // Normalizar a arrays de IDs por eje
            $axes = [];
            foreach ($groupedOptions as $group) {
                $axisOptionIds = [];
                foreach ($group as $item) {
                    $axisOptionIds[] = $item instanceof OrthogonalAttributeOption ? $item->id : (int) $item;
                }
                if (!empty($axisOptionIds)) {
                    $axes[] = array_unique($axisOptionIds);
                }
            }

            if (empty($axes)) {
                throw new InvalidArgumentException('No se encontraron opciones válidas para generar combinaciones.');
            }

            // Generar producto cartesiano
            $combinations = $this->cartesianProduct($axes);

            $generatedVariants = collect();
            $baseSkuPrefix = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $product->name));
            if (empty($baseSkuPrefix)) {
                $baseSkuPrefix = "PRD-{$product->id}";
            }

            foreach ($combinations as $combinationOptionIds) {
                // Cargar modelos de opciones
                /** @var EloquentCollection<int, OrthogonalAttributeOption> $options */
                $options = OrthogonalAttributeOption::whereIn('id', $combinationOptionIds)
                    ->orderBy('id')
                    ->get();

                $nameLabel = $options->pluck('value')->implode(' / ');
                $skuSuffixes = $options->pluck('sku_suffix')->filter()->implode('-');

                $sku = !empty($skuSuffixes)
                    ? "{$baseSkuPrefix}-{$skuSuffixes}"
                    : "{$baseSkuPrefix}-" . substr(md5($nameLabel), 0, 5);

                // Buscar si ya existe una variante para este producto con estas opciones exactas
                $existingVariant = ProductVariant::where('product_id', $product->id)
                    ->whereHas('attributeOptions', function ($query) use ($combinationOptionIds): void {
                        $query->whereIn('orthogonal_attribute_options.id', $combinationOptionIds);
                    }, '=', count($combinationOptionIds))
                    ->first();

                if ($existingVariant !== null) {
                    $existingVariant->update([
                        'name_label' => $nameLabel,
                        'is_active' => true,
                    ]);
                    $variant = $existingVariant;
                } else {
                    /** @var ProductVariant $variant */
                    $variant = ProductVariant::create([
                        'product_id' => $product->id,
                        'name_label' => $nameLabel,
                        'sku' => $sku,
                        'barcode' => null,
                        'production_minutes_delta' => 0,
                        'price_override' => null,
                        'is_active' => true,
                    ]);
                }

                // Sincronizar tabla pivote product_variant_options
                $variant->attributeOptions()->sync($combinationOptionIds);

                $generatedVariants->push($variant->load('attributeOptions'));
            }

            return $generatedVariants;
        });
    }

    /**
     * Compute Cartesian product of arrays.
     *
     * @param  list<list<int>>  $arrays
     * @return list<list<int>>
     */
    private function cartesianProduct(array $arrays): array
    {
        $result = [[]];

        foreach ($arrays as $propertyValues) {
            $tmp = [];
            foreach ($result as $resultItem) {
                foreach ($propertyValues as $propertyValue) {
                    $tmp[] = array_merge($resultItem, [$propertyValue]);
                }
            }
            $result = $tmp;
        }

        return $result;
    }
}
