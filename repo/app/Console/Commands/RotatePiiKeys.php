<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Doctor;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Re-encrypts PII-bearing columns (phone_encrypted on facilities/doctors/
 * patients/users) from the previous application key to the current key so an
 * operator can rotate APP_KEY without losing access to stored PII.
 *
 * Reads VETOPS_ENCRYPTION_KEY_PREVIOUS (unencrypted base64) as the "old"
 * key and the live APP_KEY / VETOPS_ENCRYPTION_KEY as the "new" key.
 * Rows that already decrypt with the new key are left alone, so re-runs are
 * safe after a partial rotation is interrupted.
 */
class RotatePiiKeys extends Command
{
    protected $signature = 'vetops:rotate-pii-keys
        {--batch=500 : Rows to process per chunk}';

    protected $description = 'Re-encrypt PII columns from the previous encryption key to the current one. Safe to re-run.';

    private const ENCRYPTED_COLUMNS = [
        User::class     => ['phone_encrypted'],
        Doctor::class   => ['phone_encrypted'],
        Patient::class  => ['owner_phone_encrypted'],
        Facility::class => ['phone_encrypted'],
    ];

    public function handle(): int
    {
        $previousKeyRaw = env('VETOPS_ENCRYPTION_KEY_PREVIOUS');
        if (!$previousKeyRaw) {
            $this->error('VETOPS_ENCRYPTION_KEY_PREVIOUS is not set. Export the prior APP_KEY into this variable before running.');
            return self::FAILURE;
        }

        $previousKey = $this->decodeKey($previousKeyRaw);
        $previousEnc = new Encrypter($previousKey, config('app.cipher', 'AES-256-CBC'));
        $batch = max(10, (int) $this->option('batch'));

        $totalRotated = 0;
        $totalSkipped = 0;

        foreach (self::ENCRYPTED_COLUMNS as $modelClass => $columns) {
            foreach ($columns as $column) {
                $this->line("Rotating {$modelClass}.{$column}...");

                $modelClass::query()->whereNotNull($column)->chunkById($batch, function ($rows) use ($column, $previousEnc, &$totalRotated, &$totalSkipped) {
                    foreach ($rows as $row) {
                        $ciphertext = $row->{$column};
                        try {
                            // Already decryptable with current key — nothing to do.
                            decrypt($ciphertext);
                            $totalSkipped++;
                            continue;
                        } catch (DecryptException) {
                            // Fall through to previous-key rotation.
                        }

                        try {
                            $plain = $previousEnc->decrypt($ciphertext);
                        } catch (DecryptException) {
                            $this->warn("Row {$row->getKey()} on {$column} could not be decrypted with the previous key. Skipped.");
                            continue;
                        }

                        $row->forceFill([$column => encrypt($plain)])->saveQuietly();
                        $totalRotated++;
                    }
                });
            }
        }

        $this->info("Rotated {$totalRotated} row(s). Already current: {$totalSkipped}.");

        return self::SUCCESS;
    }

    private function decodeKey(string $value): string
    {
        if (str_starts_with($value, 'base64:')) {
            $value = substr($value, 7);
            $decoded = base64_decode($value, true);
            if ($decoded === false) {
                throw new \RuntimeException('VETOPS_ENCRYPTION_KEY_PREVIOUS is not valid base64.');
            }
            return $decoded;
        }
        return $value;
    }
}
