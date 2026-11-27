<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 28.1 (CR-4): a mobilization charge on a quotation, a sales order and
 * a sale.
 *
 * What bringing the goods, the people or the equipment to the customer's
 * site costs — a service, not goods: no stock, no cost. Typed VAT-inclusive
 * like every amount, added to the whole document after the discount comes
 * off the goods, so it is never discounted:
 *
 *     total_amount = subtotal − discount + mobilization
 *
 * `subtotal` stays the goods alone. Every document already on the books gets
 * 0, so each reads exactly as it did.
 */
return new class extends Migration
{
    protected const TABLES = ['quotations', 'sales_orders', 'sales'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->decimal('mobilization', 15, 2)->default(0)->after('discount');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('mobilization');
            });
        }
    }
};
