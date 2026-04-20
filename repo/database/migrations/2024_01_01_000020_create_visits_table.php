<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained();
            $table->unsignedBigInteger('patient_id')->index();
            $table->unsignedBigInteger('doctor_id')->index();
            $table->unsignedBigInteger('service_order_id')->nullable()->index();
            $table->date('visit_date')->index();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])
                ->default('scheduled')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
