<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 35.1 (CR-3): each person chooses a light or a dark screen.
 *
 * `users.appearance` is light, dark or system (App\Enums\Appearance). Null
 * means the person has not chosen, and follows Settings -> Theme Settings ->
 * Default appearance — so every account already on the books keeps the
 * white screen it had until somebody picks otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('appearance', 10)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('appearance');
        });
    }
};
