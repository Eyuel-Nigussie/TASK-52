<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FileStorageService
{
    public function store(UploadedFile $file, string $directory, string $disk = 'public'): array
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        if ($checksum === false) {
            throw new RuntimeException('Failed to compute file checksum.');
        }

        $extension = $file->getClientOriginalExtension();
        $fileName = $checksum . '.' . $extension;
        $path = $directory . '/' . $fileName;

        if (!Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->putFileAs($directory, $file, $fileName);
        }

        return [
            'path'      => $path,
            'disk'      => $disk,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'file_size' => $file->getSize(),
            'checksum'  => $checksum,
        ];
    }

    public function verify(string $path, string $expectedChecksum, string $disk = 'public'): bool
    {
        if (!Storage::disk($disk)->exists($path)) {
            return false;
        }
        $fullPath = Storage::disk($disk)->path($path);
        $actual = hash_file('sha256', $fullPath);
        return $actual === $expectedChecksum;
    }

    public function delete(string $path, string $disk = 'public'): bool
    {
        return Storage::disk($disk)->delete($path);
    }

    public function url(string $path, string $disk = 'public'): string
    {
        return Storage::disk($disk)->url($path);
    }
}
