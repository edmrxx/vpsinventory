<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns the screens really filter and sort on.
 *
 * Found by tests/Feature/PerformanceTest, which fails when a column a list
 * screen sorts by has no index behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The users list sorts by name by default, and the policy that
            // protects the last administrator filters on is_active.
            $table->index('name', 'users_name_index');
            $table->index('is_active', 'users_is_active_index');
        });

        Schema::table('audit_trails', function (Blueprint $table) {
            // The audit filter and the "busiest user" figure group by this.
            $table->index('username', 'audit_trails_username_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_name_index');
            $table->dropIndex('users_is_active_index');
        });

        Schema::table('audit_trails', function (Blueprint $table) {
            $table->dropIndex('audit_trails_username_index');
        });
    }
};
