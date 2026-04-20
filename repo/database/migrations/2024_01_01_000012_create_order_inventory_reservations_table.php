<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->foreignId('storeroom_id')->constrained();
            $table->decimal('quantity_reserved', 12, 3)->default(0);
            $table->decimal('quantity_deducted', 12, 3)->default(0);
            $table->enum('status', ['reserved', 'deducted', 'cancelled'])->default('reserved')->index();
            $table->timestamps();
            $table->index(['service_order_id', 'item_id', 'storeroom_id'], 'oir_svc_item_store_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_inventory_reservations');
    }
};
