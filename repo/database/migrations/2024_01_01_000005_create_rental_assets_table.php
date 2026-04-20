<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->string('external_key')->nullable()->index();
            $table->string('name');
            $table->string('category', 100)->index();
            $table->string('manufacturer', 100)->nullable();
            $table->string('model_number', 100)->nullable();
            $table->string('serial_number', 100)->nullable()->unique();
            $table->string('barcode', 100)->nullable()->unique()->index();
            $table->string('qr_code', 200)->nullable()->unique()->index();
            $table->enum('status', ['available', 'rented', 'maintenance', 'in_maintenance', 'deactivated'])
                ->default('available')->index();
            $table->decimal('replacement_cost', 12, 2)->default(0);
            $table->decimal('daily_rate', 10, 2)->default(0);
            $table->decimal('weekly_rate', 10, 2)->default(0);
            $table->decimal('deposit_amount', 10, 2)->default(0);
            $table->string('photo_path')->nullable();
            $table->string('photo_checksum', 64)->nullable();
            $table->json('specs')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['facility_id', 'external_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_assets');
    }
};
