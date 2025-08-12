<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventImpersonation
{
    /**
     * Handle an incoming request.
     * Prevents certain actions while impersonating
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (session()->has('impersonator_id')) {
            // List of routes that should be blocked during impersonation
            $blockedRoutes = [
                'profile.update',
                'profile.destroy',
                'password.update',
                'two-factor.enable',
                'two-factor.disable',
                'super-admin.users.impersonate', // Prevent nested impersonation
            ];

            if (in_array($request->route()->getName(), $blockedRoutes)) {
                return redirect()->back()->with('error', 'Cette action n\'est pas autorisée en mode impersonation.');
            }
        }

        return $next($request);
    }
}
