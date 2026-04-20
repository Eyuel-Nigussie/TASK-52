<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentMedia;
use App\Models\CsvImport;
use App\Models\RentalAsset;
use App\Models\ReviewImage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Walks every stored file that the system records a SHA-256 checksum for and
 * verifies the on-disk bytes still match. Mismatches are reported to the
 * console and surfaced in the command's exit code so a scheduler/cron wrapper
 * can flip an alert.
 *
 * Covers every persisted checksum-bearing source:
 *  - CSV imports            (CsvImport.file_checksum   ↔ file_path)
 *  - Review photos          (ReviewImage.checksum      ↔ file_path)
 *  - Content media          (ContentMedia.checksum     ↔ file_path)
 *  - Rental asset photos    (RentalAsset.photo_checksum↔ photo_path)
 */
class VerifyFileChecksums extends Command
{
    protected $signature = 'vetops:verify-file-checksums
        {--since= : Restrict to records created in the last N period (e.g. 7d, 24h)}';

    protected $description = 'Verify stored file checksums match on-disk bytes. Reports drift/loss.';

    /**
     * Checksum-bearing sources: [model class, path column, checksum column, short label].
     */
    private const SOURCES = [
        [CsvImport::class,    'file_path',  'file_checksum',  'CsvImport'],
        [ReviewImage::class,  'file_path',  'checksum',       'ReviewImage'],
        [ContentMedia::class, 'file_path',  'checksum',       'ContentMedia'],
        [RentalAsset::class,  'photo_path', 'photo_checksum', 'RentalAsset'],
    ];

    public function handle(): int
    {
        $since = $this->option('since');
        $cutoff = $this->parseSince($since);

        $checked = 0;
        $missing = 0;
        $mismatched = 0;

        foreach (self::SOURCES as [$class, $pathColumn, $checksumColumn, $label]) {
            [$c, $m, $mm] = $this->verifyModel($class, $pathColumn, $checksumColumn, $label, $cutoff);
            $checked += $c;
            $missing += $m;
            $mismatched += $mm;
        }

        $this->info("Checked {$checked} file(s). Missing: {$missing}. Mismatched: {$mismatched}.");

        return ($missing + $mismatched) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param class-string<Model> $class
     * @return array{int, int, int} [checked, missing, mismatched]
     */
    private function verifyModel(string $class, string $pathColumn, string $checksumColumn, string $label, ?\DateTimeInterface $cutoff): array
    {
        $checked = 0;
        $missing = 0;
        $mismatched = 0;

        $query = $class::query()
            ->whereNotNull($pathColumn)
            ->whereNotNull($checksumColumn);
        if ($cutoff !== null) {
            $query->where('created_at', '>=', $cutoff);
        }

        foreach ($query->cursor() as $row) {
            $path = $row->{$pathColumn};
            $expected = $row->{$checksumColumn};
            if (!$path || !$expected) {
                continue;
            }
            $checked++;

            if (!Storage::exists($path)) {
                $missing++;
                $this->warn("Missing file for {$label} #{$row->getKey()}: {$path}");
                continue;
            }

            $actual = hash('sha256', Storage::get($path));
            if (!hash_equals((string) $expected, $actual)) {
                $mismatched++;
                $this->error("Checksum mismatch for {$label} #{$row->getKey()}: expected {$expected}, got {$actual}");
            }
        }

        return [$checked, $missing, $mismatched];
    }

    private function parseSince(?string $value): ?\DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!preg_match('/^(\d+)([dhm])$/', $value, $m)) {
            $this->warn("Unrecognized --since value '{$value}'; ignoring.");
            return null;
        }
        $n = (int) $m[1];
        return match ($m[2]) {
            'd' => now()->subDays($n),
            'h' => now()->subHours($n),
            'm' => now()->subMinutes($n),
        };
    }
}
