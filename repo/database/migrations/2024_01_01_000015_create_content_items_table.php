<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['announcement', 'carousel'])->index();
            $table->string('title');
            $table->string('slug')->unique()->index();
            $table->longText('body');
            $table->text('excerpt')->nullable();
            $table->enum('status', ['draft', 'in_review', 'approved', 'published', 'archived'])
                ->default('draft')->index();
            $table->unsignedSmallInteger('version')->default(1);
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedBigInteger('author_id')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->json('facility_ids')->nullable();
            $table->json('department_ids')->nullable();
            $table->json('role_targets')->nullable();
            $table->json('tags')->nullable();
            $table->string('simhash', 64)->nullable()->index();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
