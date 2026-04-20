<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FileStorageService
{
    public function store(UploadedFile $file, string $directory): array
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($checksum === false) {
            throw new RuntimeException('Failed to compute file checksum.');
        }

        $extension = $file->getClientOriginalExtension();
        $fileName = $checksum . '.' . $extension;
        $path = $directory . '/' . $fileName;

        if (!Storage::exists($path)) {
            Storage::putFileAs($directory, $file, $fileName);
        }

        return [
            'path'      => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'file_size' => $file->getSize(),
            'checksum'  => $checksum,
        ];
    }

    public function verify(string $path, string $expectedChecksum): bool
    {
        if (!Storage::exists($path)) {
            return false;
        }
        $fullPath = Storage::path($path);
        $actual = hash_file('sha256', $fullPath);
        return $actual === $expectedChecksum;
    }

    public function delete(string $path): bool
    {
        return Storage::delete($path);
    }

    public function url(string $path): string
    {
        return Storage::url($path);
    }
}
