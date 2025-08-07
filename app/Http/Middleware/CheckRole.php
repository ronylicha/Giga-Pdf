<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }
        
        $user = auth()->user();
        
        // Check if user has any of the required roles
        if (!$user->hasAnyRole($roles)) {
            abort(403, 'Accès refusé. Vous n\'avez pas les permissions nécessaires.');
        }
        
        return $next($request);
    }
}