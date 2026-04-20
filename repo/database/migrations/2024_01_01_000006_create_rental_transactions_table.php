<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('rental_assets')->cascadeOnDelete();
            $table->enum('renter_type', ['department', 'clinician']);
            $table->unsignedBigInteger('renter_id')->index();
            $table->foreignId('facility_id')->constrained();
            $table->timestamp('checked_out_at');
            $table->timestamp('expected_return_at');
            $table->timestamp('actual_return_at')->nullable();
            $table->enum('status', ['active', 'overdue', 'returned', 'cancelled'])
                ->default('active')->index();
            $table->decimal('deposit_collected', 10, 2)->default(0);
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->text('fee_terms')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_transactions');
    }
};
