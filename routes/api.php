<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConversionController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\PDFAdvancedController;
use App\Http\Controllers\Api\V1\PDFToolsController;
use App\Http\Controllers\Api\V1\ShareController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// API Version 1
Route::prefix('v1')->name('api.v1.')->group(function () {

    // Public routes
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');

    // Public share access
    Route::get('/shares/{token}', [ShareController::class, 'show'])->name('shares.show');
    Route::post('/shares/{token}/verify', [ShareController::class, 'verify'])->name('shares.verify');
    Route::get('/shares/{token}/download', [ShareController::class, 'download'])->name('shares.download');

    // Authenticated routes
    Route::middleware(['auth:sanctum'])->group(function () {

        // Auth management
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        Route::get('/user', [AuthController::class, 'user'])->name('user');
        Route::put('/user', [AuthController::class, 'updateProfile'])->name('user.update');
        Route::post('/user/change-password', [AuthController::class, 'changePassword'])->name('user.change-password');

        // 2FA
        Route::post('/user/2fa/enable', [AuthController::class, 'enable2FA'])->name('user.2fa.enable');
        Route::post('/user/2fa/disable', [AuthController::class, 'disable2FA'])->name('user.2fa.disable');
        Route::post('/user/2fa/verify', [AuthController::class, 'verify2FA'])->name('user.2fa.verify');

        // Tenant selection
        Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('/tenants/select/{tenant}', [TenantController::class, 'select'])->name('tenants.select');

        // Routes requiring tenant context
        Route::middleware(['require.tenant'])->group(function () {

            // Documents
            Route::apiResource('documents', DocumentController::class);
            Route::post('/documents/upload', [DocumentController::class, 'upload'])->name('documents.upload');
            Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
            Route::get('/documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
            Route::post('/documents/{document}/duplicate', [DocumentController::class, 'duplicate'])->name('documents.duplicate');
            Route::post('/documents/bulk-delete', [DocumentController::class, 'bulkDelete'])->name('documents.bulk-delete');
            Route::post('/documents/bulk-download', [DocumentController::class, 'bulkDownload'])->name('documents.bulk-download');
            Route::get('/documents/{document}/versions', [DocumentController::class, 'versions'])->name('documents.versions');
            Route::post('/documents/{document}/restore/{version}', [DocumentController::class, 'restoreVersion'])->name('documents.restore-version');

            // PDF Operations
            Route::prefix('documents')->name('documents.')->group(function () {
                Route::post('/merge', [PDFToolsController::class, 'merge'])->name('merge');
                Route::post('/{document}/split', [PDFToolsController::class, 'split'])->name('split');
                Route::post('/{document}/rotate', [PDFToolsController::class, 'rotate'])->name('rotate');
                Route::post('/{document}/extract', [PDFToolsController::class, 'extractPages'])->name('extract');
                Route::post('/{document}/compress', [PDFToolsController::class, 'compress'])->name('compress');
                Route::post('/{document}/watermark', [PDFToolsController::class, 'addWatermark'])->name('watermark');
                Route::post('/{document}/encrypt', [PDFToolsController::class, 'encrypt'])->name('encrypt');
                Route::post('/{document}/ocr', [PDFToolsController::class, 'ocr'])->name('ocr');
                Route::post('/{document}/optimize', [PDFToolsController::class, 'optimize'])->name('optimize');
                Route::get('/{document}/metadata', [PDFToolsController::class, 'getMetadata'])->name('metadata');
                Route::put('/{document}/metadata', [PDFToolsController::class, 'updateMetadata'])->name('metadata.update');
            });

            // Advanced PDF Features
            Route::prefix('pdf-advanced')->name('pdf-advanced.')->group(function () {
                // Digital Signatures
                Route::post('/documents/{document}/sign', [PDFAdvancedController::class, 'signDocument'])->name('sign');
                Route::get('/documents/{document}/verify-signature', [PDFAdvancedController::class, 'verifySignature'])->name('verify-signature');
                Route::post('/certificate/create', [PDFAdvancedController::class, 'createSelfSignedCertificate'])->name('certificate.create');

                // Redaction
                Route::post('/documents/{document}/redact', [PDFAdvancedController::class, 'redactDocument'])->name('redact');
                Route::post('/documents/{document}/redact-sensitive', [PDFAdvancedController::class, 'redactSensitiveData'])->name('redact-sensitive');

                // PDF Standards
                Route::post('/documents/{document}/convert-pdfa', [PDFAdvancedController::class, 'convertToPDFA'])->name('convert-pdfa');
                Route::post('/documents/{document}/convert-pdfx', [PDFAdvancedController::class, 'convertToPDFX'])->name('convert-pdfx');

                // Comparison
                Route::post('/compare', [PDFAdvancedController::class, 'compareDocuments'])->name('compare');
                Route::post('/compare-text', [PDFAdvancedController::class, 'compareText'])->name('compare-text');

                // Forms
                Route::post('/documents/{document}/create-form', [PDFAdvancedController::class, 'createForm'])->name('create-form');
                Route::post('/documents/{document}/fill-form', [PDFAdvancedController::class, 'fillForm'])->name('fill-form');
                Route::get('/documents/{document}/extract-form-data', [PDFAdvancedController::class, 'extractFormData'])->name('extract-form-data');
            });

            // Conversions
            Route::apiResource('conversions', ConversionController::class)->except(['update']);
            Route::post('/conversions/{conversion}/retry', [ConversionController::class, 'retry'])->name('conversions.retry');
            Route::post('/conversions/{conversion}/cancel', [ConversionController::class, 'cancel'])->name('conversions.cancel');
            Route::get('/conversions/{conversion}/download', [ConversionController::class, 'download'])->name('conversions.download');
            Route::post('/conversions/batch', [ConversionController::class, 'batch'])->name('conversions.batch');

            // Shares
            Route::apiResource('shares', ShareController::class)->except(['store']);
            Route::post('/documents/{document}/share', [ShareController::class, 'store'])->name('shares.store');
            Route::post('/shares/{share}/extend', [ShareController::class, 'extend'])->name('shares.extend');
            Route::post('/shares/{share}/revoke', [ShareController::class, 'revoke'])->name('shares.revoke');
            Route::get('/shares/{share}/stats', [ShareController::class, 'stats'])->name('shares.stats');

            // Search
            Route::get('/search', [DocumentController::class, 'search'])->name('search');
            Route::get('/search/advanced', [DocumentController::class, 'advancedSearch'])->name('search.advanced');

            // Statistics
            Route::get('/stats/overview', [DocumentController::class, 'statsOverview'])->name('stats.overview');
            Route::get('/stats/usage', [DocumentController::class, 'statsUsage'])->name('stats.usage');
            Route::get('/stats/activity', [DocumentController::class, 'statsActivity'])->name('stats.activity');

            // Admin routes (tenant admin only)
            Route::middleware(['role:tenant-admin'])->prefix('admin')->name('admin.')->group(function () {
                // Users management
                Route::apiResource('users', UserController::class);
                Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
                Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
                Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
                Route::post('/users/{user}/assign-role', [UserController::class, 'assignRole'])->name('users.assign-role');
                Route::get('/users/{user}/activity', [UserController::class, 'activity'])->name('users.activity');

                // Tenant settings
                Route::get('/settings', [TenantController::class, 'settings'])->name('settings');
                Route::put('/settings', [TenantController::class, 'updateSettings'])->name('settings.update');
                Route::get('/settings/limits', [TenantController::class, 'limits'])->name('settings.limits');
                Route::get('/settings/usage', [TenantController::class, 'usage'])->name('settings.usage');

                // Activity logs
                Route::get('/activity', [TenantController::class, 'activityLog'])->name('activity');
                Route::get('/activity/export', [TenantController::class, 'exportActivityLog'])->name('activity.export');

                // Storage management
                Route::get('/storage', [TenantController::class, 'storageInfo'])->name('storage');
                Route::post('/storage/cleanup', [TenantController::class, 'cleanupStorage'])->name('storage.cleanup');
                Route::post('/storage/optimize', [TenantController::class, 'optimizeStorage'])->name('storage.optimize');
            });
        });

        // Super admin routes
        Route::middleware(['role:super-admin'])->prefix('super-admin')->name('super-admin.')->group(function () {
            Route::get('/tenants', [TenantController::class, 'listAll'])->name('tenants.list');
            Route::post('/tenants', [TenantController::class, 'create'])->name('tenants.create');
            Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
            Route::delete('/tenants/{tenant}', [TenantController::class, 'delete'])->name('tenants.delete');
            Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
            Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
            Route::get('/system/stats', [TenantController::class, 'systemStats'])->name('system.stats');
            Route::get('/system/health', [TenantController::class, 'systemHealth'])->name('system.health');
        });
    });
});

// Default user endpoint for backward compatibility
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
