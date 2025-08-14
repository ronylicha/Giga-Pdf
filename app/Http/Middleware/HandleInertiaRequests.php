<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'tenant_id' => $user->tenant_id,
                    'role' => $this->getUserRole($user),
                    'roles' => $this->getUserRoles($user),
                    'permissions' => $this->getUserPermissions($user),
                    'is_super_admin' => $user->isSuperAdmin(),
                    'is_tenant_admin' => $user->isTenantAdmin(),
                ] : null,
                'tenant' => $user && $user->tenant ? [
                    'id' => $user->tenant->id,
                    'name' => $user->tenant->name,
                    'slug' => $user->tenant->slug,
                ] : null,
            ],
            'impersonation' => [
                'active' => session()->has('impersonator_id'),
                'impersonator_id' => session('impersonator_id'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }

    /**
     * Get user permissions
     */
    private function getUserPermissions($user): array
    {
        if (! $user) {
            return [];
        }

        try {
            // Set team context based on user's tenant
            if ($user->tenant_id) {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($user->tenant_id);
            } else {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
            }

            // For Spatie Permission package
            if (method_exists($user, 'getAllPermissions')) {
                return $user->getAllPermissions()->pluck('name')->toArray();
            }

            // Fallback: get permissions through roles
            if (method_exists($user, 'getPermissionsViaRoles')) {
                return $user->getPermissionsViaRoles()->pluck('name')->toArray();
            }

            return [];
        } catch (\Exception $e) {
            \Log::warning('Error getting user permissions', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get the user's primary role slug
     */
    private function getUserRole($user): ?string
    {
        if (! $user) {
            return null;
        }

        try {
            // Set team context based on user's tenant
            if ($user->tenant_id) {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($user->tenant_id);
            } else {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
            }

            // Check for super admin first (system-wide role)
            if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
                return 'super-admin';
            }

            // Get first role name using Spatie Permission
            $roles = $user->getRoleNames();
            if ($roles && $roles->count() > 0) {
                return $roles->first();
            }
        } catch (\Exception $e) {
            \Log::warning('Error getting user primary role', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return 'user'; // fallback default role
    }

    /**
     * Get all user roles as readable strings
     */
    private function getUserRoles($user): array
    {
        if (! $user) {
            return [];
        }

        $roles = [];

        try {
            // Set team context based on user's tenant
            if ($user->tenant_id) {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($user->tenant_id);
            } else {
                app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(null);
            }

            // Get all roles using Spatie Permission
            $userRoles = $user->getRoleNames();

            if ($userRoles && $userRoles->count() > 0) {
                foreach ($userRoles as $roleName) {
                    // Format role name for display
                    $formattedName = str_replace('-', ' ', $roleName);
                    $formattedName = str_replace('_', ' ', $formattedName);
                    $formattedName = ucwords($formattedName);

                    if (! in_array($formattedName, $roles)) {
                        $roles[] = $formattedName;
                    }
                }
            }

            // Fallback: if no roles, assign a default role
            if (empty($roles)) {
                $roles[] = 'User';
            }
        } catch (\Exception $e) {
            \Log::warning('Error getting user roles', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
            $roles[] = 'User';
        }

        return $roles;
    }
}
