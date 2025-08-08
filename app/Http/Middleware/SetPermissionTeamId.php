<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPermissionTeamId
{
    /**
     * Handle an incoming request.
     * Set the tenant context for Spatie Permission
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check() && auth()->user()->tenant_id) {
            // Set the tenant ID for Spatie Permission context
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId(auth()->user()->tenant_id);
        }
        
        return $next($request);
    }
}