<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('external_key', 100)->unique();
            $table->string('name');
            $table->string('category', 100)->index();
            $table->string('code', 50)->nullable()->index();
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_minutes')->default(30);
            $table->boolean('active')->default(true)->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
