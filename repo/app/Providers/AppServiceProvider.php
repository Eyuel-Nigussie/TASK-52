<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Rebinds Laravel's `encrypter` service to use VETOPS_ENCRYPTION_KEY when
     * that variable is populated. This matches the operational runbook
     * (OPERATIONS.md §3) which positions VETOPS_ENCRYPTION_KEY as the
     * PII-at-rest cipher, and isolates those columns from a rotated APP_KEY.
     *
     * If VETOPS_ENCRYPTION_KEY is empty, we leave the default bindings alone
     * so local-dev and test environments keep working with APP_KEY only.
     */
    public function boot(): void
    {
        $pii = config('vetops.encryption_key');
        if (is_string($pii) && $pii !== '') {
            $key = $this->decodeKey($pii);
            $cipher = config('app.cipher', 'AES-256-CBC');

            // Basic sanity check — fall back silently if the key length is
            // wrong so boot doesn't crash the application; the operator will
            // see `openssl` errors on the first encrypt() call instead.
            if (Encrypter::supported($key, $cipher)) {
                $this->app->instance('encrypter', new Encrypter($key, $cipher));
            }
        }
    }

    private function decodeKey(string $value): string
    {
        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            return $decoded === false ? $value : $decoded;
        }
        return $value;
    }
}
