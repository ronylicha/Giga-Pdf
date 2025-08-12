<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class InvitationController extends Controller
{
    /**
     * Show the invitation acceptance form
     */
    public function show(string $token)
    {
        $invitation = Invitation::where('token', $token)
            ->with('tenant')
            ->firstOrFail();

        // Check if already accepted
        if ($invitation->isAccepted()) {
            return redirect()->route('login')
                ->with('error', 'Cette invitation a déjà été acceptée.');
        }

        // Check if expired
        if ($invitation->hasExpired()) {
            return redirect()->route('login')
                ->with('error', 'Cette invitation a expiré.');
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'invitation' => $invitation,
            'tenant' => $invitation->tenant,
        ]);
    }

    /**
     * Accept the invitation and create user account
     */
    public function accept(Request $request, string $token)
    {
        $invitation = Invitation::where('token', $token)
            ->with('tenant')
            ->firstOrFail();

        // Check if already accepted
        if ($invitation->isAccepted()) {
            return redirect()->route('login')
                ->with('error', 'Cette invitation a déjà été acceptée.');
        }

        // Check if expired
        if ($invitation->hasExpired()) {
            return redirect()->route('login')
                ->with('error', 'Cette invitation a expiré.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // Check if user already exists
        $existingUser = User::where('email', $invitation->email)->first();

        if ($existingUser) {
            // If user exists but in different tenant, we might need to handle this differently
            if ($existingUser->tenant_id !== $invitation->tenant_id) {
                return back()->with('error', 'Un compte existe déjà avec cet email dans un autre tenant.');
            }

            // User exists in same tenant, just update their role if needed
            $existingUser->syncRoles([$invitation->role]);
            $invitation->markAsAccepted();

            return redirect()->route('login')
                ->with('success', 'Votre compte a été mis à jour. Vous pouvez maintenant vous connecter.');
        }

        // Check tenant user limit
        $tenant = $invitation->tenant;
        if ($tenant->max_users > 0 && $tenant->users()->count() >= $tenant->max_users) {
            return back()->with('error', 'Ce tenant a atteint sa limite d\'utilisateurs.');
        }

        DB::beginTransaction();

        try {
            // Create new user
            $user = User::create([
                'tenant_id' => $invitation->tenant_id,
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
            ]);

            // Assign role
            $user->assignRole($invitation->role);

            // Mark invitation as accepted
            $invitation->markAsAccepted();

            // Log activity
            activity()
                ->performedOn($user)
                ->causedBy($user)
                ->withProperties([
                    'invitation_id' => $invitation->id,
                    'invited_by' => $invitation->invited_by,
                ])
                ->log('Invitation acceptée');

            DB::commit();

            // Auto-login the user
            auth()->login($user);

            return redirect()->route('dashboard')
                ->with('success', 'Bienvenue dans ' . $tenant->name . '!');

        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()
                ->with('error', 'Erreur lors de la création du compte: ' . $e->getMessage());
        }
    }
}
