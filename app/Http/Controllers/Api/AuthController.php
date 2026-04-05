<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Mail\EmailVerificationOtpMail;
use App\Models\User;
use App\Models\UserLoginActivity;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const OTP_TTL_MINUTES = 10;
    private const PASSWORD_RESET_OTP_TTL_MINUTES = 15;
    private const PENDING_SIGNUP_TTL_MINUTES = 30;

    private function recordLoginActivity(?User $user, Request $request, string $eventType, array $context = []): void
    {
        UserLoginActivity::create([
            'user_id' => $user?->id,
            'email' => $user?->email ?? (string) $request->input('email', ''),
            'event_type' => $eventType,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'context' => $context ?: null,
        ]);
    }

    private function otpCacheKey(string $email): string
    {
        return 'email_verification_otp:'.strtolower(trim($email));
    }

    private function pendingSignupDataCacheKey(string $email): string
    {
        return 'pending_signup_data:'.strtolower(trim($email));
    }

    private function passwordResetOtpCacheKey(string $email): string
    {
        return 'password_reset_otp:'.strtolower(trim($email));
    }

    private function sendVerificationOtp(string $email): void
    {
        $otp = (string) random_int(100000, 999999);
        Cache::put($this->otpCacheKey($email), $otp, now()->addMinutes(self::OTP_TTL_MINUTES));

        try {
            Mail::to($email)->queue(new EmailVerificationOtpMail($otp, self::OTP_TTL_MINUTES));
        } catch (Throwable $exception) {
            Log::error('Failed to send verification OTP email', [
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            if (! App::environment('production')) {
                throw $exception;
            }
        }
    }

    private function sendPasswordResetOtp(string $email): void
    {
        $otp = (string) random_int(100000, 999999);
        Cache::put($this->passwordResetOtpCacheKey($email), $otp, now()->addMinutes(self::PASSWORD_RESET_OTP_TTL_MINUTES));

        try {
            Mail::send('emails.password-reset-otp', [
                'otp' => $otp,
                'ttlMinutes' => self::PASSWORD_RESET_OTP_TTL_MINUTES,
            ], function ($message) use ($email): void {
                $message->to($email)->subject('SMSGang Password Reset Code');
            });
        } catch (Throwable $exception) {
            Log::error('Failed to send password reset OTP email', [
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);

            if (! App::environment('production')) {
                throw $exception;
            }
        }
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $normalizedEmail = strtolower(trim((string) $validated['email']));

        /** @var User|null $existing */
        $existing = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        if ($existing) {
            return response()->json([
                'message' => 'An account with this email already exists. Please sign in.',
            ], 422);
        }

        Cache::put($this->pendingSignupDataCacheKey($normalizedEmail), [
            'name' => (string) $validated['name'],
            'email' => $normalizedEmail,
            'password' => (string) $validated['password'],
        ], now()->addMinutes(self::PENDING_SIGNUP_TTL_MINUTES));

        $this->sendVerificationOtp($normalizedEmail);

        return response()->json([
            'message' => 'Verification code sent. Complete verification to create your account.',
            'requires_verification' => true,
            'email' => $normalizedEmail,
        ], 201);
    }

    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        $normalizedEmail = strtolower(trim($validated['email']));
        $otp = Cache::get($this->otpCacheKey($normalizedEmail));
        if (! $otp || $otp !== $validated['otp']) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        /** @var User|null $user */
        $user = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        if ($user) {
            if ($user->email_verified_at) {
                $token = $user->createToken('auth-token')->plainTextToken;
                Cache::forget($this->otpCacheKey($normalizedEmail));

                return response()->json([
                    'message' => 'Email already verified.',
                    'user' => new UserResource($user),
                    'token' => $token,
                ]);
            }

            $user->forceFill(['email_verified_at' => now()])->save();
        } else {
            $pendingSignup = Cache::get($this->pendingSignupDataCacheKey($normalizedEmail));
            if (! is_array($pendingSignup)) {
                return response()->json(['message' => 'Signup session expired. Please register again.'], 422);
            }

            $user = User::create([
                'name' => (string) ($pendingSignup['name'] ?? ''),
                'email' => $normalizedEmail,
                'password' => (string) ($pendingSignup['password'] ?? ''),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            Log::channel('activity')->info('User registered after OTP verification', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        }

        Cache::forget($this->otpCacheKey($normalizedEmail));
        Cache::forget($this->pendingSignupDataCacheKey($normalizedEmail));

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Email verified successfully.',
            'user' => new UserResource($user->fresh()),
            'token' => $token,
        ]);
    }

    public function resendEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $normalizedEmail = strtolower(trim($validated['email']));

        /** @var User|null $user */
        $user = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        if ($user) {
            if ($user->email_verified_at) {
                return response()->json(['message' => 'Email already verified.'], 422);
            }

            $this->sendVerificationOtp($user->email);

            return response()->json([
                'message' => 'Verification code sent. Please check your email.',
            ]);
        }

        $pendingSignup = Cache::get($this->pendingSignupDataCacheKey($normalizedEmail));
        if (! is_array($pendingSignup)) {
            return response()->json(['message' => 'No pending signup found for this email.'], 404);
        }

        $this->sendVerificationOtp($normalizedEmail);

        return response()->json([
            'message' => 'Verification code sent. Please check your email.',
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            $this->recordLoginActivity(null, $request, 'failed_login', [
                'email' => $request->email,
            ]);

            Log::channel('activity')->warning('Failed login attempt', [
                'email' => $request->email,
            ]);

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if (! $user->isActive()) {
            $this->recordLoginActivity($user, $request, 'blocked_suspended_login');

            Log::channel('activity')->warning('Suspended user login attempt', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return response()->json([
                'message' => 'Your account is suspended. Please contact support team.',
            ], 403);
        }

        if (! $user->email_verified_at) {
            $this->sendVerificationOtp($user->email);

            return response()->json([
                'message' => 'Email not verified. We sent a new verification code to your email.',
                'code' => 'EMAIL_NOT_VERIFIED',
                'email' => $user->email,
            ], 403);
        }

        $user->forceFill([
            'is_online' => true,
            'last_login_ip' => $request->ip(),
            'last_user_agent' => (string) $request->userAgent(),
            'last_login_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        $this->recordLoginActivity($user, $request, 'login_success');

        $token = $user->createToken('auth-token')->plainTextToken;

        Log::channel('activity')->info('User logged in', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role->value,
        ]);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function google(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'credential' => ['required', 'string'],
        ]);

        $clientId = (string) config('services.google.client_id', '');
        if ($clientId === '') {
            return response()->json(['message' => 'Google sign-in is not configured.'], 500);
        }

        $response = Http::timeout(15)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $validated['credential'],
        ]);

        if (! $response->successful()) {
            Log::channel('activity')->warning('Google token verification failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return response()->json(['message' => 'Invalid Google credential.'], 401);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid Google credential.'], 401);
        }

        $verifiedEmail = strtolower(trim((string) ($payload['email'] ?? '')));
        $googleId = (string) ($payload['sub'] ?? '');
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
        $audience = (string) ($payload['aud'] ?? '');

        if ($verifiedEmail === '' || $googleId === '' || ! $emailVerified || $audience !== $clientId) {
            return response()->json(['message' => 'Google account could not be verified.'], 401);
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('google_id', $googleId)
            ->orWhereRaw('LOWER(email) = ?', [$verifiedEmail])
            ->first();

        if ($user && ! $user->isActive()) {
            return response()->json([
                'message' => 'Your account is suspended. Please contact support team.',
            ], 403);
        }

        $isNewUser = false;

        if (! $user) {
            $user = User::create([
                'name' => (string) ($payload['name'] ?? $payload['given_name'] ?? explode('@', $verifiedEmail)[0]),
                'email' => $verifiedEmail,
                'password' => Str::random(40),
                'google_id' => $googleId,
                'google_avatar_url' => $payload['picture'] ?? null,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $isNewUser = true;
        } else {
            $updateData = [];

            if ($user->google_id !== $googleId) {
                $updateData['google_id'] = $googleId;
            }

            if (! $user->email_verified_at) {
                $updateData['email_verified_at'] = now();
            }

            if (! empty($payload['picture'])) {
                $updateData['google_avatar_url'] = $payload['picture'];
            }

            if ($user->name === '' && ! empty($payload['name'])) {
                $updateData['name'] = $payload['name'];
            }

            if ($updateData !== []) {
                $user->forceFill($updateData)->save();
            }
        }

        $user->forceFill([
            'is_online' => true,
            'last_login_ip' => $request->ip(),
            'last_user_agent' => (string) $request->userAgent(),
            'last_login_at' => now(),
            'last_seen_at' => now(),
        ])->save();

        $this->recordLoginActivity($user, $request, $isNewUser ? 'google_signup_success' : 'google_login_success', [
            'google_id' => $googleId,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        Log::channel('activity')->info('Google sign-in completed', [
            'user_id' => $user->id,
            'email' => $user->email,
            'google_id' => $googleId,
            'linked_existing_account' => ! $isNewUser,
        ]);

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
            'linked_existing_account' => ! $isNewUser,
            'message' => $isNewUser ? 'Account created with Google.' : 'Signed in with Google.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->forceFill([
                'is_online' => false,
                'last_seen_at' => now(),
                'last_logout_at' => now(),
            ])->save();

            $this->recordLoginActivity($user, $request, 'logout');
        }

        $request->user()?->tokens()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        Log::channel('activity')->info('User profile updated', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function updatePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => $request->input('new_password'),
        ]);

        // Revoke all other active tokens after password update.
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        Log::channel('activity')->info('User password updated', [
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        /** @var User|null $user */
        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($validated['email']))])->first();

        if ($user) {
            $this->sendPasswordResetOtp($user->email);
        }

        return response()->json([
            'message' => 'If the email exists, a password reset code has been sent.',
            'email' => $validated['email'],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $normalizedEmail = strtolower(trim($validated['email']));

        /** @var User|null $user */
        $user = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
        if (! $user) {
            return response()->json(['message' => 'Invalid or expired reset code.'], 422);
        }

        $otp = Cache::get($this->passwordResetOtpCacheKey($normalizedEmail));
        if (! $otp || $otp !== $validated['otp']) {
            return response()->json(['message' => 'Invalid or expired reset code.'], 422);
        }

        $user->update([
            'password' => $validated['new_password'],
        ]);

        Cache::forget($this->passwordResetOtpCacheKey($normalizedEmail));
        $user->tokens()->delete();

        Log::channel('activity')->info('User password reset via OTP', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'Password reset successfully. Please sign in again.',
        ]);
    }
}
