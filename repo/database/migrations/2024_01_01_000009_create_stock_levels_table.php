<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignId('storeroom_id')->constrained()->cascadeOnDelete();
            $table->decimal('on_hand', 12, 3)->default(0);
            $table->decimal('reserved', 12, 3)->default(0);
            $table->decimal('available_to_promise', 12, 3)->default(0);
            $table->timestamp('last_stocktake_at')->nullable();
            $table->decimal('avg_daily_usage', 10, 3)->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['item_id', 'storeroom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
