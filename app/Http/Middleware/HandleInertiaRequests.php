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
                    'permissions' => $user->getPermissions(),
                    'is_super_admin' => $user->isSuperAdmin(),
                    'is_tenant_admin' => $user->isTenantAdmin(),
                ] : null,
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
     * Get the user's primary role slug
     */
    private function getUserRole($user): ?string
    {
        if (!$user) {
            return null;
        }
        
        try {
            // Check for super admin first (system-wide role)
            if (method_exists($user, 'hasRole') && $user->hasRole('super_admin')) {
                return 'super_admin';
            }
            
            // Get role for current tenant
            if (method_exists($user, 'role')) {
                $role = $user->role();
                if ($role) {
                    // Return simplified role slug (remove tenant suffix)
                    $slug = $role->slug;
                    if ($user->tenant_id && strpos($slug, '_' . $user->tenant_id) !== false) {
                        return str_replace('_' . $user->tenant_id, '', $slug);
                    }
                    return $slug;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('Error getting user primary role', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage()
            ]);
        }
        
        return 'user'; // fallback default role
    }
    
    /**
     * Get all user roles as readable strings
     */
    private function getUserRoles($user): array
    {
        if (!$user) {
            return [];
        }
        
        $roles = [];
        
        try {
            // Check for super admin first (system-wide role)
            if (method_exists($user, 'hasRole') && $user->hasRole('super_admin')) {
                $roles[] = 'Super Admin';
            }
            
            // Get all roles for current tenant
            if ($user->roles) {
                $userRoles = $user->roles;
                if ($userRoles && $userRoles->count() > 0) {
                    foreach ($userRoles as $role) {
                        // Skip super admin if already added
                        if ($role->slug === 'super_admin') {
                            continue;
                        }
                        
                        // Format role name
                        $roleName = $role->name ?? $role->slug;
                        $roleName = str_replace('_', ' ', $roleName);
                        $roleName = ucwords($roleName);
                        
                        if (!in_array($roleName, $roles)) {
                            $roles[] = $roleName;
                        }
                    }
                }
            }
            
            // If no roles found, try to get from the primary role method
            if (empty($roles)) {
                $primaryRole = $this->getUserRole($user);
                if ($primaryRole) {
                    $roleName = str_replace('_', ' ', $primaryRole);
                    $roleName = ucwords($roleName);
                    $roles[] = $roleName;
                }
            }
            
            // Fallback: if still no roles, assign a default role
            if (empty($roles)) {
                $roles[] = 'User';
            }
        } catch (\Exception $e) {
            \Log::warning('Error getting user roles', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage()
            ]);
            $roles[] = 'User';
        }
        
        return $roles;
    }
}
