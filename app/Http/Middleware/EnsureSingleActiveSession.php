<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureSingleActiveSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            /** @var User $user */
            $user = $request->user();
            $dbToken = User::whereKey($user->id)->value('active_session_token');
            $sessionToken = $request->session()->get('active_session_token');

            // Migración transparente de sesiones existentes al desplegar
            if ($dbToken === null && $sessionToken === null) {
                $user->startSingleSession($request, false);

                return $next($request);
            }

            if ($dbToken !== $sessionToken) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                $loginUrl = route('login', ['reason' => 'session_replaced']);

                if ($request->header('X-Inertia')) {
                    return Inertia::location($loginUrl);
                }

                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'session_replaced' => true,
                        'message' => 'Tu sesión fue cerrada porque se inició sesión con este usuario en otro equipo.',
                        'redirect' => $loginUrl,
                    ], 401);
                }

                return redirect()->to($loginUrl);
            }
        }

        return $next($request);
    }
}
