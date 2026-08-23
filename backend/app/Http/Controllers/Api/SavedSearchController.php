<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedSearchController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        return response()->json(['data' => SavedSearch::where('user_id', $r->user()->id)->latest()->get()]);
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate(['name' => 'required|string|max:120', 'query' => 'nullable|string|max:500', 'filters' => 'required|array', 'notificationsEnabled' => 'boolean']);
        $item = SavedSearch::create(['user_id' => $r->user()->id, 'name' => $data['name'], 'natural_query' => $data['query'] ?? null, 'filters' => $data['filters'], 'notifications_enabled' => $data['notificationsEnabled'] ?? true]);

        return response()->json(['data' => $item], 201);
    }

    public function destroy(Request $r, SavedSearch $savedSearch): JsonResponse
    {
        abort_unless($savedSearch->user_id === $r->user()->id, 403);
        $savedSearch->delete();

        return response()->json(status: 204);
    }
}
