<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merge_requests', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 100)->index();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');
            $table->json('conflict_data')->nullable();
            $table->json('resolution_rules')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending')->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merge_requests');
    }
};
