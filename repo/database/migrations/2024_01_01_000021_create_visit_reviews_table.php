<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained();
            $table->unsignedBigInteger('doctor_id')->index();
            $table->unsignedSmallInteger('rating')->default(5);
            $table->json('tags')->nullable();
            $table->text('body')->nullable();
            $table->enum('status', ['pending', 'published', 'hidden', 'appealed'])
                ->default('pending')->index();
            $table->timestamp('submitted_at')->useCurrent();
            $table->string('submitted_by_name', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_reviews');
    }
};
