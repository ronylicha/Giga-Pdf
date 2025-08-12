<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class TwoFactorController extends Controller
{
    protected $google2fa;
    
    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }
    
    /**
     * Show the 2FA setup page
     */
    public function show(Request $request)
    {
        $user = $request->user();
        
        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.security');
        }
        
        return Inertia::render('Auth/TwoFactor/Setup');
    }
    
    /**
     * Enable 2FA - Generate secret and QR code
     */
    public function enable(Request $request)
    {
        $user = $request->user();
        
        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'error' => '2FA is already enabled'
            ], 400);
        }
        
        // Generate secret key
        $secret = $this->google2fa->generateSecretKey();
        
        // Store temporarily in session
        session(['2fa_secret' => $secret]);
        
        // Generate QR code
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );
        
        // Generate QR code SVG
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        
        $writer = new Writer($renderer);
        $qrCode = $writer->writeString($qrCodeUrl);
        
        // Generate recovery codes
        $recoveryCodes = collect(range(1, 8))->map(function () {
            return Str::random(10) . '-' . Str::random(10);
        })->toArray();
        
        // Store recovery codes in session temporarily
        session(['2fa_recovery_codes' => $recoveryCodes]);
        
        return response()->json([
            'secret' => $secret,
            'qr_code' => 'data:image/svg+xml;base64,' . base64_encode($qrCode),
            'recovery_codes' => $recoveryCodes
        ]);
    }
    
    /**
     * Confirm and save 2FA setup
     */
    public function confirm(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6'
        ]);
        
        $user = $request->user();
        $secret = session('2fa_secret');
        $recoveryCodes = session('2fa_recovery_codes');
        
        if (!$secret || !$recoveryCodes) {
            return back()->withErrors([
                'code' => 'Session expired. Please start the setup again.'
            ]);
        }
        
        // Verify the code
        $valid = $this->google2fa->verifyKey($secret, $request->code);
        
        if (!$valid) {
            return back()->withErrors([
                'code' => 'Invalid verification code. Please try again.'
            ]);
        }
        
        // Enable 2FA for the user
        $user->enableTwoFactor($secret, $recoveryCodes);
        
        // Clear session
        session()->forget(['2fa_secret', '2fa_recovery_codes']);
        
        // Log the activity
        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ])
            ->log('Two-factor authentication enabled');
        
        return redirect()->route('profile.security')
            ->with('success', 'Two-factor authentication has been enabled successfully.');
    }
    
    /**
     * Disable 2FA
     */
    public function disable(Request $request)
    {
        $request->validate([
            'password' => 'required|current_password'
        ]);
        
        $user = $request->user();
        
        if (!$user->hasTwoFactorEnabled()) {
            return back()->withErrors([
                'error' => 'Two-factor authentication is not enabled.'
            ]);
        }
        
        // Disable 2FA
        $user->disableTwoFactor();
        
        // Log the activity
        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ])
            ->log('Two-factor authentication disabled');
        
        return redirect()->route('profile.security')
            ->with('success', 'Two-factor authentication has been disabled.');
    }
    
    /**
     * Show 2FA challenge page
     */
    public function challenge(Request $request)
    {
        if (!session('2fa_user_id')) {
            return redirect()->route('login');
        }
        
        return Inertia::render('Auth/TwoFactor/Challenge', [
            'recovery' => $request->get('recovery', false)
        ]);
    }
    
    /**
     * Verify 2FA code
     */
    public function verify(Request $request)
    {
        $request->validate([
            'code' => 'required|string'
        ]);
        
        $userId = session('2fa_user_id');
        
        if (!$userId) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Session expired. Please login again.'
                ], 401);
            }
            return redirect()->route('login')
                ->withErrors(['code' => 'Session expired. Please login again.']);
        }
        
        $user = User::findOrFail($userId);
        
        // Check if it's a recovery code or TOTP code
        if (strlen($request->code) > 10) {
            // Recovery code
            $valid = $user->verifyRecoveryCode($request->code);
            
            if ($valid) {
                // Log recovery code usage
                activity()
                    ->performedOn($user)
                    ->causedBy($user)
                    ->withProperties([
                        'ip' => $request->ip(),
                        'method' => 'recovery_code'
                    ])
                    ->log('Logged in using recovery code');
            }
        } else {
            // TOTP code
            $secret = $user->two_factor_secret; // decrypted via cast
            $valid = $this->google2fa->verifyKey($secret, $request->code);
        }
        
        if (!$valid) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Invalid verification code. Please try again.'
                ], 422);
            }
            return back()->withErrors([
                'code' => 'Invalid verification code. Please try again.'
            ]);
        }
        
        // Clear 2FA session
        session()->forget('2fa_user_id');
        
        // Login the user
        Auth::loginUsingId($user->id);
        $user->updateLastLogin($request->ip());
        
        // Mark 2FA as verified for this session
        session(['2fa_verified' => true]);
        
        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Verification successful',
                'redirect' => route('dashboard')
            ]);
        }
        
        return redirect()->intended(route('dashboard'));
    }
    
    /**
     * Generate new recovery codes
     */
    public function regenerateRecoveryCodes(Request $request)
    {
        $request->validate([
            'password' => 'required|current_password'
        ]);
        
        $user = $request->user();
        
        if (!$user->hasTwoFactorEnabled()) {
            return back()->withErrors([
                'error' => 'Two-factor authentication is not enabled.'
            ]);
        }
        
        // Generate new recovery codes
        $recoveryCodes = $user->generateRecoveryCodes();
        
        // Log the activity
        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->withProperties([
                'ip' => $request->ip()
            ])
            ->log('Recovery codes regenerated');
        
        return response()->json([
            'recovery_codes' => $recoveryCodes,
            'message' => 'Recovery codes have been regenerated successfully.'
        ]);
    }
    
    /**
     * Show QR code for existing 2FA
     */
    public function showQrCode(Request $request)
    {
        $request->validate([
            'password' => 'required|current_password'
        ]);
        
        $user = $request->user();
        
        if (!$user->hasTwoFactorEnabled()) {
            return response()->json([
                'error' => 'Two-factor authentication is not enabled.'
            ], 400);
        }
        
        $secret = $user->two_factor_secret; // decrypted via cast
        
        // Generate QR code
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );
        
        // Generate QR code SVG
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        
        $writer = new Writer($renderer);
        $qrCode = $writer->writeString($qrCodeUrl);
        
        return response()->json([
            'qr_code' => 'data:image/svg+xml;base64,' . base64_encode($qrCode)
        ]);
    }
}