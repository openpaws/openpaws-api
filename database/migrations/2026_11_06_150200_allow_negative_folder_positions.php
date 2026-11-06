<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** New items are placed at min(position)-1, so folder_submission.position must be signed. */
    public function up(): void
    {
        Schema::table('folder_submission', function (Blueprint $table) {
            $table->integer('position')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('folder_submission', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->change();
        });
    }
};
