<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'display_name');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->unique()->after('id');
            $table->timestamp('adult_confirmed_at')->nullable()->after('email_verified_at');
            $table->string('max_rating', 10)->default('general')->after('adult_confirmed_at');
            $table->string('role', 20)->default('user')->after('max_rating');
            $table->string('status', 20)->default('active')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'adult_confirmed_at', 'max_rating', 'role', 'status']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('display_name', 'name');
        });
    }
};
