<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotWorker
{
    /**
     * Handle an incoming request and ensure the user is not a worker in restricted sections.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->currentRole() === 'worker') {
            if ($request->expectsJson() || !$request->isMethod('GET')) {
                abort(403, 'Acción no permitida para el rol trabajador.');
            }

            return redirect()->route('kanban.index')->with(
                'error',
                'No tienes permisos para acceder a esta sección. Como trabajador, tu acceso está enfocado en el tablero Kanban y consulta de materiales.'
            );
        }

        return $next($request);
    }
}

