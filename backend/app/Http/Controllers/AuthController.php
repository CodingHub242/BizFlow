<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\TenantStatus;
use Illuminate\Validation\Rule;
use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'],'password' => ['required', 'string'],]);

        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $tenant = Tenant::query()->find($user->tenant_id);

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Business account could not be found.',
                'code' => 'TENANT_NOT_FOUND',
            ], 403);
        }

        if ($tenant->status === TenantStatus::PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Your business is awaiting platform approval.',
                'code' => 'TENANT_PENDING',
            ], 403);
        }

        if ($tenant->status === TenantStatus::REJECTED) {
            return response()->json([
                'success' => false,
                'message' => 'Your business registration was rejected.',
                'code' => 'TENANT_REJECTED',
            ], 403);
        }

        if ($tenant->status === TenantStatus::SUSPENDED) {
            return response()->json([
                'success' => false,
                'message' => 'Your business account has been suspended.',
                'code' => 'TENANT_SUSPENDED',
            ], 403);
        }

        $token = $user->createToken('BizFlow Web');

        // $roles = DB::table('model_has_roles')
        //     ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
        //     ->where('model_has_roles.model_id', $user->id)
        //     ->where('model_has_roles.model_type', \App\Models\User::class)
        //     ->pluck('roles.name');

        // foreach($roles as $role){}
        setPermissionsTeamId($user->tenant_id);

        $role = $user->getRoleNames()->first();

        $permissions = $user->getAllPermissions()
            ->pluck('name')
            ->unique()
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                //get the role of the user from model has roles and sanctum
                'role' => $role,
                'tenant_id' => $user->tenant_id,
                'permissions' => $permissions,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout successful.',
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->name = trim($validated['name']);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Your profile was updated successfully.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }


    public function requestPasswordResetOtp(Request $request): JsonResponse
    {
        $user = $request->user();

        $otp = (string) random_int(100000, 999999);

        // Invalidate any previous OTP/reset authorization for this user.
        PasswordResetOtp::where('user_id', $user->id)->delete();

        $record = PasswordResetOtp::create([
            'user_id' => $user->id,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
        ]);

        try {
            Mail::to($user->email)->send(
                new PasswordResetOtpMail($otp, $user->name)
            );
        }  catch (\Throwable $exception) {
                $record->delete();

                Log::error('Password reset OTP email delivery failed.', [
                    'user_id' => $user->id,
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                    'exception_file' => $exception->getFile(),
                    'exception_line' => $exception->getLine(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'We could not send the verification email. Please try again later.',
                ], 503);
            }

        return response()->json([
            'success' => true,
            'message' => 'A verification code has been sent to your registered email address.',
        ]);
    }

    public function verifyPasswordResetOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        $record = PasswordResetOtp::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (
            !$record ||
            $record->expires_at->isPast() ||
            $record->attempts >= 5
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The verification code is invalid or has expired. Request a new code.',
            ], 422);
        }

        if (!Hash::check($validated['otp'], $record->otp_hash)) {
            $record->increment('attempts');

            return response()->json([
                'success' => false,
                'message' => 'The verification code is invalid or has expired.',
            ], 422);
        }

        $resetToken = Str::random(64);

        $record->update([
            'verified_at' => now(),
            'reset_token_hash' => hash('sha256', $resetToken),
            'reset_token_expires_at' => now()->addMinutes(10),
            // Prevent the OTP from being reused.
            //'consumed_at' => now(),
           // 'otp_hash' => '',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Verification successful. You may now set a new password.',
            'reset_token' => $resetToken,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reset_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $request->user();

      $record = PasswordResetOtp::where('user_id', $user->id)
    ->where('reset_token_hash', hash('sha256', $validated['reset_token']))
    ->whereNotNull('verified_at')
    ->whereNull('consumed_at')
    ->first();

        // A valid token must have a future expiry, so check it separately below.
        if (!$record) {
            $record = PasswordResetOtp::where('user_id', $user->id)
                ->where('reset_token_hash', hash('sha256', $validated['reset_token']))
                ->whereNotNull('verified_at')
                ->whereNull('consumed_at')
                ->first();
        }

        if (
            !$record ||
            !$record->reset_token_expires_at ||
            $record->reset_token_expires_at->isPast() ||
            $record->consumed_at
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The reset authorization is invalid or has expired. Please verify a new code.',
            ], 422);
        }

        $user->password = $validated['password'];
        $user->save();

        $record->update([
            'consumed_at' => now(),
            'reset_token_hash' => null,
            'reset_token_expires_at' => null,
        ]);

        // Require the user to sign in again on all devices.
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully. Please sign in again.',
        ]);
    }

}