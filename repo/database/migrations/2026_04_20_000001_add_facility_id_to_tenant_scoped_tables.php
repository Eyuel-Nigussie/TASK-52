<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `facility_id` to the `merge_requests` and `audit_logs` tables so the
 * multi-location tenant isolation audit concerns (High 2, High 3) have a
 * real discriminator column to filter on. Nullable to accommodate existing
 * rows written before this migration and admin-wide audit entries that have
 * no single facility (login/logout, system jobs, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merge_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('merge_requests', 'facility_id')) {
                $table->unsignedBigInteger('facility_id')->nullable()->after('entity_type')->index();
            }
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('audit_logs', 'facility_id')) {
                $table->unsignedBigInteger('facility_id')->nullable()->after('user_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('merge_requests', function (Blueprint $table) {
            if (Schema::hasColumn('merge_requests', 'facility_id')) {
                $table->dropColumn('facility_id');
            }
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            if (Schema::hasColumn('audit_logs', 'facility_id')) {
                $table->dropColumn('facility_id');
            }
        });
    }
};
