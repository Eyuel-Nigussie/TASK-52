<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('visit_reviews')->cascadeOnDelete();
            $table->unsignedBigInteger('raised_by')->index();
            $table->text('reason');
            $table->enum('status', ['open', 'resolved'])->default('open')->index();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_appeals');
    }
};
