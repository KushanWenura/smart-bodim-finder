<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\AdminAuditLog;
use App\Models\AiFeedback;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\ListingAiRiskAssessment;
use App\Models\OwnerProfile;
use App\Models\Review;
use App\Models\SearchLog;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\AiServiceClient;
use App\Services\ListingRiskService;
use App\Services\ListingWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard(AiServiceClient $ai): JsonResponse
    {
        $searches = SearchLog::count();
        $details = DB::table('analytics_events')->where('event_type', 'listing_detail_viewed')->count();
        $contacts = DB::table('analytics_events')->where('event_type', 'contact_started')->count();

        return response()->json(['metrics' => ['users' => User::count(), 'tenants' => User::where('role', 'tenant')->count(), 'owners' => User::where('role', 'owner')->count(), 'suspendedUsers' => User::where('status', 'suspended')->count(), 'published' => Listing::where('status', 'published')->count(), 'available' => Listing::publiclyVisible()->count(), 'pending' => Listing::where('status', 'pending_review')->count(), 'flaggedReviews' => Review::where('moderation_status', 'flagged')->count(), 'conversations' => DB::table('conversations')->count(), 'searches' => $searches], 'conversions' => ['searchToDetail' => $searches ? round($details / $searches, 4) : null, 'detailToContact' => $details ? round($contacts / $details, 4) : null, 'searchToContact' => $searches ? round($contacts / $searches, 4) : null], 'topQueries' => SearchLog::select('sanitized_query', DB::raw('COUNT(*) as total'))->whereNotNull('sanitized_query')->where('sanitized_query', '!=', '')->groupBy('sanitized_query')->orderByDesc('total')->limit(10)->get(), 'popularAreas' => Listing::select('public_area', DB::raw('SUM(view_count) as views'))->where('status', 'published')->groupBy('public_area')->orderByDesc('views')->limit(10)->get(), 'recentSearches' => SearchLog::latest()->limit(8)->get(), 'pendingListings' => ListingResource::collection(Listing::where('status', 'pending_review')->with(['owner', 'facilities', 'images'])->latest('submitted_at')->get()), 'ai' => $ai->health()]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:100']);
        $term = $data['q'];

        return response()->json([
            'users' => User::where(fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"))->limit(10)->get(['id', 'name', 'email', 'role', 'status']),
            'listings' => Listing::where(fn ($query) => $query->where('title', 'like', "%{$term}%")->orWhere('public_slug', 'like', "%{$term}%")->orWhere('city', 'like', "%{$term}%"))->limit(10)->get(['id', 'public_slug', 'title', 'city', 'status']),
            'reviews' => Review::with(['tenant:id,name', 'listing:id,title'])->where('body', 'like', "%{$term}%")->limit(10)->get(['id', 'tenant_id', 'listing_id', 'rating', 'body', 'moderation_status']),
            'conversations' => Conversation::with(['listing:id,title', 'tenant:id,name', 'owner:id,name'])->where('subject', 'like', "%{$term}%")->limit(10)->get(['id', 'listing_id', 'tenant_id', 'owner_id', 'subject', 'updated_at']),
        ]);
    }

    public function listings(Request $r)
    {
        $q = Listing::with(['owner.ownerProfile', 'facilities', 'images']);
        if ($r->filled('status')) {
            $q->where('status', $r->string('status'));
        }

        return ListingResource::collection($q->latest()->paginate(min(50, max(1, $r->integer('perPage', 20)))));
    }

    public function riskAssessments(Request $request, ListingRiskService $risk): JsonResponse
    {
        if ($request->boolean('refresh')) {
            Listing::whereIn('status', ['pending_review', 'change_pending'])->with('images')->each(fn (Listing $listing) => $risk->assess($listing));
        }

        return response()->json(['data' => ListingAiRiskAssessment::with('listing:id,title,public_slug,status')->orderByDesc('risk_score')->paginate(30)]);
    }

    public function aiMetrics(): JsonResponse
    {
        $feedback = DB::table('ai_feedback');
        $helpful = (clone $feedback)->where('event_type', 'helpful')->count();
        $notHelpful = (clone $feedback)->where('event_type', 'not_helpful')->count();
        $latencies = SearchLog::where('created_at', '>=', now()->subDays(30))->orderBy('latency_ms')->pluck('latency_ms')->values();
        $p95 = $latencies->isEmpty() ? null : $latencies->get((int) floor(($latencies->count() - 1) * .95));
        $total = SearchLog::where('created_at', '>=', now()->subDays(30))->count();

        return response()->json([
            'feedback' => [
                'total' => (clone $feedback)->count(), 'helpful' => $helpful, 'notHelpful' => $notHelpful,
                'helpfulRate' => ($helpful + $notHelpful) > 0 ? round($helpful / ($helpful + $notHelpful), 4) : null,
                'resultClicks' => (clone $feedback)->where('event_type', 'result_click')->count(),
                'enquiries' => (clone $feedback)->where('event_type', 'enquiry')->count(),
            ],
            'search' => [
                'last30Days' => $total,
                'noResultRate' => $total > 0 ? round(SearchLog::where('created_at', '>=', now()->subDays(30))->where('result_count', 0)->count() / $total, 4) : null,
                'p95LatencyMs' => $p95,
                'languages' => SearchLog::whereNotNull('filters')->get()->groupBy(fn ($log) => data_get($log->filters, 'language', 'unknown'))->map->count(),
                'modelVersions' => SearchLog::select('model_version', DB::raw('COUNT(*) as total'))->whereNotNull('model_version')->groupBy('model_version')->get(),
            ],
            'evaluation' => ['consented' => DB::table('ai_evaluation_samples')->where('consent_confirmed', true)->count(), 'labelled' => DB::table('ai_evaluation_samples')->where('annotation_status', 'labelled')->count()],
            'risk' => ['assessed' => DB::table('listing_ai_risk_assessments')->count(), 'highRisk' => DB::table('listing_ai_risk_assessments')->where('risk_score', '>=', 50)->count()],
        ]);
    }

    public function feedbackTrainingExport(): JsonResponse
    {
        $positive = ['favorite', 'enquiry', 'moved_in'];
        $negative = ['hide', 'not_helpful'];
        $rows = AiFeedback::query()
            ->whereNotNull('listing_id')
            ->whereIn('event_type', array_merge($positive, $negative))
            ->get()
            ->filter(fn ($event) => is_array(data_get($event->metadata, 'breakdown')))
            ->map(fn ($event) => [
                'id' => $event->id,
                'sessionGroup' => hash_hmac('sha256', (string) ($event->search_log_id ?? 'user-'.$event->user_id), (string) config('app.key')),
                'features' => data_get($event->metadata, 'breakdown'),
                'label' => in_array($event->event_type, $positive, true) ? 1 : 0,
                'outcome' => $event->event_type,
            ])->values();

        return response()->json(['data' => $rows, 'privacy' => 'Contains numeric ranking features and pseudonymous groups only; no query, contact, address or message text.', 'minimumTrainingRows' => 100]);
    }

    public function moderate(Request $r, Listing $listing, string $action, ListingWorkflow $workflow): JsonResponse
    {
        $map = ['approve' => 'published', 'reject' => 'rejected', 'suspend' => 'suspended', 'restore' => 'published'];
        abort_unless(isset($map[$action]), 404);
        $data = $r->validate(['reason' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'min:8', 'max:1000']]);
        $updated = $workflow->transition($listing, $map[$action], $r->user(), $data['reason'] ?? 'Approved after administrator verification.');
        $listing->owner->notify(new PlatformNotification('listing', "Listing {$action}d", $data['reason'] ?? "{$listing->title} is now {$updated->status}.", '/owner/listings'));

        return response()->json(['data' => new ListingResource($updated)]);
    }

    public function owners()
    {
        return response()->json(OwnerProfile::with('user')->where('verification_status', 'pending')->paginate(20));
    }

    public function verifyOwner(Request $r, OwnerProfile $ownerProfile): JsonResponse
    {
        $data = $r->validate(['decision' => 'required|in:verified,rejected,suspended', 'reason' => 'required|string|min:8|max:1000']);
        $before = $ownerProfile->verification_status;
        $ownerProfile->update(['verification_status' => $data['decision'], 'admin_notes' => $data['reason'], 'verified_by' => $r->user()->id, 'verified_at' => $data['decision'] === 'verified' ? now() : null]);
        AdminAuditLog::create(['actor_id' => $r->user()->id, 'action' => 'owner.'.$data['decision'], 'target_type' => 'user', 'target_id' => $ownerProfile->user_id, 'reason' => $data['reason'], 'before_state' => ['status' => $before], 'after_state' => ['status' => $data['decision']]]);
        $ownerProfile->user->notify(new PlatformNotification('owner', "Owner account {$data['decision']}", $data['reason'], '/owner/profile'));

        return response()->json(['data' => $ownerProfile->fresh('user')]);
    }

    public function users(Request $r)
    {
        $q = User::query();
        $q->when($r->string('q')->toString(), fn ($x, $v) => $x->where(fn ($z) => $z->where('name', 'like', "%$v%")->orWhere('email', 'like', "%$v%")));

        return response()->json($q->paginate(30));
    }

    public function userStatus(Request $r, User $user): JsonResponse
    {
        abort_if($user->id === $r->user()->id, 422, 'You cannot suspend your own active account.');
        $data = $r->validate(['status' => 'required|in:active,suspended,archived', 'reason' => 'required|string|min:8|max:1000']);
        $before = $user->status;
        $user->update(['status' => $data['status']]);
        AdminAuditLog::create(['actor_id' => $r->user()->id, 'action' => 'user.status', 'target_type' => 'user', 'target_id' => $user->id, 'reason' => $data['reason'], 'before_state' => ['status' => $before], 'after_state' => ['status' => $data['status']]]);

        return response()->json(['data' => $user]);
    }

    public function reviews()
    {
        return response()->json(Review::with(['tenant:id,name,email', 'listing:id,title'])->latest()->paginate(30));
    }

    public function moderateReview(Request $r, Review $review, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['hide', 'restore'], true), 404);
        $data = $r->validate(['reason' => 'required|string|min:5|max:1000']);
        $before = $review->moderation_status;
        $review->update(['moderation_status' => $action === 'hide' ? 'hidden' : 'visible']);
        AdminAuditLog::create(['actor_id' => $r->user()->id, 'action' => 'review.'.$action, 'target_type' => 'review', 'target_id' => $review->id, 'reason' => $data['reason'], 'before_state' => ['status' => $before], 'after_state' => ['status' => $review->moderation_status]]);

        return response()->json(['data' => $review]);
    }

    public function notify(Request $r): JsonResponse
    {
        $data = $r->validate(['target' => 'required|in:all,tenant,owner,selected', 'userIds' => 'array|max:500', 'userIds.*' => 'integer|exists:users,id', 'title' => 'required|string|max:160', 'message' => 'required|string|max:600', 'link' => 'nullable|regex:/^\/[A-Za-z0-9_\-\/]*$/']);
        $q = User::where('status', 'active');
        if (in_array($data['target'], ['tenant', 'owner'], true)) {
            $q->where('role', $data['target']);
        }if ($data['target'] === 'selected') {
            $q->whereIn('id', $data['userIds'] ?? []);
        }$users = $q->get();
        foreach ($users as $user) {
            $user->notify(new PlatformNotification('announcement', $data['title'], $data['message'], $data['link'] ?? null));
        }

        return response()->json(['message' => 'Notification queued.', 'recipients' => $users->count()], 201);
    }

    public function audit()
    {
        return response()->json(AdminAuditLog::latest()->paginate(50));
    }
}
