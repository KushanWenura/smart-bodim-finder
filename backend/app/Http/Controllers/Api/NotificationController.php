<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        return response()->json($r->user()->notifications()->paginate(20));
    }

    public function read(Request $r, string $id): JsonResponse
    {
        $n = $r->user()->notifications()->findOrFail($id);
        $n->markAsRead();

        return response()->json(['message' => 'Marked read.']);
    }

    public function readAll(Request $r): JsonResponse
    {
        $r->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked read.']);
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        $r->user()->notifications()->findOrFail($id)->delete();

        return response()->json(status: 204);
    }
}
