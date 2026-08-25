<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiFeedback;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Analytics;
use App\Services\PersonalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    private function authorizeParticipant(Request $r, Conversation $c): void
    {
        abort_unless(in_array($r->user()->id, [$c->tenant_id, $c->owner_id], true), 403);
    }

    public function index(Request $r): JsonResponse
    {
        $archiveColumn = $r->user()->role === 'tenant' ? 'tenant_archived_at' : 'owner_archived_at';
        $items = Conversation::with(['listing.images', 'messages'])->where(fn ($q) => $q->where('tenant_id', $r->user()->id)->orWhere('owner_id', $r->user()->id))->whereNull($archiveColumn)->latest('updated_at')->paginate(20);

        return response()->json($items);
    }

    public function store(Request $r, PersonalizationService $personalization): JsonResponse
    {
        $data = $r->validate(['listingId' => 'required|exists:listings,id', 'subject' => 'nullable|string|max:160', 'text' => 'required|string|min:2|max:2000']);
        $listing = Listing::where('status', 'published')->findOrFail($data['listingId']);
        $c = Conversation::firstOrCreate(['listing_id' => $listing->id, 'tenant_id' => $r->user()->id, 'owner_id' => $listing->owner_id], ['subject' => $data['subject'] ?? "Enquiry about {$listing->title}"]);
        $m = $c->messages()->create(['sender_id' => $r->user()->id, 'body' => $data['text']]);
        $c->touch();
        $listing->owner->notify(new PlatformNotification('message', 'New tenant enquiry', "{$r->user()->name} asked about {$listing->title}.", '/owner/messages'));
        Analytics::record('contact_started', $listing->id);
        AiFeedback::create(['user_id' => $r->user()->id, 'listing_id' => $listing->id, 'event_type' => 'enquiry', 'occurred_at' => now()]);
        $personalization->learn($r->user(), 'enquiry', $listing->load('facilities'));

        return response()->json(['data' => $c->load(['listing', 'messages'])], 201);
    }

    public function messages(Request $r, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($r, $conversation);

        return response()->json(['data' => $conversation->messages()->latest()->paginate(50)]);
    }

    public function reply(Request $r, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($r, $conversation);
        $data = $r->validate(['text' => 'required|string|min:2|max:2000']);
        $m = $conversation->messages()->create(['sender_id' => $r->user()->id, 'body' => $data['text']]);
        $conversation->touch();
        $recipientId = $r->user()->id === $conversation->tenant_id ? $conversation->owner_id : $conversation->tenant_id;
        User::find($recipientId)?->notify(new PlatformNotification('message', 'New reply', 'You received a new reply.', "/messages/{$conversation->id}"));

        return response()->json(['data' => $m], 201);
    }

    public function read(Request $r, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($r, $conversation);
        $last = $conversation->messages()->latest()->value('id');
        \DB::table('conversation_reads')->updateOrInsert(['conversation_id' => $conversation->id, 'user_id' => $r->user()->id], ['last_read_message_id' => $last, 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['message' => 'Marked read.']);
    }

    public function archive(Request $r, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($r, $conversation);
        $column = $r->user()->id === $conversation->tenant_id ? 'tenant_archived_at' : 'owner_archived_at';
        $conversation->update([$column => now()]);

        return response()->json(['message' => 'Conversation archived.']);
    }
}
