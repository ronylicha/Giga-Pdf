<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantSelected
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Si l'utilisateur n'est pas connecté, continuer
        if (!auth()->check()) {
            return $next($request);
        }
        
        $user = auth()->user();
        
        // Si l'utilisateur n'a pas de tenant, rediriger vers une page d'erreur
        if (!$user->tenant_id) {
            // Sauf si c'est un super admin
            if (!$user->isSuperAdmin()) {
                auth()->logout();
                return redirect()->route('login')
                    ->with('error', 'Your account is not associated with any organization. Please contact support.');
            }
        }
        
        // Vérifier que le tenant est actif
        if ($user->tenant && !$user->tenant->isSubscriptionActive()) {
            auth()->logout();
            return redirect()->route('login')
                ->with('error', 'Your organization subscription has expired. Please contact your administrator.');
        }
        
        // Vérifier que l'utilisateur est actif
        if (!$user->isActive()) {
            auth()->logout();
            return redirect()->route('login')
                ->with('error', 'Your account has been deactivated. Please contact your administrator.');
        }
        
        return $next($request);
    }
}