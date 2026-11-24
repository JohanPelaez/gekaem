<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class GenerateNextShippingGuideNumberAction
{
    /**
     * Atomically increment and return the tenant's next shipping guide sequence number.
     */
    public function execute(Tenant $tenant): int
    {
        return DB::transaction(function () use ($tenant): int {
            /** @var Tenant $locked */
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            $current = (int) ($locked->shipping_guide_sequence ?? 1000000000);
            if ($current < 1000000000) {
                $current = 1000000000;
            }

            $next = $current + 1;
            $locked->shipping_guide_sequence = $next;
            $locked->save();

            return $next;
        });
    }
}
