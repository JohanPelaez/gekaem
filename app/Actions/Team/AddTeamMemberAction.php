<?php

declare(strict_types=1);

namespace App\Actions\Team;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Bold\BoldPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AddTeamMemberAction
{
    public function __construct(
        protected BoldPaymentService $boldService
    ) {}

    /**
     * Add a team member applying trial limits, paid seats rules, and Bold extra seat charges.
     *
     * @param array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     role: string
     * } $data
     * @return array{0: User, 1: Tenant}
     * @throws ValidationException
     */
    public function execute(array $data, Tenant $tenant): array
    {
        $isCeoRole = in_array($data['role'], ['owner', 'admin'], true);

        if ($isCeoRole && !$tenant->canAddCeo()) {
            throw ValidationException::withMessages([
                'role' => 'Has alcanzado el límite de Usuarios CEO incluidos en tu plan.',
            ]);
        }

        if (!$isCeoRole && !$tenant->canAddWorker()) {
            throw ValidationException::withMessages([
                'role' => 'Has alcanzado el límite de Usuarios Operario incluidos en tu plan.',
            ]);
        }

        // Registrar o vincular usuario al taller
        return DB::transaction(function () use ($data, $tenant, $isCeoRole): array {
            /** @var User|null $user */
            $user = User::where('email', strtolower(trim($data['email'])))->first();

            if ($user === null) {
                $user = User::create([
                    'name' => trim($data['name']),
                    'email' => strtolower(trim($data['email'])),
                    'password' => Hash::make($data['password']),
                    'is_super_admin' => false,
                    'current_tenant_id' => $tenant->id,
                ]);
            }

            $pivotRole = $isCeoRole ? 'owner' : 'worker';

            if ($tenant->users()->where('users.id', $user->id)->exists()) {
                $tenant->users()->updateExistingPivot($user->id, ['role' => $pivotRole]);
            } else {
                $tenant->users()->attach($user->id, ['role' => $pivotRole]);
            }

            // Sincronizar parallel_workers si el total de usuarios activos supera la capacidad previa
            $settings = $tenant->settings ?? [];
            $activeCount = $tenant->activeUsersCount();
            if (($settings['parallel_workers'] ?? 1) < $activeCount) {
                $settings['parallel_workers'] = $activeCount;
                $tenant->update(['settings' => $settings]);
            }

            return [$user, $tenant->refresh()];
        });
    }
}

