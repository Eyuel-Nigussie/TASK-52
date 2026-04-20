<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storeroom_id')->constrained();
            $table->enum('status', ['open', 'pending_approval', 'approved', 'closed'])
                ->default('open')->index();
            $table->unsignedBigInteger('started_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('approval_reason')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_sessions');
    }
};
