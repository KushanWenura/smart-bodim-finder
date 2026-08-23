<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ListingImageService
{
    public function store(Listing $listing, UploadedFile $file, array $meta = []): ListingImage
    {
        $bytes = file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            abort(422, 'The uploaded file is not a valid supported image.');
        }
        $path = $file->store("listings/{$listing->id}", 'public');
        $thumb = $this->thumbnail($bytes, $info, "listings/{$listing->id}/thumb-".basename($path));
        $order = (int) ($listing->images()->max('sort_order') ?? -1) + 1;
        $cover = ($meta['is_cover'] ?? false) || $listing->images()->count() === 0;
        if ($cover) {
            $listing->images()->update(['is_cover' => false]);
        }

        return $listing->images()->create(['storage_path' => $path, 'thumbnail_path' => $thumb, 'mime_type' => $info['mime'], 'byte_size' => $file->getSize(), 'width' => $info[0], 'height' => $info[1], 'caption' => $meta['caption'] ?? null, 'alt_text' => $meta['alt_text'] ?? $listing->title, 'sort_order' => $order, 'is_cover' => $cover]);
    }

    private function thumbnail(string $bytes, array $info, string $path): string
    {
        if (! function_exists('imagecreatefromstring')) {
            Storage::disk('public')->put($path, $bytes);

            return $path;
        }

        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            Storage::disk('public')->put($path, $bytes);

            return $path;
        }

        $width = min(640, $info[0]);
        $height = (int) round($info[1] * ($width / $info[0]));
        $dest = imagecreatetruecolor($width, $height);
        imagecopyresampled($dest, $src, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        ob_start();
        imagewebp($dest, null, 82);
        $thumbnailPath = preg_replace('/\.[^.]+$/', '.webp', $path);
        Storage::disk('public')->put($thumbnailPath, ob_get_clean());
        imagedestroy($src);
        imagedestroy($dest);

        return $thumbnailPath;
    }

    public function delete(ListingImage $image): void
    {
        Storage::disk('public')->delete([$image->storage_path, $image->thumbnail_path]);
        $image->delete();
    }
}
