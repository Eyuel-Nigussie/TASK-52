<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FileStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileChecksumTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_sha256_checksum_computed_on_store(): void
    {
        $service = new FileStorageService();
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $result = $service->store($file, 'test-dir');

        $this->assertArrayHasKey('checksum', $result);
        $this->assertEquals(64, strlen($result['checksum'])); // SHA-256 hex is 64 chars
    }

    public function test_integrity_check_passes_for_stored_file(): void
    {
        $service = new FileStorageService();
        $file = UploadedFile::fake()->create('document.txt', 50, 'text/plain');

        $result = $service->store($file, 'test-dir');

        $this->assertTrue($service->verify($result['path'], $result['checksum']));
    }

    public function test_integrity_check_fails_for_wrong_checksum(): void
    {
        $service = new FileStorageService();
        $file = UploadedFile::fake()->create('document.txt', 50, 'text/plain');

        $result = $service->store($file, 'test-dir');

        $this->assertFalse($service->verify($result['path'], 'wrongchecksum'));
    }

    public function test_integrity_check_fails_for_nonexistent_file(): void
    {
        $service = new FileStorageService();
        $this->assertFalse($service->verify('nonexistent/file.txt', 'anychecksum'));
    }

    public function test_same_content_produces_same_checksum(): void
    {
        $service = new FileStorageService();
        $content = 'same content';

        $file1 = UploadedFile::fake()->createWithContent('file1.txt', $content);
        $file2 = UploadedFile::fake()->createWithContent('file2.txt', $content);

        $result1 = $service->store($file1, 'dir1');
        $result2 = $service->store($file2, 'dir2');

        $this->assertEquals($result1['checksum'], $result2['checksum']);
    }

    public function test_different_content_produces_different_checksum(): void
    {
        $service = new FileStorageService();

        $file1 = UploadedFile::fake()->createWithContent('file1.txt', 'content one');
        $file2 = UploadedFile::fake()->createWithContent('file2.txt', 'content two');

        $result1 = $service->store($file1, 'dir1');
        $result2 = $service->store($file2, 'dir2');

        $this->assertNotEquals($result1['checksum'], $result2['checksum']);
    }
}
