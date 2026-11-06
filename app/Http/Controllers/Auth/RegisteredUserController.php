<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterTenantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $plan = $request->query('plan') === 'pro' ? 'pro' : 'standard';

        return Inertia::render('Auth/Register', [
            'initialPlan' => $plan,
        ]);
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(RegisterRequest $request, RegisterTenantAction $action): RedirectResponse
    {
        [$user, $tenant] = $action->execute($request->validated());

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->route('dashboard')->with('success', '¡Bienvenido a gipPyme! Tu taller ha sido configurado con éxito.');
    }
}

