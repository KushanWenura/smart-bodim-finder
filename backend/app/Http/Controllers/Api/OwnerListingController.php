<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Services\ListingImageService;
use App\Services\ListingRiskService;
use App\Services\ListingWorkflow;
use App\Services\SriLankanAddressNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OwnerListingController extends Controller
{
    private function rules(): array
    {
        return ['title' => 'required|string|max:160', 'description' => 'required|string|min:40|max:8000', 'propertyType' => 'required|in:boarding_room,shared_room,private_room,annex,studio,small_house,hostel', 'price' => 'required|integer|min:5000|max:2000000', 'deposit' => 'nullable|integer|min:0|max:5000000', 'privateAddress' => 'nullable|string|max:300', 'area' => 'required|string|max:100', 'city' => 'required|string|max:80', 'district' => 'required|string|max:80', 'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'genderRule' => 'required|in:any,male_only,female_only', 'occupancy' => 'required|integer|between:1,20', 'availableFrom' => 'nullable|date', 'sharingAllowed' => 'boolean', 'furnished' => 'boolean', 'houseRules' => 'nullable|string|max:3000', 'facilityIds' => 'array|max:30', 'facilityIds.*' => 'integer|exists:facilities,id'];
    }

    public function index(Request $r): JsonResponse
    {
        return response()->json(['data' => ListingResource::collection(Listing::where('owner_id', $r->user()->id)->with(['owner', 'facilities', 'images', 'statusHistory'])->latest()->get())]);
    }

    public function show(Request $r, Listing $listing): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);

        return response()->json(['data' => new ListingResource($listing->load(['owner', 'facilities', 'images', 'statusHistory']))]);
    }

    public function history(Request $r, Listing $listing): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);

        return response()->json(['data' => $listing->statusHistory()->with('actor:id,name,role')->latest()->get()]);
    }

    public function store(Request $r, SriLankanAddressNormalizer $normalizer): JsonResponse
    {
        $data = $r->validate($this->rules());
        $data = $this->normalizeLocation($data, $normalizer);
        $listing = DB::transaction(function () use ($r, $data) {
            $listing = Listing::create($this->fields($data) + ['owner_id' => $r->user()->id, 'public_slug' => 'SBF-'.strtoupper(Str::random(10)), 'status' => 'draft', 'available' => true]);
            $listing->facilities()->sync($data['facilityIds'] ?? []);

            return $listing;
        });

        return response()->json(['data' => new ListingResource($listing->load(['owner', 'facilities', 'images']))], 201);
    }

    public function update(Request $r, Listing $listing, SriLankanAddressNormalizer $normalizer, ListingWorkflow $workflow): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);
        abort_unless(in_array($listing->status, ['draft', 'rejected', 'published', 'change_pending', 'rejected_changes'], true), 409);
        $data = $r->validate($this->rules());
        $data = $this->normalizeLocation($data, $normalizer);
        $fields = $this->fields($data);
        $existingFacilities = $listing->facilities()->pluck('facilities.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $nextFacilities = collect($data['facilityIds'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $publicFields = ['title', 'description', 'property_type', 'monthly_price_lkr', 'deposit_lkr', 'public_area', 'city', 'district', 'latitude', 'longitude', 'gender_rule', 'occupancy_limit', 'available_from', 'sharing_allowed', 'furnished', 'house_rules'];
        $requiresReview = $listing->status === 'published' && (
            collect($publicFields)->contains(fn ($field) => $listing->{$field} != $fields[$field])
            || $existingFacilities !== $nextFacilities
        );

        DB::transaction(function () use ($listing, $data, $fields) {
            $listing->update($fields);
            $listing->facilities()->sync($data['facilityIds'] ?? []);
        });

        if ($requiresReview) {
            $listing = $workflow->transition($listing->fresh(), 'change_pending', $r->user(), 'Published details changed by owner and require administrator review.');
        }

        return response()->json(['data' => new ListingResource($listing->fresh()->load(['owner', 'facilities', 'images']))]);
    }

    public function submit(Request $r, Listing $listing, ListingWorkflow $workflow, ListingRiskService $risk): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);
        abort_if($listing->images()->count() === 0, 422, 'At least one image is required before submission.');

        $assessment = $risk->assess($listing);
        $target = $listing->status === 'rejected_changes' ? 'change_pending' : 'pending_review';
        $updated = $workflow->transition($listing, $target, $r->user(), $listing->status === 'rejected_changes' ? 'Corrected changes resubmitted by owner' : 'Submitted by owner');

        return response()->json([
            'data' => new ListingResource($updated),
            'qualityCheck' => [
                'riskScore' => $assessment->risk_score,
                'flags' => $assessment->flags,
                'message' => 'Automated checks assist administrator review and never reject a listing automatically.',
            ],
        ]);
    }

    public function deactivate(Request $r, Listing $listing, ListingWorkflow $workflow): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);

        return response()->json(['data' => new ListingResource($workflow->transition($listing, 'deactivated', $r->user(), 'Deactivated by owner'))]);
    }

    public function upload(Request $r, Listing $listing, ListingImageService $images, ListingWorkflow $workflow): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);
        abort_unless(in_array($listing->status, ['draft', 'rejected', 'published', 'change_pending', 'rejected_changes'], true), 409);
        abort_if($listing->images()->count() >= 10, 422, 'A listing can have at most ten images.');
        $data = $r->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120', 'caption' => 'nullable|string|max:240', 'alt_text' => 'nullable|string|max:240', 'is_cover' => 'nullable|boolean']);
        $image = $images->store($listing, $data['image'], $data);
        if ($listing->status === 'published') {
            $workflow->transition($listing->fresh(), 'change_pending', $r->user(), 'Published listing photos changed by owner and require administrator review.');
        }

        return response()->json(['data' => $image], 201);
    }

    public function removeImage(Request $r, Listing $listing, ListingImage $image, ListingImageService $images, ListingWorkflow $workflow): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id && $image->listing_id === $listing->id, 403);
        abort_unless(in_array($listing->status, ['draft', 'rejected', 'published', 'change_pending', 'rejected_changes'], true), 409);
        $images->delete($image);
        if ($listing->status === 'published') {
            $workflow->transition($listing->fresh(), 'change_pending', $r->user(), 'Published listing photos changed by owner and require administrator review.');
        }

        return response()->json(status: 204);
    }

    private function fields(array $d): array
    {
        return ['title' => $d['title'], 'description' => $d['description'], 'property_type' => $d['propertyType'], 'monthly_price_lkr' => $d['price'], 'deposit_lkr' => $d['deposit'] ?? null, 'private_address' => $d['privateAddress'] ?? null, 'public_area' => $d['area'], 'city' => $d['city'], 'district' => $d['district'], 'latitude' => $d['latitude'], 'longitude' => $d['longitude'], 'gender_rule' => $d['genderRule'], 'occupancy_limit' => $d['occupancy'], 'available_from' => $d['availableFrom'] ?? null, 'sharing_allowed' => $d['sharingAllowed'] ?? false, 'furnished' => $d['furnished'] ?? false, 'house_rules' => $d['houseRules'] ?? null];
    }

    private function normalizeLocation(array $data, SriLankanAddressNormalizer $normalizer): array
    {
        $location = $normalizer->canonicalize($data['area'], $data['city'], $data['district']);
        $data['area'] = $location['area'];
        $data['city'] = $location['city'];
        $data['district'] = $location['district'];

        return $data;
    }
}
