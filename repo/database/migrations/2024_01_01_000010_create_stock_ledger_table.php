<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items');
            $table->foreignId('storeroom_id')->constrained();
            $table->enum('transaction_type', ['inbound', 'outbound', 'transfer', 'adjustment', 'stocktake'])->index();
            $table->decimal('quantity', 12, 3);
            $table->decimal('balance_after', 12, 3);
            $table->string('reference_type', 100)->nullable()->index();
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->unsignedBigInteger('from_storeroom_id')->nullable();
            $table->unsignedBigInteger('to_storeroom_id')->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['item_id', 'storeroom_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger');
    }
};
