<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingAvailabilityBlock;
use App\Models\RentalDispute;
use App\Models\Reservation;
use App\Models\VerificationEvidence;
use App\Models\ViewingRequest;
use App\Notifications\PlatformNotification;
use App\Services\RentalAgreementPdfService;
use App\Services\RentalAgreementService;
use App\Services\ReservationAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RentalExperienceController extends Controller
{
    public function availability(Listing $listing, ReservationAvailabilityService $availability): JsonResponse
    {
        $availability->expireStaleHolds($listing->id);
        $listing->loadMissing('rentalSettings');
        $busy = $listing->reservations()->where(function ($query) {
            $query->where('status', 'confirmed')
                ->orWhere(fn ($held) => $held->where('status', 'held')->where('hold_expires_at', '>', now()));
        })->get(['id', 'move_in_date', 'move_out_date', 'status'])->map(fn ($item) => [
            'id' => $item->id, 'startDate' => $item->move_in_date->toDateString(),
            'endDate' => $item->move_out_date->toDateString(), 'type' => $item->status,
        ]);
        $blocks = $listing->availabilityBlocks()->orderBy('start_date')->get()->map(fn ($item) => [
            'id' => $item->id, 'startDate' => $item->start_date->toDateString(),
            'endDate' => $item->end_date->toDateString(), 'type' => $item->type,
            'reason' => $item->reason,
        ]);

        return response()->json([
            'snapshot' => $availability->snapshot($listing),
            'busyPeriods' => $busy->concat($blocks)->sortBy('startDate')->values(),
            'settings' => $this->settingsPayload($listing),
        ]);
    }

    public function ownerSettings(Request $request, Listing $listing): JsonResponse
    {
        $this->owner($request, $listing);

        return response()->json(['data' => $this->settingsPayload($listing->loadMissing('rentalSettings'))]);
    }

    public function updateOwnerSettings(Request $request, Listing $listing): JsonResponse
    {
        $this->owner($request, $listing);
        $data = $request->validate([
            'minimumStayMonths' => 'required|integer|between:1,24', 'maximumStayMonths' => 'nullable|integer|between:1,60|gte:minimumStayMonths',
            'minimumNoticeDays' => 'required|integer|between:0,90', 'viewingNoticeHours' => 'required|integer|between:1,168',
            'viewingWindowStart' => 'required|date_format:H:i', 'viewingWindowEnd' => 'required|date_format:H:i|after:viewingWindowStart',
            'utilitiesEstimateLkr' => 'required|integer|between:0,100000', 'mealsEstimateLkr' => 'required|integer|between:0,200000',
            'transportEstimateLkr' => 'required|integer|between:0,100000', 'cancellationPolicy' => 'nullable|string|max:1000',
        ]);
        $listing->rentalSettings()->updateOrCreate(['listing_id' => $listing->id], [
            'minimum_stay_months' => $data['minimumStayMonths'], 'maximum_stay_months' => $data['maximumStayMonths'] ?? null,
            'minimum_notice_days' => $data['minimumNoticeDays'], 'viewing_notice_hours' => $data['viewingNoticeHours'],
            'viewing_window_start' => $data['viewingWindowStart'], 'viewing_window_end' => $data['viewingWindowEnd'],
            'utilities_estimate_lkr' => $data['utilitiesEstimateLkr'], 'meals_estimate_lkr' => $data['mealsEstimateLkr'],
            'transport_estimate_lkr' => $data['transportEstimateLkr'], 'cancellation_policy' => $data['cancellationPolicy'] ?? null,
        ]);

        return response()->json(['data' => $this->settingsPayload($listing->fresh()->load('rentalSettings'))]);
    }

    public function storeBlock(Request $request, Listing $listing, ReservationAvailabilityService $availability): JsonResponse
    {
        $this->owner($request, $listing);
        $data = $request->validate([
            'startDate' => 'required|date|after_or_equal:today', 'endDate' => 'required|date|after_or_equal:startDate',
            'type' => 'required|in:owner_block,maintenance,private_use', 'reason' => 'nullable|string|max:300',
        ]);
        abort_if($availability->hasConflict($listing->id, $data['startDate'], $data['endDate']), 409, 'This period overlaps an existing hold, reservation or availability block.');
        $block = $listing->availabilityBlocks()->create([
            'created_by' => $request->user()->id, 'start_date' => $data['startDate'], 'end_date' => $data['endDate'],
            'type' => $data['type'], 'reason' => $data['reason'] ?? null,
        ]);
        $availability->syncListing($listing);

        return response()->json(['data' => $block], 201);
    }

    public function destroyBlock(Request $request, ListingAvailabilityBlock $block, ReservationAvailabilityService $availability): JsonResponse
    {
        $this->owner($request, $block->listing);
        $listing = $block->listing;
        $block->delete();
        $availability->syncListing($listing);

        return response()->json(status: 204);
    }

    public function visitSafety(Request $request, ViewingRequest $viewing): JsonResponse
    {
        abort_unless($request->user()->id === $viewing->tenant_id, 403);
        $data = $request->validate([
            'emergencyContactName' => 'required|string|max:120',
            'emergencyContactPhone' => ['required', 'regex:/^(?:\+94|0)7\d{8}$/'],
        ]);
        $token = Str::random(48);
        $viewing->forceFill([
            'emergency_contact_name' => $data['emergencyContactName'],
            'emergency_contact_phone' => $data['emergencyContactPhone'],
            'share_token_hash' => hash('sha256', $token),
        ])->save();

        return response()->json([
            'message' => 'Private visit-safety link created. Only share it with your trusted contact.',
            'shareUrl' => rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://127.0.0.1:5173')), '/').'/visit/'.$token,
        ]);
    }

    public function visitShare(string $token): JsonResponse
    {
        $viewing = ViewingRequest::with('listing:id,title,public_area,city')->where('share_token_hash', hash('sha256', $token))->firstOrFail();

        return response()->json(['data' => [
            'listing' => $viewing->listing->title, 'area' => $viewing->listing->public_area.', '.$viewing->listing->city,
            'scheduledAt' => $viewing->proposed_at?->toIso8601String(), 'status' => $viewing->status,
            'tenantCheckedInAt' => $viewing->tenant_checked_in_at?->toIso8601String(),
            'tenantCheckedOutAt' => $viewing->tenant_checked_out_at?->toIso8601String(),
            'privacy' => 'This safety view intentionally excludes the tenant contact, owner contact and exact property address.',
        ]]);
    }

    public function attendance(Request $request, ViewingRequest $viewing, string $action): JsonResponse
    {
        abort_unless(in_array($request->user()->id, [$viewing->tenant_id, $viewing->owner_id], true), 403);
        abort_unless(in_array($action, ['check-in', 'check-out', 'no-show'], true), 404);
        abort_unless(in_array($viewing->status, ['accepted', 'completed'], true), 409, 'Attendance is available only for an accepted viewing.');
        $side = $request->user()->id === $viewing->tenant_id ? 'tenant' : 'owner';
        abort_if($action === 'check-out' && ! $viewing->{"{$side}_checked_in_at"}, 409, 'Check in before recording a check-out.');
        abort_if($action === 'check-in' && $viewing->{"{$side}_checked_in_at"}, 409, 'This visit check-in was already recorded.');
        $fields = match ($action) {
            'check-in' => ["{$side}_checked_in_at" => now(), "{$side}_attendance" => 'attended'],
            'check-out' => ["{$side}_checked_out_at" => now(), "{$side}_attendance" => 'attended'],
            default => ["{$side}_attendance" => 'other_party_no_show'],
        };
        $viewing->update($fields);
        $other = $side === 'tenant' ? $viewing->owner : $viewing->tenant;
        $other->notify(new PlatformNotification('viewing_attendance', 'Viewing attendance updated', $request->user()->name.' updated the visit status.', '/'.($side === 'tenant' ? 'owner' : 'tenant').'/journey'));

        return response()->json(['data' => $viewing->fresh()]);
    }

    public function agreement(Request $request, Reservation $reservation, RentalAgreementService $agreements): JsonResponse
    {
        $this->participant($request, $reservation);
        abort_unless(in_array($reservation->status, ['confirmed', 'completed'], true), 409, 'The rental must be confirmed before an agreement is generated.');

        return response()->json(['data' => $agreements->ensure($reservation)]);
    }

    public function acceptAgreement(Request $request, Reservation $reservation, RentalAgreementService $agreements): JsonResponse
    {
        $this->participant($request, $reservation);
        abort_unless($reservation->status === 'confirmed', 409, 'Only a confirmed reservation agreement can be accepted.');
        $data = $request->validate(['confirm' => 'accepted']);
        $agreement = $agreements->ensure($reservation);
        $field = $request->user()->id === $reservation->tenant_id ? 'tenant_accepted_at' : 'owner_accepted_at';
        $agreement->update([$field => now()]);
        $agreement->refresh();
        if ($agreement->tenant_accepted_at && $agreement->owner_accepted_at) {
            $agreement->update(['status' => 'accepted']);
        }
        $other = $request->user()->id === $reservation->tenant_id ? $reservation->owner : $reservation->tenant;
        $other->notify(new PlatformNotification('agreement_updated', 'Rental agreement updated', $request->user()->name.' accepted the rental agreement.', '/'.($other->role).'/journey'));

        return response()->json(['data' => $agreement->fresh()]);
    }

    public function downloadAgreement(Request $request, Reservation $reservation, RentalAgreementService $agreements, RentalAgreementPdfService $pdf): Response
    {
        $this->participant($request, $reservation);
        abort_unless(in_array($reservation->status, ['confirmed', 'completed'], true), 409, 'The rental must be confirmed before an agreement is downloaded.');
        $agreement = $agreements->ensure($reservation);

        return response($pdf->render($agreement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$agreement->agreement_number.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeDispute(Request $request, Reservation $reservation): JsonResponse
    {
        $this->participant($request, $reservation);
        abort_if($reservation->disputes()->where('reporter_id', $request->user()->id)->exists(), 409, 'You already submitted a report for this reservation.');
        $data = $request->validate([
            'category' => 'required|in:no_show,misrepresentation,payment,conduct,property_condition,other',
            'details' => 'required|string|min:20|max:3000',
        ]);
        $dispute = $reservation->disputes()->create($data + ['reporter_id' => $request->user()->id, 'status' => 'open']);

        return response()->json(['data' => $dispute, 'message' => 'The report was submitted for administrator review.'], 201);
    }

    public function disputes(): JsonResponse
    {
        return response()->json(['data' => RentalDispute::with(['reservation.listing:id,title', 'reporter:id,name,role'])->latest()->paginate(30)]);
    }

    public function resolveDispute(Request $request, RentalDispute $dispute): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:investigating,resolved,dismissed', 'resolution' => 'required|string|min:10|max:3000']);
        $dispute->update($data + ['resolved_by' => $request->user()->id, 'resolved_at' => in_array($data['status'], ['resolved', 'dismissed'], true) ? now() : null]);

        return response()->json(['data' => $dispute->fresh()]);
    }

    public function submitVerificationEvidence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:phone,identity,property_authority,address',
            'reference' => 'required|string|min:4|max:160',
        ]);
        $evidence = $request->user()->verificationEvidence()->create($data + ['status' => 'pending']);

        return response()->json(['data' => $evidence], 201);
    }

    public function verificationEvidence(): JsonResponse
    {
        return response()->json(['data' => VerificationEvidence::with('user:id,name,email,role')->latest()->paginate(30)]);
    }

    public function reviewVerificationEvidence(Request $request, VerificationEvidence $evidence): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:verified,rejected', 'reviewNote' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($request, $evidence, $data) {
            $evidence->update(['status' => $data['status'], 'review_note' => $data['reviewNote'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            if ($evidence->type === 'phone' && $data['status'] === 'verified') {
                $evidence->user->forceFill(['phone_verified_at' => now()])->save();
            }
        });

        return response()->json(['data' => $evidence->fresh()]);
    }

    private function settingsPayload(Listing $listing): array
    {
        $settings = $listing->rentalSettings;

        return [
            'minimumStayMonths' => (int) ($settings?->minimum_stay_months ?? 1),
            'maximumStayMonths' => $settings?->maximum_stay_months ? (int) $settings->maximum_stay_months : null,
            'minimumNoticeDays' => (int) ($settings?->minimum_notice_days ?? 2),
            'viewingNoticeHours' => (int) ($settings?->viewing_notice_hours ?? 12),
            'viewingWindowStart' => substr((string) ($settings?->viewing_window_start ?? '09:00'), 0, 5),
            'viewingWindowEnd' => substr((string) ($settings?->viewing_window_end ?? '18:00'), 0, 5),
            'costEstimate' => [
                'rent' => (int) $listing->monthly_price_lkr, 'utilities' => (int) ($settings?->utilities_estimate_lkr ?? 3500),
                'meals' => (int) ($settings?->meals_estimate_lkr ?? 12000), 'transport' => (int) ($settings?->transport_estimate_lkr ?? 5000),
                'total' => (int) $listing->monthly_price_lkr + (int) ($settings?->utilities_estimate_lkr ?? 3500) + (int) ($settings?->meals_estimate_lkr ?? 12000) + (int) ($settings?->transport_estimate_lkr ?? 5000),
            ],
            'cancellationPolicy' => $settings?->cancellation_policy,
        ];
    }

    private function owner(Request $request, Listing $listing): void
    {
        abort_unless($request->user()->id === $listing->owner_id, 403);
    }

    private function participant(Request $request, Reservation $reservation): void
    {
        abort_unless(in_array($request->user()->id, [$reservation->tenant_id, $reservation->owner_id], true), 403);
    }
}
