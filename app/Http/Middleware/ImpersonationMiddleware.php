<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Share impersonation status with all views
        if (session()->has('impersonator_id')) {
            view()->share('isImpersonating', true);
            view()->share('impersonatorId', session('impersonator_id'));

            // Also share via Inertia
            if (class_exists('\Inertia\Inertia')) {
                \Inertia\Inertia::share('impersonation', [
                    'active' => true,
                    'impersonator_id' => session('impersonator_id'),
                ]);
            }
        } else {
            view()->share('isImpersonating', false);

            if (class_exists('\Inertia\Inertia')) {
                \Inertia\Inertia::share('impersonation', [
                    'active' => false,
                ]);
            }
        }

        return $next($request);
    }
}
