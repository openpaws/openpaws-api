<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 10)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // also indexes user_id
            $table->string('type', 10);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->mediumText('body')->nullable();
            $table->string('rating', 10);
            $table->string('status', 20)->default('processing');
            $table->string('visibility', 10)->default('public');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'visibility', 'rating', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
