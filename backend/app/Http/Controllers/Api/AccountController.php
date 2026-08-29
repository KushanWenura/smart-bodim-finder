<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->hasSession() ? $request->session()->getId() : '';
        $sessions = DB::table('sessions')->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')->get()->map(function ($session) use ($currentId): array {
                return [
                    'id' => $this->sessionFingerprint((string) $session->id),
                    'current' => $currentId !== '' && hash_equals((string) $session->id, $currentId),
                    'device' => $this->deviceName((string) ($session->user_agent ?? '')),
                    'ipAddress' => $this->maskIp((string) ($session->ip_address ?? '')),
                    'lastActiveAt' => now()->setTimestamp((int) $session->last_activity)->toIso8601String(),
                ];
            })->values();

        return response()->json(['data' => $sessions, 'privacy' => 'Only your own sessions are shown. Network addresses are masked and raw browser details are not returned.']);
    }

    public function revokeSession(Request $request, string $fingerprint): JsonResponse
    {
        $session = DB::table('sessions')->where('user_id', $request->user()->id)->get()
            ->first(fn ($candidate) => hash_equals($this->sessionFingerprint((string) $candidate->id), $fingerprint));
        abort_unless($session, 404);
        $currentId = $request->hasSession() ? $request->session()->getId() : '';
        abort_if($currentId !== '' && hash_equals((string) $session->id, $currentId), 422, 'Use Log out to end the current session.');
        DB::table('sessions')->where('id', $session->id)->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'The selected session has been signed out.']);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $data = $request->validate(['currentPassword' => ['required', 'current_password:web']]);
        Auth::logoutOtherDevices($data['currentPassword']);
        $currentId = $request->hasSession() ? $request->session()->getId() : '';
        $query = DB::table('sessions')->where('user_id', $request->user()->id);
        if ($currentId !== '') {
            $query->where('id', '!=', $currentId);
        }
        $deleted = $query->delete();

        return response()->json(['message' => "Signed out {$deleted} other session(s)."]);
    }

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

    private function sessionFingerprint(string $id): string
    {
        return substr(hash_hmac('sha256', $id, (string) config('app.key')), 0, 24);
    }

    private function deviceName(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Microsoft Edge',
            str_contains($agent, 'Chrome/') => 'Google Chrome',
            str_contains($agent, 'Firefox/') => 'Mozilla Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };
        $platform = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        return Str::limit("{$browser} on {$platform}", 80, '');
    }

    private function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.*.*";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 2)).':*';
        }

        return 'Unavailable';
    }
}
