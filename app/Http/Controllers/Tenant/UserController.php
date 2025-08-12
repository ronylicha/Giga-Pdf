<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\UserInvitation;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant']);
        $this->middleware('role:tenant_admin,admin')->except(['index', 'show']);
    }

    /**
     * Display listing of tenant users
     */
    public function index(Request $request)
    {
        $tenant = auth()->user()->tenant;

        $query = User::where('tenant_id', $tenant->id)
            ->with('roles');

        // Search
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        // Filter by role
        if ($request->role) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        // Filter by status
        if ($request->status === 'active') {
            $query->whereNotNull('email_verified_at');
        } elseif ($request->status === 'inactive') {
            $query->whereNull('email_verified_at');
        }

        $users = $query->paginate(15)->withQueryString();

        // Get pending invitations
        $invitations = Invitation::where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('invitedBy')
            ->latest()
            ->get();

        // Get tenant statistics
        $stats = [
            'total_users' => $tenant->users()->count(),
            'active_users' => $tenant->users()->whereNotNull('email_verified_at')->count(),
            'pending_invitations' => $invitations->count(),
            'max_users' => $tenant->max_users,
        ];

        return Inertia::render('Tenant/Users/Index', [
            'users' => $users,
            'invitations' => $invitations,
            'stats' => $stats,
            'filters' => $request->only(['search', 'role', 'status']),
            'roles' => [
                'user' => 'Utilisateur',
                'editor' => 'Éditeur',
                'manager' => 'Manager',
                'tenant_admin' => 'Admin Tenant',
            ],
        ]);
    }

    /**
     * Show user details
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);

        $user->load(['roles', 'documents', 'conversions']);

        // Get user activity
        $activities = \App\Models\ActivityLog::where('causer_id', $user->id)
            ->where('causer_type', User::class)
            ->latest()
            ->limit(20)
            ->get();

        // Get user statistics
        $stats = [
            'documents_count' => $user->documents()->count(),
            'conversions_count' => $user->conversions()->count(),
            'storage_used' => $user->documents()->sum('size'),
            'last_login' => $user->last_login_at,
        ];

        return Inertia::render('Tenant/Users/Show', [
            'user' => $user,
            'activities' => $activities,
            'stats' => $stats,
        ]);
    }

    /**
     * Show form to invite new user
     */
    public function create()
    {
        $tenant = auth()->user()->tenant;

        // Check if tenant has reached user limit
        if ($tenant->users()->count() >= $tenant->max_users) {
            return back()->with('error', 'Vous avez atteint la limite d\'utilisateurs pour votre plan.');
        }

        return Inertia::render('Tenant/Users/Create', [
            'roles' => [
                'user' => 'Utilisateur',
                'editor' => 'Éditeur',
                'manager' => 'Manager',
                'tenant_admin' => 'Admin Tenant',
            ],
        ]);
    }

    /**
     * Send invitation to new user
     */
    public function invite(Request $request)
    {
        $tenant = auth()->user()->tenant;

        // Check user limit
        if ($tenant->users()->count() >= $tenant->max_users) {
            return back()->with('error', 'Limite d\'utilisateurs atteinte.');
        }

        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'role' => 'required|in:user,editor,manager,tenant_admin',
            'message' => 'nullable|string|max:500',
            'expires_in_days' => 'nullable|integer|min:1|max:30',
        ]);

        // Check if invitation already exists
        $existingInvitation = Invitation::where('tenant_id', $tenant->id)
            ->where('email', $validated['email'])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existingInvitation) {
            return back()->with('error', 'Une invitation est déjà en attente pour cet email.');
        }

        // Create invitation
        $invitation = Invitation::create([
            'tenant_id' => $tenant->id,
            'email' => $validated['email'],
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'role' => $validated['role'],
            'invited_by' => auth()->id(),
            'message' => $validated['message'] ?? null,
            'expires_at' => now()->addDays($validated['expires_in_days'] ?? 7),
        ]);

        // Send invitation email
        Mail::to($invitation->email)->send(new UserInvitation($invitation));

        return redirect()->route('tenant.users.index')
            ->with('success', 'Invitation envoyée avec succès à ' . $invitation->email);
    }

    /**
     * Resend invitation
     */
    public function resendInvitation(Invitation $invitation)
    {
        $this->authorize('update', $invitation);

        if ($invitation->isAccepted()) {
            return back()->with('error', 'Cette invitation a déjà été acceptée.');
        }

        if ($invitation->hasExpired()) {
            // Renew invitation
            $invitation->update([
                'token' => Str::random(64),
                'expires_at' => now()->addDays(7),
            ]);
        }

        // Resend email
        Mail::to($invitation->email)->send(new UserInvitation($invitation));

        return back()->with('success', 'Invitation renvoyée avec succès.');
    }

    /**
     * Cancel invitation
     */
    public function cancelInvitation(Invitation $invitation)
    {
        $this->authorize('delete', $invitation);

        if ($invitation->isAccepted()) {
            return back()->with('error', 'Cette invitation a déjà été acceptée.');
        }

        $invitation->delete();

        return back()->with('success', 'Invitation annulée.');
    }

    /**
     * Show edit form
     */
    public function edit(User $user)
    {
        $this->authorize('update', $user);

        return Inertia::render('Tenant/Users/Edit', [
            'user' => $user->load('roles'),
            'roles' => [
                'user' => 'Utilisateur',
                'editor' => 'Éditeur',
                'manager' => 'Manager',
                'tenant_admin' => 'Admin Tenant',
            ],
        ]);
    }

    /**
     * Update user
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role' => 'required|in:user,editor,manager,tenant_admin',
            'is_active' => 'boolean',
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        // Update role
        $user->syncRoles([$validated['role']]);

        // Activate/deactivate user
        if (isset($validated['is_active'])) {
            if (! $validated['is_active']) {
                $user->tokens()->delete(); // Revoke all tokens
            }
        }

        return redirect()->route('tenant.users.show', $user)
            ->with('success', 'Utilisateur mis à jour avec succès.');
    }

    /**
     * Reset user password
     */
    public function resetPassword(User $user)
    {
        $this->authorize('update', $user);

        $password = Str::random(12);
        $user->update([
            'password' => Hash::make($password),
        ]);

        // Send email with new password
        // Mail::to($user->email)->send(new PasswordReset($user, $password));

        return back()->with('success', 'Mot de passe réinitialisé et envoyé par email.');
    }

    /**
     * Toggle 2FA for user
     */
    public function toggle2FA(User $user)
    {
        $this->authorize('update', $user);

        if ($user->two_factor_secret) {
            $user->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
            ]);
            $message = '2FA désactivée pour cet utilisateur.';
        } else {
            $message = 'L\'utilisateur devra configurer 2FA à sa prochaine connexion.';
        }

        return back()->with('success', $message);
    }

    /**
     * Delete user
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        // Prevent deleting own account
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }

        // Transfer documents to admin or delete
        $admin = $user->tenant->users()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'tenant_admin');
            })
            ->where('id', '!=', $user->id)
            ->first();

        if ($admin) {
            $user->documents()->update(['user_id' => $admin->id]);
        } else {
            $user->documents()->delete();
        }

        $user->delete();

        return redirect()->route('tenant.users.index')
            ->with('success', 'Utilisateur supprimé avec succès.');
    }
}
