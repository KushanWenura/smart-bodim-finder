<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Reservation;
use App\Models\ViewingRequest;
use App\Notifications\PlatformNotification;
use App\Services\PersonalizationService;
use App\Services\RentalAgreementService;
use App\Services\ReservationAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalJourneyController extends Controller
{
    public function index(Request $request, ReservationAvailabilityService $availability): JsonResponse
    {
        $availability->expireStaleHolds();
        abort_unless(in_array($request->user()->role, ['tenant', 'owner'], true), 403);
        $column = $request->user()->role === 'tenant' ? 'tenant_id' : 'owner_id';
        $viewings = ViewingRequest::with(['listing.images', 'tenant:id,name', 'owner:id,name'])->where($column, $request->user()->id)->latest()->get();
        $reservations = Reservation::with(['listing.images', 'tenant:id,name', 'owner:id,name', 'viewingRequest', 'agreement', 'disputes'])->where($column, $request->user()->id)->latest()->get();

        return response()->json(['viewings' => $viewings, 'reservations' => $reservations]);
    }

    public function conversation(Request $request, Conversation $conversation, ReservationAvailabilityService $availability): JsonResponse
    {
        $this->participant($request, $conversation);
        $availability->expireStaleHolds($conversation->listing_id);

        return response()->json([
            'viewings' => $conversation->viewingRequests()->latest()->get(),
            'reservations' => $conversation->reservations()->latest()->get(),
        ]);
    }

    public function requestViewing(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($request->user()->id === $conversation->tenant_id, 403);
        $data = $request->validate(['proposedAt' => 'required|date|after:now', 'note' => 'nullable|string|max:500']);
        $settings = $conversation->listing->rentalSettings;
        $proposed = Carbon::parse($data['proposedAt']);
        $noticeHours = (int) ($settings?->viewing_notice_hours ?? 12);
        abort_if($proposed->lt(now()->addHours($noticeHours)), 422, "This owner requires at least {$noticeHours} hours of viewing notice.");
        $this->assertWithinViewingWindow($proposed, $settings);
        abort_if($conversation->viewingRequests()->whereIn('status', ['requested', 'accepted', 'alternative_proposed'])->exists(), 409, 'This conversation already has an active viewing request.');
        $viewing = $conversation->viewingRequests()->create([
            'listing_id' => $conversation->listing_id, 'tenant_id' => $conversation->tenant_id, 'owner_id' => $conversation->owner_id,
            'proposed_at' => $proposed->copy()->utc(), 'tenant_note' => $data['note'] ?? null, 'status' => 'requested',
        ]);
        $conversation->owner->notify(new PlatformNotification('viewing_requested', 'New viewing request', $request->user()->name.' requested a property viewing.', '/owner/journey'));
        $conversation->messages()->create(['sender_id' => $request->user()->id, 'body' => 'Viewing requested for '.$viewing->proposed_at->copy()->setTimezone('Asia/Colombo')->format('d M Y, g:i A').'.']);
        $conversation->touch();

        return response()->json(['data' => $viewing], 201);
    }

    public function tenantViewingAction(Request $request, ViewingRequest $viewing, string $action): JsonResponse
    {
        abort_unless($request->user()->id === $viewing->tenant_id, 403);
        abort_unless(in_array($action, ['cancel', 'accept-alternative'], true), 404);
        abort_if(in_array($viewing->status, ['completed', 'declined', 'cancelled'], true), 409, 'This viewing can no longer be changed.');
        if ($action === 'accept-alternative') {
            abort_unless($viewing->status === 'alternative_proposed' && $viewing->alternative_at, 409);
            $viewing->update(['status' => 'accepted', 'proposed_at' => $viewing->alternative_at, 'responded_at' => now()]);
        } else {
            $viewing->update(['status' => 'cancelled', 'responded_at' => now()]);
        }
        $viewing->owner->notify(new PlatformNotification('viewing_updated', 'Viewing updated', 'A tenant updated a viewing for '.$viewing->listing->title.'.', '/owner/journey'));

        return response()->json(['data' => $viewing->fresh()]);
    }

    public function ownerViewingAction(Request $request, ViewingRequest $viewing, string $action): JsonResponse
    {
        abort_unless($request->user()->id === $viewing->owner_id, 403);
        abort_unless(in_array($action, ['accept', 'decline', 'propose', 'complete'], true), 404);
        $data = $request->validate(['note' => 'nullable|string|max:500', 'alternativeAt' => 'nullable|date|after:now']);
        abort_if($action === 'propose' && empty($data['alternativeAt']), 422, 'Choose an alternative viewing time.');
        $alternative = $action === 'propose' ? Carbon::parse($data['alternativeAt']) : null;
        if ($alternative) {
            $this->assertWithinViewingWindow($alternative, $viewing->listing->rentalSettings);
        }
        $status = match ($action) {
            'accept' => 'accepted', 'decline' => 'declined', 'propose' => 'alternative_proposed', 'complete' => 'completed'
        };
        if ($action === 'complete') {
            abort_unless($viewing->status === 'accepted', 409, 'Only an accepted viewing can be completed.');
        }
        if (in_array($action, ['accept', 'decline', 'propose'], true)) {
            abort_unless(in_array($viewing->status, ['requested', 'alternative_proposed'], true), 409);
        }
        $viewing->update([
            'status' => $status, 'owner_note' => $data['note'] ?? null, 'responded_at' => now(),
            'alternative_at' => $alternative?->copy()->utc() ?? $viewing->alternative_at,
            'completed_at' => $action === 'complete' ? now() : null,
        ]);
        $viewing->tenant->notify(new PlatformNotification('viewing_'.$status, 'Viewing '.str_replace('_', ' ', $status), 'The owner updated your viewing for '.$viewing->listing->title.'.', '/tenant/journey'));

        return response()->json(['data' => $viewing->fresh()]);
    }

    public function requestReservation(Request $request, Conversation $conversation, ReservationAvailabilityService $availability): JsonResponse
    {
        abort_unless($request->user()->id === $conversation->tenant_id, 403);
        $data = $request->validate([
            'viewingId' => 'required|integer|exists:viewing_requests,id', 'moveInDate' => 'required|date|after_or_equal:today',
            'moveOutDate' => 'required|date|after:moveInDate', 'occupants' => 'required|integer|min:1|max:20', 'message' => 'nullable|string|max:700',
        ]);
        $viewing = $conversation->viewingRequests()->whereKey($data['viewingId'])->where('status', 'completed')->firstOrFail();
        $settings = $conversation->listing->rentalSettings;
        $minimumNotice = (int) ($settings?->minimum_notice_days ?? 2);
        $minimumStay = (int) ($settings?->minimum_stay_months ?? 1);
        $maximumStay = $settings?->maximum_stay_months ? (int) $settings->maximum_stay_months : null;
        $moveIn = Carbon::parse($data['moveInDate']);
        $moveOut = Carbon::parse($data['moveOutDate']);
        abort_if($moveIn->lt(today()->addDays($minimumNotice)), 422, "This owner requires {$minimumNotice} days of notice before move-in.");
        abort_if($moveOut->lt($moveIn->copy()->addMonths($minimumStay)), 422, "The minimum stay is {$minimumStay} month(s).");
        abort_if($maximumStay && $moveOut->gt($moveIn->copy()->addMonths($maximumStay)), 422, "The maximum stay is {$maximumStay} month(s).");
        abort_if($data['occupants'] > $conversation->listing->occupancy_limit, 422, 'The requested number of occupants exceeds this listing’s limit.');
        abort_if($availability->hasConflict($conversation->listing_id, $data['moveInDate'], $data['moveOutDate']), 409, 'Those dates are no longer available. Choose another period.');
        abort_if($conversation->reservations()->whereIn('status', ['requested', 'held', 'confirmed'])->exists(), 409, 'This conversation already has an active reservation request.');
        $reservation = $conversation->reservations()->create([
            'viewing_request_id' => $viewing->id, 'listing_id' => $conversation->listing_id, 'tenant_id' => $conversation->tenant_id,
            'owner_id' => $conversation->owner_id, 'move_in_date' => $data['moveInDate'], 'move_out_date' => $data['moveOutDate'],
            'occupants' => $data['occupants'], 'tenant_message' => $data['message'] ?? null, 'status' => 'requested',
        ]);
        $conversation->owner->notify(new PlatformNotification('reservation_requested', 'Reservation requested', $request->user()->name.' requested to reserve '.$conversation->listing->title.'.', '/owner/journey'));

        return response()->json(['data' => $reservation], 201);
    }

    public function ownerReservationAction(Request $request, Reservation $reservation, string $action, ReservationAvailabilityService $availability): JsonResponse
    {
        abort_unless($request->user()->id === $reservation->owner_id, 403);
        abort_unless(in_array($action, ['accept', 'decline', 'cancel', 'complete'], true), 404);
        $data = $request->validate(['message' => 'nullable|string|max:700']);

        DB::transaction(function () use ($reservation, $action, $data, $availability) {
            $locked = Reservation::lockForUpdate()->findOrFail($reservation->id);
            if ($action === 'accept') {
                abort_unless($locked->status === 'requested', 409);
                abort_if($availability->hasConflict($locked->listing_id, $locked->move_in_date, $locked->move_out_date, $locked->id), 409, 'Another accepted reservation overlaps these dates.');
                $locked->update(['status' => 'held', 'hold_expires_at' => now()->addHours(48), 'owner_accepted_at' => now(), 'owner_message' => $data['message'] ?? null]);
            } elseif ($action === 'decline') {
                abort_unless($locked->status === 'requested', 409);
                $locked->update(['status' => 'declined', 'owner_message' => $data['message'] ?? null]);
            } elseif ($action === 'cancel') {
                abort_unless(in_array($locked->status, ['held', 'confirmed'], true), 409);
                $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'owner_message' => $data['message'] ?? null]);
            } else {
                abort_unless($locked->status === 'confirmed' && $locked->move_out_date->lte(today()), 409, 'This rental period has not ended yet.');
                $locked->update(['status' => 'completed']);
            }
            $availability->syncListing($locked->listing);
        });
        $reservation->tenant->notify(new PlatformNotification('reservation_updated', 'Reservation updated', 'The owner updated your reservation for '.$reservation->listing->title.'.', '/tenant/journey'));

        return response()->json(['data' => $reservation->fresh()]);
    }

    public function tenantReservationAction(Request $request, Reservation $reservation, string $action, ReservationAvailabilityService $availability, RentalAgreementService $agreements, PersonalizationService $personalization): JsonResponse
    {
        abort_unless($request->user()->id === $reservation->tenant_id, 403);
        abort_unless(in_array($action, ['confirm', 'cancel'], true), 404);
        if ($action === 'confirm') {
            abort_unless($reservation->status === 'held' && $reservation->hold_expires_at?->isFuture(), 409, 'The temporary hold has expired. Ask the owner to review the request again.');
            abort_if($availability->hasConflict($reservation->listing_id, $reservation->move_in_date, $reservation->move_out_date, $reservation->id), 409, 'Those dates are no longer available.');
            $reservation->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            $agreements->ensure($reservation);
            $personalization->learn($request->user(), 'moved_in', $reservation->listing);
        } else {
            abort_unless(in_array($reservation->status, ['requested', 'held', 'confirmed'], true), 409);
            $reservation->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }
        $availability->syncListing($reservation->listing);
        $reservation->owner->notify(new PlatformNotification('reservation_updated', 'Reservation updated', $request->user()->name.' updated the reservation for '.$reservation->listing->title.'.', '/owner/journey'));

        return response()->json(['data' => $reservation->fresh()]);
    }

    private function participant(Request $request, Conversation $conversation): void
    {
        abort_unless(in_array($request->user()->id, [$conversation->tenant_id, $conversation->owner_id], true), 403);
    }

    private function assertWithinViewingWindow(Carbon $proposed, mixed $settings): void
    {
        $start = substr((string) ($settings?->viewing_window_start ?? '09:00'), 0, 5);
        $end = substr((string) ($settings?->viewing_window_end ?? '18:00'), 0, 5);
        $localTime = $proposed->copy()->setTimezone('Asia/Colombo')->format('H:i');

        abort_unless($localTime >= $start && $localTime <= $end, 422, "Choose a viewing between {$start} and {$end}.");
    }
}
