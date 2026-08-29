<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AreaSafetyReport;
use App\Models\Listing;
use App\Services\Analytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AreaSafetyReportController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => AreaSafetyReport::query()
            ->with('listing:id,title,public_slug')
            ->where('tenant_id', $request->user()->id)
            ->latest()->get()]);
    }

    public function store(Request $request, Listing $listing): JsonResponse
    {
        abort_unless($listing->status === 'published' && $listing->available, 404);
        $data = $request->validate([
            'visitBasis' => 'required|in:resident,viewing,regular_commute',
            'visitPeriod' => 'required|in:day,evening,both',
            'visitedOn' => 'nullable|date|before_or_equal:today',
            'lightingRating' => 'required|integer|between:1,5',
            'transportRating' => 'required|integer|between:1,5',
            'publicActivityRating' => 'required|integer|between:1,5',
            'roadSafetyRating' => 'required|integer|between:1,5',
            'emergencyAccessRating' => 'required|integer|between:1,5',
            'comment' => 'required|string|min:20|max:2000',
            'consentForResearch' => 'required|accepted',
        ]);

        $report = AreaSafetyReport::updateOrCreate(
            ['listing_id' => $listing->id, 'tenant_id' => $request->user()->id],
            [
                'visit_basis' => $data['visitBasis'],
                'visit_period' => $data['visitPeriod'],
                'visited_on' => $data['visitedOn'] ?? null,
                'lighting_rating' => $data['lightingRating'],
                'transport_rating' => $data['transportRating'],
                'public_activity_rating' => $data['publicActivityRating'],
                'road_safety_rating' => $data['roadSafetyRating'],
                'emergency_access_rating' => $data['emergencyAccessRating'],
                'comment' => $data['comment'],
                'consent_for_research' => true,
                'moderation_status' => 'pending',
                'moderated_by' => null,
                'moderated_at' => null,
            ]
        );
        Analytics::record('area_safety_report_submitted', $listing->id, ['period' => $report->visit_period]);

        return response()->json([
            'data' => $report,
            'message' => 'Your observation is awaiting moderation and is not included in the area score yet.',
        ], 201);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => AreaSafetyReport::query()
            ->with(['tenant:id,name', 'listing:id,title,public_area'])
            ->latest()->paginate(50)]);
    }

    public function moderate(Request $request, AreaSafetyReport $report, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:8|max:1000']);
        $before = $report->moderation_status;
        $status = $action === 'approve' ? 'visible' : 'rejected';
        $report->update([
            'moderation_status' => $status,
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ]);
        AdminAuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'area_safety_report.'.$action,
            'target_type' => AreaSafetyReport::class,
            'target_id' => $report->id,
            'reason' => $data['reason'],
            'before_state' => ['status' => $before],
            'after_state' => ['status' => $status],
        ]);

        return response()->json(['data' => $report->fresh()]);
    }
}
