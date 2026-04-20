<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->decimal('base_price', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            // One active pricing per (service × facility × start) — indexed for lookup.
            $table->index(['service_id', 'facility_id', 'effective_from'], 'svc_pricing_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_pricings');
    }
};
