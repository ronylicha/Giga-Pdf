<?php

use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ConversionController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShareController;
use Illuminate\Foundation\Application;
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
        
        // Admin routes
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
            Route::resource('tenants', TenantController::class);
            Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
            Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
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