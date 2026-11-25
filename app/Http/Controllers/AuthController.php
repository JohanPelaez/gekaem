<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    /**
     * Show the login view.
     */
    public function showLogin(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('kanban.index');
        }

        return Inertia::render('Auth/Login', [
            'canDemoLogin' => app()->isLocal(),
            'status' => session('status'),
            'warning' => session('warning'),
            'reason' => $request->query('reason'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (!Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => 'Las credenciales proporcionadas no coinciden con nuestros registros.',
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $sessionInfo = $user->startSingleSession($request, $remember);

        $tenant = $user->currentTenant();
        if ($tenant !== null) {
            session(['tenant_id' => $tenant->id]);
            $request->session()->put('tenant_id', $tenant->id);
            if ($user->current_tenant_id !== $tenant->id) {
                $user->updateQuietly(['current_tenant_id' => $tenant->id]);
            }
        }

        $defaultRoute = $user->isSuperAdmin() ? route('admin.index') : route('kanban.index');
        $response = redirect()->intended($defaultRoute);

        if ($sessionInfo['replaced_previous']) {
            $response->with('info', 'Se cerró automáticamente la sesión que tenías abierta en otro equipo.');
        }

        return $response;
    }

    /**
     * 1-Click Demo Login for Local Development.
     */
    public function demoLogin(Request $request): RedirectResponse
    {
        abort_unless(app()->isLocal(), 404);

        $validated = $request->validate([
            'role' => ['required', 'string', 'in:admin,owner,worker'],
        ]);

        $ownerRoles = ['owner', 'dueño', 'dueno'];
        $workerRoles = ['worker', 'operator', 'operador', 'trabajador'];

        $user = match ($validated['role']) {
            'admin' => User::where('is_super_admin', true)->first(),
            'owner' => User::where('is_super_admin', false)
                ->whereHas('tenants', fn ($q) => $q->whereIn('tenant_user.role', $ownerRoles))
                ->first(),
            'worker' => User::where('is_super_admin', false)
                ->whereHas('tenants', fn ($q) => $q->whereIn('tenant_user.role', $workerRoles))
                ->first(),
        };

        if ($user === null) {
            return redirect()->route('login')->with('error', "No se encontró un usuario con rol '{$validated['role']}'. Ejecuta el seeder.");
        }

        Auth::login($user);
        $request->session()->regenerate();
        $sessionInfo = $user->startSingleSession($request, false);

        $tenant = $user->currentTenant();
        if ($tenant !== null) {
            session(['tenant_id' => $tenant->id]);
            $request->session()->put('tenant_id', $tenant->id);
            if ($user->current_tenant_id !== $tenant->id) {
                $user->updateQuietly(['current_tenant_id' => $tenant->id]);
            }
        }

        $response = redirect()->route($user->isSuperAdmin() ? 'admin.index' : 'kanban.index');

        if ($sessionInfo['replaced_previous']) {
            $response->with('info', 'Se cerró automáticamente la sesión que tenías abierta en otro equipo.');
        }

        return $response;
    }

    /**
     * Destroy an authenticated session.
     */
    public function logout(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $user?->clearSingleSessionIfCurrent($request);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
