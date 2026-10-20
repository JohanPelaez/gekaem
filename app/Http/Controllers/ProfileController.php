<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile and workshop settings page.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $isOwnerOrAdmin = $user->isSuperAdmin() || $user->currentRole() === 'owner';

        $tenantData = null;
        $teamMembers = [];

        if ($isOwnerOrAdmin) {
            $tenant = $user->currentTenant();
            if ($tenant !== null) {
                $tenantData = [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'daily_productive_minutes' => (int) $tenant->daily_productive_minutes,
                    'daily_hours' => round(((int) $tenant->daily_productive_minutes) / 60, 1),
                    'default_buffer_percent' => (int) $tenant->default_buffer_percent,
                    'settings' => $tenant->settings ?? [],
                ];
                $teamMembers = $tenant->users()->withPivot('role')->get();
            }
        }

        return Inertia::render('Settings/Index', [
            'profileUser' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->currentRole(),
                'is_super_admin' => $user->isSuperAdmin(),
            ],
            'tenant' => $tenantData,
            'teamMembers' => $teamMembers,
            'canManageTenant' => $isOwnerOrAdmin,
            'initialTab' => $request->query('tab', 'profile'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ]);

        $user->update($validated);

        return redirect()->back()->with('success', 'Tu información de perfil se actualizó correctamente.');
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->back()->with('success', 'Tu contraseña ha sido cambiada exitosamente.');
    }
}
