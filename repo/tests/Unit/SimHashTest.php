<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DeduplicationService;
use Tests\TestCase;

class SimHashTest extends TestCase
{
    private DeduplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeduplicationService();
    }

    public function test_identical_texts_produce_same_hash(): void
    {
        $text = 'This is a test announcement for all staff members.';
        $hash1 = $this->service->computeSimHash($text);
        $hash2 = $this->service->computeSimHash($text);

        $this->assertEquals($hash1, $hash2);
    }

    public function test_very_similar_texts_detected_as_similar(): void
    {
        $text1 = 'Please attend the staff meeting on Friday at 2pm in the main conference room.';
        $text2 = 'Please attend the staff meeting on Friday at 2pm in the main conference room building.';

        $hash1 = $this->service->computeSimHash($text1);
        $hash2 = $this->service->computeSimHash($text2);

        $this->assertTrue($this->service->isSimilar($hash1, $hash2));
    }

    public function test_completely_different_texts_not_similar(): void
    {
        $text1 = 'Staff meeting on Friday about budget planning and resource allocation for next quarter.';
        $text2 = 'Emergency surgery training scheduled for new medical equipment operation and safety.';

        $hash1 = $this->service->computeSimHash($text1);
        $hash2 = $this->service->computeSimHash($text2);

        $this->assertFalse($this->service->isSimilar($hash1, $hash2));
    }

    public function test_hamming_distance_identical_is_zero(): void
    {
        $text = 'Same text content here';
        $hash = $this->service->computeSimHash($text);

        $distance = $this->service->hammingDistance($hash, $hash);
        $this->assertEquals(0, $distance);
    }

    public function test_url_normalization(): void
    {
        $url1 = 'http://www.example.com/path/';
        $url2 = 'https://example.com/path';

        $normalized1 = $this->service->normalizeUrl($url1);
        $normalized2 = $this->service->normalizeUrl($url2);

        $this->assertEquals($normalized1, $normalized2);
    }

    public function test_url_normalization_strips_query(): void
    {
        $url = 'http://example.com/path?query=value#fragment';
        $normalized = $this->service->normalizeUrl($url);
        $this->assertEquals('example.com/path', $normalized);
    }

    public function test_key_field_matching(): void
    {
        $record = ['name' => 'Dr. Smith', 'specialty' => 'Surgery'];
        $candidates = [
            ['name' => 'Dr. Jones', 'specialty' => 'Surgery'],
            ['name' => 'Dr. Smith', 'specialty' => 'Surgery'],
            ['name' => 'Dr. Brown', 'specialty' => 'Internal'],
        ];

        $match = $this->service->matchByKeyFields($record, $candidates, ['name', 'specialty']);
        $this->assertNotNull($match);
        $this->assertEquals('Dr. Smith', $match['name']);
    }

    public function test_key_field_matching_returns_null_when_no_match(): void
    {
        $record = ['name' => 'Dr. Unknown', 'specialty' => 'Exotic'];
        $candidates = [
            ['name' => 'Dr. Jones', 'specialty' => 'Surgery'],
        ];

        $match = $this->service->matchByKeyFields($record, $candidates, ['name', 'specialty']);
        $this->assertNull($match);
    }

    public function test_content_fingerprint_is_consistent(): void
    {
        $content = 'Test content for fingerprinting';
        $fp1 = $this->service->computeContentFingerprint($content);
        $fp2 = $this->service->computeContentFingerprint($content);

        $this->assertEquals($fp1, $fp2);
    }

    public function test_content_fingerprint_ignores_extra_whitespace(): void
    {
        $content1 = 'Test content here';
        $content2 = 'Test   content   here';

        $fp1 = $this->service->computeContentFingerprint($content1);
        $fp2 = $this->service->computeContentFingerprint($content2);

        $this->assertEquals($fp1, $fp2);
    }
}
