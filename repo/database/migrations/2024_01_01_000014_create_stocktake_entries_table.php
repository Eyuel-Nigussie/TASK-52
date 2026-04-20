<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('stocktake_sessions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->decimal('system_quantity', 12, 3)->default(0);
            $table->decimal('counted_quantity', 12, 3)->default(0);
            $table->decimal('variance_pct', 8, 4)->default(0);
            $table->boolean('requires_approval')->default(false)->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('approval_reason')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_entries');
    }
};
