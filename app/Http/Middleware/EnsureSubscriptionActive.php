<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionActive
{
    /**
     * Handle an incoming request and ensure tenant subscription or trial is valid.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        // Superadmins de la plataforma no se bloquean por suscripción de taller
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Rutas excluidas de bloqueo (Facturación, actualización de tarjeta y cierre de sesión)
        if (
            $request->routeIs('settings.billing*') ||
            $request->routeIs('logout') ||
            $request->is('settings/billing*') ||
            $request->is('logout')
        ) {
            return $next($request);
        }

        /** @var Tenant|null $tenant */
        $tenant = $user->currentTenant();

        if ($tenant === null) {
            return $next($request);
        }

        // Si now() > tenant->trial_ends_at y no tiene payment_method_token ni suscripción active
        if ($tenant->isTrialExpired() && !$tenant->hasValidPaymentMethod() && $tenant->subscription_status !== 'active') {
            return redirect()->route('settings.billing')->with(
                'error',
                'Tu periodo de prueba de 15 días ha finalizado. Para continuar gestionando tu taller y acceder a tus pedidos, por favor agrega un método de pago válido.'
            );
        }

        return $next($request);
    }
}

