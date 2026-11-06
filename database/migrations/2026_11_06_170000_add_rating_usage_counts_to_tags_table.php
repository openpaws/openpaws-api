<?php

use App\Models\Tag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-rating usage counts, so tag suggestions and tag pages count only what the viewer may see.
 * usage_count stays as their sum. Backfills by recounting every tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->unsignedInteger('usage_general')->default(0)->after('usage_count');
            $table->unsignedInteger('usage_mature')->default(0)->after('usage_general');
            $table->unsignedInteger('usage_adult')->default(0)->after('usage_mature');
        });

        DB::table('tags')->orderBy('id')->chunkById(500, function ($tags) {
            Tag::recount($tags->pluck('id')->all());
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn(['usage_general', 'usage_mature', 'usage_adult']);
        });
    }
};
