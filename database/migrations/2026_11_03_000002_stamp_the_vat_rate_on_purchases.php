<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The VAT rate a delivery was actually invoiced at.
 *
 * On the buying side VAT is not a view. Whether a delivery carried VAT is
 * a fact about that invoice, decided by the supplier who sent it, and it
 * does not change afterwards. The day a supplier registers for VAT, last
 * year's deliveries from them must not quietly redraw.
 *
 * A column like this was built for sales and taken out again in October.
 * The reason it came out does not apply here: there it would only have
 * recorded that a comparison view happened to be switched on, which is
 * noise. This records what the supplier charged.
 *
 * Existing deliveries are filled in from each supplier's VAT flag as it
 * stands today. That is the best guess available — nothing older says what
 * the supplier was back then — and it is why the flag should be set before
 * this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->default(0)->after('total_amount');
        });

        $rate = round((float) config('settings.groups.tax.fields.tax\.vat_rate.default', 12), 2);

        $stored = DB::table('settings')->where('key', 'tax.vat_rate')->value('value');

        if ($stored !== null) {
            $rate = round((float) $stored, 2);
        }

        DB::table('purchases')
            ->whereIn('supplier_id', DB::table('suppliers')->where('is_vat_registered', true)->select('id'))
            ->update(['vat_rate' => $rate]);
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });
    }
};
