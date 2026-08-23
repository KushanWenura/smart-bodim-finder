<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Services\Analytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavoriteController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $ids = DB::table('favorites')->where('user_id', $r->user()->id)->pluck('listing_id');

        return response()->json(['data' => ListingResource::collection(Listing::whereIn('id', $ids)->with(['owner', 'facilities', 'images'])->get())]);
    }

    public function store(Request $r, Listing $listing): JsonResponse
    {
        DB::transaction(function () use ($r, $listing) {
            $created = DB::table('favorites')->insertOrIgnore(['user_id' => $r->user()->id, 'listing_id' => $listing->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($created) {
                $listing->increment('favorite_count');
            }
        });
        Analytics::record('favorite_added', $listing->id);

        return response()->json(['favorite' => true]);
    }

    public function destroy(Request $r, Listing $listing): JsonResponse
    {
        DB::transaction(function () use ($r, $listing) {
            $deleted = DB::table('favorites')->where(['user_id' => $r->user()->id, 'listing_id' => $listing->id])->delete();
            if ($deleted && $listing->favorite_count > 0) {
                $listing->decrement('favorite_count');
            }
        });
        Analytics::record('favorite_removed', $listing->id);

        return response()->json(['favorite' => false]);
    }
}
