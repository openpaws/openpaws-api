<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('role', 20);
            $table->string('disk', 50);
            $table->string('path', 512);
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
