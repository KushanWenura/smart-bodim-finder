<?php

namespace App\Services;

use App\Models\RentalAgreement;

class RentalAgreementPdfService
{
    public function render(RentalAgreement $agreement): string
    {
        $terms = $agreement->terms_snapshot;
        $lines = [
            'Terms version: '.$agreement->terms_version,
            'Generated: '.$agreement->generated_at->format('Y-m-d H:i').' Asia/Colombo',
            '',
            'PROPERTY',
            (string) $terms['listingTitle'],
            (string) $terms['publicArea'].' (the exact address remains participant-only)',
            '',
            'PARTIES',
            'Owner: '.(string) $terms['ownerName'],
            'Tenant: '.(string) $terms['tenantName'],
            '',
            'RENTAL PERIOD AND COST',
            'Move in: '.(string) $terms['moveInDate'],
            'Move out: '.(string) $terms['moveOutDate'],
            'Occupants: '.(string) $terms['occupants'],
            'Monthly rent: LKR '.number_format((int) $terms['monthlyRentLkr']),
            'Refundable deposit stated by owner: LKR '.number_format((int) $terms['depositLkr']),
            '',
            'HOUSE RULES',
            (string) $terms['houseRules'],
            '',
            'CANCELLATION',
            (string) $terms['cancellationPolicy'],
            '',
            'SAFETY AND PLATFORM NOTICE',
            (string) $terms['safetyNotice'],
            '',
            'ACCEPTANCE RECORD',
            'Owner accepted: '.($agreement->owner_accepted_at?->format('Y-m-d H:i') ?? 'Pending'),
            'Tenant accepted: '.($agreement->tenant_accepted_at?->format('Y-m-d H:i') ?? 'Pending'),
            'Status: '.str_replace('_', ' ', strtoupper($agreement->status)),
            'Integrity hash: '.$agreement->content_hash,
            '',
            'This PDF is a record of electronic acceptance. It is not legal advice.',
        ];

        $wrapped = collect($lines)->flatMap(fn (string $line) => $line === '' ? [''] : explode("\n", wordwrap($this->ascii($line), 88, "\n", true)))->values()->all();
        $pages = array_chunk($wrapped, 43);

        return $this->buildPdf($pages, $agreement);
    }

    private function buildPdf(array $pages, RentalAgreement $agreement): string
    {
        $objects = [];
        $pageObjectIds = [];
        $fontId = 3;
        $boldFontId = 4;
        $nextId = 5;
        foreach ($pages as $pageIndex => $page) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $pageObjectIds[] = $pageId;
            $stream = "q\n0.075 0.102 0.220 rg\n0 758 595 84 re f\n0.973 0.337 0.431 rg\n0 758 9 84 re f\nQ\n";
            $stream .= "BT\n/F2 18 Tf\n1 1 1 rg\n38 805 Td\n(BodimBuddy.lk) Tj\n/F1 9 Tf\n0.82 0.84 0.92 rg\n0 -19 Td\n(Rental agreement - ".$this->escape($agreement->agreement_number).") Tj\nET\n";
            $stream .= "BT\n/F1 9.5 Tf\n0.10 0.12 0.20 rg\n55 725 Td\n15 TL\n";
            foreach ($page as $line) {
                $heading = $line !== '' && $line === strtoupper($line) && strlen($line) < 46;
                $stream .= $heading ? "/F2 10.5 Tf\n0.075 0.102 0.220 rg\n" : "/F1 9.5 Tf\n0.13 0.15 0.23 rg\n";
                $stream .= '('.$this->escape($line).") Tj\nT*\n";
            }
            $stream .= "ET\n";
            $stream .= "q\n0.88 0.89 0.93 RG\n55 43 m\n540 43 l\nS\nQ\nBT\n/F1 7.5 Tf\n0.42 0.44 0.54 rg\n55 27 Td\n(Privacy-safe agreement record - Page ".($pageIndex + 1).' of '.count($pages).") Tj\nET\n";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontId} 0 R /F2 {$boldFontId} 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream";
        }
        $kids = implode(' ', array_map(fn (int $id) => "{$id} 0 R", $pageObjectIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count ".count($pageObjectIds).' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }

        return $pdf."trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private function ascii(string $value): string
    {
        return preg_replace('/[^\x20-\x7E]/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value) ?? $value;
    }
}
