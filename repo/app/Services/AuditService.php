<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    /**
     * Keys that must never land in the audit log verbatim.
     *
     * Why: audit logs are immutable and broadly readable (managers and admins),
     * so any secret that reaches this column is effectively exposed. Values for
     * these keys are replaced with the sentinel below.
     */
    private const REDACT_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'api_token',
        'token',
        'captcha_token',
        'phone_encrypted',
        'owner_phone_encrypted',
        'license_number',
    ];

    private const REDACTED = '***REDACTED***';

    public function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
        ?int $facilityId = null
    ): AuditLog {
        // Default the event's facility scope to the authenticated user's own
        // facility if the caller doesn't supply one. This lets every audit
        // row be filtered by facility for tenant-scoped managers.
        if ($facilityId === null) {
            $facilityId = Auth::user()?->facility_id;
        }

        return AuditLog::create([
            'user_id'     => $userId ?? Auth::id(),
            'facility_id' => $facilityId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => $oldValues !== null ? $this->redact($oldValues) : null,
            'new_values'  => $newValues !== null ? $this->redact($newValues) : null,
            'ip_address'  => Request::ip(),
            'user_agent'  => Request::userAgent(),
            'session_id'  => session()->getId(),
        ]);
    }

    public function logModel(
        string $action,
        object $model,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        // If the model carries a facility_id, capture it on the audit row so
        // downstream queries can filter by facility without re-joining.
        $facilityId = null;
        if (isset($model->facility_id)) {
            $facilityId = (int) $model->facility_id;
        }

        return $this->log(
            action: $action,
            entityType: get_class($model),
            entityId: $model->getKey(),
            oldValues: $oldValues,
            newValues: $newValues,
            facilityId: $facilityId,
        );
    }

    public function logLogin(string $username, bool $success, string $ip): void
    {
        $this->log(
            action: $success ? 'auth.login' : 'auth.login_failed',
            newValues: ['username' => $username, 'ip' => $ip],
        );
    }

    public function logExport(string $entityType, array $filters = []): void
    {
        $this->log(
            action: 'export',
            entityType: $entityType,
            newValues: ['filters' => $filters],
        );
    }

    /**
     * Recursively replace sensitive values with a sentinel.
     *
     * Applies case-insensitive key matching so variants like PASSWORD and
     * Password are caught. Nested arrays are walked in place.
     */
    private function redact(array $values): array
    {
        $redactSet = array_flip(array_map('strtolower', self::REDACT_KEYS));

        $walker = function (array $input) use (&$walker, $redactSet): array {
            foreach ($input as $key => $value) {
                if (is_string($key) && isset($redactSet[strtolower($key)])) {
                    $input[$key] = self::REDACTED;
                    continue;
                }
                if (is_array($value)) {
                    $input[$key] = $walker($value);
                }
            }
            return $input;
        };

        return $walker($values);
    }
}
