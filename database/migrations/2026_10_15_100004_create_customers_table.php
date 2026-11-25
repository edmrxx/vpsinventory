<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('code', 40)->unique();
            $table->string('name', 160)->index();

            $table->string('contact_person', 160)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('tin', 40)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();

            // The counter sale needs a customer, so one row is marked as the
            // walk-in. The flag is used instead of the name, because the name
            // can be edited.
            $table->boolean('is_walk_in')->default(false)->index();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
