<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Require2FA
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        // Si l'utilisateur n'est pas connecté, continuer
        if (!$user) {
            return $next($request);
        }
        
        // Si l'utilisateur n'a pas activé la 2FA, continuer
        if (!$user->hasTwoFactorEnabled()) {
            // Vérifier si le tenant requiert la 2FA
            if ($user->tenant && $user->tenant->getSetting('require_2fa', false)) {
                return redirect()->route('2fa.setup')
                    ->with('warning', 'Your organization requires two-factor authentication. Please set it up to continue.');
            }
            
            return $next($request);
        }
        
        // Si la 2FA est déjà vérifiée pour cette session, continuer
        if (session('2fa_verified')) {
            return $next($request);
        }
        
        // Stocker l'URL de destination
        session(['url.intended' => $request->url()]);
        
        // Stocker l'ID de l'utilisateur et déconnecter
        session(['2fa_user_id' => $user->id]);
        auth()->logout();
        
        // Rediriger vers la page de vérification 2FA
        return redirect()->route('2fa.challenge');
    }
}