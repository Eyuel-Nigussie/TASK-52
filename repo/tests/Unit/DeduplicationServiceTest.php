<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DeduplicationService;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage for DeduplicationService helpers (SimHash, URL
 * normalization, key-field matching, content fingerprint). These helpers
 * are used by import and content workflows; locking them down here keeps
 * future changes from silently breaking duplicate detection.
 */
class DeduplicationServiceTest extends TestCase
{
    private DeduplicationService $dedup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dedup = new DeduplicationService();
    }

    public function test_normalize_url_strips_scheme_www_and_trailing_slash(): void
    {
        $this->assertSame('example.com/foo', $this->dedup->normalizeUrl('https://www.example.com/foo/'));
        $this->assertSame('example.com/foo', $this->dedup->normalizeUrl('http://example.com/foo'));
        $this->assertSame('example.com/foo', $this->dedup->normalizeUrl('HTTPS://WWW.EXAMPLE.COM/foo/'));
    }

    public function test_normalize_url_drops_query_and_fragment(): void
    {
        $this->assertSame('example.com/a', $this->dedup->normalizeUrl('https://example.com/a?x=1&y=2'));
        $this->assertSame('example.com/a', $this->dedup->normalizeUrl('https://example.com/a#section'));
    }

    public function test_match_by_key_fields_case_insensitive_trims(): void
    {
        $candidates = [
            ['id' => 1, 'first_name' => 'Jane', 'last_name' => 'Smith'],
            ['id' => 2, 'first_name' => 'Bob',  'last_name' => 'Jones'],
        ];

        $match = $this->dedup->matchByKeyFields(
            ['first_name' => ' jane ', 'last_name' => 'SMITH'],
            $candidates,
            ['first_name', 'last_name'],
        );

        $this->assertSame(1, $match['id']);
    }

    public function test_match_by_key_fields_returns_null_when_no_match(): void
    {
        $candidates = [['id' => 1, 'name' => 'Alpha']];

        $this->assertNull(
            $this->dedup->matchByKeyFields(['name' => 'Beta'], $candidates, ['name']),
        );
    }

    public function test_content_fingerprint_ignores_whitespace_and_tags(): void
    {
        $a = $this->dedup->computeContentFingerprint('<p>Hello    world</p>');
        $b = $this->dedup->computeContentFingerprint('Hello world');

        $this->assertSame($a, $b);
    }

    public function test_simhash_is_stable_for_identical_content(): void
    {
        $a = $this->dedup->computeSimHash('The quick brown fox jumps over the lazy dog.');
        $b = $this->dedup->computeSimHash('The quick brown fox jumps over the lazy dog.');

        $this->assertSame($a, $b);
    }

    public function test_simhash_differs_for_unrelated_content(): void
    {
        $a = $this->dedup->computeSimHash('Emergency surgery notice for the weekend.');
        $b = $this->dedup->computeSimHash('Pharmacy inventory replenished this morning.');

        $this->assertGreaterThan(0, $this->dedup->hammingDistance($a, $b));
    }

    public function test_simhash_treats_url_variants_as_near_identical(): void
    {
        $bodyA = 'Please read the update at https://www.example.com/updates/ for more.';
        $bodyB = 'Please read the update at http://example.com/updates for more.';

        // URL normalization inside SimHash computation should collapse the
        // trivial scheme/www/trailing-slash differences, producing hashes that
        // are effectively identical (Hamming distance 0 for this pair).
        $hashA = $this->dedup->computeSimHash($bodyA);
        $hashB = $this->dedup->computeSimHash($bodyB);

        $this->assertTrue($this->dedup->isSimilar($hashA, $hashB, 2));
    }

    public function test_hamming_distance_is_symmetric(): void
    {
        $a = $this->dedup->computeSimHash('abc def ghi jkl mno pqr');
        $b = $this->dedup->computeSimHash('xyz abc def ghi jkl mno');

        $this->assertSame(
            $this->dedup->hammingDistance($a, $b),
            $this->dedup->hammingDistance($b, $a),
        );
    }

    public function test_is_similar_respects_threshold(): void
    {
        $a = $this->dedup->computeSimHash('one two three four five six');
        $b = $this->dedup->computeSimHash('completely different content that shares nothing');

        $this->assertFalse($this->dedup->isSimilar($a, $b, 3));
    }
}
