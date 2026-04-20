<?php

declare(strict_types=1);

return [
    'inactivity_timeout' => (int) env('VETOPS_INACTIVITY_TIMEOUT', 15),
    'max_login_attempts' => (int) env('VETOPS_MAX_LOGIN_ATTEMPTS', 10),
    'login_window_minutes' => (int) env('VETOPS_LOGIN_WINDOW_MINUTES', 10),
    'captcha_after' => (int) env('VETOPS_CAPTCHA_AFTER', 5),
    'audit_retention_years' => (int) env('VETOPS_AUDIT_RETENTION_YEARS', 7),
    'overdue_hours' => (int) env('VETOPS_OVERDUE_HOURS', 2),
    'safety_stock_days' => (int) env('VETOPS_SAFETY_STOCK_DAYS', 14),
    'deposit_rate' => (float) env('VETOPS_DEPOSIT_RATE', 0.20),
    'deposit_min' => (float) env('VETOPS_DEPOSIT_MIN', 50.00),
    'stocktake_variance_pct' => (float) env('VETOPS_STOCKTAKE_VARIANCE_PCT', 5),
    'upload_max_mb' => (int) env('VETOPS_UPLOAD_MAX_MB', 20),
    'encryption_key' => env('VETOPS_ENCRYPTION_KEY', env('APP_KEY')),

    'roles' => [
        'system_admin' => 'System Administrator',
        'clinic_manager' => 'Clinic Manager',
        'inventory_clerk' => 'Inventory Clerk',
        'technician_doctor' => 'Technician/Doctor',
        'content_editor' => 'Content Editor',
        'content_approver' => 'Content Approver',
    ],

    'rental_statuses' => ['available', 'rented', 'in_maintenance', 'deactivated'],
    'transaction_statuses' => ['active', 'overdue', 'returned', 'cancelled'],
    'content_statuses' => ['draft', 'in_review', 'approved', 'published', 'archived'],
    'content_types' => ['announcement', 'carousel'],
    'review_statuses' => ['pending', 'published', 'hidden', 'appealed'],
    'stocktake_statuses' => ['open', 'pending_approval', 'approved', 'closed'],
    'ledger_types' => ['inbound', 'outbound', 'transfer', 'adjustment', 'stocktake'],
    'reservation_strategies' => ['lock_at_creation', 'deduct_at_close'],
];
