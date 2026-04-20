<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained();
            $table->string('external_key')->index();
            $table->string('name', 100);
            $table->string('species', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->string('owner_name', 150)->nullable();
            $table->text('owner_phone_encrypted')->nullable();
            $table->string('owner_email')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['facility_id', 'external_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
