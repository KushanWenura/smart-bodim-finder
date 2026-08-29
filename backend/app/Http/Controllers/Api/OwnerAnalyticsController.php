<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Services\PriceIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnerAnalyticsController extends Controller
{
    public function __invoke(Request $request, PriceIntelligenceService $prices): JsonResponse
    {
        $listings = Listing::where('owner_id', $request->user()->id)->with(['facilities', 'images'])->get();
        $ids = $listings->pluck('id');
        $events = DB::table('analytics_events')->whereIn('listing_id', $ids)->select('listing_id', 'event_type', DB::raw('COUNT(*) as total'))->groupBy('listing_id', 'event_type')->get()->groupBy('listing_id');
        $conversations = DB::table('conversations')->whereIn('listing_id', $ids)->select('listing_id', DB::raw('COUNT(*) as total'))->groupBy('listing_id')->pluck('total', 'listing_id');
        $viewings = DB::table('viewing_requests')->whereIn('listing_id', $ids)->select('listing_id', DB::raw('COUNT(*) as total'))->groupBy('listing_id')->pluck('total', 'listing_id');
        $rentals = DB::table('reservations')->whereIn('listing_id', $ids)->whereIn('status', ['confirmed', 'completed'])->select('listing_id', DB::raw('COUNT(*) as total'))->groupBy('listing_id')->pluck('total', 'listing_id');

        $rows = $listings->map(function (Listing $listing) use ($events, $conversations, $viewings, $rentals, $prices): array {
            $eventCounts = collect($events->get($listing->id, []))->pluck('total', 'event_type');
            $views = max((int) $listing->view_count, (int) $eventCounts->get('listing_detail_viewed', 0));
            $contacts = (int) $conversations->get($listing->id, 0);
            $viewingCount = (int) $viewings->get($listing->id, 0);
            $rentalCount = (int) $rentals->get($listing->id, 0);
            $price = $prices->assess($listing);
            $recommendations = [];
            if ($views >= 10 && $contacts / max(1, $views) < .05) {
                $recommendations[] = 'Many people view this listing but few enquire. Clarify included bills, rules and the strongest room benefit.';
            }
            if ($listing->images->count() < 4) {
                $recommendations[] = 'Add at least four clear photos covering the room, bathroom, entrance and shared facilities.';
            }
            if (($price['available'] ?? false) && ($price['priceVsMedianPercent'] ?? 0) > 15) {
                $recommendations[] = 'Rent is above the local peer median. Explain the facilities or included costs that justify the difference.';
            }
            if ($recommendations === []) {
                $recommendations[] = 'Listing evidence and engagement look healthy. Keep availability and photos current.';
            }

            return ['listingId' => $listing->id, 'title' => $listing->title, 'status' => $listing->status, 'views' => $views, 'favorites' => (int) $listing->favorite_count, 'enquiries' => $contacts, 'viewings' => $viewingCount, 'confirmedRentals' => $rentalCount, 'viewToEnquiryRate' => $views ? round($contacts / $views, 4) : null, 'enquiryToViewingRate' => $contacts ? round($viewingCount / $contacts, 4) : null, 'priceIntelligence' => $price, 'recommendations' => $recommendations];
        })->sortByDesc('views')->values();

        $trend = DB::table('analytics_events')->join('listings', 'listings.id', '=', 'analytics_events.listing_id')->where('listings.owner_id', $request->user()->id)->where('analytics_events.occurred_at', '>=', now()->subDays(30))->selectRaw('DATE(analytics_events.occurred_at) as day, analytics_events.event_type, COUNT(*) as total')->groupBy('day', 'analytics_events.event_type')->orderBy('day')->get();

        return response()->json(['summary' => ['listings' => $listings->count(), 'views' => $rows->sum('views'), 'favorites' => $rows->sum('favorites'), 'enquiries' => $rows->sum('enquiries'), 'viewings' => $rows->sum('viewings'), 'confirmedRentals' => $rows->sum('confirmedRentals')], 'listings' => $rows, 'trend' => $trend, 'privacy' => 'Aggregates contain event counts only; tenant messages and contact details are excluded.']);
    }
}
