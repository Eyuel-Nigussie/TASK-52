<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_attempts', function (Blueprint $table) {
            $table->string('device_id', 64)->nullable()->index()->after('ip_address');
            $table->string('throttle_key', 64)->nullable()->index()->after('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('login_attempts', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'throttle_key']);
        });
    }
};
