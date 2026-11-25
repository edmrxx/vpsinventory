<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Is this supplier VAT-registered?
 *
 * Not to be confused with `tax.vat_registered` in Settings. That one is a
 * **view**: it redraws every document so the client can compare the two
 * ways of trading, and nothing is stored. This is a **record**: whether a
 * particular supplier charges VAT is a fact about that supplier, and it
 * stays written down.
 *
 * Every supplier already on the books starts as Non-VAT, which matches the
 * system's own default. The registered ones are then ticked by hand, which
 * is quicker than unticking the rest and safer than guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_vat_registered')->default(false)->after('tin');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('is_vat_registered');
        });
    }
};
