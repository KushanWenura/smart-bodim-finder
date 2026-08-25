<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SearchLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiEvaluationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'searchLogId' => 'required|integer|exists:search_logs,id',
            'candidateListingIds' => 'array|max:20',
            'candidateListingIds.*' => 'integer|exists:listings,id',
            'consentConfirmed' => 'accepted',
        ]);
        $log = SearchLog::whereKey($data['searchLogId'])->where('user_id', $request->user()->id)->firstOrFail();
        $anonymized = $this->anonymize((string) $log->sanitized_query);
        $hash = hash('sha256', mb_strtolower($anonymized));
        $id = DB::table('ai_evaluation_samples')->insertGetId([
            'user_id' => $request->user()->id,
            'search_log_id' => $log->id,
            'language' => data_get($log->filters, 'language', 'unknown'),
            'query_hash' => $hash,
            'anonymized_query' => $anonymized,
            'predicted_intent' => json_encode($log->filters, JSON_UNESCAPED_UNICODE),
            'candidate_listing_ids' => json_encode(array_values(array_unique($data['candidateListingIds'] ?? []))),
            'annotation_status' => 'pending',
            'consent_confirmed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['recorded' => true, 'id' => $id, 'message' => 'Thank you. Contact details were removed before this query entered the evaluation queue.'], 201);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('ai_evaluation_samples')->orderBy('annotation_status')->latest()->paginate(50)]);
    }

    public function label(Request $request, int $sample): JsonResponse
    {
        $data = $request->validate([
            'correctedIntent' => 'required|array',
            'rankedRelevantListingIds' => 'required|array|max:20',
            'rankedRelevantListingIds.*' => 'integer|exists:listings,id',
            'notes' => 'nullable|string|max:1000',
        ]);
        $updated = DB::table('ai_evaluation_samples')->where('id', $sample)->update([
            'human_labels' => json_encode(['correctedIntent' => $data['correctedIntent'], 'rankedRelevantListingIds' => $data['rankedRelevantListingIds'], 'notes' => $data['notes'] ?? null], JSON_UNESCAPED_UNICODE),
            'annotation_status' => 'labelled',
            'updated_at' => now(),
        ]);
        abort_if($updated === 0, 404);

        return response()->json(['labelled' => true]);
    }

    private function anonymize(string $query): string
    {
        $query = preg_replace('/\b(?:\+94|0)7\d{8}\b/', '[phone removed]', $query) ?? $query;
        $query = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email removed]', $query) ?? $query;
        $query = preg_replace('/\b(?:NIC|passport|ID)\s*[:#-]?\s*[A-Z0-9-]{6,20}\b/i', '[identifier removed]', $query) ?? $query;

        return mb_substr(trim($query), 0, 500);
    }
}
