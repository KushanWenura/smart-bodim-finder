<?php

namespace App\Services;

use App\Models\RentalAgreement;
use App\Models\Reservation;

class RentalAgreementService
{
    public function ensure(Reservation $reservation): RentalAgreement
    {
        $reservation->loadMissing(['listing.rentalSettings', 'tenant:id,name,email,phone', 'owner:id,name,email,phone']);
        $settings = $reservation->listing->rentalSettings;
        $terms = [
            'listingTitle' => $reservation->listing->title,
            'publicArea' => $reservation->listing->public_area.', '.$reservation->listing->city,
            'tenantName' => $reservation->tenant->name,
            'ownerName' => $reservation->owner->name,
            'moveInDate' => $reservation->move_in_date->toDateString(),
            'moveOutDate' => $reservation->move_out_date->toDateString(),
            'occupants' => $reservation->occupants,
            'monthlyRentLkr' => (int) $reservation->listing->monthly_price_lkr,
            'depositLkr' => (int) ($reservation->listing->deposit_lkr ?? 0),
            'houseRules' => $reservation->listing->house_rules ?: 'The tenant agrees to reasonable property rules disclosed before acceptance.',
            'cancellationPolicy' => $settings?->cancellation_policy ?: 'Any cancellation must be recorded in BodimBuddy.lk. Refunds or payments are handled directly by the parties; the platform is not a payment processor.',
            'safetyNotice' => 'Both parties should inspect the property, confirm identities and keep payment evidence. BodimBuddy.lk does not guarantee a property or collect rent.',
        ];
        $encoded = json_encode($terms, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return RentalAgreement::firstOrCreate(
            ['reservation_id' => $reservation->id],
            [
                'agreement_number' => 'BB-'.now()->format('Y').'-'.str_pad((string) $reservation->id, 7, '0', STR_PAD_LEFT),
                'terms_version' => '2026-01', 'terms_snapshot' => $terms, 'content_hash' => hash('sha256', $encoded ?: ''),
                'status' => 'pending_acceptance', 'generated_at' => now(),
            ]
        );
    }
}
