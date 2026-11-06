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
        // Caso A: Periodo de Prueba (trialing)
        if ($tenant->subscription_status === 'trialing') {
            if ($tenant->activeUsersCount() >= 5) {
                throw ValidationException::withMessages([
                    'email' => 'Durante el periodo de prueba gratis puedes tener hasta 5 usuarios. Para agregar más usuarios a tu taller, por favor activa tu suscripción registrando una tarjeta de crédito o débito.',
                ]);
            }
        }

        // Caso C: Suscripción Activa sin cupos disponibles (requiere cobro de $15.000 COP)
        if ($tenant->requiresImmediateExtraSeatCharge()) {
            if (!$tenant->hasValidPaymentMethod()) {
                throw ValidationException::withMessages([
                    'email' => 'Debes registrar un método de pago válido antes de agregar usuarios adicionales a tu plan.',
                ]);
            }

            $seatNumber = $tenant->activeUsersCount() + 1;
            $this->boldService->chargeToken(
                $tenant,
                15000,
                "Cupo Usuario Extra #{$seatNumber} ({$data['name']})"
            );

            // Incrementar cupos pagados cubiertos en este ciclo
            $tenant->increment('paid_seats');
        }

        // Caso B y finalización: Registrar o vincular usuario al taller
        return DB::transaction(function () use ($data, $tenant): array {
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

            if ($tenant->users()->where('users.id', $user->id)->exists()) {
                $tenant->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
            } else {
                $tenant->users()->attach($user->id, ['role' => $data['role']]);
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

