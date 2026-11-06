<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 10)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('description', 500)->nullable();
            $table->string('kind', 10)->default('custom');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'name']); // case-insensitive under the default collation
            $table->index(['user_id', 'kind']);
        });

        Schema::create('folder_submission', function (Blueprint $table) {
            $table->foreignId('folder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->primary(['folder_id', 'submission_id']);
            $table->index('submission_id');
            $table->index(['folder_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_submission');
        Schema::dropIfExists('folders');
    }
};
