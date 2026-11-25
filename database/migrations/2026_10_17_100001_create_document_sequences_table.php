<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();

            // One counter per prefix per year, locked while a number is taken
            // so two people saving at the same moment cannot be handed the
            // same reference. The year is 0 when numbering does not reset.
            $table->string('prefix', 8);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);

            $table->timestamps();

            $table->unique(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
