<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ListingImageQualityService
{
    public const VERSION = 'local-image-quality-v1.0.0';

    public function analyze(string $bytes, array $info): array
    {
        $width = (int) $info[0];
        $height = (int) $info[1];
        $score = 100;
        $flags = [];
        if ($width < 800 || $height < 600) {
            $score -= 28;
            $flags[] = ['code' => 'low_resolution', 'message' => 'Use a photo at least 800 × 600 pixels.'];
        }
        $ratio = $height > 0 ? $width / $height : 1;
        if ($ratio > 2.4 || $ratio < .55) {
            $score -= 16;
            $flags[] = ['code' => 'extreme_aspect', 'message' => 'Very narrow images hide room context. Use a normal landscape or portrait photo.'];
        }
        if (strlen($bytes) < 45_000) {
            $score -= 10;
            $flags[] = ['code' => 'small_file', 'message' => 'The image is heavily compressed and may look unclear.'];
        }

        $hash = null;
        if (function_exists('imagecreatefromstring')) {
            $source = @imagecreatefromstring($bytes);
            if ($source) {
                $sample = imagecreatetruecolor(9, 8);
                imagecopyresampled($sample, $source, 0, 0, 0, 0, 9, 8, $width, $height);
                $brightness = [];
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 9; $x++) {
                        $rgb = imagecolorat($sample, $x, $y);
                        $brightness[] = ((($rgb >> 16) & 255) * .299) + ((($rgb >> 8) & 255) * .587) + (($rgb & 255) * .114);
                    }
                }
                $mean = array_sum($brightness) / count($brightness);
                $variance = array_sum(array_map(fn ($value) => ($value - $mean) ** 2, $brightness)) / count($brightness);
                if ($mean < 48) {
                    $score -= 22;
                    $flags[] = ['code' => 'too_dark', 'message' => 'The room is too dark to assess confidently.'];
                } elseif ($mean > 225) {
                    $score -= 18;
                    $flags[] = ['code' => 'too_bright', 'message' => 'Highlights are overexposed; retake the photo with softer light.'];
                }
                if (sqrt($variance) < 22) {
                    $score -= 18;
                    $flags[] = ['code' => 'low_detail', 'message' => 'The photo has very little visible detail and may be blurred.'];
                }
                $bits = '';
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $bits .= $brightness[($y * 9) + $x] > $brightness[($y * 9) + $x + 1] ? '1' : '0';
                    }
                }
                $hash = collect(str_split($bits, 4))->map(fn ($chunk) => dechex(bindec($chunk)))->join('');
                imagedestroy($sample);
                imagedestroy($source);
            }
        }

        return ['score' => max(0, $score), 'flags' => $flags, 'perceptualHash' => $hash, 'version' => self::VERSION];
    }

    public function analyzeFile(string $storagePath): ?array
    {
        if (Storage::disk('public')->exists($storagePath)) {
            $bytes = Storage::disk('public')->get($storagePath);
        } else {
            $publicPath = public_path(ltrim($storagePath, '/\\'));
            if (! is_file($publicPath)) {
                return null;
            }
            $bytes = file_get_contents($publicPath);
        }
        $info = @getimagesizefromstring($bytes);

        return $info ? $this->analyze($bytes, $info) : null;
    }

    public function hammingDistance(?string $left, ?string $right): ?int
    {
        if (! $left || ! $right || strlen($left) !== strlen($right)) {
            return null;
        }
        $bits = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
        $distance = 0;
        for ($i = 0; $i < strlen($left); $i++) {
            $distance += $bits[hexdec($left[$i]) ^ hexdec($right[$i])];
        }

        return $distance;
    }
}
