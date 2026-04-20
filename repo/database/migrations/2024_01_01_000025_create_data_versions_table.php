<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_versions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 100)->index();
            $table->unsignedBigInteger('entity_id')->index();
            $table->unsignedSmallInteger('version');
            $table->json('data');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->useCurrent()->index();
            $table->string('change_summary', 255)->nullable();
            $table->index(['entity_type', 'entity_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_versions');
    }
};
