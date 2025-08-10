<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'tenant_name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        DB::beginTransaction();

        try {
            // Create the tenant first
            $tenant = Tenant::create([
                'name' => $request->tenant_name,
                'slug' => Str::slug($request->tenant_name),
                'max_storage_gb' => 1, // 1GB storage per account
                'max_users' => 999999, // Unlimited users
                'max_file_size_mb' => 25, // 25MB per file
                'settings' => [
                    'max_users' => 999999,
                    'max_storage_gb' => 1,
                    'max_file_size_mb' => 25,
                    'features' => [
                        'ocr' => true,
                        'conversion' => true,
                        'editor' => true,
                        'sharing' => true,
                        'pdf_tools' => true,
                        'merge' => true,
                        'split' => true,
                        'compress' => true,
                        'watermark' => true,
                        'encrypt' => true,
                        'rotate' => true,
                        'extract' => true,
                    ],
                ],
                'features' => [
                    'ocr' => true,
                    'conversion' => true,
                    'editor' => true,
                    'sharing' => true,
                    'pdf_tools' => true,
                    'merge' => true,
                    'split' => true,
                    'compress' => true,
                    'watermark' => true,
                    'encrypt' => true,
                    'rotate' => true,
                    'extract' => true,
                ],
                'subscription_plan' => 'free',
                'is_active' => true,
            ]);

            // Create the user as tenant admin
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'is_active' => true, // Ensure user is active
                'email_verified_at' => now(), // Auto-verify for tenant admins
            ]);
            
            // Set team context for Spatie permissions
            app()[\Spatie\Permission\PermissionRegistrar::class]->setPermissionsTeamId($tenant->id);
            
            // Assign the tenant-admin role using Spatie permissions
            $user->assignRole('tenant-admin');

            DB::commit();

            event(new Registered($user));

            Auth::login($user);

            return redirect(route('dashboard', absolute: false));

        } catch (\Exception $e) {
            DB::rollBack();
            
            return back()->withErrors([
                'tenant_name' => 'Failed to create organization. Please try again.',
            ])->withInput($request->except('password', 'password_confirmation'));
        }
    }
}
