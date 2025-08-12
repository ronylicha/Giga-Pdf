<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenant
{
    /**
     * Handle an incoming request.
     * Ensure user has a tenant (super-admin excluded from certain routes)
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Super-admin bypass - redirect to appropriate routes
        if ($user->isSuperAdmin()) {
            // Redirect super-admin to their specific routes
            $currentRoute = $request->route()->getName();

            // Routes that super-admin should not access (tenant-specific routes)
            $tenantOnlyRoutes = [
                'documents.index',
                'documents.create',
                'documents.store',
                'documents.show',
                'documents.edit',
                'documents.update',
                'documents.destroy',
                'conversions.index',
                'conversions.create',
                'conversions.store',
                'tools.merge',
                'tools.split',
                'tools.rotate',
                'tools.compress',
                'tools.watermark',
                'tools.encrypt',
                'tools.ocr',
                'tools.extract',
            ];

            if (in_array($currentRoute, $tenantOnlyRoutes)) {
                // Redirect super-admin to tenant management instead
                return redirect()->route('tenants.index')
                    ->with('info', 'En tant que super-admin, vous devez gérer les tenants depuis cette interface.');
            }

            // Allow super-admin to continue for other routes
            return $next($request);
        }

        // Regular users must have a tenant
        if (! $user->tenant_id || ! $user->tenant) {
            return redirect()->route('home')
                ->with('error', 'Votre compte n\'est associé à aucun tenant. Veuillez contacter l\'administrateur.');
        }

        // Check if tenant is active
        if (! $user->tenant->is_active) {
            auth()->logout();

            return redirect()->route('login')
                ->with('error', 'Votre tenant a été suspendu. Veuillez contacter l\'administrateur.');
        }

        return $next($request);
    }
}
