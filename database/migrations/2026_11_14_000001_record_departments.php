<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Departments, and the department a purchase request is raised for
 * (phase 24.1, the client's purchasing flow of 13 November 2026).
 *
 * A short master list like Categories: the owner keeps it, a request picks
 * one, and one used on a request is switched off rather than deleted. The two
 * the client named are put in here, not by a seeder, so a server deployed
 * without db:seed still has them; `data:reset` empties the list like every
 * other master, and the client then enters their own.
 *
 * Every request raised before this phase has no department, and keeps none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('departments')->insert([
            ['name' => 'Electrical', 'description' => 'Wiring, lighting and electrical work.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Construction', 'description' => 'Building and site work.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('supplier_id')
                ->constrained()->restrictOnDelete();
            // The list filters on it. MySQL indexes a foreign key by itself;
            // SQLite, in the tests, does not.
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
