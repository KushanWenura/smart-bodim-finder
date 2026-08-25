<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Privacy-safe multilingual slot extraction for accommodation requests.
 *
 * The service deliberately returns structured evidence and confidence instead
 * of allowing a language model to execute database filters directly.
 */
class QueryUnderstandingService
{
    private const FACILITIES = [
        'WiFi' => ['wifi', 'wi-fi', 'wi fi', 'internet', 'වයිෆයි', 'ඉන්ටර්නෙට්', 'வைஃபை', 'இணையம்'],
        'Parking' => ['parking', 'car park', 'vehicle parking', 'වාහන නැවැත්වීම', 'parking eka', 'கார் பார்க்கிங்', 'வாகன நிறுத்தம்'],
        'Meals' => ['meal', 'meals', 'food provided', 'කෑම', 'ආහාර', 'kaama', 'சாப்பாடு', 'உணவு'],
        'Kitchen access' => ['kitchen', 'cooking facility', 'cook', 'කුස්සිය', 'උයන්න', 'kussiya', 'சமையலறை', 'சமைக்க'],
        'Attached bathroom' => ['attached bathroom', 'private bathroom', 'ensuite', 'washroom', 'ඇටෑච් බාත්රූම්', 'වෙනම බාත්රූම්', 'නාන කාමරය', 'தனி குளியலறை', 'இணைக்கப்பட்ட குளியலறை'],
        'Hot water' => ['hot water', 'උණු වතුර', 'unu wathura', 'சுடு நீர்'],
        'Air conditioning' => ['air conditioning', 'air conditioned', 'aircon', 'a/c', 'ac room', 'ac', 'ඒසී', 'වායුසමීකරණ', 'ac eka', 'ஏசி', 'குளிர்சாதனம்'],
        'Security/CCTV' => ['cctv', 'security camera', 'guard', 'ආරක්ෂාව', 'සීසීටීවී', 'பாதுகாப்பு', 'சிசிடிவி'],
        'Study area' => ['study area', 'study space', 'study desk', 'පාඩම් කරන්න', 'study table', 'படிக்கும் இடம்', 'படிப்பு மேசை'],
        'Laundry' => ['laundry', 'washing machine', 'රෙදි සෝදන', 'washing machine eka', 'துணி துவைக்கும்'],
    ];

    private const NEARBY = [
        'bus_station' => ['bus stop', 'bus stand', 'bus station', 'bus halt', 'බස් නැවතුම', 'බස් හෝල්ට්', 'பேருந்து நிறுத்தம்'],
        'train_station' => ['train station', 'railway station', 'train', 'දුම්රිය ස්ථානය', 'கோவில் ரயில் நிலையம்', 'ரயில் நிலையம்'],
        'supermarket' => ['cargills', 'food city', 'supermarket', 'grocery', 'keells', 'සුපිරි වෙළඳසැල', 'கார்கில்ஸ்', 'பல்பொருள் அங்காடி'],
        'hospital' => ['hospital', 'medical centre', 'medical center', 'clinic', 'රෝහල', 'மருத்துவமனை'],
        'pharmacy' => ['pharmacy', 'ෆාමසිය', 'மருந்தகம்'],
        'food' => ['restaurant', 'cafe', 'canteen', 'food place', 'කඩේ', 'කෑම කඩේ', 'உணவகம்', 'கஃபே'],
        'bank_atm' => ['bank', 'atm', 'බැංකුව', 'ATM එක', 'வங்கி', 'ஏடிஎம்'],
        'police' => ['police station', 'පොලිසිය', 'காவல் நிலையம்'],
        'laundry' => ['laundry', 'රෙදි සෝදන', 'துணி துவைக்கும்'],
    ];

    private const PREFERENCE_MARKERS = [
        'prefer', 'preferred', 'ideally', 'would like', 'nice to have', 'if possible',
        'කැමති', 'තිබුණොත් හොඳයි', 'පුළුවන් නම්', 'වඩා හොඳ', 'viramathi',
        'விரும்புகிறேன்', 'இருந்தால் நல்லது', 'முடிந்தால்',
    ];

    private const EXCLUSION_MARKERS = [
        'without', 'no ', 'not ', "don't want", 'do not want', 'avoid',
        'එපා', 'නැති', 'නොමැති', 'one na', 'නොවෙයි',
        'வேண்டாம்', 'இல்லாமல்', 'தவிர்க்க',
    ];

    public function understand(string $text): array
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $normalized = Str::lower(Str::ascii($clean));
        $slots = [];
        $hardFacilities = [];
        $preferredFacilities = [];
        $excludedFacilities = [];

        foreach (self::FACILITIES as $canonical => $aliases) {
            if (($match = $this->firstAlias($clean, $aliases)) === null) {
                continue;
            }
            $kind = $this->constraintKind($clean, $match);
            ${$kind.'Facilities'}[] = $canonical;
            $slots[] = $this->slot('facility', $canonical, $kind, 0.94, $match);
        }

        $nearby = [];
        foreach (self::NEARBY as $canonical => $aliases) {
            if (($match = $this->firstAlias($clean, $aliases)) === null) {
                continue;
            }
            $kind = $this->constraintKind($clean, $match);
            if ($kind !== 'excluded') {
                $nearby[] = $canonical;
                $slots[] = $this->slot('nearby', $canonical, $kind === 'hard' ? 'preferred' : $kind, 0.9, $match);
            }
        }

        $result = [
            'language' => $this->language($clean),
            'hardFacilities' => array_values(array_unique($hardFacilities)),
            'preferredFacilities' => array_values(array_unique($preferredFacilities)),
            'excludedFacilities' => array_values(array_unique($excludedFacilities)),
            'nearbyPriorities' => array_values(array_unique($nearby)),
        ];

        $this->extractBudget($clean, $result, $slots);
        $this->extractRadius($clean, $result, $slots);
        $this->extractOccupancy($clean, $result, $slots);
        $this->extractCategorical($clean, $normalized, $result, $slots);

        $recognized = count($slots);
        $locationCue = preg_match('/\b(?:near|close to|campus|university|workplace|office|around)\b|ළඟ|අසල|அருகில்/ui', $clean) === 1;
        $result['needsDestination'] = $locationCue;
        $result['slots'] = $slots;
        $result['confidence'] = [
            'overall' => round(min(0.97, 0.5 + ($recognized * 0.055)), 2),
            'recognizedSlots' => $recognized,
            'language' => $result['language'],
            'requiresClarification' => $recognized === 0 || ($locationCue && ! preg_match('/\b(?:maharagama|moratuwa|colombo|kandy|galle|jaffna|malabe|peradeniya|homagama|nugegoda|dehiwala)\b/ui', $clean)),
        ];

        return $result;
    }

    private function extractBudget(string $text, array &$result, array &$slots): void
    {
        if (preg_match('/(?:between|from)\s*(?:rs\.?|lkr)?\s*([0-9][0-9,.]*\s*k?)\s*(?:and|to|-)\s*(?:rs\.?|lkr)?\s*([0-9][0-9,.]*\s*k?)/i', $text, $match)) {
            $a = $this->money($match[1]);
            $b = $this->money($match[2]);
            if (min($a, $b) >= 5000) {
                $result['minPrice'] = min($a, $b);
                $result['maxPrice'] = max($a, $b);
                $slots[] = $this->slot('budget_range', [$result['minPrice'], $result['maxPrice']], 'hard', 0.98, $match[0]);
            }
        } elseif (preg_match('/(?:under|below|maximum|max|budget(?:\s+(?:of|to))?|less\s+than|up\s+to|අඩුවෙන්|යට|අයවැය|கீழ்|பட்ஜெட்)\s*(?:rs\.?|lkr)?\s*([0-9][0-9,.]*\s*k?)/ui', $text, $match)) {
            $value = $this->money($match[1]);
            if ($value >= 5000) {
                $kind = $this->constraintKind($text, $match[0]);
                $result[$kind === 'preferred' ? 'preferredMaxPrice' : 'maxPrice'] = $value;
                $slots[] = $this->slot('max_price', $value, $kind, 0.98, $match[0]);
            }
        }
    }

    private function extractRadius(string $text, array &$result, array &$slots): void
    {
        if (preg_match('/(?:within|inside|less than|under|ඇතුළත|අඩු|உள்ளே|குறைவாக)\s*([0-9]+(?:\.[0-9]+)?)\s*(?:km|කිමී|கிமீ)/ui', $text, $match)) {
            $result['radiusKm'] = min(50, max(1, (float) $match[1]));
            $slots[] = $this->slot('radius_km', $result['radiusKm'], 'hard', 0.98, $match[0]);
        }
    }

    private function extractOccupancy(string $text, array &$result, array &$slots): void
    {
        if (preg_match('/(?:for|fit|suitable for|ට|සඳහා)\s*(one|two|three|four|[1-9]|එක්|දෙන්න|තුන්දෙන|ஒரு|இரண்டு|மூன்று)\s*(?:people|persons?|students?|tenants?|friends?|දෙනෙකුට|பேர்)?/ui', $text, $match)) {
            $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'එක්' => 1, 'දෙන්න' => 2, 'තුන්දෙන' => 3, 'ஒரு' => 1, 'இரண்டு' => 2, 'மூன்று' => 3];
            $result['occupancy'] = $words[Str::lower($match[1])] ?? (int) $match[1];
            $slots[] = $this->slot('occupancy', $result['occupancy'], 'hard', 0.91, $match[0]);
        }
    }

    private function extractCategorical(string $text, string $normalized, array &$result, array &$slots): void
    {
        $types = ['shared room' => 'shared_room', 'private room' => 'private_room', 'boarding room' => 'boarding_room', 'small house' => 'small_house', 'annex' => 'annex', 'studio' => 'studio', 'hostel' => 'hostel', 'බෝඩිම' => 'boarding_room', 'அறை' => 'private_room'];
        foreach ($types as $alias => $value) {
            if (str_contains(Str::lower($text), $alias)) {
                $kind = $this->constraintKind($text, $alias);
                if ($kind === 'preferred') {
                    $result['preferredPropertyType'] = $value;
                } elseif ($kind === 'excluded') {
                    $result['excludedPropertyTypes'] = [$value];
                } else {
                    $result['propertyType'] = $value;
                }
                $slots[] = $this->slot('property_type', $value, $kind, 0.9, $alias);
                break;
            }
        }
        if (preg_match('/\b(?:female|women|girls|ladies)\b|ගැහැණු|කාන්තා|பெண்கள்/ui', $text, $match)) {
            $result['gender'] = 'female_only';
            $slots[] = $this->slot('gender_rule', 'female_only', 'hard', 0.95, $match[0]);
        } elseif (preg_match('/\b(?:male|men|boys|gents)\b|පිරිමි|ஆண்கள்/ui', $text, $match)) {
            $result['gender'] = 'male_only';
            $slots[] = $this->slot('gender_rule', 'male_only', 'hard', 0.95, $match[0]);
        }
        if (preg_match('/\b(?:unfurnished)\b|ගෘහභාණ්ඩ නැති|தளபாடமற்ற/ui', $text, $match)) {
            $kind = $this->constraintKind($text, $match[0]);
            $result[$kind === 'preferred' ? 'preferredFurnished' : 'furnished'] = false;
            $slots[] = $this->slot('furnished', false, $kind, 0.96, $match[0]);
        } elseif (preg_match('/\b(?:furnished|fully furnished)\b|ගෘහභාණ්ඩ සහිත|தளபாடங்கள்/ui', $text, $match)) {
            $kind = $this->constraintKind($text, $match[0]);
            $result[$kind === 'preferred' ? 'preferredFurnished' : 'furnished'] = true;
            $slots[] = $this->slot('furnished', true, $kind, 0.96, $match[0]);
        }
        $result['preference'] = match (true) {
            preg_match('/closest|nearest|shortest commute|ළඟම|அருகிலுள்ள/ui', $text) === 1 => 'closest',
            preg_match('/cheapest|lowest price|affordable|best value|ලාභම|மலிவான/ui', $text) === 1 => 'value',
            preg_match('/best rated|highest rated|top rated|හොඳම rating|சிறந்த மதிப்பீடு/ui', $text) === 1 => 'rating',
            preg_match('/safest|safety first|ආරක්ෂිතම|பாதுகாப்பான/ui', $text) === 1 => 'safety',
            default => null,
        };
    }

    private function firstAlias(string $text, array $aliases): ?string
    {
        $lower = Str::lower($text);
        foreach (collect($aliases)->sortByDesc(fn ($value) => mb_strlen($value)) as $alias) {
            $needle = Str::lower($alias);
            $matched = mb_strlen($needle) <= 3 && preg_match('/^[a-z0-9]+$/', $needle)
                ? preg_match('/\b'.preg_quote($needle, '/').'\b/i', $lower) === 1
                : str_contains($lower, $needle);
            if ($matched) {
                return $alias;
            }
        }

        return null;
    }

    private function constraintKind(string $text, string $match): string
    {
        $lower = Str::lower($text);
        $position = mb_strpos($lower, Str::lower($match));
        $prefix = $position === false ? $lower : mb_substr($lower, max(0, $position - 32), min(32, $position));
        $suffix = $position === false ? '' : mb_substr($lower, $position + mb_strlen($match), 20);
        if ($this->containsAny($prefix, self::EXCLUSION_MARKERS)) {
            return 'excluded';
        }
        if ($this->containsAny($prefix, self::PREFERENCE_MARKERS) || preg_match('/^\s*(?:is\s+)?preferred\b/', $suffix)) {
            return 'preferred';
        }

        return 'hard';
    }

    private function containsAny(string $text, array $needles): bool
    {
        return collect($needles)->contains(fn ($needle) => str_contains($text, Str::lower($needle)));
    }

    private function money(string $raw): int
    {
        $clean = Str::lower(trim($raw));
        $thousands = str_ends_with($clean, 'k');
        $number = (float) str_replace([',', 'k', ' '], '', $clean);

        return (int) round($number * ($thousands ? 1000 : 1));
    }

    private function language(string $text): string
    {
        $sinhala = preg_match_all('/[\x{0D80}-\x{0DFF}]/u', $text);
        $tamil = preg_match_all('/[\x{0B80}-\x{0BFF}]/u', $text);
        if ($sinhala && preg_match('/[A-Za-z]/', $text)) {
            return 'si-en';
        }
        if ($tamil && preg_match('/[A-Za-z]/', $text)) {
            return 'ta-en';
        }
        if ($sinhala) {
            return 'si';
        }
        if ($tamil) {
            return 'ta';
        }

        return 'en';
    }

    private function slot(string $name, mixed $value, string $kind, float $confidence, string $evidence): array
    {
        return compact('name', 'value', 'kind', 'confidence', 'evidence');
    }
}
