<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained();
            $table->string('external_key')->index();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('specialty', 100)->nullable();
            $table->string('license_number', 50)->nullable()->unique();
            $table->text('phone_encrypted')->nullable();
            $table->string('email')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['facility_id', 'external_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
