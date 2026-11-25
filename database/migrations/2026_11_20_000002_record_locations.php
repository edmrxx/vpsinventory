<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Locations: the store and the warehouse (phase 31.1, the client's concern
 * of 19 November 2026 — a delivery is received at either, and both hold
 * stock).
 *
 * A short master list shaped like Departments. Nothing reads it yet: the
 * stock stays one figure per product until phase 31.2 counts it per
 * location. The two places the client named are put in here, not by a
 * seeder, so a server deployed without db:seed still has them; the owner
 * renames them or adds more on the Locations screen, and `data:reset`
 * empties the list like every other master.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('type', 20)->index();
            $table->string('address', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('locations')->insert([
            ['name' => 'Main Store', 'type' => 'store', 'address' => null, 'description' => 'Where goods are sold over the counter.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Warehouse', 'type' => 'warehouse', 'address' => null, 'description' => 'Where stock is kept until it goes to the store.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
