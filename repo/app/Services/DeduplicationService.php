<?php

declare(strict_types=1);

namespace App\Services;

class DeduplicationService
{
    private const SIMHASH_BITS = 64;

    public function computeSimHash(string $text): string
    {
        $text = mb_strtolower(strip_tags($text));
        // Normalize embedded URLs so "http://example.com/x" and
        // "https://www.example.com/x/" hash to the same shingle set —
        // otherwise cosmetic URL edits bypass near-duplicate detection.
        $text = preg_replace_callback(
            '#\bhttps?://[^\s<>"\']+#i',
            fn($m) => $this->normalizeUrl($m[0]),
            $text,
        ) ?? $text;
        $shingles = $this->getShingles($text, 3);
        $vector = array_fill(0, self::SIMHASH_BITS, 0);

        foreach ($shingles as $shingle) {
            $hash = $this->murmurHash($shingle);
            for ($i = 0; $i < self::SIMHASH_BITS; $i++) {
                $vector[$i] += (($hash >> $i) & 1) ? 1 : -1;
            }
        }

        $fingerprint = '';
        for ($i = 0; $i < self::SIMHASH_BITS; $i++) {
            $fingerprint .= $vector[$i] > 0 ? '1' : '0';
        }

        return base_convert($fingerprint, 2, 16);
    }

    public function hammingDistance(string $hash1, string $hash2): int
    {
        $bin1 = str_pad(base_convert($hash1, 16, 2), self::SIMHASH_BITS, '0', STR_PAD_LEFT);
        $bin2 = str_pad(base_convert($hash2, 16, 2), self::SIMHASH_BITS, '0', STR_PAD_LEFT);
        $distance = 0;
        for ($i = 0; $i < self::SIMHASH_BITS; $i++) {
            if ($bin1[$i] !== $bin2[$i]) {
                $distance++;
            }
        }
        return $distance;
    }

    public function isSimilar(string $hash1, string $hash2, int $threshold = 6): bool
    {
        return $this->hammingDistance($hash1, $hash2) <= $threshold;
    }

    public function normalizeUrl(string $url): string
    {
        $url = mb_strtolower(trim($url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url) ?? $url;
        $url = rtrim($url, '/');
        $url = preg_replace('/[?#].*$/', '', $url) ?? $url;
        return $url;
    }

    public function computeContentFingerprint(string $content): string
    {
        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', strip_tags($content)) ?? $content);
        return hash('sha256', $normalized);
    }

    public function matchByKeyFields(array $record, array $candidates, array $keyFields): ?array
    {
        foreach ($candidates as $candidate) {
            $allMatch = true;
            foreach ($keyFields as $field) {
                $recordVal = mb_strtolower(trim((string) ($record[$field] ?? '')));
                $candidateVal = mb_strtolower(trim((string) ($candidate[$field] ?? '')));
                if ($recordVal !== $candidateVal) {
                    $allMatch = false;
                    break;
                }
            }
            if ($allMatch) {
                return $candidate;
            }
        }
        return null;
    }

    private function getShingles(string $text, int $k): array
    {
        $words = preg_split('/\s+/', $text) ?: [];
        $shingles = [];
        $n = count($words);
        for ($i = 0; $i <= $n - $k; $i++) {
            $shingles[] = implode(' ', array_slice($words, $i, $k));
        }
        return array_unique($shingles);
    }

    private function murmurHash(string $str): int
    {
        $hash = crc32($str);
        return $hash & 0xFFFFFFFF;
    }
}
