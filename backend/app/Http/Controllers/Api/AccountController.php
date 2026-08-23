<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        $request->user()->tokens()->delete();
        Auth::logoutOtherDevices($data['currentPassword']);

        return response()->json(['message' => 'Password changed. Other sessions have been signed out.']);
    }

    public function archive(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'current_password:web']]);
        abort_if($request->user()->role === 'admin', 422, 'Administrator accounts must be archived by another administrator.');
        $request->user()->update(['status' => 'archived']);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Account archived.']);
    }
}
