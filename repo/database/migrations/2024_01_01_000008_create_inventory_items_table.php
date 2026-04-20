<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('external_key')->unique()->index();
            $table->string('name');
            $table->string('sku', 100)->nullable()->unique()->index();
            $table->string('category', 100)->index();
            $table->string('unit_of_measure', 50)->default('unit');
            $table->unsignedSmallInteger('safety_stock_days')->default(14);
            $table->decimal('reorder_point', 10, 3)->default(0);
            $table->json('supplier_info')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
