<?php

use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\PDFToolsController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Tenant\UserController as TenantUserController;
use App\Http\Controllers\SuperAdmin\TenantManagementController;
use App\Http\Controllers\SuperAdmin\UserManagementController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public routes
Route::get('/', function () {
    return Inertia::render('Landing', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
})->name('home');

// Shared document access (public with optional password)
Route::get('/shared/{share:token}', [ShareController::class, 'show'])->name('share.show');
Route::post('/shared/{share:token}/verify', [ShareController::class, 'verify'])->name('share.verify');
Route::get('/shared/{share:token}/download', [ShareController::class, 'download'])->name('share.download');

// Invitation routes (public)
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.accept');
Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept.post');

// Authentication routes
require __DIR__.'/auth.php';

// Public 2FA challenge routes (user is logged out during challenge)
Route::prefix('two-factor')->group(function () {
    Route::get('/challenge', [TwoFactorController::class, 'challenge'])->name('2fa.challenge');
    Route::post('/verify', [TwoFactorController::class, 'verify'])->name('2fa.verify');
});

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {
    
    // 2FA routes (only for authenticated user managing their 2FA)
    Route::prefix('two-factor')->group(function () {
        // Setup (optional, from profile)
        Route::get('/', [TwoFactorController::class, 'show'])->name('2fa.setup');
        Route::post('/enable', [TwoFactorController::class, 'enable'])->name('2fa.enable');
        Route::post('/confirm', [TwoFactorController::class, 'confirm'])->name('2fa.confirm');
        Route::post('/disable', [TwoFactorController::class, 'disable'])->name('2fa.disable');
        Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('2fa.recovery-codes');
    });

    // Profile security (2FA management) page
    Route::get('/profile/security', function () {
        return Inertia::render('Profile/Security');
    })->name('profile.security');
    
    // Profile routes (from Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Impersonation stop route (must be outside super-admin group)
    Route::post('/stop-impersonation', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'stopImpersonation'])->name('stop-impersonation');
    
    // Routes requiring 2FA (if enabled)
    Route::middleware(['2fa'])->group(function () {
        
        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        
        // Documents (requires tenant)
        Route::middleware(['require.tenant'])->prefix('documents')->name('documents.')->group(function () {
            Route::get('/', [DocumentController::class, 'index'])->name('index');
            Route::get('/create', [DocumentController::class, 'create'])->name('create');
            Route::post('/upload', [DocumentController::class, 'upload'])->name('upload');
            Route::get('/conversions', [DocumentController::class, 'conversions'])->name('conversions');
            Route::get('/shared', [DocumentController::class, 'shared'])->name('shared');
            Route::get('/{document}', [DocumentController::class, 'show'])->name('show');
            Route::get('/{document}/edit', [DocumentController::class, 'edit'])->name('edit');
            Route::put('/{document}', [DocumentController::class, 'update'])->name('update');
            Route::delete('/{document}', [DocumentController::class, 'destroy'])->name('destroy');
            Route::get('/{document}/download', [DocumentController::class, 'download'])->name('download');
            Route::get('/{document}/preview', [DocumentController::class, 'preview'])->name('preview');
            Route::get('/{document}/serve', [DocumentController::class, 'serve'])->name('serve');
            Route::get('/{document}/extract-content', [DocumentController::class, 'extractContent'])->name('extract-content');
            Route::post('/{document}/update-content', [DocumentController::class, 'updateContent'])->name('update-content');
            Route::post('/{document}/apply-modification', [DocumentController::class, 'applyModification'])->name('apply-modification');
            Route::post('/{document}/save-modifications', [DocumentController::class, 'saveModifications'])->name('save-modifications');
            Route::post('/{document}/share', [DocumentController::class, 'share'])->name('share');
            
            // Bulk operations
            Route::post('/bulk-delete', [DocumentController::class, 'bulkDelete'])->name('bulk-delete');
            Route::post('/bulk-download', [DocumentController::class, 'bulkDownload'])->name('bulk-download');
            
            // HTML Editor routes
            Route::get('/{document}/html-editor', [DocumentController::class, 'htmlEditor'])->name('html-editor');
            Route::post('/{document}/convert-to-html', [DocumentController::class, 'convertToHtml'])->name('convert-to-html');
            Route::post('/{document}/save-html', [DocumentController::class, 'saveHtml'])->name('save-html');
            Route::post('/{document}/save-html-as-pdf', [DocumentController::class, 'saveHtmlAsPdf'])->name('save-html-as-pdf');
            Route::get('/{document}/assets/{filename}', [DocumentController::class, 'serveAsset'])->name('serve-asset')->where('filename', '.*');
            
            // PDF specific operations
            Route::post('/merge', [DocumentController::class, 'merge'])->name('merge');
            Route::post('/{document}/split', [DocumentController::class, 'split'])->name('split');
            Route::post('/{document}/rotate', [DocumentController::class, 'rotate'])->name('rotate');
            Route::post('/{document}/extract', [DocumentController::class, 'extractPages'])->name('extract');
            Route::post('/{document}/compress', [DocumentController::class, 'compress'])->name('compress');
            Route::post('/{document}/ocr', [DocumentController::class, 'ocr'])->name('ocr');
            Route::post('/{document}/watermark', [DocumentController::class, 'addWatermark'])->name('watermark');
            Route::post('/{document}/encrypt', [DocumentController::class, 'encrypt'])->name('encrypt');
        });
        
        // Conversions (requires tenant)
        Route::middleware(['require.tenant'])->prefix('conversions')->name('conversions.')->group(function () {
            Route::get('/', [ConversionController::class, 'index'])->name('index');
            Route::post('/create', [ConversionController::class, 'create'])->name('create');
            Route::get('/{conversion}', [ConversionController::class, 'show'])->name('show');
            Route::delete('/{conversion}', [ConversionController::class, 'destroy'])->name('destroy');
            Route::post('/{conversion}/retry', [ConversionController::class, 'retry'])->name('retry');
        });
        
        // PDF Tools (requires tenant)
        Route::middleware(['require.tenant'])->prefix('tools')->name('tools.')->group(function () {
            Route::get('/merge', [PDFToolsController::class, 'merge'])->name('merge');
            Route::get('/split', [PDFToolsController::class, 'split'])->name('split');
            Route::get('/rotate', [PDFToolsController::class, 'rotate'])->name('rotate');
            Route::get('/compress', [PDFToolsController::class, 'compress'])->name('compress');
            Route::get('/watermark', [PDFToolsController::class, 'watermark'])->name('watermark');
            Route::get('/encrypt', [PDFToolsController::class, 'encrypt'])->name('encrypt');
            Route::get('/ocr', [PDFToolsController::class, 'ocr'])->name('ocr');
            Route::get('/extract', [PDFToolsController::class, 'extract'])->name('extract');
        });
        
        // Tenant admin routes
        Route::middleware(['role:tenant-admin'])->prefix('tenant')->name('tenant.')->group(function () {
            // User management within tenant
            Route::get('/users', [TenantUserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [TenantUserController::class, 'create'])->name('users.create');
            Route::post('/users/invite', [TenantUserController::class, 'invite'])->name('users.invite');
            Route::get('/users/{user}', [TenantUserController::class, 'show'])->name('users.show');
            Route::get('/users/{user}/edit', [TenantUserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [TenantUserController::class, 'update'])->name('users.update');
            Route::delete('/users/{user}', [TenantUserController::class, 'destroy'])->name('users.destroy');
            Route::post('/users/{user}/reset-password', [TenantUserController::class, 'resetPassword'])->name('users.reset-password');
            Route::post('/users/{user}/toggle-2fa', [TenantUserController::class, 'toggle2FA'])->name('users.toggle-2fa');
            
            // Invitations
            Route::post('/invitations/{invitation}/resend', [TenantUserController::class, 'resendInvitation'])->name('invitations.resend');
            Route::delete('/invitations/{invitation}', [TenantUserController::class, 'cancelInvitation'])->name('invitations.cancel');
            
            // Settings
            Route::get('/settings', [AdminDashboardController::class, 'settings'])->name('settings');
            Route::post('/settings', [AdminDashboardController::class, 'updateSettings'])->name('settings.update');
            
            // Activity logs
            Route::get('/activity', [AdminDashboardController::class, 'activity'])->name('activity');
            
            // Storage management
            Route::get('/storage', [AdminDashboardController::class, 'storage'])->name('storage');
            Route::post('/storage/cleanup', [AdminDashboardController::class, 'cleanupStorage'])->name('storage.cleanup');
            
            // Roles management
            Route::resource('roles', RoleController::class);
            Route::get('/roles/{role}/users', [RoleController::class, 'users'])->name('roles.users');
            Route::post('/roles/{role}/assign-users', [RoleController::class, 'assignUsers'])->name('roles.assign-users');
            Route::delete('/roles/{role}/users/{user}', [RoleController::class, 'removeUser'])->name('roles.remove-user');
            
            // Admin sub-routes
            Route::prefix('admin')->name('admin.')->group(function () {
                Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
                Route::resource('users', TenantUserController::class);
                Route::resource('roles', RoleController::class);
                Route::get('/activity', [AdminDashboardController::class, 'activity'])->name('activity');
                Route::get('/storage', [AdminDashboardController::class, 'storage'])->name('storage');
                Route::get('/settings', [AdminDashboardController::class, 'settings'])->name('settings');
            });
        });
        
        // Admin routes (keeping for backwards compatibility)
        Route::middleware(['role:admin,tenant-admin'])->prefix('admin')->name('admin.')->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
            
            // User management
            Route::resource('users', AdminUserController::class);
            Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->name('users.reset-password');
            Route::post('/users/{user}/toggle-2fa', [AdminUserController::class, 'toggle2FA'])->name('users.toggle-2fa');
            
            // Settings
            Route::get('/settings', [AdminDashboardController::class, 'settings'])->name('settings');
            Route::post('/settings', [AdminDashboardController::class, 'updateSettings'])->name('settings.update');
            
            // Activity logs
            Route::get('/activity', [AdminDashboardController::class, 'activity'])->name('activity');
            
            // Storage management
            Route::get('/storage', [AdminDashboardController::class, 'storage'])->name('storage');
            Route::post('/storage/cleanup', [AdminDashboardController::class, 'cleanupStorage'])->name('storage.cleanup');
        });
        
        // Tenant management routes (only for super-admin)
        Route::middleware(['super.admin'])->prefix('tenants')->name('tenants.')->group(function () {
            Route::get('/', [TenantController::class, 'index'])->name('index');
            Route::get('/create', [TenantController::class, 'create'])->name('create');
            Route::post('/', [TenantController::class, 'store'])->name('store');
            Route::get('/{tenant}', [TenantController::class, 'show'])->name('show');
            Route::get('/{tenant}/edit', [TenantController::class, 'edit'])->name('edit');
            Route::put('/{tenant}', [TenantController::class, 'update'])->name('update');
            Route::delete('/{tenant}', [TenantController::class, 'destroy'])->name('destroy');
            Route::post('/{tenant}/toggle-status', [TenantController::class, 'toggleStatus'])->name('toggle-status');
        });
        
        // Super admin routes
        Route::middleware(['role:super-admin'])->prefix('super-admin')->name('super-admin.')->group(function () {
            Route::get('/dashboard', [TenantController::class, 'dashboard'])->name('dashboard');
            
            // Tenant management
            Route::get('/tenants', [TenantManagementController::class, 'index'])->name('tenants.index');
            Route::get('/tenants/create', [TenantManagementController::class, 'create'])->name('tenants.create');
            Route::post('/tenants', [TenantManagementController::class, 'store'])->name('tenants.store');
            Route::get('/tenants/{tenant}', [TenantManagementController::class, 'show'])->name('tenants.show');
            Route::get('/tenants/{tenant}/edit', [TenantManagementController::class, 'edit'])->name('tenants.edit');
            Route::put('/tenants/{tenant}', [TenantManagementController::class, 'update'])->name('tenants.update');
            Route::delete('/tenants/{tenant}', [TenantManagementController::class, 'destroy'])->name('tenants.destroy');
            Route::post('/tenants/{tenant}/suspend', [TenantManagementController::class, 'suspend'])->name('tenants.suspend');
            Route::post('/tenants/{tenant}/reactivate', [TenantManagementController::class, 'reactivate'])->name('tenants.reactivate');
            Route::get('/tenants/{tenant}/export', [TenantManagementController::class, 'export'])->name('tenants.export');
            Route::get('/tenants/{tenant}/edit-limits', [TenantManagementController::class, 'editLimits'])->name('tenants.edit-limits');
            Route::patch('/tenants/{tenant}/update-limits', [TenantManagementController::class, 'updateLimits'])->name('tenants.update-limits');
            
            // User management
            Route::get('/users', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'index'])->name('users.index');
            Route::get('/users/create', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'create'])->name('users.create');
            Route::post('/users', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'store'])->name('users.store');
            Route::get('/users/{user}', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'show'])->name('users.show');
            Route::get('/users/{user}/edit', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'update'])->name('users.update');
            Route::delete('/users/{user}', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'destroy'])->name('users.destroy');
            Route::post('/users/{user}/reset-password', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'resetPassword'])->name('users.reset-password');
            Route::post('/users/{user}/verify-email', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'verifyEmail'])->name('users.verify-email');
            Route::post('/users/{user}/disable-2fa', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'disable2FA'])->name('users.disable-2fa');
            Route::post('/users/{user}/impersonate', [\App\Http\Controllers\SuperAdmin\SuperAdminUsersController::class, 'impersonate'])->name('users.impersonate');
            
            // System management
            Route::get('/system', [TenantController::class, 'system'])->name('system');
            Route::get('/horizon', function () {
                return redirect('/horizon');
            })->name('horizon');
        });
    });
});

// Horizon route (protected by Horizon's own authorization)
Route::get('/horizon/{any?}', function () {
    return redirect('/horizon');
})->where('any', '.*');