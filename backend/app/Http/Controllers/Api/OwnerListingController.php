<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Services\ListingImageService;
use App\Services\ListingWorkflow;
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

    public function history(Request $r, Listing $listing): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);

        return response()->json(['data' => $listing->statusHistory()->with('actor:id,name,role')->latest()->get()]);
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate($this->rules());
        $listing = DB::transaction(function () use ($r, $data) {
            $listing = Listing::create($this->fields($data) + ['owner_id' => $r->user()->id, 'public_slug' => 'SBF-'.strtoupper(Str::random(10)), 'status' => 'draft', 'available' => true]);
            $listing->facilities()->sync($data['facilityIds'] ?? []);

            return $listing;
        });

        return response()->json(['data' => new ListingResource($listing->load(['owner', 'facilities', 'images']))], 201);
    }

    public function update(Request $r, Listing $listing): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);
        abort_unless(in_array($listing->status, ['draft', 'rejected', 'published'], true), 409);
        $data = $r->validate($this->rules());
        DB::transaction(function () use ($listing, $data) {
            $material = $listing->status === 'published' && collect(['monthly_price_lkr', 'public_area', 'city', 'gender_rule', 'occupancy_limit'])->contains(fn ($field) => array_key_exists($field, $this->fields($data)) && $listing->{$field} != $this->fields($data)[$field]);
            $listing->update($this->fields($data) + ($material ? ['status' => 'change_pending'] : []));
            $listing->facilities()->sync($data['facilityIds'] ?? []);
        });

        return response()->json(['data' => new ListingResource($listing->fresh()->load(['owner', 'facilities', 'images']))]);
    }

    public function submit(Request $r, Listing $listing, ListingWorkflow $workflow): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);
        abort_if($listing->images()->count() === 0, 422, 'At least one image is required before submission.');

        return response()->json(['data' => new ListingResource($workflow->transition($listing, 'pending_review', $r->user(), 'Submitted by owner'))]);
    }

    public function deactivate(Request $r, Listing $listing, ListingWorkflow $workflow): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);

        return response()->json(['data' => new ListingResource($workflow->transition($listing, 'deactivated', $r->user(), 'Deactivated by owner'))]);
    }

    public function upload(Request $r, Listing $listing, ListingImageService $images): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id, 403);
        abort_if($listing->images()->count() >= 10, 422, 'A listing can have at most ten images.');
        $data = $r->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120', 'caption' => 'nullable|string|max:240', 'alt_text' => 'nullable|string|max:240', 'is_cover' => 'nullable|boolean']);

        return response()->json(['data' => $images->store($listing, $data['image'], $data)], 201);
    }

    public function removeImage(Request $r, Listing $listing, ListingImage $image, ListingImageService $images): JsonResponse
    {
        abort_unless($listing->owner_id === $r->user()->id && $image->listing_id === $listing->id, 403);
        $images->delete($image);

        return response()->json(status: 204);
    }

    private function fields(array $d): array
    {
        return ['title' => $d['title'], 'description' => $d['description'], 'property_type' => $d['propertyType'], 'monthly_price_lkr' => $d['price'], 'deposit_lkr' => $d['deposit'] ?? null, 'private_address' => $d['privateAddress'] ?? null, 'public_area' => $d['area'], 'city' => $d['city'], 'district' => $d['district'], 'latitude' => $d['latitude'], 'longitude' => $d['longitude'], 'gender_rule' => $d['genderRule'], 'occupancy_limit' => $d['occupancy'], 'available_from' => $d['availableFrom'] ?? null, 'sharing_allowed' => $d['sharingAllowed'] ?? false, 'furnished' => $d['furnished'] ?? false, 'house_rules' => $d['houseRules'] ?? null];
    }
}
