<?php

use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Tenant\UserController as TenantUserController;
use App\Http\Controllers\SuperAdmin\TenantManagementController;
use App\Http\Controllers\SuperAdmin\UserManagementController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShareController;
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

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {
    
    // 2FA setup routes
    Route::prefix('two-factor')->name('two-factor.')->group(function () {
        Route::get('/', [TwoFactorController::class, 'index'])->name('index');
        Route::post('/enable', [TwoFactorController::class, 'enable'])->name('enable');
        Route::post('/verify', [TwoFactorController::class, 'verify'])->name('verify');
        Route::post('/disable', [TwoFactorController::class, 'disable'])->name('disable');
        Route::post('/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('recovery-codes');
    });
    
    // Profile routes (from Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Routes requiring 2FA (if enabled)
    Route::middleware(['2fa'])->group(function () {
        
        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        
        // Documents
        Route::prefix('documents')->name('documents.')->group(function () {
            Route::get('/', [DocumentController::class, 'index'])->name('index');
            Route::get('/create', [DocumentController::class, 'create'])->name('create');
            Route::post('/upload', [DocumentController::class, 'upload'])->name('upload');
            Route::get('/{document}', [DocumentController::class, 'show'])->name('show');
            Route::get('/{document}/edit', [DocumentController::class, 'edit'])->name('edit');
            Route::put('/{document}', [DocumentController::class, 'update'])->name('update');
            Route::delete('/{document}', [DocumentController::class, 'destroy'])->name('destroy');
            Route::get('/{document}/download', [DocumentController::class, 'download'])->name('download');
            Route::get('/{document}/preview', [DocumentController::class, 'preview'])->name('preview');
            Route::post('/{document}/share', [DocumentController::class, 'share'])->name('share');
            
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
        
        // Conversions
        Route::prefix('conversions')->name('conversions.')->group(function () {
            Route::get('/', [ConversionController::class, 'index'])->name('index');
            Route::post('/create', [ConversionController::class, 'create'])->name('create');
            Route::get('/{conversion}', [ConversionController::class, 'show'])->name('show');
            Route::delete('/{conversion}', [ConversionController::class, 'destroy'])->name('destroy');
            Route::post('/{conversion}/retry', [ConversionController::class, 'retry'])->name('retry');
        });
        
        // Tenant admin routes
        Route::middleware(['role:tenant_admin'])->prefix('tenant')->name('tenant.')->group(function () {
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
        });
        
        // Admin routes (keeping for backwards compatibility)
        Route::middleware(['role:admin,tenant_admin'])->prefix('admin')->name('admin.')->group(function () {
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
        
        // Super admin routes
        Route::middleware(['role:super_admin'])->prefix('super-admin')->name('super-admin.')->group(function () {
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
            
            // User management
            Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserManagementController::class, 'create'])->name('users.create');
            Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
            Route::get('/users/{user}', [UserManagementController::class, 'show'])->name('users.show');
            Route::get('/users/{user}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
            Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');
            Route::post('/users/{user}/reset-password', [UserManagementController::class, 'resetPassword'])->name('users.reset-password');
            Route::post('/users/{user}/toggle-2fa', [UserManagementController::class, 'toggle2FA'])->name('users.toggle-2fa');
            Route::post('/users/{user}/impersonate', [UserManagementController::class, 'impersonate'])->name('users.impersonate');
            Route::post('/users/stop-impersonation', [UserManagementController::class, 'stopImpersonation'])->name('users.stop-impersonation');
            Route::get('/users/export', [UserManagementController::class, 'export'])->name('users.export');
            Route::post('/users/bulk-action', [UserManagementController::class, 'bulkAction'])->name('users.bulk-action');
            
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