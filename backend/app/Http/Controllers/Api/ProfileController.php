<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $r): JsonResponse
    {
        $data = $r->validate(['name' => 'required|string|max:120', 'phone' => ['required', 'regex:/^(?:\+94|0)7\d{8}$/'], 'category' => 'nullable|in:student,worker,other', 'institutionOrWorkplace' => 'nullable|string|max:180', 'preferredCity' => 'nullable|string|max:100', 'minBudget' => 'nullable|integer|min:0', 'maxBudget' => 'nullable|integer|min:0', 'preferenceText' => 'nullable|string|max:1000', 'requiredFacilities' => 'array|max:30', 'aiLearningEnabled' => 'nullable|boolean', 'resetLearnedPreferences' => 'nullable|boolean', 'businessName' => 'nullable|string|max:160', 'address' => 'nullable|string|max:300', 'verificationReference' => 'nullable|string|max:120']);
        $user = $r->user();
        $user->update(['name' => $data['name'], 'phone' => $data['phone']]);
        if ($user->role === 'tenant') {
            $profileData = ['category' => $data['category'] ?? null, 'institution_or_workplace' => $data['institutionOrWorkplace'] ?? null, 'min_budget_lkr' => $data['minBudget'] ?? null, 'max_budget_lkr' => $data['maxBudget'] ?? null, 'preference_text' => $data['preferenceText'] ?? null, 'required_facilities' => $data['requiredFacilities'] ?? []];
            if (array_key_exists('aiLearningEnabled', $data)) {
                $profileData['ai_learning_enabled'] = $data['aiLearningEnabled'];
            }
            if ($data['resetLearnedPreferences'] ?? false) {
                $profileData['learned_preferences'] = null;
            }
            $user->tenantProfile()->updateOrCreate(['user_id' => $user->id], $profileData);
        }if ($user->role === 'owner') {
            $user->ownerProfile()->updateOrCreate(['user_id' => $user->id], ['business_name' => $data['businessName'] ?? null, 'address' => $data['address'] ?? null, 'verification_reference' => $data['verificationReference'] ?? null]);
        }

return response()->json(['data' => $user->fresh()->load(['tenantProfile', 'ownerProfile'])]);
    }
}
