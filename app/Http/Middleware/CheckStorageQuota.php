<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckStorageQuota
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Seulement pour les requêtes d'upload
        if (! $request->hasFile('document') && ! $request->hasFile('file')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || ! $user->tenant) {
            return $next($request);
        }

        $tenant = $user->tenant;

        // Vérifier le quota de stockage global
        if ($tenant->isStorageQuotaExceeded()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'storage_quota_exceeded',
                    'message' => 'Your organization has exceeded its storage quota. Please contact your administrator.',
                    'current_usage' => $tenant->getStorageUsage(),
                    'max_storage' => $tenant->max_storage_gb * 1024 * 1024 * 1024,
                    'percentage' => $tenant->getStorageUsagePercentage(),
                ], 413);
            }

            return redirect()->back()
                ->with('error', 'Your organization has exceeded its storage quota.')
                ->withInput();
        }

        // Vérifier la taille du fichier
        $file = $request->file('document') ?? $request->file('file');

        if ($file) {
            $maxFileSize = $tenant->max_file_size_mb * 1024 * 1024;

            if ($file->getSize() > $maxFileSize) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => 'file_too_large',
                        'message' => sprintf(
                            'File size exceeds the maximum allowed size of %d MB.',
                            $tenant->max_file_size_mb
                        ),
                        'file_size' => $file->getSize(),
                        'max_size' => $maxFileSize,
                    ], 413);
                }

                return redirect()->back()
                    ->with('error', sprintf(
                        'File size exceeds the maximum allowed size of %d MB.',
                        $tenant->max_file_size_mb
                    ))
                    ->withInput();
            }

            // Vérifier si le nouveau fichier dépassera le quota
            $availableStorage = $tenant->getAvailableStorage();

            if ($file->getSize() > $availableStorage) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'error' => 'insufficient_storage',
                        'message' => 'Not enough storage space available.',
                        'required' => $file->getSize(),
                        'available' => $availableStorage,
                    ], 413);
                }

                return redirect()->back()
                    ->with('error', 'Not enough storage space available.')
                    ->withInput();
            }
        }

        return $next($request);
    }
}
