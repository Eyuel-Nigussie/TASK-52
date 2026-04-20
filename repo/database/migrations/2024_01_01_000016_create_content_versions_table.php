<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('title');
            $table->longText('body');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->text('change_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['content_item_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_versions');
    }
};
