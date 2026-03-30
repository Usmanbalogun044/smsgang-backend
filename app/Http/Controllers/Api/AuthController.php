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
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    private function otpCacheKey(string $email): string
    {
        return 'email_verification_otp:'.strtolower(trim($email));
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

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        $this->sendVerificationOtp($user->email);

        Log::channel('activity')->info('User registered', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'Account created. Check your email for the verification code.',
            'requires_verification' => true,
            'email' => $user->email,
        ], 201);
    }

    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ]);

        /** @var User|null $user */
        $user = User::where('email', $validated['email'])->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->email_verified_at) {
            $token = $user->createToken('auth-token')->plainTextToken;
            return response()->json([
                'message' => 'Email already verified.',
                'user' => new UserResource($user),
                'token' => $token,
            ]);
        }

        $otp = Cache::get($this->otpCacheKey($validated['email']));
        if (! $otp || $otp !== $validated['otp']) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        Cache::forget($this->otpCacheKey($validated['email']));

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

        /** @var User|null $user */
        $user = User::where('email', $validated['email'])->first();
        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email already verified.'], 422);
        }

        $this->sendVerificationOtp($user->email);

        return response()->json([
            'message' => 'Verification code sent. Please check your email.',
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            Log::channel('activity')->warning('Failed login attempt', [
                'email' => $request->email,
            ]);

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if (! $user->isActive()) {
            Log::channel('activity')->warning('Suspended user login attempt', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return response()->json([
                'message' => 'Account suspended.',
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

    public function logout(Request $request): JsonResponse
    {
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
}
