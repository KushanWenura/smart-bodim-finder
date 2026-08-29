<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OwnerProfile;
use App\Models\TenantProfile;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['role' => 'required|in:tenant,owner', 'name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:190|unique:users,email', 'phone' => ['required', 'regex:/^(?:\+94|0)7\d{8}$/'], 'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()]]);
        $user = DB::transaction(function () use ($data) {
            $user = User::create([...$data, 'email' => mb_strtolower($data['email']), 'status' => 'active', 'password' => Hash::make($data['password'])]);
            $data['role'] === 'tenant' ? TenantProfile::create(['user_id' => $user->id]) : OwnerProfile::create(['user_id' => $user->id, 'verification_status' => 'pending']);

            return $user;
        });

        return response()->json(['data' => $user, 'message' => $data['role'] === 'owner' ? 'Account created; owner verification is pending.' : 'Account created successfully.'], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt(['email' => mb_strtolower($credentials['email']), 'password' => $credentials['password']])) {
            return response()->json(['message' => 'The email or password is incorrect.'], 422);
        }
        $request->session()->regenerate();
        $user = $request->user();
        if ($user->status !== 'active') {
            Auth::logout();

            return response()->json(['message' => 'This account is not active.'], 403);
        }

        return response()->json(['data' => $user->load(['tenantProfile', 'ownerProfile'])]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()?->load(['tenantProfile', 'ownerProfile'])]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If that account exists, a reset link has been sent.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()]]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            if (Schema::hasTable('personal_access_tokens')) {
                $user->tokens()->delete();
            }
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET ? response()->json(['message' => 'Password reset successfully.']) : response()->json(['message' => __($status)], 422);
    }
}
