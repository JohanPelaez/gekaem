<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class UpdateSenderProfileAction
{
    /**
     * Update the tenant's sender profile used on shipping guides.
     *
     * @param array<string, mixed> $data
     */
    public function execute(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data): Tenant {
            $payload = [
                'shipping_header' => isset($data['shipping_header']) && trim((string) $data['shipping_header']) !== ''
                    ? trim((string) $data['shipping_header'])
                    : null,
                'tax_id' => isset($data['tax_id']) && trim((string) $data['tax_id']) !== ''
                    ? trim((string) $data['tax_id'])
                    : null,
                'phone' => isset($data['phone']) && trim((string) $data['phone']) !== ''
                    ? trim((string) $data['phone'])
                    : null,
                'email' => isset($data['email']) && trim((string) $data['email']) !== ''
                    ? trim((string) $data['email'])
                    : null,
                'address' => isset($data['address']) && trim((string) $data['address']) !== ''
                    ? trim((string) $data['address'])
                    : null,
                'city' => isset($data['city']) && trim((string) $data['city']) !== ''
                    ? trim((string) $data['city'])
                    : null,
                'department' => isset($data['department']) && trim((string) $data['department']) !== ''
                    ? trim((string) $data['department'])
                    : null,
            ];

            if (isset($data['name']) && trim((string) $data['name']) !== '') {
                $payload['name'] = trim((string) $data['name']);
            }

            $tenant->update($payload);

            return $tenant->fresh();
        });
    }
}
