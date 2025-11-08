<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Mail\PasswordResetMail;
use Inertia\Inertia;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }
    
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);
    
        $key = Str::lower($request->input('email')).'|'.$request->ip();
    
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again after {$seconds} seconds.",
            ]);
        }
    
        $remember = $request->boolean('remember');
    
        if (Auth::attempt($credentials, $remember)) {
            RateLimiter::clear($key);
            $request->session()->regenerate();
            return redirect()->intended('dashboard');
        }
    
        RateLimiter::hit($key, 60);
    
        throw ValidationException::withMessages([
            'email' => 'Invalid credentials. Please try again.',
        ]);
    }
    
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect('/login');
    }

    /**
     * Show forgot password form
     */
    public function showForgotPassword()
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Send password reset link
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Rate limiting for password reset requests
        $key = 'password-reset:' . $request->ip();
        
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->with('error', "Too many password reset attempts. Please try again in {$seconds} seconds.");
        }

        // Check if user exists
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Don't reveal if email exists for security
            return back()->with('success', 'If your email is registered, you will receive a password reset link shortly.');
        }

        // Delete old tokens for this email
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        // Create new token
        $token = Str::random(64);

        // Store token in database
        DB::table('password_reset_tokens')->insert([
            'email' => $request->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        // Generate reset URL
        $resetUrl = url("/reset-password/{$token}?email=" . urlencode($request->email));

        // Send email
        try {
            Mail::to($request->email)->send(new PasswordResetMail($resetUrl, 10));
            
            RateLimiter::hit($key, 300); // 5 minutes decay

            \Log::info('Password reset email sent', [
                'email' => $request->email,
                'ip' => $request->ip(),
            ]);

            return back()->with('success', 'Password reset link sent successfully! Please check your email.');
        } catch (\Exception $e) {
            \Log::error('Failed to send password reset email', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to send password reset email. Please try again later.');
        }
    }

    /**
     * Show reset password form
     */
    public function showResetPassword(Request $request, $token)
    {
        $email = $request->query('email');

        // Verify token exists and is not expired
        if ($email) {
            $resetRecord = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->first();

            if (!$resetRecord) {
                return Inertia::render('Auth/TokenExpired', [
                    'message' => 'Invalid or expired reset link.'
                ]);
            }

            // Check if token is expired (10 minutes)
            $tokenCreatedAt = Carbon::parse($resetRecord->created_at);
            if ($tokenCreatedAt->addMinutes(10)->isPast()) {
                // Delete expired token
                DB::table('password_reset_tokens')
                    ->where('email', $email)
                    ->delete();

                return Inertia::render('Auth/TokenExpired', [
                    'message' => 'This reset link has expired. Please request a new one.'
                ]);
            }
        }

        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $email,
        ]);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        // Get the token from database
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            return back()->with('error', 'Invalid or expired reset link.');
        }

        // Check if token is expired (10 minutes)
        $tokenCreatedAt = Carbon::parse($resetRecord->created_at);
        if ($tokenCreatedAt->addMinutes(10)->isPast()) {
            // Delete expired token
            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            return back()->with('error', 'This reset link has expired. Please request a new one.');
        }

        // Verify token
        if (!Hash::check($request->token, $resetRecord->token)) {
            return back()->with('error', 'Invalid reset link.');
        }

        // Update user password
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return back()->with('error', 'User not found.');
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // Delete the used token
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        \Log::info('Password reset successful', [
            'email' => $request->email,
            'ip' => $request->ip(),
        ]);

        // Redirect to login page with success message
        return redirect('/login')->with('success', 'Password reset successful! You can now login with your new password.');
    }
}